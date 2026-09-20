<?php
session_start();
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true || strtolower($_SESSION['role'] ?? '') !== 'customer') {
    header('Location: /sps/customer_login.php');
    exit;
}

require_once '../includes/dbh.inc.php';
require_once '../includes/notifications.php';

$customerId = (int)($_SESSION['customer_id'] ?? 0);
$customerName = $_SESSION['customer_name'] ?? 'Customer';

$conn->exec("CREATE TABLE IF NOT EXISTS customer_support_messages (
    id INT AUTO_INCREMENT PRIMARY KEY,
    customer_id INT NOT NULL,
    message LONGTEXT NOT NULL,
    status VARCHAR(30) NOT NULL DEFAULT 'Open',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX(customer_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

$conn->exec("CREATE TABLE IF NOT EXISTS customer_service_requests (
    id INT AUTO_INCREMENT PRIMARY KEY,
    customer_id INT NOT NULL,
    request_number VARCHAR(30) DEFAULT NULL,
    service_type VARCHAR(100) NOT NULL,
    location VARCHAR(255) NOT NULL,
    preferred_date DATE DEFAULT NULL,
    customer_phone VARCHAR(30) DEFAULT NULL,
    urgency VARCHAR(30) NOT NULL DEFAULT 'Normal',
    equipment_details VARCHAR(255) DEFAULT NULL,
    problem_summary VARCHAR(255) DEFAULT NULL,
    description LONGTEXT NOT NULL,
    special_instructions LONGTEXT DEFAULT NULL,
    status VARCHAR(30) NOT NULL DEFAULT 'Pending',
    approved_workorder_id INT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX(customer_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

try {
    $conn->exec("ALTER TABLE customer_service_requests ADD COLUMN IF NOT EXISTS request_number VARCHAR(30) DEFAULT NULL");
    $conn->exec("ALTER TABLE customer_service_requests ADD COLUMN IF NOT EXISTS customer_phone VARCHAR(30) DEFAULT NULL");
    $conn->exec("ALTER TABLE customer_service_requests ADD COLUMN IF NOT EXISTS urgency VARCHAR(30) NOT NULL DEFAULT 'Normal'");
    $conn->exec("ALTER TABLE customer_service_requests ADD COLUMN IF NOT EXISTS equipment_details VARCHAR(255) DEFAULT NULL");
    $conn->exec("ALTER TABLE customer_service_requests ADD COLUMN IF NOT EXISTS problem_summary VARCHAR(255) DEFAULT NULL");
    $conn->exec("ALTER TABLE customer_service_requests ADD COLUMN IF NOT EXISTS special_instructions LONGTEXT DEFAULT NULL");

    $requestNumberRows = $conn->query("SELECT id FROM customer_service_requests WHERE request_number IS NULL OR request_number = '' ORDER BY id");
    while ($requestRow = $requestNumberRows->fetch(PDO::FETCH_ASSOC)) {
        $requestNumber = 'SR-' . str_pad((string)(int)$requestRow['id'], 5, '0', STR_PAD_LEFT);
        $updateRequestNumber = $conn->prepare('UPDATE customer_service_requests SET request_number = ? WHERE id = ?');
        $updateRequestNumber->execute([$requestNumber, (int)$requestRow['id']]);
    }
} catch (Exception $e) {
    // ignore migration issues if the table is already compatible
}

$customer = $conn->prepare('SELECT id, name, email, phone, address, city, state, zip FROM customers WHERE id = ? LIMIT 1');
$customer->execute([$customerId]);
$customerRow = $customer->fetch(PDO::FETCH_ASSOC);

$requestLimit = isset($_GET['request_limit']) ? (int)$_GET['request_limit'] : 10;
if (!in_array($requestLimit, [10, 50, 100], true)) {
    $requestLimit = 10;
}

$workorderLimit = isset($_GET['workorder_limit']) ? (int)$_GET['workorder_limit'] : 10;
if (!in_array($workorderLimit, [10, 50, 100], true)) {
    $workorderLimit = 10;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['request_halt'])) {
        $haltId = (int)$_POST['request_halt'];
        if ($haltId > 0) {
            $woStmt = $conn->prepare('SELECT id, status, order_number FROM workorders WHERE id = ? AND customer_id = ? LIMIT 1');
            $woStmt->execute([$haltId, $customerId]);
            $targetWo = $woStmt->fetch(PDO::FETCH_ASSOC);

            if ($targetWo) {
                $currentStatus = strtolower(trim((string)($targetWo['status'] ?? '')));
                if (!in_array($currentStatus, ['on hold', 'completed', 'closed'], true)) {
                    $oldStatus = $targetWo['status'] ?? '';
                    $upd = $conn->prepare('UPDATE workorders SET status = ? WHERE id = ?');
                    $upd->execute(['On Hold', $haltId]);

                    $ins = $conn->prepare('INSERT INTO workorder_edits (workorder_id, field_name, old_value, new_value, edited_by) VALUES (?, ?, ?, ?, ?)');
                    $ins->execute([$haltId, 'status', $oldStatus, 'On Hold', null]);

                    $orderLabel = !empty($targetWo['order_number']) ? $targetWo['order_number'] : 'WO' . str_pad((string)$haltId, 4, '0', STR_PAD_LEFT);
                    notify_staff_roles(
                        $conn,
                        ['admin', 'office'],
                        'customer_halt_request',
                        'Customer requested a pause on work order: ' . $orderLabel,
                        'The customer has requested that work be paused on work order ' . $orderLabel . '. Its status has been set to On Hold.',
                        '/sps/pages/view_workorder.php?id=' . $haltId
                    );
                }
            }
        }
        header('Location: /sps/pages/customer_dashboard.php?halt_requested=1&request_limit=' . $requestLimit . '&workorder_limit=' . $workorderLimit);
        exit;
    }

    if (isset($_POST['delete_request'])) {
        $deleteId = (int)$_POST['delete_request'];
        if ($deleteId > 0) {
            $deleteRequest = $conn->prepare('DELETE FROM customer_service_requests WHERE id = ? AND customer_id = ? AND approved_workorder_id IS NULL');
            $deleteRequest->execute([$deleteId, $customerId]);
        }
        header('Location: /sps/pages/customer_dashboard.php?request_deleted=1&request_limit=' . $requestLimit . '&workorder_limit=' . $workorderLimit);
        exit;
    }

    if (isset($_POST['bulk_delete_requests'])) {
        $selectedValues = isset($_POST['selected_ids']) ? (string)$_POST['selected_ids'] : '';
        $selectedIds = [];

        if ($selectedValues !== '') {
            foreach (preg_split('/\s*,\s*/', $selectedValues, -1, PREG_SPLIT_NO_EMPTY) as $selectedValue) {
                $selectedId = (int)$selectedValue;
                if ($selectedId > 0) {
                    $selectedIds[] = $selectedId;
                }
            }
            $selectedIds = array_values(array_unique($selectedIds));
        }

        if (empty($selectedIds)) {
            $selectedRequests = $_POST['selected_requests'] ?? [];
            if (is_array($selectedRequests)) {
                foreach ($selectedRequests as $selectedRequestId) {
                    $selectedId = (int)$selectedRequestId;
                    if ($selectedId > 0) {
                        $selectedIds[] = $selectedId;
                    }
                }
                $selectedIds = array_values(array_unique($selectedIds));
            }
        }

        if (!empty($selectedIds)) {
            try {
                $conn->beginTransaction();
                foreach ($selectedIds as $selectedId) {
                    $bulkDelete = $conn->prepare('DELETE FROM customer_service_requests WHERE id = ? AND customer_id = ? AND approved_workorder_id IS NULL');
                    $bulkDelete->execute([$selectedId, $customerId]);
                }
                $conn->commit();
            } catch (Exception $e) {
                if ($conn->inTransaction()) {
                    $conn->rollBack();
                }
            }
        }

        header('Location: /sps/pages/customer_dashboard.php?request_deleted=1&request_limit=' . $requestLimit . '&workorder_limit=' . $workorderLimit);
        exit;
    }
}

$workOrders = $conn->prepare('SELECT * FROM workorders WHERE customer_id = :customer_id ORDER BY updated_at DESC, created_at DESC LIMIT ' . (int)$workorderLimit);
$workOrders->execute([
    ':customer_id' => $customerId,
]);
$workOrders = $workOrders->fetchAll(PDO::FETCH_ASSOC);

$serviceRequests = $conn->prepare('SELECT r.*, w.id AS linked_workorder_id, w.status AS linked_workorder_status, w.order_number AS linked_workorder_number FROM customer_service_requests r LEFT JOIN workorders w ON w.id = r.approved_workorder_id WHERE r.customer_id = :customer_id ORDER BY r.created_at DESC LIMIT ' . (int)$requestLimit);
$serviceRequests->execute([
    ':customer_id' => $customerId,
]);
$serviceRequests = $serviceRequests->fetchAll(PDO::FETCH_ASSOC);

$title = 'Customer Dashboard';
require_once '../includes/header.php';

$totalJobs = count($workOrders);
$openJobs = 0;
$inProgressJobs = 0;
$completedJobs = 0;
foreach ($workOrders as $wo) {
    $status = strtolower(trim((string)($wo['status'] ?? 'Open')));
    if ($status === 'completed' || $status === 'closed') {
        $completedJobs++;
    } elseif ($status === 'in progress') {
        $inProgressJobs++;
    } else {
        $openJobs++;
    }
}
?>

<div style="max-width: 1200px; margin: 32px auto 48px; padding: 0 18px;">
    <h2>Customer Dashboard</h2>
    <p>Welcome back, <?php echo htmlspecialchars($customerName, ENT_QUOTES, 'UTF-8'); ?>.</p>

    <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 16px; margin: 20px 0 26px;">
        <div style="background:#f3f4f6; border:1px solid #d1d5db; border-radius:8px; padding:16px;">
            <div style="font-size:12px; text-transform:uppercase; color:#4b5563;">Total Work Orders</div>
            <div style="font-size:32px; font-weight:700; margin-top:6px; color:#111827;"><?php echo (int)$totalJobs; ?></div>
        </div>
        <div style="background:#fff7ed; border:1px solid #fed7aa; border-radius:8px; padding:16px;">
            <div style="font-size:12px; text-transform:uppercase; color:#9a5b00;">Open</div>
            <div style="font-size:32px; font-weight:700; margin-top:6px; color:#9a5b00;"><?php echo (int)$openJobs; ?></div>
        </div>
        <div style="background:#eff6ff; border:1px solid #bfdbfe; border-radius:8px; padding:16px;">
            <div style="font-size:12px; text-transform:uppercase; color:#1d4ed8;">In Progress</div>
            <div style="font-size:32px; font-weight:700; margin-top:6px; color:#1d4ed8;"><?php echo (int)$inProgressJobs; ?></div>
        </div>
        <div style="background:#ecfdf5; border:1px solid #a7f3d0; border-radius:8px; padding:16px;">
            <div style="font-size:12px; text-transform:uppercase; color:#166534;">Completed</div>
            <div style="font-size:32px; font-weight:700; margin-top:6px; color:#166534;"><?php echo (int)$completedJobs; ?></div>
        </div>
    </div>

    <div style="display:flex; gap:10px; flex-wrap:wrap; margin-bottom: 22px;">
        <a href="/sps/pages/customer_profile.php" style="display:inline-flex; align-items:center; justify-content:center; height:36px; padding:0 14px; background:linear-gradient(135deg, #2563eb, #1d4ed8); color:#fff; text-decoration:none; border-radius:8px; font-weight:700; font-size:13px; box-shadow:0 6px 18px rgba(37,99,235,0.18);">Profile</a>
        <a href="/sps/pages/customer_request_job.php" style="display:inline-flex; align-items:center; justify-content:center; height:36px; padding:0 14px; background:linear-gradient(135deg, #0f766e, #115e59); color:#fff; text-decoration:none; border-radius:8px; font-weight:700; font-size:13px; box-shadow:0 6px 18px rgba(15,118,110,0.18);">Request New Service</a>
        <a href="/sps/pages/customer_support.php" style="display:inline-flex; align-items:center; justify-content:center; height:36px; padding:0 14px; background:linear-gradient(135deg, #0f766e, #115e59); color:#fff; text-decoration:none; border-radius:8px; font-weight:700; font-size:13px; box-shadow:0 6px 18px rgba(15,118,110,0.18);">Live Support</a>
        <a href="/sps/logout.php" style="display:inline-flex; align-items:center; justify-content:center; height:36px; padding:0 14px; background:linear-gradient(135deg, #6b7280, #4b5563); color:#fff; text-decoration:none; border-radius:8px; font-weight:700; font-size:13px; box-shadow:0 6px 18px rgba(75,85,99,0.16);">Logout</a>
    </div>

    <div style="margin-bottom:18px; color:#475569; font-size:13px;">
        Status updates refresh automatically every 20 seconds.
    </div>

    <?php if (isset($_GET['request_submitted']) && $_GET['request_submitted'] == '1'): ?>
        <div style="background:#ecfdf5; color:#166534; border:1px solid #a7f3d0; border-radius:8px; padding:12px 16px; margin-bottom:18px; font-weight:700;">
            Your service request was submitted successfully. Our team will review it and accept it into a work order when approved.
        </div>
    <?php endif; ?>
    <?php if (isset($_GET['request_deleted']) && $_GET['request_deleted'] == '1'): ?>
        <div style="background:#fef2f2; color:#991b1b; border:1px solid #fecaca; border-radius:8px; padding:12px 16px; margin-bottom:18px; font-weight:700;">
            The request was removed successfully.
        </div>
    <?php endif; ?>
    <?php if (isset($_GET['halt_requested']) && $_GET['halt_requested'] == '1'): ?>
        <div style="background:#fff7ed; color:#92400e; border:1px solid #fdba74; border-radius:8px; padding:12px 16px; margin-bottom:18px; font-weight:700;">
            Your request to pause this job was sent. The work order has been set to On Hold and our team has been notified.
        </div>
    <?php endif; ?>

    <div style="background:#fff; border:1px solid #e5e7eb; border-radius:10px; box-shadow:0 1px 10px rgba(0,0,0,0.04); overflow:hidden; margin-bottom:24px;">
        <div style="background:#0f766e; color:#fff; padding:8px 12px; font-weight:700; display:flex; align-items:center; justify-content:space-between; gap:10px; flex-wrap:nowrap; min-height:42px;">
            <span style="font-size:14px; white-space:nowrap;">Service Requests</span>
            <div style="display:flex; align-items:center; gap:8px; flex-wrap:nowrap; margin-left:auto;">
                <label style="font-size:11px; font-weight:600; color:#ecfeff; display:flex; align-items:center; gap:5px; white-space:nowrap;">
                    Show
                    <select id="request-limit" onchange="window.location.href = updateQueryString(window.location.href, 'request_limit', this.value);" style="padding:2px 6px; border-radius:5px; border:1px solid rgba(255,255,255,0.35); background:#ffffff; color:#0f172a; font-weight:700; font-size:11px; min-width:66px; height:26px;">
                        <option value="10" <?php echo $requestLimit === 10 ? 'selected' : ''; ?>>10</option>
                        <option value="50" <?php echo $requestLimit === 50 ? 'selected' : ''; ?>>50</option>
                        <option value="100" <?php echo $requestLimit === 100 ? 'selected' : ''; ?>>100</option>
                    </select>
                </label>
                <button type="button" class="panel-toggle" data-panel="service-panel" data-label-hide="Hide" data-label-show="Show" style="background:rgba(255,255,255,0.14); border:1px solid rgba(255,255,255,0.35); color:#fff; border-radius:5px; padding:4px 9px; cursor:pointer; font-weight:700; font-size:11px; line-height:1; height:26px;">Hide</button>
            </div>
        </div>
        <div id="service-panel" style="display:block;">
            <?php if (empty($serviceRequests)): ?>
                <div style="padding:20px; color:#4b5563;">You have not submitted any service requests yet.</div>
            <?php else: ?>
                <form id="bulk-delete-form" method="post" action="/sps/pages/customer_dashboard.php?request_limit=<?php echo (int)$requestLimit; ?>&workorder_limit=<?php echo (int)$workorderLimit; ?>" onsubmit="return confirm('Delete the selected requests? This cannot be undone.');">
                    <input type="hidden" id="selected_ids" name="selected_ids" value="">
                    <div style="display:flex; align-items:center; justify-content:space-between; gap:10px; padding:8px 10px; background:#f8fafc; border-bottom:1px solid #e5e7eb;">
                        <label style="display:inline-flex; align-items:center; gap:6px; font-size:12px; font-weight:700; color:#334155; margin:0;">
                            <input type="checkbox" id="select-all-requests" style="accent-color:#0f766e; width:14px; height:14px;">
                            Select all
                        </label>
                        <button id="bulk-delete-button" type="submit" name="bulk_delete_requests" value="1" style="display:none; align-items:center; justify-content:center; width:58px; height:20px; padding:0 6px; background:linear-gradient(135deg, #f87171, #dc2626); color:#fff; border:none; border-radius:5px; cursor:pointer; font-weight:700; font-size:9px; line-height:1; letter-spacing:0.02em; box-shadow:0 2px 8px rgba(220,38,38,0.15);">Delete</button>
                    </div>
                    <table style="width:100%; border-collapse:collapse;">
                        <thead>
                            <tr style="background:#f8fafc;">
                                <th style="padding:6px 8px; text-align:left; border-bottom:1px solid #e5e7eb; font-size:12px; line-height:1.2; width:34px;">Sel</th>
                                <th style="padding:6px 8px; text-align:left; border-bottom:1px solid #e5e7eb; font-size:12px; line-height:1.2;">Request #</th>
                                <th style="padding:6px 8px; text-align:left; border-bottom:1px solid #e5e7eb; font-size:12px; line-height:1.2;">Type</th>
                                <th style="padding:6px 8px; text-align:left; border-bottom:1px solid #e5e7eb; font-size:12px; line-height:1.2;">Location</th>
                                <th style="padding:6px 8px; text-align:left; border-bottom:1px solid #e5e7eb; font-size:12px; line-height:1.2;">Preferred Date</th>
                                <th style="padding:6px 8px; text-align:left; border-bottom:1px solid #e5e7eb; font-size:12px; line-height:1.2;">Status</th>
                                <th style="padding:6px 8px; text-align:left; border-bottom:1px solid #e5e7eb; font-size:12px; line-height:1.2;">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($serviceRequests as $request): ?>
                                <?php
                                    $requestNumber = trim((string)($request['request_number'] ?? ''));
                                    if ($requestNumber === '') {
                                        $requestNumber = 'SR-' . str_pad((string)(int)$request['id'], 5, '0', STR_PAD_LEFT);
                                    }
                                    $requestRowBg = ($request['id'] % 2 === 0) ? '#f8fafc' : '#ffffff';
                                ?>
                                <tr style="border-bottom:1px solid #e5e7eb; background:<?php echo $requestRowBg; ?>;">
                                    <td style="padding:4px 8px; font-size:12px; line-height:1.2;">
                                        <?php if (empty($request['linked_workorder_id']) && strtolower((string)($request['status'] ?? 'Pending')) !== 'accepted'): ?>
                                            <input type="checkbox" name="selected_requests[]" value="<?php echo (int)$request['id']; ?>" class="request-select-checkbox" style="accent-color:#0f766e; width:14px; height:14px;">
                                        <?php else: ?>
                                            <span style="color:#94a3b8; font-size:11px;">—</span>
                                        <?php endif; ?>
                                    </td>
                                    <td style="padding:4px 8px; font-weight:700; color:#0f172a; font-size:12px; line-height:1.2;"><a href="/sps/pages/view_service_request.php?id=<?php echo (int)$request['id']; ?>" style="color:#007BFF; text-decoration:none; font-weight:700;"><?php echo htmlspecialchars($requestNumber, ENT_QUOTES, 'UTF-8'); ?></a></td>
                                    <td style="padding:4px 8px; font-size:12px; line-height:1.2; "><?php echo htmlspecialchars($request['service_type'] ?? 'Service', ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td style="padding:4px 8px; font-size:12px; line-height:1.2; "><?php echo htmlspecialchars($request['location'] ?? '', ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td style="padding:4px 8px; font-size:12px; line-height:1.2; "><?php echo htmlspecialchars($request['preferred_date'] ?? 'Not set', ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td style="padding:4px 8px; font-size:12px; line-height:1.2;">
                                        <?php
                                        $requestStatus = strtolower(trim((string)($request['status'] ?? 'Pending')));
                                        $requestColor = '#1d4ed8';
                                        if ($requestStatus === 'accepted') { $requestColor = '#166534'; }
                                        elseif ($requestStatus === 'rejected') { $requestColor = '#991b1b'; }
                                        elseif ($requestStatus === 'pending') { $requestColor = '#7c3aed'; }
                                        ?>
                                        <span style="display:inline-block; padding:4px 8px; border-radius:999px; background:rgba(59,130,246,0.12); color:<?php echo $requestColor; ?>; font-weight:700;">
                                            <?php echo htmlspecialchars($request['status'] ?? 'Pending', ENT_QUOTES, 'UTF-8'); ?>
                                        </span>
                                        <?php if (!empty($request['linked_workorder_id'])): ?>
                                            <div style="margin-top:6px;">
                                                <a href="/sps/pages/view_workorder.php?id=<?php echo (int)$request['linked_workorder_id']; ?>" style="font-size:12px; color:#007BFF; text-decoration:none; font-weight:700;">View work order #<?php echo htmlspecialchars(!empty($request['linked_workorder_number']) ? $request['linked_workorder_number'] : 'WO' . str_pad((string)(int)$request['linked_workorder_id'], 4, '0', STR_PAD_LEFT), ENT_QUOTES, 'UTF-8'); ?></a>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                    <td style="padding:4px 8px; font-size:12px; line-height:1; vertical-align:middle;">
                                        <a href="/sps/pages/view_service_request.php?id=<?php echo (int)$request['id']; ?>" style="display:inline-flex; align-items:center; justify-content:center; height:24px; padding:0 8px; background:#e0f2fe; color:#0f172a; text-decoration:none; border-radius:7px; font-weight:700; font-size:11px; border:1px solid #bae6fd; vertical-align:middle; margin-right:6px;">View</a>
                                        <?php if (empty($request['linked_workorder_id']) && strtolower((string)($request['status'] ?? 'Pending')) !== 'accepted'): ?>
                                            <form method="post" action="/sps/pages/customer_dashboard.php?request_limit=<?php echo (int)$requestLimit; ?>&workorder_limit=<?php echo (int)$workorderLimit; ?>" onsubmit="return confirm('Delete this request? This cannot be undone.');" style="display:inline-block; margin:0; line-height:1; vertical-align:middle;">
                                                <input type="hidden" name="delete_request" value="<?php echo (int)$request['id']; ?>">
                                                <button type="submit" title="Delete request" aria-label="Delete request" style="display:inline-flex; align-items:center; justify-content:center; width:24px; height:24px; background:linear-gradient(135deg, #f87171, #dc2626); color:#fff; border:1px solid rgba(255,255,255,0.6); border-radius:7px; padding:0; cursor:pointer; font-size:12px; line-height:1; box-shadow:0 3px 10px rgba(239,68,68,0.18); font-weight:700; margin:0; vertical-align:middle;">🗑</button>
                                            </form>
                                        <?php else: ?>
                                            <span style="color:#6b7280; font-size:12px; display:inline-block; line-height:1; vertical-align:middle;">Locked</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </form>
            <?php endif; ?>
        </div>
    </div>

    <div style="background:#fff; border:1px solid #e5e7eb; border-radius:10px; box-shadow:0 1px 10px rgba(0,0,0,0.04); overflow:hidden;">
        <div style="background:#007BFF; color:#fff; padding:8px 12px; font-weight:700; display:flex; align-items:center; justify-content:space-between; gap:10px; flex-wrap:nowrap; min-height:42px;">
            <div style="display:flex; align-items:center; gap:8px; white-space:nowrap;">
                <span style="font-size:14px;">My Work Orders</span>
                <button type="button" id="status-guide-toggle" aria-label="Open status guide" title="Click to view status meanings" onclick="var panel=document.getElementById('status-guide-panel'); if(panel){ panel.style.display = panel.style.display === 'none' ? 'block' : 'none'; }" style="display:inline-flex; align-items:center; justify-content:center; gap:6px; height:26px; padding:0 10px; border:1px solid rgba(255,255,255,0.75); border-radius:999px; background:#eff6ff; color:#1d4ed8; cursor:pointer; font-size:11px; line-height:1; font-weight:700; box-shadow:0 2px 6px rgba(30,64,175,0.15);">ⓘ Status Guide</button>
            </div>
            <div style="display:flex; align-items:center; gap:8px; flex-wrap:nowrap; margin-left:auto;">
                <label style="font-size:11px; font-weight:600; color:#eff6ff; display:flex; align-items:center; gap:5px; white-space:nowrap;">
                    Show
                    <select id="workorder-limit" onchange="window.location.href = updateQueryString(window.location.href, 'workorder_limit', this.value);" style="padding:2px 6px; border-radius:5px; border:1px solid rgba(255,255,255,0.35); background:#ffffff; color:#0f172a; font-weight:700; font-size:11px; min-width:66px; height:26px;">
                        <option value="10" <?php echo $workorderLimit === 10 ? 'selected' : ''; ?>>10</option>
                        <option value="50" <?php echo $workorderLimit === 50 ? 'selected' : ''; ?>>50</option>
                        <option value="100" <?php echo $workorderLimit === 100 ? 'selected' : ''; ?>>100</option>
                    </select>
                </label>
                <button type="button" class="panel-toggle" data-panel="workorder-panel" data-label-hide="Hide" data-label-show="Show" style="background:rgba(255,255,255,0.14); border:1px solid rgba(255,255,255,0.35); color:#fff; border-radius:5px; padding:4px 9px; cursor:pointer; font-weight:700; font-size:11px; line-height:1; height:26px;">Hide</button>
            </div>
        </div>
        <div id="workorder-panel" style="display:block;">
            <div id="status-guide-panel" style="display:none; padding:12px 16px; border-bottom:1px solid #e5e7eb; background:#f8fafc;">
                <div style="font-size:12px; font-weight:700; color:#0f172a; margin-bottom:8px;">Status guide</div>
                <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap:8px;">
                    <div style="padding:8px 10px; border:1px solid #dbeafe; border-radius:8px; background:#eff6ff; color:#1d4ed8; font-size:12px; line-height:1.4;"><strong>Open:</strong> Your request has been received and is waiting for review.</div>
                    <div style="padding:8px 10px; border:1px solid #dbeafe; border-radius:8px; background:#eff6ff; color:#1d4ed8; font-size:12px; line-height:1.4;"><strong>In Progress:</strong> A technician is actively working on your job.</div>
                    <div style="padding:8px 10px; border:1px solid #fef3c7; border-radius:8px; background:#fffbeb; color:#92400e; font-size:12px; line-height:1.4;"><strong>Waiting for Parts:</strong> The job is paused until required materials arrive.</div>
                    <div style="padding:8px 10px; border:1px solid #e9d5ff; border-radius:8px; background:#faf5ff; color:#6b21a8; font-size:12px; line-height:1.4;"><strong>On Hold:</strong> Work is paused because of scheduling, access, or a customer decision.</div>
                    <div style="padding:8px 10px; border:1px solid #dcfce7; border-radius:8px; background:#f0fdf4; color:#166534; font-size:12px; line-height:1.4;"><strong>Completed:</strong> The service has been finished and is ready for review.</div>
                    <div style="padding:8px 10px; border:1px solid #e5e7eb; border-radius:8px; background:#f3f4f6; color:#374151; font-size:12px; line-height:1.4;"><strong>Closed:</strong> The job has been fully closed and archived.</div>
                </div>
            </div>
            <?php if (empty($workOrders)): ?>
                <div style="padding:20px; color:#4b5563;">You do not have any work orders assigned yet.</div>
            <?php else: ?>
                <div style="overflow-x:auto;">
                <table style="width:100%; border-collapse:collapse;">
                    <thead>
                        <tr style="background:#f8fafc;">
                            <th style="padding:6px 8px; text-align:left; border-bottom:1px solid #e5e7eb; font-size:12px; line-height:1.2;">Work Order</th>
                            <th style="padding:6px 8px; text-align:left; border-bottom:1px solid #e5e7eb; font-size:12px; line-height:1.2;">Issue</th>
                            <th style="padding:6px 8px; text-align:left; border-bottom:1px solid #e5e7eb; font-size:12px; line-height:1.2;">Status</th>
                            <th style="padding:6px 8px; text-align:left; border-bottom:1px solid #e5e7eb; font-size:12px; line-height:1.2;">Date</th>
                            <th style="padding:6px 8px; text-align:left; border-bottom:1px solid #e5e7eb; font-size:12px; line-height:1.2;">Location</th>
                            <th style="padding:6px 8px; text-align:left; border-bottom:1px solid #e5e7eb; font-size:12px; line-height:1.2; min-width:150px;">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($workOrders as $wo): ?>
                            <?php
                                $woIssue = trim((string)($wo['requested_work'] ?? $wo['work_description'] ?? ''));
                                if ($woIssue === '') {
                                    $woIssue = 'No issue details provided';
                                }
                            ?>
                            <?php $workOrderRowBg = ((int)$wo['id'] % 2 === 0) ? '#f8fafc' : '#ffffff'; ?>
                            <tr style="border-bottom:1px solid #e5e7eb; background:<?php echo $workOrderRowBg; ?>;">
                                <td style="padding:4px 8px; font-size:12px; line-height:1.2;">#<?php echo htmlspecialchars(!empty($wo['order_number']) ? $wo['order_number'] : 'WO' . str_pad((string)(int)$wo['id'], 4, '0', STR_PAD_LEFT), ENT_QUOTES, 'UTF-8'); ?></td>
                                <td style="padding:4px 8px; max-width:220px; color:#374151; font-size:12px; line-height:1.2; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;" title="<?php echo htmlspecialchars($woIssue, ENT_QUOTES, 'UTF-8'); ?>">
                                    <?php echo htmlspecialchars($woIssue, ENT_QUOTES, 'UTF-8'); ?>
                                </td>
                                <td style="padding:4px 8px; font-size:12px; line-height:1.2;">
                                    <?php
                                    $woStatus = strtolower(trim((string)($wo['status'] ?? 'Open')));
                                    $statusColor = '#2563eb';
                                    if ($woStatus === 'completed' || $woStatus === 'closed') { $statusColor = '#198754'; }
                                    elseif ($woStatus === 'in progress') { $statusColor = '#0ea5e9'; }
                                    elseif ($woStatus === 'waiting for parts') { $statusColor = '#d97706'; }
                                    elseif ($woStatus === 'on hold') { $statusColor = '#7c3aed'; }
                                    ?>
                                    <span style="display:inline-block; padding:4px 8px; border-radius:999px; background:rgba(37,99,235,0.1); color:<?php echo $statusColor; ?>; font-weight:700;">
                                        <?php echo htmlspecialchars($wo['status'] ?? 'Open', ENT_QUOTES, 'UTF-8'); ?>
                                    </span>
                                </td>
                                <td style="padding:4px 8px; font-size:12px; line-height:1.2;"><?php echo htmlspecialchars($wo['order_date'] ?? '', ENT_QUOTES, 'UTF-8'); ?></td>
                                <td style="padding:4px 8px; font-size:12px; line-height:1.2;"><?php echo htmlspecialchars($wo['location'] ?? '', ENT_QUOTES, 'UTF-8'); ?></td>
                                <td style="padding:4px 8px; font-size:12px; line-height:1.2; white-space:nowrap;">
                                    <a href="/sps/pages/view_workorder.php?id=<?php echo (int)$wo['id']; ?>" style="display:inline-flex; align-items:center; justify-content:center; height:24px; padding:0 8px; background:#e0f2fe; color:#0f172a; text-decoration:none; border-radius:7px; font-weight:700; font-size:11px; border:1px solid #bae6fd; margin-right:4px;">View</a>
                                    <?php if (!in_array($woStatus, ['on hold', 'completed', 'closed'], true)): ?>
                                        <form method="post" action="/sps/pages/customer_dashboard.php?request_limit=<?php echo (int)$requestLimit; ?>&workorder_limit=<?php echo (int)$workorderLimit; ?>" onsubmit="return confirm('Request to pause this job? Our team will be notified.');" style="display:inline-block; margin:0;">
                                            <input type="hidden" name="request_halt" value="<?php echo (int)$wo['id']; ?>">
                                            <button type="submit" title="Request to pause this job" aria-label="Request to pause this job" style="display:inline-flex; align-items:center; justify-content:center; width:24px; height:24px; padding:0; background:#fff7ed; color:#92400e; border:1px solid #fdba74; border-radius:7px; font-size:12px; cursor:pointer;">⏸</button>
                                        </form>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
    function updateQueryString(url, key, value) {
        var searchParams = new URLSearchParams(window.location.search);
        searchParams.set(key, value);
        return window.location.origin + window.location.pathname + '?' + searchParams.toString();
    }

var statusGuideToggle = document.getElementById('status-guide-toggle');
        var statusGuidePanel = document.getElementById('status-guide-panel');

        if (statusGuideToggle && statusGuidePanel) {
            statusGuideToggle.addEventListener('click', function () {
                var isHidden = statusGuidePanel.style.display === 'none';
                statusGuidePanel.style.display = isHidden ? 'block' : 'none';
            });
        }

        var selectAll = document.getElementById('select-all-requests');
        var bulkDeleteButton = document.getElementById('bulk-delete-button');
        var bulkDeleteForm = document.getElementById('bulk-delete-form');
        var selectedIdsInput = document.getElementById('selected_ids');

        function updateBulkDeleteButton() {
            var checkboxes = document.querySelectorAll('.request-select-checkbox');
            var selectedIds = [];

            checkboxes.forEach(function (checkbox) {
                if (checkbox.checked) {
                    selectedIds.push(checkbox.value);
                }
            });

            if (selectedIdsInput) {
                selectedIdsInput.value = selectedIds.join(',');
            }

            if (bulkDeleteButton) {
                bulkDeleteButton.style.display = selectedIds.length ? 'inline-flex' : 'none';
            }
        }

        if (bulkDeleteForm) {
            bulkDeleteForm.addEventListener('submit', function (event) {
                var selectedIds = [];
                document.querySelectorAll('.request-select-checkbox').forEach(function (checkbox) {
                    if (checkbox.checked) {
                        selectedIds.push(checkbox.value);
                    }
                });

                if (!selectedIds.length) {
                    event.preventDefault();
                    return false;
                }

                if (selectedIdsInput) {
                    selectedIdsInput.value = selectedIds.join(',');
                }
            });
        }

        if (selectAll) {
            selectAll.addEventListener('change', function () {
                var checkboxes = document.querySelectorAll('.request-select-checkbox');
                checkboxes.forEach(function (checkbox) {
                    checkbox.checked = selectAll.checked;
                });
                updateBulkDeleteButton();
            });
        }

        document.querySelectorAll('.request-select-checkbox').forEach(function (checkbox) {
            checkbox.addEventListener('change', updateBulkDeleteButton);
        });

        updateBulkDeleteButton();

        document.querySelectorAll('.panel-toggle').forEach(function (button) {
            button.addEventListener('click', function () {
                var panelId = this.getAttribute('data-panel');
                var panel = document.getElementById(panelId);
                if (!panel) {
                    return;
                }

                var isHidden = panel.style.display === 'none';
                panel.style.display = isHidden ? 'block' : 'none';
                this.textContent = isHidden ? 'Hide' : 'Show';
            });
        });
    });

    setTimeout(function () {
        window.location.reload();
    }, 20000);
</script>

<?php require_once '../includes/footer.php'; ?>
