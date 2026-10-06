<?php
session_start();
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true || strtolower($_SESSION['role'] ?? '') !== 'customer') {
    header('Location: /sps/customer_login.php');
    exit;
}

require_once '../includes/dbh.inc.php';
require_once '../includes/deleted_records.inc.php';
require_once '../includes/notifications.php';
require_once '../includes/customer_update_state.inc.php';
require_once '../includes/property_entry_logs.inc.php';
ensure_customer_update_state_schema($conn);
ensure_property_entry_log_schema($conn);

$conn->exec("CREATE TABLE IF NOT EXISTS customer_hidden_workorders (
    customer_id INT NOT NULL,
    workorder_id INT NOT NULL,
    hidden_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (customer_id, workorder_id),
    INDEX (workorder_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
$conn->exec("CREATE TABLE IF NOT EXISTS customer_hidden_service_requests (
    customer_id INT NOT NULL,
    service_request_id INT NOT NULL,
    hidden_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (customer_id, service_request_id),
    INDEX (service_request_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$customerId = (int)($_SESSION['customer_id'] ?? 0);
$customerName = $_SESSION['customer_name'] ?? 'Customer';
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

function customer_can_hide_request_from_profile(PDO $conn, int $requestId, int $customerId): bool
{
    $stmt = $conn->prepare('SELECT r.status, r.created_at, w.id AS linked_workorder_id FROM customer_service_requests r LEFT JOIN workorders w ON w.id = r.approved_workorder_id WHERE r.id = ? AND r.customer_id = ? LIMIT 1');
    $stmt->execute([$requestId, $customerId]);
    $request = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$request) {
        return false;
    }

    if (empty($request['linked_workorder_id'])) {
        return true;
    }

    $status = strtolower(trim((string)($request['status'] ?? '')));
    $createdAt = strtotime((string)($request['created_at'] ?? ''));
    return in_array($status, ['accepted', 'closed'], true)
        && $createdAt !== false
        && $createdAt <= strtotime('-90 days');
}

function customer_hide_workorder_from_profile(PDO $conn, int $workorderId, int $customerId): bool
{
    $stmt = $conn->prepare('SELECT status FROM workorders WHERE id = ? AND customer_id = ? LIMIT 1');
    $stmt->execute([$workorderId, $customerId]);
    $workorder = $stmt->fetch(PDO::FETCH_ASSOC);
    $status = strtolower(trim((string)($workorder['status'] ?? '')));
    if (!$workorder || !in_array($status, ['completed', 'closed'], true)) {
        return false;
    }

    $conn->prepare('INSERT IGNORE INTO customer_hidden_workorders (customer_id, workorder_id) VALUES (?, ?)')->execute([$customerId, $workorderId]);
    return true;
}

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
    $conn->exec("ALTER TABLE customer_service_requests ADD COLUMN IF NOT EXISTS preferred_end_date DATE DEFAULT NULL");
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
    if (isset($_POST['bulk_hide_workorders'])) {
        if (!hash_equals($_SESSION['csrf_token'], (string)($_POST['csrf_token'] ?? ''))) {
            header('Location: /sps/pages/customer_dashboard.php?workorder_hide_error=1&request_limit=' . $requestLimit . '&workorder_limit=' . $workorderLimit);
            exit;
        }
        $selectedWorkorderIds = array_values(array_unique(array_filter(array_map('intval', (array)($_POST['selected_workorders'] ?? [])))));
        $hiddenCount = 0;
        foreach ($selectedWorkorderIds as $selectedWorkorderId) {
            if (customer_hide_workorder_from_profile($conn, $selectedWorkorderId, $customerId)) {
                $hiddenCount++;
            }
        }
        if ($hiddenCount > 0) {
            header('Location: /sps/pages/customer_dashboard.php?workorders_hidden=' . $hiddenCount . '&request_limit=' . $requestLimit . '&workorder_limit=' . $workorderLimit);
        } else {
            header('Location: /sps/pages/customer_dashboard.php?workorder_hide_error=1&request_limit=' . $requestLimit . '&workorder_limit=' . $workorderLimit);
        }
        exit;
    }

    if (isset($_POST['hide_workorder'])) {
        $hideId = (int)$_POST['hide_workorder'];
        if (!hash_equals($_SESSION['csrf_token'], (string)($_POST['csrf_token'] ?? ''))) {
            header('Location: /sps/pages/customer_dashboard.php?workorder_hide_error=1&request_limit=' . $requestLimit . '&workorder_limit=' . $workorderLimit);
            exit;
        }
        if (customer_hide_workorder_from_profile($conn, $hideId, $customerId)) {
            header('Location: /sps/pages/customer_dashboard.php?workorder_hidden=1&request_limit=' . $requestLimit . '&workorder_limit=' . $workorderLimit);
        } else {
            header('Location: /sps/pages/customer_dashboard.php?workorder_hide_error=1&request_limit=' . $requestLimit . '&workorder_limit=' . $workorderLimit);
        }
        exit;
    }

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
                        ['admin', 'office', 'staff'],
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
        if (hash_equals($_SESSION['csrf_token'], (string)($_POST['csrf_token'] ?? '')) && $deleteId > 0 && customer_can_hide_request_from_profile($conn, $deleteId, $customerId)) {
            $conn->prepare('INSERT IGNORE INTO customer_hidden_service_requests (customer_id, service_request_id) VALUES (?, ?)')->execute([$customerId, $deleteId]);
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

        if (!empty($selectedIds) && hash_equals($_SESSION['csrf_token'], (string)($_POST['csrf_token'] ?? ''))) {
            foreach ($selectedIds as $selectedId) {
                if (customer_can_hide_request_from_profile($conn, $selectedId, $customerId)) {
                    $conn->prepare('INSERT IGNORE INTO customer_hidden_service_requests (customer_id, service_request_id) VALUES (?, ?)')->execute([$customerId, $selectedId]);
                }
            }
        }

        header('Location: /sps/pages/customer_dashboard.php?request_deleted=1&request_limit=' . $requestLimit . '&workorder_limit=' . $workorderLimit);
        exit;
    }
}

$workOrders = $conn->prepare('SELECT w.* FROM workorders w WHERE w.customer_id = :customer_id AND NOT EXISTS (SELECT 1 FROM customer_hidden_workorders h WHERE h.customer_id = :hidden_customer_id AND h.workorder_id = w.id) ORDER BY w.updated_at DESC, w.created_at DESC LIMIT ' . (int)$workorderLimit);
$workOrders->execute([
    ':customer_id' => $customerId,
    ':hidden_customer_id' => $customerId,
]);
$workOrders = $workOrders->fetchAll(PDO::FETCH_ASSOC);

$serviceRequests = $conn->prepare('SELECT r.*, w.id AS linked_workorder_id, w.status AS linked_workorder_status, w.order_number AS linked_workorder_number FROM customer_service_requests r LEFT JOIN workorders w ON w.id = r.approved_workorder_id WHERE r.customer_id = :customer_id ORDER BY r.created_at DESC LIMIT ' . (int)$requestLimit);
$serviceRequests = $conn->prepare('SELECT r.*, w.id AS linked_workorder_id, w.status AS linked_workorder_status, w.order_number AS linked_workorder_number FROM customer_service_requests r LEFT JOIN workorders w ON w.id = r.approved_workorder_id WHERE r.customer_id = :customer_id AND NOT EXISTS (SELECT 1 FROM customer_hidden_service_requests h WHERE h.customer_id = :hidden_customer_id AND h.service_request_id = r.id) ORDER BY r.created_at DESC LIMIT ' . (int)$requestLimit);
$serviceRequests->execute([
    ':customer_id' => $customerId,
    ':hidden_customer_id' => $customerId,
]);
$serviceRequests = $serviceRequests->fetchAll(PDO::FETCH_ASSOC);

$unreadWorkOrderUpdates = [];
foreach ($workOrders as $workOrder) {
    $workOrderId = (int)$workOrder['id'];
    $latestActivity = customer_workorder_latest_activity($conn, $workOrderId, (string)($workOrder['created_at'] ?? ''));
    $lastSeen = customer_update_seen_at($conn, $customerId, 'workorder', $workOrderId);
    $baseline = $lastSeen ?? (string)($workOrder['created_at'] ?? '');
    if ($latestActivity !== '' && $baseline !== '' && $latestActivity > $baseline) {
        $unreadWorkOrderUpdates[$workOrderId] = true;
    }
}

$unreadServiceRequestUpdates = [];
foreach ($serviceRequests as $serviceRequest) {
    $serviceRequestId = (int)$serviceRequest['id'];
    $latestActivity = (string)($serviceRequest['updated_at'] ?? $serviceRequest['created_at'] ?? '');
    $lastSeen = customer_update_seen_at($conn, $customerId, 'service_request', $serviceRequestId);
    $baseline = $lastSeen ?? (string)($serviceRequest['created_at'] ?? '');
    if ($latestActivity !== '' && $baseline !== '' && $latestActivity > $baseline) {
        $unreadServiceRequestUpdates[$serviceRequestId] = true;
    }
}

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

<style>
    .customer-workorders-table { width:100%; table-layout:fixed; border-collapse:collapse; }
    .customer-workorders-table th:nth-child(1),
    .customer-workorders-table td:nth-child(1) { width:13%; }
    .customer-workorders-table th:nth-child(2),
    .customer-workorders-table td:nth-child(2) { width:34%; }
    .customer-workorders-table th:nth-child(3),
    .customer-workorders-table td:nth-child(3) { width:11%; }
    .customer-workorders-table th:nth-child(4),
    .customer-workorders-table td:nth-child(4) { width:12%; }
    .customer-workorders-table th:nth-child(5),
    .customer-workorders-table td:nth-child(5) { width:16%; }
    #service-panel,
    #bulk-delete-form { width:100%; min-width:0; }
    #service-panel > form { display:block; width:100%; }
    #service-panel .customer-requests-table { width:100% !important; table-layout:fixed; border-collapse:collapse; }
    .customer-requests-table th,
    .customer-requests-table td { overflow:hidden; text-overflow:ellipsis; white-space:nowrap; vertical-align:middle; }
    .customer-requests-table th:first-child,
    .customer-requests-table td:first-child { padding-left:18px !important; }
    .customer-requests-table .request-actions { white-space:nowrap; }
    .customer-requests-table .request-actions form { display:inline-block; margin:0; vertical-align:middle; }
    .customer-workorders-table th,
    .customer-workorders-table td { min-width:0; overflow-wrap:anywhere; word-break:break-word; }
    .customer-workorders-table .workorder-actions { width:92px; white-space:nowrap !important; }
    .customer-workorders-table .workorder-actions form { display:inline-block !important; margin:0; vertical-align:middle; }
    .customer-workorders-table .workorder-actions a,
    .customer-workorders-table .workorder-actions button { vertical-align:middle; }
    .customer-workorders-table th:first-child,
    .customer-workorders-table td:first-child { padding-left:18px !important; }
    .customer-update-row { background:#eff6ff !important; box-shadow:inset 3px 0 #2563eb; }
    @media (max-width:640px) {
        .customer-workorders-table th:nth-child(1),
        .customer-workorders-table td:nth-child(1) { width:15%; }
        .customer-workorders-table th:nth-child(2),
        .customer-workorders-table td:nth-child(2) { width:31%; }
        .customer-workorders-table th:nth-child(3),
        .customer-workorders-table td:nth-child(3) { width:10%; }
        .customer-workorders-table th,
        .customer-workorders-table td { padding:5px 4px !important; font-size:11px !important; }
        .customer-workorders-table .workorder-actions { width:78px; white-space:nowrap !important; }
        .customer-workorders-table .workorder-actions a { padding:0 5px !important; font-size:10px !important; }
        .customer-workorders-table .workorder-actions button { width:22px !important; height:22px !important; }
        .customer-workorders-table .workorder-actions button.profile-hide-button { width:40px !important; min-width:40px !important; height:22px !important; padding:0 3px !important; font-size:9px !important; }
        .customer-requests-table th,
        .customer-requests-table td { padding:5px 4px !important; font-size:10px !important; line-height:1.2 !important; }
        .customer-requests-table th:first-child,
        .customer-requests-table td:first-child { padding-left:8px !important; }
        .customer-requests-table .request-actions a { height:22px !important; padding:0 5px !important; font-size:10px !important; }
        .customer-requests-table .request-actions button { width:22px !important; height:22px !important; }
    }
</style>

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
        Dashboard updates are checked automatically every few seconds.
    </div>

    <?php if (isset($_GET['request_submitted']) && $_GET['request_submitted'] == '1'): ?>
        <div style="background:#ecfdf5; color:#166534; border:1px solid #a7f3d0; border-radius:8px; padding:12px 16px; margin-bottom:18px; font-weight:700;">
            Your service request was submitted successfully. Our team will review it and accept it into a work order when approved.
        </div>
    <?php endif; ?>
    <?php if (isset($_GET['request_deleted']) && $_GET['request_deleted'] == '1'): ?>
        <div style="background:#fef2f2; color:#991b1b; border:1px solid #fecaca; border-radius:8px; padding:12px 16px; margin-bottom:18px; font-weight:700;">
            The request was removed from your profile. It remains available to our team.
        </div>
    <?php endif; ?>
    <?php if (isset($_GET['workorders_hidden'])): ?>
        <div style="background:#eff6ff; color:#1e40af; border:1px solid #bfdbfe; border-radius:8px; padding:12px 16px; margin-bottom:18px; font-weight:700;">Removed <?php echo (int)$_GET['workorders_hidden']; ?> closed work order(s) from your profile. They remain in our system.</div>
    <?php endif; ?>
    <?php if (isset($_GET['workorder_hidden']) && $_GET['workorder_hidden'] == '1'): ?>
        <div style="background:#eff6ff; color:#1e40af; border:1px solid #bfdbfe; border-radius:8px; padding:12px 16px; margin-bottom:18px; font-weight:700;">The closed work order was removed from your profile. It remains available to our team.</div>
    <?php elseif (isset($_GET['workorder_hide_error']) && $_GET['workorder_hide_error'] == '1'): ?>
        <div style="background:#fef2f2; color:#991b1b; border:1px solid #fecaca; border-radius:8px; padding:12px 16px; margin-bottom:18px; font-weight:700;">Only a work order belonging to your account that is Completed or Closed can be hidden from your profile.</div>
    <?php endif; ?>
    <?php if (isset($_GET['halt_requested']) && $_GET['halt_requested'] == '1'): ?>
        <div style="background:#fff7ed; color:#92400e; border:1px solid #fdba74; border-radius:8px; padding:12px 16px; margin-bottom:18px; font-weight:700;">
            Your request to pause this job was sent. The work order has been set to On Hold and our team has been notified.
        </div>
    <?php endif; ?>

    <div class="customer-dashboard-card" style="background:#fff; border:1px solid #e5e7eb; border-radius:10px; box-shadow:0 1px 10px rgba(0,0,0,0.04); overflow:hidden; margin-bottom:24px;">
    <div class="customer-dashboard-card-header" style="background:#0f766e; color:#fff; padding:8px 12px; font-weight:700; display:flex; align-items:center; justify-content:space-between; gap:10px; flex-wrap:nowrap; min-height:42px;">
            <span style="font-size:14px; white-space:nowrap;">Service Requests</span>
            <div class="customer-dashboard-card-tools" style="display:flex; align-items:center; gap:8px; flex-wrap:nowrap; margin-left:auto;">
                <label style="font-size:11px; font-weight:600; color:#ecfeff; display:flex; align-items:center; gap:5px; white-space:nowrap;">
                    Show
                    <select id="request-limit" onchange="window.location.href = updateQueryString(window.location.href, 'request_limit', this.value);" style="padding:2px 6px; border-radius:5px; border:1px solid rgba(255,255,255,0.35); background:#ffffff; color:#0f172a; font-weight:700; font-size:11px; min-width:66px; height:26px;">
                        <option value="10" <?php echo $requestLimit === 10 ? 'selected' : ''; ?>>10</option>
                        <option value="50" <?php echo $requestLimit === 50 ? 'selected' : ''; ?>>50</option>
                        <option value="100" <?php echo $requestLimit === 100 ? 'selected' : ''; ?>>100</option>
                    </select>
                </label>
                <button type="button" class="panel-toggle" data-panel="service-panel" data-label-hide="Hide" data-label-show="Show" aria-expanded="true" style="background:rgba(255,255,255,0.14); border:1px solid rgba(255,255,255,0.35); color:#fff; border-radius:5px; padding:4px 9px; cursor:pointer; font-weight:700; font-size:11px; line-height:1; height:26px;">Hide</button>
            </div>
        </div>
        <div id="service-panel">
            <?php if (empty($serviceRequests)): ?>
                <div style="padding:20px; color:#4b5563;">You have not submitted any service requests yet.</div>
            <?php else: ?>
                <form id="bulk-delete-form" method="post" action="/sps/pages/customer_dashboard.php?request_limit=<?php echo (int)$requestLimit; ?>&workorder_limit=<?php echo (int)$workorderLimit; ?>" onsubmit="return confirm('Hide the selected service requests from your profile? Our team can still access them.');">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8'); ?>">
                    <input type="hidden" id="selected_ids" name="selected_ids" value="">
                    <div style="display:flex; align-items:center; justify-content:space-between; gap:10px; padding:8px 10px; background:#f8fafc; border-bottom:1px solid #e5e7eb;">
                        <label style="display:inline-flex; align-items:center; gap:6px; font-size:12px; font-weight:700; color:#334155; margin:0;">
                            <input type="checkbox" id="select-all-requests" style="accent-color:#0f766e; width:14px; height:14px;">
                            Select all
                        </label>
                        <button id="bulk-delete-button" type="submit" name="bulk_delete_requests" value="1" title="Hide selected requests from your profile" aria-label="Hide selected requests from your profile" style="display:none; align-items:center; justify-content:center; width:46px; min-width:46px; height:24px; padding:0 5px; background:#475569; color:#fff; border:none; border-radius:5px; cursor:pointer; font-weight:700; font-size:10px;">Hide</button>
                    </div>
                </form>
                    <table class="customer-requests-table">
                        <colgroup>
                            <col style="width:7%;">
                            <col style="width:9%;">
                            <col style="width:9%;">
                            <col style="width:31%;">
                            <col style="width:12%;">
                            <col style="width:16%;">
                            <col style="width:16%;">
                        </colgroup>
                        <thead>
                            <tr style="background:#f8fafc;">
                                <th style="padding:6px 8px; text-align:left; border-bottom:1px solid #e5e7eb; font-size:12px; line-height:1.2; width:34px;">Sel</th>
                                <th style="padding:6px 8px; text-align:left; border-bottom:1px solid #e5e7eb; font-size:12px; line-height:1.2;">Request #</th>
                                <th style="padding:6px 8px; text-align:left; border-bottom:1px solid #e5e7eb; font-size:12px; line-height:1.2;">Type</th>
                                <th style="padding:6px 8px; text-align:left; border-bottom:1px solid #e5e7eb; font-size:12px; line-height:1.2;">Location</th>
                                <th style="padding:6px 8px; text-align:left; border-bottom:1px solid #e5e7eb; font-size:12px; line-height:1.2;">Dates</th>
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
                                    $requestHasUnreadUpdate = !empty($unreadServiceRequestUpdates[(int)$request['id']]);
                                    $requestStatus = strtolower(trim((string)($request['status'] ?? '')));
                                    $requestCreatedAt = strtotime((string)($request['created_at'] ?? ''));
                                    $requestIsOld = $requestCreatedAt !== false && $requestCreatedAt <= strtotime('-90 days');
                                    $canHideRequest = empty($request['linked_workorder_id'])
                                        || (in_array($requestStatus, ['accepted', 'closed'], true) && $requestIsOld);
                                ?>
                                <tr class="<?php echo $requestHasUnreadUpdate ? 'customer-update-row' : ''; ?>" style="border-bottom:1px solid #e5e7eb; background:<?php echo $requestHasUnreadUpdate ? '#eff6ff' : $requestRowBg; ?>;">
                                    <td style="padding:4px 8px; font-size:12px; line-height:1.2;">
                                        <?php if ($canHideRequest): ?>
                                            <input type="checkbox" form="bulk-delete-form" name="selected_requests[]" value="<?php echo (int)$request['id']; ?>" class="request-select-checkbox" style="accent-color:#0f766e; width:14px; height:14px;">
                                        <?php else: ?>
                                            <span style="color:#94a3b8; font-size:11px;">—</span>
                                        <?php endif; ?>
                                    </td>
                                    <td style="padding:4px 8px; font-weight:700; color:#0f172a; font-size:12px; line-height:1.2;">
                                        <a href="/sps/pages/view_service_request.php?id=<?php echo (int)$request['id']; ?>" style="color:#007BFF; text-decoration:none; font-weight:700;"><?php echo htmlspecialchars($requestNumber, ENT_QUOTES, 'UTF-8'); ?></a>
                                    </td>
                                    <td style="padding:4px 8px; font-size:12px; line-height:1.2; "><?php echo htmlspecialchars($request['service_type'] ?? 'Service', ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td style="padding:4px 8px; font-size:12px; line-height:1.2; "><?php echo htmlspecialchars($request['location'] ?? '', ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td style="padding:4px 8px; font-size:12px; line-height:1.2;">
                                        <?php
                                            $preferredStart = trim((string)($request['preferred_date'] ?? ''));
                                            $preferredEnd = trim((string)($request['preferred_end_date'] ?? ''));
                                            echo htmlspecialchars(
                                                ($preferredStart !== '' ? date('M j', strtotime($preferredStart)) : '—') . ' – ' . ($preferredEnd !== '' ? date('M j', strtotime($preferredEnd)) : '—'),
                                                ENT_QUOTES,
                                                'UTF-8'
                                            );
                                        ?>
                                    </td>
                                    <td style="padding:4px 8px; font-size:12px; line-height:1.2;">
                                        <?php
                                        $requestStatus = strtolower(trim((string)($request['status'] ?? 'Pending')));
                                        $requestColor = '#1d4ed8';
                                        if ($requestStatus === 'accepted') { $requestColor = '#166534'; }
                                        elseif ($requestStatus === 'rejected') { $requestColor = '#991b1b'; }
                                        elseif ($requestStatus === 'pending') { $requestColor = '#7c3aed'; }
                                        ?>
                                        <span style="display:inline-block; padding:3px 7px; border-radius:999px; background:rgba(59,130,246,0.12); color:<?php echo $requestColor; ?>; font-weight:700;">
                                            <?php echo htmlspecialchars($request['status'] ?? 'Pending', ENT_QUOTES, 'UTF-8'); ?>
                                        </span>
                                        <?php if (!empty($request['linked_workorder_id'])): ?>
                                            <a href="/sps/pages/view_workorder.php?id=<?php echo (int)$request['linked_workorder_id']; ?>" style="margin-left:4px;font-size:10px;color:#007BFF;text-decoration:none;font-weight:700;">WO #<?php echo htmlspecialchars(!empty($request['linked_workorder_number']) ? $request['linked_workorder_number'] : 'WO' . str_pad((string)(int)$request['linked_workorder_id'], 4, '0', STR_PAD_LEFT), ENT_QUOTES, 'UTF-8'); ?></a>
                                        <?php endif; ?>
                                    </td>
                                    <td class="request-actions" style="padding:4px 8px; font-size:12px; line-height:1; vertical-align:middle;">
                                        <a href="/sps/pages/view_service_request.php?id=<?php echo (int)$request['id']; ?>" style="display:inline-flex; align-items:center; justify-content:center; height:24px; padding:0 8px; background:#e0f2fe; color:#0f172a; text-decoration:none; border-radius:7px; font-weight:700; font-size:11px; border:1px solid #bae6fd; vertical-align:middle; margin-right:6px;">View</a>
                                        <?php if ($canHideRequest): ?>
                                            <form method="post" action="/sps/pages/customer_dashboard.php?request_limit=<?php echo (int)$requestLimit; ?>&workorder_limit=<?php echo (int)$workorderLimit; ?>" onsubmit="return confirm('Hide this service request from your profile? Our team can still access it.');" style="display:inline-block; margin:0; line-height:1; vertical-align:middle;">
                                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8'); ?>">
                                                <input type="hidden" name="delete_request" value="<?php echo (int)$request['id']; ?>">
                                                <button type="submit" title="Hide from your profile" aria-label="Hide service request from your profile" style="display:inline-flex; align-items:center; justify-content:center; width:24px; height:24px; background:#f1f5f9; color:#334155; border:1px solid #cbd5e1; border-radius:7px; padding:0; cursor:pointer; font-size:10px; line-height:1; font-weight:700; margin:0; vertical-align:middle;">Hide</button>
                                            </form>
                                        <?php else: ?>
                                            <span style="color:#6b7280; font-size:12px; display:inline-block; line-height:1; vertical-align:middle;">Locked</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
            <?php endif; ?>
        </div>
    </div>

    <div class="customer-dashboard-card" style="background:#fff; border:1px solid #e5e7eb; border-radius:10px; box-shadow:0 1px 10px rgba(0,0,0,0.04); overflow:hidden;">
        <div class="customer-dashboard-card-header" style="background:#007BFF; color:#fff; padding:8px 12px; font-weight:700; display:flex; align-items:center; justify-content:space-between; gap:10px; flex-wrap:nowrap; min-height:42px;">
            <div class="customer-dashboard-card-title" style="display:flex; align-items:center; gap:8px; white-space:nowrap;">
                <span style="font-size:14px;">My Work Orders</span>
                <button type="button" id="status-guide-toggle" aria-label="Open status guide" title="Click or hover to view status meanings" aria-expanded="false" style="display:inline-flex; align-items:center; justify-content:center; gap:6px; height:26px; padding:0 10px; border:1px solid rgba(255,255,255,0.75); border-radius:999px; background:#eff6ff; color:#1d4ed8; cursor:pointer; font-size:11px; line-height:1; font-weight:700; box-shadow:0 2px 6px rgba(30,64,175,0.15);">ⓘ Status Guide</button>
            </div>
            <div class="customer-dashboard-card-tools" style="display:flex; align-items:center; gap:8px; flex-wrap:nowrap; margin-left:auto;">
                <label style="font-size:11px; font-weight:600; color:#eff6ff; display:flex; align-items:center; gap:5px; white-space:nowrap;">
                    Show
                    <select id="workorder-limit" onchange="window.location.href = updateQueryString(window.location.href, 'workorder_limit', this.value);" style="padding:2px 6px; border-radius:5px; border:1px solid rgba(255,255,255,0.35); background:#ffffff; color:#0f172a; font-weight:700; font-size:11px; min-width:66px; height:26px;">
                        <option value="10" <?php echo $workorderLimit === 10 ? 'selected' : ''; ?>>10</option>
                        <option value="50" <?php echo $workorderLimit === 50 ? 'selected' : ''; ?>>50</option>
                        <option value="100" <?php echo $workorderLimit === 100 ? 'selected' : ''; ?>>100</option>
                    </select>
                </label>
                <button type="button" class="panel-toggle" data-panel="workorder-panel" data-label-hide="Hide" data-label-show="Show" aria-expanded="true" style="background:rgba(255,255,255,0.14); border:1px solid rgba(255,255,255,0.35); color:#fff; border-radius:5px; padding:4px 9px; cursor:pointer; font-weight:700; font-size:11px; line-height:1; height:26px;">Hide</button>
            </div>
        </div>
        <div id="workorder-panel">
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
                <form id="bulk-hide-workorders-form" method="post" action="/sps/pages/customer_dashboard.php?request_limit=<?php echo (int)$requestLimit; ?>&workorder_limit=<?php echo (int)$workorderLimit; ?>" onsubmit="return confirm('Hide the selected completed work orders from your profile? They remain in our system.');" style="display:flex; align-items:center; justify-content:flex-start; gap:12px; padding:8px 10px; background:#f8fafc; border-bottom:1px solid #e5e7eb;">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8'); ?>">
                    <label style="display:inline-flex; align-items:center; gap:6px; margin:0; font-size:12px; font-weight:700; color:#334155;">
                                            <button id="bulk-hide-workorders-button" type="submit" name="bulk_hide_workorders" value="1" title="Hide selected work orders from your profile" aria-label="Hide selected closed work orders from your profile" style="display:none; width:64px; max-width:80px; min-width:64px; box-sizing:border-box; height:24px; padding:0 6px; border:0; border-radius:5px; background:#475569; color:#fff; font-size:10px; font-weight:700; cursor:pointer;">Hide</button>
                        <input type="checkbox" id="select-all-workorders" style="accent-color:#475569; width:14px; height:14px;">
                        Select all closed work orders
                    </label>
                </form>
                <div style="width:100%; overflow-x:hidden;">
                <table class="customer-workorders-table">
                    <thead>
                        <tr style="background:#f8fafc;">
                            <th style="padding:6px 8px; text-align:left; border-bottom:1px solid #e5e7eb; font-size:12px; line-height:1.2;">Work Order</th>
                            <th style="padding:6px 8px; text-align:left; border-bottom:1px solid #e5e7eb; font-size:12px; line-height:1.2;">Issue</th>
                            <th style="padding:6px 8px; text-align:left; border-bottom:1px solid #e5e7eb; font-size:12px; line-height:1.2;">Status</th>
                            <th style="padding:6px 8px; text-align:left; border-bottom:1px solid #e5e7eb; font-size:12px; line-height:1.2;">Date</th>
                            <th style="padding:6px 8px; text-align:left; border-bottom:1px solid #e5e7eb; font-size:12px; line-height:1.2;">Location</th>
                            <th style="padding:6px 8px; text-align:left; border-bottom:1px solid #e5e7eb; font-size:12px; line-height:1.2;">Action</th>
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
                            <?php
                                $workOrderRowBg = ((int)$wo['id'] % 2 === 0) ? '#f8fafc' : '#ffffff';
                                $workOrderHasUnreadUpdate = !empty($unreadWorkOrderUpdates[(int)$wo['id']]);
                                $woStatus = strtolower(trim((string)($wo['status'] ?? 'Open')));
                            ?>
                            <tr class="<?php echo $workOrderHasUnreadUpdate ? 'customer-update-row' : ''; ?>" style="border-bottom:1px solid #e5e7eb; background:<?php echo $workOrderHasUnreadUpdate ? '#eff6ff' : $workOrderRowBg; ?>;">
                                <td style="padding:4px 8px; font-size:12px; line-height:1.2;">
                                    <?php if (in_array($woStatus, ['completed', 'closed'], true)): ?>
                                        <input type="checkbox" form="bulk-hide-workorders-form" name="selected_workorders[]" value="<?php echo (int)$wo['id']; ?>" class="workorder-hide-checkbox" aria-label="Select closed work order <?php echo (int)$wo['id']; ?>" style="accent-color:#475569; width:13px; height:13px; margin-right:3px;">
                                    <?php endif; ?>
                                    #<?php echo htmlspecialchars(!empty($wo['order_number']) ? $wo['order_number'] : 'WO' . str_pad((string)(int)$wo['id'], 4, '0', STR_PAD_LEFT), ENT_QUOTES, 'UTF-8'); ?>
                                    <?php if (!empty($unreadWorkOrderUpdates[(int)$wo['id']])): ?><span style="margin-left:4px;color:#1d4ed8;font-size:10px;font-weight:800;letter-spacing:.03em;">NEW</span><?php endif; ?>
                                </td>
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
                                <td class="workorder-actions" style="padding:4px 8px; font-size:12px; line-height:1.2;">
                                    <a href="/sps/pages/view_workorder.php?id=<?php echo (int)$wo['id']; ?>" style="display:inline-flex; align-items:center; justify-content:center; height:24px; padding:0 8px; background:#e0f2fe; color:#0f172a; text-decoration:none; border-radius:7px; font-weight:700; font-size:11px; border:1px solid #bae6fd; margin-right:4px;">View</a>
                                    <?php if (!in_array($woStatus, ['on hold', 'completed', 'closed'], true)): ?>
                                        <form method="post" action="/sps/pages/customer_dashboard.php?request_limit=<?php echo (int)$requestLimit; ?>&workorder_limit=<?php echo (int)$workorderLimit; ?>" onsubmit="return confirm('Request to pause this job? Our team will be notified.');" style="display:inline-block; margin:0;">
                                            <input type="hidden" name="request_halt" value="<?php echo (int)$wo['id']; ?>">
                                            <button type="submit" title="Request to pause this job" aria-label="Request to pause this job" style="display:inline-flex; align-items:center; justify-content:center; width:24px; height:24px; padding:0; background:#fff7ed; color:#92400e; border:1px solid #fdba74; border-radius:7px; font-size:12px; cursor:pointer;">⏸</button>
                                        </form>
                                    <?php endif; ?>
                                    <?php if (in_array($woStatus, ['completed', 'closed'], true)): ?>
                                        <form method="post" action="/sps/pages/customer_dashboard.php?request_limit=<?php echo (int)$requestLimit; ?>&workorder_limit=<?php echo (int)$workorderLimit; ?>" onsubmit="return confirm('Hide this closed work order from your profile? It will remain in our system.');" style="display:inline-block; margin:0;">
                                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8'); ?>">
                                            <input type="hidden" name="hide_workorder" value="<?php echo (int)$wo['id']; ?>">
                                            <button type="submit" class="profile-hide-button" title="Hide work order from your profile" aria-label="Hide closed work order from your profile" style="display:inline-flex; align-items:center; justify-content:center; width:40px; min-width:40px; max-width:80px; height:22px; box-sizing:border-box; padding:0 3px; background:#f1f5f9; color:#334155; border:1px solid #cbd5e1; border-radius:7px; font-size:9px; font-weight:700; cursor:pointer; white-space:nowrap;">Hide</button>
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
            var statusGuideHideTimer = null;
            var statusGuidePinned = false;
            function showStatusGuide() {
                if (statusGuideHideTimer) window.clearTimeout(statusGuideHideTimer);
                statusGuidePanel.style.display = 'block';
                statusGuideToggle.setAttribute('aria-expanded', 'true');
            }
            function scheduleStatusGuideHide() {
                if (statusGuideHideTimer) window.clearTimeout(statusGuideHideTimer);
                statusGuideHideTimer = window.setTimeout(function () {
                    if (!statusGuideToggle.matches(':hover') && !statusGuidePanel.matches(':hover') && !statusGuideToggle.matches(':focus')) {
                        statusGuidePanel.style.display = 'none';
                        statusGuideToggle.setAttribute('aria-expanded', 'false');
                    }
                }, 250);
            }
            statusGuideToggle.addEventListener('click', function () {
                statusGuidePinned = !statusGuidePinned;
                if (statusGuidePinned) {
                    showStatusGuide();
                } else {
                    scheduleStatusGuideHide();
                }
            });
            statusGuideToggle.addEventListener('mouseenter', showStatusGuide);
            statusGuideToggle.addEventListener('mouseleave', scheduleStatusGuideHide);
            statusGuidePanel.addEventListener('mouseenter', function () {
                if (statusGuideHideTimer) window.clearTimeout(statusGuideHideTimer);
            });
            statusGuidePanel.addEventListener('mouseleave', scheduleStatusGuideHide);
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

        var selectAllWorkorders = document.getElementById('select-all-workorders');
        var bulkHideWorkordersButton = document.getElementById('bulk-hide-workorders-button');
        var workorderHideCheckboxes = Array.from(document.querySelectorAll('.workorder-hide-checkbox'));
        function updateBulkHideWorkordersButton() {
            var selectedCount = workorderHideCheckboxes.filter(function (checkbox) { return checkbox.checked; }).length;
            if (bulkHideWorkordersButton) {
                bulkHideWorkordersButton.style.display = selectedCount ? 'inline-flex' : 'none';
            }
            if (selectAllWorkorders) {
                selectAllWorkorders.checked = workorderHideCheckboxes.length > 0 && selectedCount === workorderHideCheckboxes.length;
            }
        }
        if (selectAllWorkorders) {
            selectAllWorkorders.addEventListener('change', function () {
                workorderHideCheckboxes.forEach(function (checkbox) { checkbox.checked = selectAllWorkorders.checked; });
                updateBulkHideWorkordersButton();
            });
        }
        workorderHideCheckboxes.forEach(function (checkbox) {
            checkbox.addEventListener('change', updateBulkHideWorkordersButton);
        });
        updateBulkHideWorkordersButton();

        document.querySelectorAll('.panel-toggle').forEach(function (button) {
            button.addEventListener('click', function () {
                var panelId = this.getAttribute('data-panel');
                var panel = document.getElementById(panelId);
                if (!panel) {
                    return;
                }

                panel.hidden = !panel.hidden;
                var isVisible = !panel.hidden;
                this.textContent = isVisible ? (this.dataset.labelHide || 'Hide') : (this.dataset.labelShow || 'Show');
                this.setAttribute('aria-expanded', String(isVisible));
            });
        });

</script>

<?php require_once '../includes/footer.php'; ?>
