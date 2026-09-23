<?php
session_start();
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true || !in_array(strtolower($_SESSION['role'] ?? ''), ['admin', 'office'], true)) {
    header('Location: /sps/login.php');
    exit;
}

require_once '../includes/dbh.inc.php';
require_once '../includes/notifications.php';

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
     $columnCheck = $conn->query("
        SELECT COUNT(*)
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'workorders'
          AND COLUMN_NAME = 'created_via'
    ");

    if ((int)$columnCheck->fetchColumn() === 0) {
        $conn->exec("
            ALTER TABLE workorders
            ADD COLUMN created_via VARCHAR(30) DEFAULT NULL
        ");
    }
} catch (PDOException $e) {
    die('Database migration error: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8'));
}

$message = '';
$messageType = 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['action'])) {
    $requestId = (int)($_POST['request_id'] ?? 0);
    $action = trim((string)$_POST['action']);

    $request = $conn->prepare('SELECT * FROM customer_service_requests WHERE id = ? LIMIT 1');
    $request->execute([$requestId]);
    $requestRow = $request->fetch(PDO::FETCH_ASSOC);

    if (!$requestRow) {
        $message = 'The selected request could not be found.';
        $messageType = 'error';
    } else {
        if ($action === 'reject') {
            $update = $conn->prepare('UPDATE customer_service_requests SET status = ?, updated_at = NOW() WHERE id = ?');
            $update->execute(['Rejected', $requestId]);
            $message = 'Service request rejected.';
        } elseif ($action === 'approve') {
            $customer = $conn->prepare('SELECT id, name, phone, address FROM customers WHERE id = ? LIMIT 1');
            $customer->execute([(int)$requestRow['customer_id']]);
            $customerRow = $customer->fetch(PDO::FETCH_ASSOC);

            if (!$customerRow) {
                $message = 'Customer record not found for this request.';
                $messageType = 'error';
            } else {
                $orderDate = !empty($requestRow['preferred_date']) ? $requestRow['preferred_date'] : date('Y-m-d');
                $workDescription = trim((string)($requestRow['description'] ?? ''));
                if (!empty($requestRow['equipment_details'])) {
                    $workDescription = 'Equipment / Asset: ' . trim((string)$requestRow['equipment_details']) . "\n\n" . $workDescription;
                }
                if (!empty($requestRow['special_instructions'])) {
                    $workDescription .= "\n\nSpecial Instructions: " . trim((string)$requestRow['special_instructions']);
                }

                $createWorkOrder = $conn->prepare('INSERT INTO workorders (
                    customer_id,
                    client_name,
                    client_phone,
                    location,
                    order_date,
                    requested_work,
                    additional_comments,
                    status,
                    priority,
                    order_received_by,
                    work_description,
                    created_via,
                    created_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())');

                $success = $createWorkOrder->execute([
                    (int)$requestRow['customer_id'],
                    (string)($customerRow['name'] ?? 'Customer'),
                    (string)($requestRow['customer_phone'] ?? ($customerRow['phone'] ?? '')),
                    (string)$requestRow['location'],
                    $orderDate,
                    (string)($requestRow['problem_summary'] ?? $requestRow['description'] ?? 'Service requested'),
                    (string)($requestRow['problem_summary'] ?? ''),
                    'Open',
                    (string)($requestRow['urgency'] ?? 'Normal'),
                    (int)($_SESSION['user_id'] ?? 0),
                    $workDescription,
                    'service_request'
                ]);

                if (!$success) {
                    $message = 'The work order could not be created from this request.';
                    $messageType = 'error';
                } else {
                    $workOrderId = (int)$conn->lastInsertId();
                    $updateRequest = $conn->prepare('UPDATE customer_service_requests SET status = ?, approved_workorder_id = ?, updated_at = NOW() WHERE id = ?');
                    $updateRequest->execute(['Accepted', $workOrderId, $requestId]);
                    $requestLabel = 'WO-' . str_pad((string)$workOrderId, 4, '0', STR_PAD_LEFT);
                    notify_staff_roles(
                        $conn,
                        ['admin', 'office'],
                        'service_request_approved',
                        'Service request approved: ' . $requestLabel,
                        'A customer service request has been approved and converted into work order ' . $requestLabel . '.',
                        '/sps/pages/view_workorder.php?id=' . $workOrderId
                    );
                    $message = 'Service request approved and a work order was created successfully.';
                }
            }
        }
    }
}

$requests = $conn->query('SELECT r.*, c.name AS customer_name, c.email AS customer_email, c.phone AS customer_phone, w.order_number AS linked_workorder_number FROM customer_service_requests r LEFT JOIN customers c ON c.id = r.customer_id LEFT JOIN workorders w ON w.id = r.approved_workorder_id ORDER BY r.created_at DESC')->fetchAll(PDO::FETCH_ASSOC);

$title = 'Manage Service Requests';
require_once '../includes/header.php';
?>

<div style="max-width: 1200px; margin: 32px auto 48px; padding: 0 18px;">
    <div class="page-header">
        <h2 style="margin:0;">Service Requests</h2>
        <a href="/sps/pages/admin_dashboard.php" style="color:#007BFF; text-decoration:none;">← Back to Dashboard</a>
    </div>

    <?php if ($message !== ''): ?>
        <div style="margin:18px 0; padding:12px 16px; border-radius:8px; border:1px solid <?php echo $messageType === 'error' ? '#fecaca' : '#bbf7d0'; ?>; background:<?php echo $messageType === 'error' ? '#fee2e2' : '#ecfdf5'; ?>; color:<?php echo $messageType === 'error' ? '#991b1b' : '#166534'; ?>; font-weight:700;">
            <?php echo htmlspecialchars($message, ENT_QUOTES, 'UTF-8'); ?>
        </div>
    <?php endif; ?>

    <div style="background:#fff; border:1px solid #e5e7eb; border-radius:10px; box-shadow:0 1px 10px rgba(0,0,0,0.04); overflow:hidden;">
        <div style="background:#007BFF; color:#fff; padding:12px 16px; font-weight:700;">Customer Job Requests</div>

        <?php if (empty($requests)): ?>
            <div style="padding:20px; color:#4b5563;">There are no service requests at the moment.</div>
        <?php else: ?>
            <table style="width:100%; border-collapse:collapse;">
                <thead>
                    <tr style="background:#f8fafc;">
                        <th style="padding:6px 8px; text-align:left; border-bottom:1px solid #e5e7eb; font-size:12px; line-height:1.2;">Request #</th>
                        <th style="padding:6px 8px; text-align:left; border-bottom:1px solid #e5e7eb; font-size:12px; line-height:1.2;">Customer</th>
                        <th style="padding:6px 8px; text-align:left; border-bottom:1px solid #e5e7eb; font-size:12px; line-height:1.2;">Type</th>
                        <th style="padding:6px 8px; text-align:left; border-bottom:1px solid #e5e7eb; font-size:12px; line-height:1.2;">Location</th>
                        <th style="padding:6px 8px; text-align:left; border-bottom:1px solid #e5e7eb; font-size:12px; line-height:1.2;">Preferred Date</th>
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
                        ?>
                        <tr style="border-bottom:1px solid #f1f5f9; vertical-align:top;">
                            <td style="padding:6px 8px; font-weight:700; color:#0f172a; font-size:12px; line-height:1.2; "><a href="/sps/pages/view_service_request.php?id=<?php echo (int)$request['id']; ?>" style="color:#007BFF; text-decoration:none; font-weight:700;"><?php echo htmlspecialchars($requestLabel, ENT_QUOTES, 'UTF-8'); ?></a></td>
                            <td style="padding:6px 8px; font-size:12px; line-height:1.2;">
                                <?php echo htmlspecialchars($request['customer_name'] ?? 'Unknown Customer', ENT_QUOTES, 'UTF-8'); ?><br>
                                <span style="font-size:11px; color:#64748b;"><?php echo htmlspecialchars($request['customer_email'] ?? '', ENT_QUOTES, 'UTF-8'); ?></span>
                            </td>
                            <td style="padding:6px 8px; font-size:12px; line-height:1.2; "><?php echo htmlspecialchars($request['service_type'] ?? 'Service', ENT_QUOTES, 'UTF-8'); ?></td>
                            <td style="padding:10px 12px;"><?php echo htmlspecialchars($request['location'] ?? '', ENT_QUOTES, 'UTF-8'); ?></td>
                            <td style="padding:10px 12px;"><?php echo htmlspecialchars($request['preferred_date'] ?? 'Not set', ENT_QUOTES, 'UTF-8'); ?></td>
                            <td style="padding:10px 12px;"><?php echo htmlspecialchars($request['urgency'] ?? 'Normal', ENT_QUOTES, 'UTF-8'); ?></td>
                            <td style="padding:10px 12px; max-width:220px;"><?php echo nl2br(htmlspecialchars($request['problem_summary'] ?? '', ENT_QUOTES, 'UTF-8')); ?></td>
                            <td style="padding:10px 12px; max-width:280px;"><?php echo nl2br(htmlspecialchars($request['description'] ?? '', ENT_QUOTES, 'UTF-8')); ?></td>
                            <td style="padding:10px 12px;">
                                <span style="display:inline-block; padding:4px 8px; border-radius:999px; background:rgba(59,130,246,0.12); color:#1d4ed8; font-weight:700;">
                                    <?php echo htmlspecialchars($request['status'] ?? 'Pending', ENT_QUOTES, 'UTF-8'); ?>
                                </span>
                            </td>
                            <td style="padding:10px 12px;">
                                <div style="display:flex; gap:8px; flex-wrap:wrap; align-items:center;">
                                    <a href="/sps/pages/view_service_request.php?id=<?php echo (int)$request['id']; ?>" style="color:#0f172a; background:#e0f2fe; border:1px solid #bae6fd; text-decoration:none; font-weight:700; padding:6px 10px; border-radius:6px;">View</a>
                                    <?php if (($request['status'] ?? '') === 'Pending'): ?>
                                        <form method="post" action="/sps/pages/manage_service_requests.php" style="display:flex; gap:8px; flex-wrap:wrap; margin:0;">
                                            <input type="hidden" name="request_id" value="<?php echo (int)$request['id']; ?>">
                                            <button type="submit" name="action" value="approve" style="background:#15803d; color:#fff; border:none; border-radius:6px; padding:8px 10px; cursor:pointer; font-weight:700;">Approve</button>
                                            <button type="submit" name="action" value="reject" style="background:#b91c1c; color:#fff; border:none; border-radius:6px; padding:8px 10px; cursor:pointer; font-weight:700;">Reject</button>
                                        </form>
                                    <?php elseif (!empty($request['approved_workorder_id'])): ?>
                                        <a href="/sps/pages/view_workorder.php?id=<?php echo (int)$request['approved_workorder_id']; ?>" style="color:#007BFF; text-decoration:none; font-weight:700;">View Work Order #<?php echo htmlspecialchars(!empty($request['linked_workorder_number']) ? $request['linked_workorder_number'] : 'WO' . str_pad((string)(int)$request['approved_workorder_id'], 4, '0', STR_PAD_LEFT), ENT_QUOTES, 'UTF-8'); ?></a>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</div>

<?php require_once '../includes/footer.php'; ?>
