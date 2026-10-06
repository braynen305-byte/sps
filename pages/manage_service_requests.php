<?php
session_start();
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true || !in_array(strtolower($_SESSION['role'] ?? ''), ['admin', 'office'], true)) {
    header('Location: /sps/login.php');
    exit;
}

require_once '../includes/dbh.inc.php';
require_once '../includes/deleted_records.inc.php';
require_once '../includes/notifications.php';
require_once '../includes/workorder_technicians.inc.php';
require_once '../includes/customer_update_state.inc.php';
ensure_workorder_technicians_schema($conn);
ensure_staff_update_state_schema($conn);
require_once '../includes/customer_assets.inc.php';
ensure_customer_asset_schema($conn);

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$conn->exec("CREATE TABLE IF NOT EXISTS customer_service_requests (
    id INT AUTO_INCREMENT PRIMARY KEY,
    customer_id INT NOT NULL,
    asset_id INT DEFAULT NULL,
    request_number VARCHAR(30) DEFAULT NULL,
    service_type VARCHAR(100) NOT NULL,
    location VARCHAR(255) NOT NULL,
    preferred_date DATE DEFAULT NULL,
    preferred_end_date DATE DEFAULT NULL,
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
     $conn->exec("ALTER TABLE customer_service_requests ADD COLUMN IF NOT EXISTS preferred_end_date DATE DEFAULT NULL");
    $requestNumberRows = $conn->query("SELECT id FROM customer_service_requests WHERE request_number IS NULL OR request_number = '' ORDER BY id");
    while ($requestRow = $requestNumberRows->fetch(PDO::FETCH_ASSOC)) {
        $requestNumber = 'SR-' . str_pad((string)(int)$requestRow['id'], 5, '0', STR_PAD_LEFT);
        $updateRequestNumber = $conn->prepare('UPDATE customer_service_requests SET request_number = ? WHERE id = ?');
        $updateRequestNumber->execute([$requestNumber, (int)$requestRow['id']]);
    }
} catch (Exception $e) {
    // ignore migration issues if the table is already compatible
}

try {
    $workorderColumns = [
        'customer_id' => 'INT DEFAULT NULL',
        'asset_id' => 'INT DEFAULT NULL',
        'service_type' => 'VARCHAR(100) DEFAULT NULL',
        'equipment_details' => 'VARCHAR(255) DEFAULT NULL',
        'work_location' => 'VARCHAR(30) DEFAULT NULL',
        'service_call_fee' => 'DECIMAL(10,2) NOT NULL DEFAULT 0',
        'special_instructions' => 'LONGTEXT NULL',
        'expected_start_date' => 'DATE DEFAULT NULL',
        'expected_end_date' => 'DATE DEFAULT NULL',
        'requested_work' => 'LONGTEXT NULL',
        'additional_comments' => 'LONGTEXT NULL',
        'vessel_vin' => 'VARCHAR(100) DEFAULT NULL',
        'vessel_hours' => 'DECIMAL(10,2) DEFAULT NULL',
        'status' => "VARCHAR(50) NOT NULL DEFAULT 'Open'",
        'priority' => "VARCHAR(20) NOT NULL DEFAULT 'Normal'",
        'order_received_by' => 'INT DEFAULT NULL',
        'work_description' => 'LONGTEXT NULL',
        'created_via' => 'VARCHAR(30) DEFAULT NULL'
    ];
    $existingWorkorderColumns = $conn->query("SHOW COLUMNS FROM workorders")->fetchAll(PDO::FETCH_COLUMN);

    foreach ($workorderColumns as $columnName => $columnDefinition) {
        if (!in_array($columnName, $existingWorkorderColumns, true)) {
            $conn->exec("ALTER TABLE workorders ADD COLUMN `$columnName` $columnDefinition");
        }
    }
} catch (PDOException $e) {
    die('Database migration error: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8'));
}

$message = '';
$messageType = 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !hash_equals($_SESSION['csrf_token'], (string)($_POST['csrf_token'] ?? ''))) {
    $message = 'Your session could not be verified. Please reload the page and try again.';
    $messageType = 'error';
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'archive_selected_requests') {
    $selectedRequestIds = array_values(array_unique(array_filter(array_map('intval', (array)($_POST['selected_request_ids'] ?? [])))));
    $archivedCount = 0;
    $skippedCount = 0;
    foreach ($selectedRequestIds as $selectedRequestId) {
        $eligibleStmt = $conn->prepare('SELECT r.status, w.id AS linked_workorder_id FROM customer_service_requests r LEFT JOIN workorders w ON w.id = r.approved_workorder_id WHERE r.id = ? LIMIT 1');
        $eligibleStmt->execute([$selectedRequestId]);
        $eligibleRequest = $eligibleStmt->fetch(PDO::FETCH_ASSOC);
        $eligibleStatus = strtolower(trim((string)($eligibleRequest['status'] ?? '')));
        if (!$eligibleRequest || !in_array($eligibleStatus, ['rejected', 'closed'], true) || !empty($eligibleRequest['linked_workorder_id'])) {
            $skippedCount++;
            continue;
        }
        try {
            if (archive_service_request($conn, $selectedRequestId, strtolower(trim((string)($_SESSION['role'] ?? 'office'))), (int)($_SESSION['user_id'] ?? 0))) {
                $archivedCount++;
            } else {
                $skippedCount++;
            }
        } catch (Throwable $archiveError) {
            error_log('Service request archive failed: ' . $archiveError->getMessage());
            $skippedCount++;
        }
    }
    $message = $archivedCount . ' service request(s) moved to Trash.' . ($skippedCount > 0 ? ' ' . $skippedCount . ' were skipped because they are active or linked to a work order.' : '');
    $messageType = $archivedCount > 0 ? 'success' : 'error';
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['action'])) {
    $requestId = (int)($_POST['request_id'] ?? 0);
    $action = trim((string)$_POST['action']);
    $approvedWorkOrderId = 0;
    $customerPortalNotice = null;

    $conn->beginTransaction();
    $request = $conn->prepare('SELECT * FROM customer_service_requests WHERE id = ? LIMIT 1 FOR UPDATE');
    $request->execute([$requestId]);
    $requestRow = $request->fetch(PDO::FETCH_ASSOC);
    $alreadyDecidedStatus = strtolower(trim((string)($requestRow['status'] ?? '')));

    if (!$requestRow) {
        $message = 'The selected request could not be found.';
        $messageType = 'error';
    } elseif (strtolower(trim((string)($requestRow['status'] ?? ''))) !== 'pending') {
        $message = 'Only pending service requests can be approved or rejected.';
        $messageType = 'error';
    } else {
        if ($action === 'reject') {
            $update = $conn->prepare('UPDATE customer_service_requests SET status = ?, updated_at = NOW() WHERE id = ?');
            $update->execute(['Rejected', $requestId]);
            $requestLabel = trim((string)($requestRow['request_number'] ?? '')) ?: ('SR-' . str_pad((string)$requestId, 5, '0', STR_PAD_LEFT));
            $customerPortalNotice = [
                'customer_id' => (int)$requestRow['customer_id'],
                'subject' => 'Service request update: ' . $requestLabel,
                'message' => 'Your service request ' . $requestLabel . ' was reviewed and could not be approved. Contact support if you need more information.',
                'link' => '/sps/pages/view_service_request.php?id=' . $requestId
            ];
            $message = 'Service request rejected.';
        } elseif ($action === 'approve') {
            $customer = $conn->prepare('SELECT id, name, phone, address FROM customers WHERE id = ? LIMIT 1');
            $customer->execute([(int)$requestRow['customer_id']]);
            $customerRow = $customer->fetch(PDO::FETCH_ASSOC);

            if (!$customerRow) {
                $message = 'Customer record not found for this request.';
                $messageType = 'error';
            } else {
                $orderDate = date('Y-m-d');
                $workDescription = trim((string)($requestRow['description'] ?? ''));
                $requestUrgency = trim((string)($requestRow['urgency'] ?? 'Normal'));
                if (!in_array($requestUrgency, ['Normal', 'Urgent', 'Emergency'], true)) {
                    $requestUrgency = 'Normal';
                }
                $approvedAssetId = null;
                $approvedAsset = null;
                if (!empty($requestRow['asset_id'])) {
                    $assetCheck = $conn->prepare('SELECT id, serial_number, hours FROM customer_assets WHERE id = ? AND customer_id = ? LIMIT 1');
                    $assetCheck->execute([(int)$requestRow['asset_id'], (int)$requestRow['customer_id']]);
                    $approvedAsset = $assetCheck->fetch(PDO::FETCH_ASSOC) ?: null;
                    $approvedAssetId = $approvedAsset ? (int)$approvedAsset['id'] : null;
                }

                $createWorkOrder = $conn->prepare('INSERT INTO workorders (
                    customer_id,
                    asset_id,
                    client_name,
                    client_phone,
                    location,
                    order_date,
                    work_location,
                    service_call_fee,
                    service_type,
                    equipment_details,
                    special_instructions,
                    expected_start_date,
                    expected_end_date,
                    requested_work,
                    vessel_vin,
                    vessel_hours,
                    additional_comments,
                    status,
                    priority,
                    order_received_by,
                    work_description,
                    created_via,
                    created_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())');

                $success = $createWorkOrder->execute([
    (int)$requestRow['customer_id'],
    $approvedAssetId,
    (string)($customerRow['name'] ?? 'Customer'),
    (string)($requestRow['customer_phone'] ?? ($customerRow['phone'] ?? '')),
    (string)$requestRow['location'],
    $orderDate,
    'customer_property',
    (trim((string)($requestRow['service_type'] ?? '')) === 'Service Call') ? '75.00' : '0.00',
    (string)($requestRow['service_type'] ?? ''),
    (string)($requestRow['equipment_details'] ?? ''),
    (string)($requestRow['special_instructions'] ?? ''),
    $requestRow['preferred_date'] ?: null,
    $requestRow['preferred_end_date'] ?: null,

    // Requested Work will be displayed from the linked Service Request
    // on the View Work Order page.
    (string)($requestRow['problem_summary'] ?? $requestRow['description'] ?? 'Service requested'),

    (string)($approvedAsset['serial_number'] ?? ''),
    $approvedAsset['hours'] ?? null,

    // Leave Additional Comments blank for Service Request-created work orders.
    '',

    'Open',
    $requestUrgency,
    (int)($_SESSION['user_id'] ?? 0),
    $workDescription,
    'service_request'
]);

                if (!$success) {
                    $message = 'The work order could not be created from this request.';
                    $messageType = 'error';
                } else {
                    $workOrderId = (int)$conn->lastInsertId();
                    $primaryTechnicianStmt = $conn->prepare('SELECT work_performed_by FROM workorders WHERE id = ? LIMIT 1');
                    $primaryTechnicianStmt->execute([$workOrderId]);
                    sync_primary_workorder_technician($conn, $workOrderId, $primaryTechnicianStmt->fetchColumn());
                    // Request urgency is the work order's starting priority, not an edit.
                    $setRequestPriority = $conn->prepare('UPDATE workorders SET priority = ? WHERE id = ?');
                    $setRequestPriority->execute([$requestUrgency, $workOrderId]);

                    $updateRequest = $conn->prepare('UPDATE customer_service_requests SET status = ?, approved_workorder_id = ?, updated_at = NOW() WHERE id = ?');
                    $updateRequest->execute(['Accepted', $workOrderId, $requestId]);
                    $approvedWorkOrderId = $workOrderId;
                    $requestLabel = trim((string)($requestRow['request_number'] ?? '')) ?: ('SR-' . str_pad((string)$requestId, 5, '0', STR_PAD_LEFT));
                    $customerPortalNotice = [
                        'customer_id' => (int)$requestRow['customer_id'],
                        'subject' => 'Service request approved: ' . $requestLabel,
                        'message' => 'Your service request ' . $requestLabel . ' was approved and converted into work order WO-' . str_pad((string)$workOrderId, 4, '0', STR_PAD_LEFT) . '.',
                        'link' => '/sps/pages/view_workorder.php?id=' . $workOrderId
                    ];
                    $message = 'Service request approved and a work order was created successfully.';
                }
            }
        }
    }
    $conn->commit();

    if (is_array($customerPortalNotice) && (int)$customerPortalNotice['customer_id'] > 0) {
        try {
            notify_customer_event(
                $conn,
                (int)$customerPortalNotice['customer_id'],
                'service_request_status',
                (string)$customerPortalNotice['subject'],
                (string)$customerPortalNotice['message'],
                (string)$customerPortalNotice['link']
            );
        } catch (Throwable $notificationError) {
            error_log('Service-request customer notification failed: ' . $notificationError->getMessage());
        }
    }

    if ($approvedWorkOrderId > 0) {
        $requestLabel = 'WO-' . str_pad((string)$approvedWorkOrderId, 4, '0', STR_PAD_LEFT);
        try {
            notify_staff_roles(
                $conn,
                ['admin', 'office'],
                'service_request_approved',
                'Service request approved: ' . $requestLabel,
                'A customer service request has been approved and converted into work order ' . $requestLabel . '.',
                '/sps/pages/view_workorder.php?id=' . $approvedWorkOrderId
            );
        } catch (Throwable $notificationError) {
            error_log('Service request approval notification failed: ' . $notificationError->getMessage());
        }
    }

    if (($_POST['return_to'] ?? '') === 'request_detail' && $messageType === 'success' && in_array($action, ['approve', 'reject'], true)) {
        header('Location: /sps/pages/view_service_request.php?id=' . $requestId . '&decision=' . urlencode($action));
        exit;
    }
    if (($_POST['return_to'] ?? '') === 'request_detail' && in_array($action, ['approve', 'reject'], true)
        && in_array($alreadyDecidedStatus, ['accepted', 'rejected'], true)) {
        header('Location: /sps/pages/view_service_request.php?id=' . $requestId . '&decision=already_' . urlencode($alreadyDecidedStatus));
        exit;
    }
}

$requests = $conn->query('SELECT r.*, c.name AS customer_name, c.email AS customer_email, c.phone AS customer_phone, w.id AS linked_workorder_id, w.order_number AS linked_workorder_number FROM customer_service_requests r LEFT JOIN customers c ON c.id = r.customer_id LEFT JOIN workorders w ON w.id = r.approved_workorder_id ORDER BY r.created_at DESC')->fetchAll(PDO::FETCH_ASSOC);
$staffUserId = (int)($_SESSION['user_id'] ?? 0);
$unreadServiceRequests = [];
foreach ($requests as $requestRow) {
    $requestId = (int)$requestRow['id'];
    $lastSeen = staff_update_seen_at($conn, $staffUserId, 'service_request', $requestId);
    $baseline = $lastSeen ?? (string)($requestRow['created_at'] ?? '');
    $latestActivity = (string)($requestRow['updated_at'] ?? $requestRow['created_at'] ?? '');
    if ($latestActivity !== '' && $baseline !== '' && $latestActivity > $baseline) {
        $unreadServiceRequests[$requestId] = true;
    }
}

$title = 'Manage Service Requests';
$dashboardBackUrl = strtolower(trim((string)($_SESSION['role'] ?? ''))) === 'admin'
    ? '/sps/pages/admin_dashboard.php'
    : '/sps/pages/dashboard.php';
require_once '../includes/header.php';
?>

<div style="max-width: 1750px; margin: 32px auto 48px; padding: 0 18px;">
    <div class="page-header">
        <h2 style="margin:0;">Service Requests</h2>
        <a href="<?php echo htmlspecialchars($dashboardBackUrl, ENT_QUOTES, 'UTF-8'); ?>" style="color:#007BFF; text-decoration:none;">← Back to Dashboard</a>
    </div>

    <?php if ($message !== ''): ?>
        <div style="margin:18px 0; padding:12px 16px; border-radius:8px; border:1px solid <?php echo $messageType === 'error' ? '#fecaca' : '#bbf7d0'; ?>; background:<?php echo $messageType === 'error' ? '#fee2e2' : '#ecfdf5'; ?>; color:<?php echo $messageType === 'error' ? '#991b1b' : '#166534'; ?>; font-weight:700;">
            <?php echo htmlspecialchars($message, ENT_QUOTES, 'UTF-8'); ?>
        </div>
    <?php endif; ?>

    <div style="background:#fff; border:1px solid #e5e7eb; border-radius:10px; box-shadow:0 1px 10px rgba(0,0,0,0.04); overflow:hidden;">
        <div style="background:#007BFF; color:#fff; padding:12px 16px; font-weight:700;">Customer Job Requests</div>
        <p class="service-request-mobile-hint">Key details are shown here. Tap an SR number or view icon to open the complete request.</p>
        <style>
            .service-request-mobile-hint { display:none; }
            .service-request-table { width:100%; table-layout:fixed; }
            .service-request-table th,
            .service-request-table td { overflow-wrap:anywhere; word-break:normal; }
            .service-request-table th:nth-child(1), .service-request-table td:nth-child(1) { width:8%; }
            .service-request-table th:nth-child(2), .service-request-table td:nth-child(2) { width:12%; }
            .service-request-table th:nth-child(3), .service-request-table td:nth-child(3) { width:7%; }
            .service-request-table th:nth-child(4), .service-request-table td:nth-child(4) { width:9%; }
            .service-request-table th:nth-child(5), .service-request-table td:nth-child(5) { width:9%; }
            .service-request-table th:nth-child(6), .service-request-table td:nth-child(6) { width:7%; }
            .service-request-table th:nth-child(7), .service-request-table td:nth-child(7) { width:10%; }
            .service-request-table th:nth-child(8), .service-request-table td:nth-child(8) { width:11%; }
            .service-request-table th:nth-child(9), .service-request-table td:nth-child(9) { width:80px; }
            .service-request-table th:nth-child(10), .service-request-table td:nth-child(10) { width:130px; }
            .service-requests-table-scroll { width:100%; max-width:100%; }
            .service-request-unread td { background:#eff6ff; }
            .service-request-unread td:first-child { box-shadow:inset 3px 0 #2563eb; }
            .service-request-actions-cell { width:150px; white-space:nowrap; }
            .service-request-actions { display:flex; align-items:center; justify-content:flex-start; flex-wrap:nowrap; gap:6px; white-space:nowrap; }
            .service-request-actions .service-request-decision-form { display:flex; align-items:center; flex-wrap:nowrap; gap:6px; width:auto; margin:0; }
            .service-request-icon-action { display:inline-flex; flex:0 0 34px; width:34px; height:34px; min-width:34px; align-items:center; justify-content:center; margin:0; padding:0; border:1px solid #cbd5e1; border-radius:7px; background:#fff; color:#1e3a5f; cursor:pointer; text-decoration:none; box-sizing:border-box; }
            .service-request-icon-action svg { width:17px; height:17px; }
            .service-request-icon-action.view { background:#e0f2fe; border-color:#bae6fd; color:#0f172a; }
            .service-request-icon-action.approve { background:#15803d; border-color:#15803d; color:#fff; }
            .service-request-icon-action.reject { background:#b91c1c; border-color:#b91c1c; color:#fff; }
            .service-request-icon-action:hover { filter:brightness(.94); }
            .service-request-icon-action:focus-visible { outline:3px solid #93c5fd; outline-offset:2px; }
            .service-request-linked-workorder { display:inline-block; max-width:120px; overflow:hidden; text-overflow:ellipsis; vertical-align:middle; color:#1d4ed8; font-size:11px; font-weight:700; text-decoration:none; }
            .service-request-status-badge { display:inline-flex !important; align-items:center; white-space:nowrap; padding:3px 7px !important; font-size:11px !important; line-height:1 !important; }
        </style>

        <div style="display:flex; flex-wrap:wrap; align-items:center; justify-content:space-between; gap:12px; padding:12px 16px; border-bottom:1px solid #e5e7eb;">
            <form id="bulk-archive-requests-form" method="post" action="/sps/pages/manage_service_requests.php" style="display:flex; flex-wrap:wrap; align-items:center; gap:12px; margin:0;" onsubmit="return confirm('Move the selected closed or rejected service requests to Trash? They can be restored for 7 months.');">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8'); ?>">
                <input type="hidden" name="action" value="archive_selected_requests">
                <label style="display:inline-flex; align-items:center; gap:6px; margin:0; font-size:12px; font-weight:700;">
                    <input type="checkbox" id="select-all-archivable-requests"> Select eligible
                </label>
                <button type="submit" id="archive-selected-requests" disabled style="padding:8px 12px; border:0; border-radius:6px; background:#b91c1c; color:#fff; font-weight:700; cursor:pointer; opacity:.55;">Archive Selected</button>
            </form>
            <a href="/sps/pages/deleted_records.php" style="color:#1d4ed8; font-weight:700; text-decoration:none;">Trash / Restore</a>
        </div>

        <?php if (empty($requests)): ?>
            <div style="padding:20px; color:#4b5563;">There are no service requests at the moment.</div>
        <?php else: ?>
            <div class="service-requests-table-scroll">
            <table class="service-request-table" style="width:100%; border-collapse:collapse;">
                <thead>
                    <tr style="background:#f8fafc;">
                        <th style="padding:6px 8px; text-align:left; border-bottom:1px solid #e5e7eb; font-size:12px; line-height:1.2;">Request #</th>
                        <th style="padding:6px 8px; text-align:left; border-bottom:1px solid #e5e7eb; font-size:12px; line-height:1.2;">Customer</th>
                        <th style="padding:6px 8px; text-align:left; border-bottom:1px solid #e5e7eb; font-size:12px; line-height:1.2;">Type</th>
                        <th style="padding:6px 8px; text-align:left; border-bottom:1px solid #e5e7eb; font-size:12px; line-height:1.2;">Location</th>
                        <th style="padding:6px 8px; text-align:left; border-bottom:1px solid #e5e7eb; font-size:12px; line-height:1.2;">Preferred Dates</th>
                        <th style="padding:6px 8px; text-align:left; border-bottom:1px solid #e5e7eb; font-size:12px; line-height:1.2;">Urgency</th>
                        <th style="padding:6px 8px; text-align:left; border-bottom:1px solid #e5e7eb; font-size:12px; line-height:1.2;">Summary</th>
                        <th style="padding:6px 8px; text-align:left; border-bottom:1px solid #e5e7eb; font-size:12px; line-height:1.2;">Details</th>
                        <th style="padding:6px 8px; text-align:left; border-bottom:1px solid #e5e7eb; font-size:12px; line-height:1.2;">Status</th>
                        <th style="padding:6px 8px; text-align:left; border-bottom:1px solid #e5e7eb; font-size:12px; line-height:1.2;">Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($requests as $request): ?>
                        <?php
                            $requestLabel = trim((string)($request['request_number'] ?? ''));
                            if ($requestLabel === '') {
                                $requestLabel = 'SR-' . str_pad((string)(int)$request['id'], 5, '0', STR_PAD_LEFT);
                            }
                            $hasUnreadRequestUpdate = !empty($unreadServiceRequests[(int)$request['id']]);
                            $requestStatus = strtolower(trim((string)($request['status'] ?? '')));
                            $canArchiveRequest = in_array($requestStatus, ['rejected', 'closed'], true) && empty($request['linked_workorder_id']);
                        ?>
                            <tr class="service-request-row <?php echo $hasUnreadRequestUpdate ? 'service-request-unread' : ''; ?>" style="border-bottom:1px solid #f1f5f9; vertical-align:top;">
                            <td class="sr-number-cell" style="padding:6px 8px; font-weight:700; color:#0f172a; font-size:12px; line-height:1.2; "><?php if ($canArchiveRequest): ?><input class="archive-request-checkbox" type="checkbox" form="bulk-archive-requests-form" name="selected_request_ids[]" value="<?php echo (int)$request['id']; ?>" aria-label="Select <?php echo htmlspecialchars($requestLabel, ENT_QUOTES, 'UTF-8'); ?> for archive" style="margin-right:5px; accent-color:#b91c1c;"><?php endif; ?><a href="/sps/pages/view_service_request.php?id=<?php echo (int)$request['id']; ?>" style="color:#007BFF; text-decoration:none; font-weight:700;"><?php echo htmlspecialchars($requestLabel, ENT_QUOTES, 'UTF-8'); ?></a><?php if ($hasUnreadRequestUpdate): ?><span style="margin-left:5px;color:#1d4ed8;font-size:10px;font-weight:800;">NEW</span><?php endif; ?></td>
                            <td class="sr-customer-cell" style="padding:6px 8px; font-size:12px; line-height:1.2;">
                                <?php echo htmlspecialchars($request['customer_name'] ?? 'Unknown Customer', ENT_QUOTES, 'UTF-8'); ?><br>
                                <span style="font-size:11px; color:#64748b;"><?php echo htmlspecialchars($request['customer_email'] ?? '', ENT_QUOTES, 'UTF-8'); ?></span>
                            </td>
                            <td class="sr-type-cell" style="padding:6px 8px; font-size:12px; line-height:1.2; "><?php echo htmlspecialchars($request['service_type'] ?? 'Service', ENT_QUOTES, 'UTF-8'); ?></td>
                            <td class="sr-location-cell" style="padding:10px 12px;"><?php echo htmlspecialchars($request['location'] ?? '', ENT_QUOTES, 'UTF-8'); ?></td>
                            <td class="sr-dates-cell" style="padding:4px 8px; font-size:12px; line-height:1.5;">
    <div>
        <strong>Start:</strong>
        <?php
        $preferredStart = trim((string)($request['preferred_date'] ?? ''));
        echo $preferredStart !== ''
            ? htmlspecialchars(date('M j, Y', strtotime($preferredStart)), ENT_QUOTES, 'UTF-8')
            : 'Not set';
        ?>
    </div>

    <div>
        <strong>End:</strong>
        <?php
        $preferredEnd = trim((string)($request['preferred_end_date'] ?? ''));
        echo $preferredEnd !== ''
            ? htmlspecialchars(date('M j, Y', strtotime($preferredEnd)), ENT_QUOTES, 'UTF-8')
            : 'Not set';
        ?>
    </div>
</td>
                            <td class="sr-urgency-cell" style="padding:10px 12px;"><?php echo htmlspecialchars($request['urgency'] ?? 'Normal', ENT_QUOTES, 'UTF-8'); ?></td>
                            <td class="sr-summary-cell" style="padding:10px 12px; max-width:220px;"><?php echo nl2br(htmlspecialchars($request['problem_summary'] ?? '', ENT_QUOTES, 'UTF-8')); ?></td>
                            <td class="sr-details-cell" style="padding:10px 12px; max-width:280px;"><?php echo nl2br(htmlspecialchars($request['description'] ?? '', ENT_QUOTES, 'UTF-8')); ?></td>
                            <td class="sr-status-cell" style="padding:10px 12px;">
                                <span class="service-request-status-badge" style="display:inline-block; padding:4px 8px; border-radius:999px; background:rgba(59,130,246,0.12); color:#1d4ed8; font-weight:700;">
                                    <?php echo htmlspecialchars($request['status'] ?? 'Pending', ENT_QUOTES, 'UTF-8'); ?>
                                </span>
                            </td>
                            <td class="service-request-actions-cell sr-actions-cell" style="padding:8px; width:150px;">
                                <div class="service-request-actions">
                                    <a class="service-request-icon-action view" href="/sps/pages/view_service_request.php?id=<?php echo (int)$request['id']; ?>" title="View service request" aria-label="View service request">
                                        <svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7-10-7-10-7Z"></path><circle cx="12" cy="12" r="3"></circle></svg>
                                    </a>
                                    <?php if (($request['status'] ?? '') === 'Pending'): ?>
                                        <form class="service-request-decision-form" method="post" action="/sps/pages/manage_service_requests.php">
                                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8'); ?>">
                                            <input type="hidden" name="request_id" value="<?php echo (int)$request['id']; ?>">
                                            <button class="service-request-icon-action approve" type="submit" name="action" value="approve" title="Approve service request" aria-label="Approve service request">
                                                <svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="m5 12 4 4L19 6"></path></svg>
                                            </button>
                                            <button class="service-request-icon-action reject" type="submit" name="action" value="reject" title="Reject service request" aria-label="Reject service request">
                                                <svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><path d="m6 6 12 12M18 6 6 18"></path></svg>
                                            </button>
                                        </form>
                                    <?php elseif (!empty($request['approved_workorder_id'])): ?>
                                        <a class="service-request-linked-workorder" href="/sps/pages/view_workorder.php?id=<?php echo (int)$request['approved_workorder_id']; ?>" title="Review linked work order">WO <?php echo htmlspecialchars(!empty($request['linked_workorder_number']) ? $request['linked_workorder_number'] : 'WO' . str_pad((string)(int)$request['approved_workorder_id'], 4, '0', STR_PAD_LEFT), ENT_QUOTES, 'UTF-8'); ?></a>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            </div>
            <script>
                (function () {
                    const selectAll = document.getElementById('select-all-archivable-requests');
                    const checkboxes = Array.from(document.querySelectorAll('.archive-request-checkbox'));
                    const submitButton = document.getElementById('archive-selected-requests');
                    const updateSelection = function () {
                        const selectedCount = checkboxes.filter(function (checkbox) { return checkbox.checked; }).length;
                        submitButton.disabled = selectedCount === 0;
                        submitButton.style.opacity = selectedCount === 0 ? '.55' : '1';
                        selectAll.checked = checkboxes.length > 0 && selectedCount === checkboxes.length;
                    };
                    selectAll.addEventListener('change', function () {
                        checkboxes.forEach(function (checkbox) { checkbox.checked = selectAll.checked; });
                        updateSelection();
                    });
                    checkboxes.forEach(function (checkbox) { checkbox.addEventListener('change', updateSelection); });
                    updateSelection();
                })();
            </script>
        <?php endif; ?>
    </div>
</div>

<?php require_once '../includes/footer.php'; ?>
