<?php
session_start();
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true || strtolower($_SESSION['role'] ?? '') !== 'customer') {
    header('Location: /sps/customer_login.php');
    exit;
}

require_once '../includes/dbh.inc.php';
require_once '../includes/notifications.php';

try {
    $existingColumns = $conn->query("SHOW COLUMNS FROM customer_service_requests")->fetchAll(PDO::FETCH_COLUMN);
    $requiredColumns = [
        'request_number' => "ALTER TABLE customer_service_requests ADD COLUMN request_number VARCHAR(30) DEFAULT NULL",
        'customer_phone' => "ALTER TABLE customer_service_requests ADD COLUMN customer_phone VARCHAR(30) DEFAULT NULL",
        'urgency' => "ALTER TABLE customer_service_requests ADD COLUMN urgency VARCHAR(30) NOT NULL DEFAULT 'Normal'",
        'equipment_details' => "ALTER TABLE customer_service_requests ADD COLUMN equipment_details VARCHAR(255) DEFAULT NULL",
        'problem_summary' => "ALTER TABLE customer_service_requests ADD COLUMN problem_summary VARCHAR(255) DEFAULT NULL",
        'special_instructions' => "ALTER TABLE customer_service_requests ADD COLUMN special_instructions LONGTEXT DEFAULT NULL",
    ];

    foreach ($requiredColumns as $columnName => $alterSql) {
        if (!in_array($columnName, $existingColumns, true)) {
            try {
                $conn->exec($alterSql);
            } catch (Exception $e) {
                // ignore migration issues if this table is already in a compatible state
            }
        }
    }
} catch (Exception $e) {
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
}

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

$customerId = (int)($_SESSION['customer_id'] ?? 0);
$message = '';
$messageType = 'error';

$customerProfile = $conn->prepare('SELECT id, name, phone, address, city, state, zip FROM customers WHERE id = ? LIMIT 1');
$customerProfile->execute([$customerId]);
$customerProfile = $customerProfile->fetch(PDO::FETCH_ASSOC);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $serviceType = trim((string)($_POST['service_type'] ?? ''));
    $location = trim((string)($_POST['location'] ?? ''));
    $preferredDate = trim((string)($_POST['preferred_date'] ?? ''));
    $customerPhone = trim((string)($_POST['customer_phone'] ?? ''));
    $urgency = trim((string)($_POST['urgency'] ?? 'Normal'));
    $equipmentDetails = trim((string)($_POST['equipment_details'] ?? ''));
    $problemSummary = trim((string)($_POST['problem_summary'] ?? ''));
    $description = trim((string)($_POST['description'] ?? ''));
    $specialInstructions = trim((string)($_POST['special_instructions'] ?? ''));

    if ($serviceType === '' || $location === '' || $problemSummary === '' || $description === '') {
        $message = 'Please provide the service type, service location, brief problem summary, and detailed description of the issue.';
    } else {
        $sameRequestFingerprint = md5(implode('|', [
            (string)$customerId,
            strtolower(trim((string)$serviceType)),
            strtolower(trim((string)$location)),
            strtolower(trim((string)$preferredDate)),
            strtolower(trim((string)$problemSummary)),
            strtolower(trim((string)$description)),
        ]));

        $existingRequest = $conn->prepare('SELECT id, status FROM customer_service_requests WHERE customer_id = ? AND status IN (\'Pending\', \'Accepted\') AND service_type = ? AND location = ? AND COALESCE(problem_summary, \'\') = ? AND COALESCE(description, \'\') = ? LIMIT 1');
        $existingRequest->execute([
            $customerId,
            $serviceType,
            $location,
            $problemSummary,
            $description,
        ]);
        $duplicateRequest = $existingRequest->fetch(PDO::FETCH_ASSOC);

        if ($duplicateRequest) {
            $message = 'This looks like the same request already on file. Please use your dashboard to track it or submit a different request if you truly need a new one.';
            $messageType = 'error';
        } else {
        $schemaColumns = $conn->query("SHOW COLUMNS FROM customer_service_requests")->fetchAll(PDO::FETCH_COLUMN);
        $insertFields = ['customer_id', 'service_type', 'location', 'preferred_date'];
        $insertValues = [$customerId, $serviceType, $location, $preferredDate !== '' ? $preferredDate : null];

        if (in_array('customer_phone', $schemaColumns, true)) {
            $insertFields[] = 'customer_phone';
            $insertValues[] = $customerPhone !== '' ? $customerPhone : ($customerProfile['phone'] ?? null);
        }
        if (in_array('urgency', $schemaColumns, true)) {
            $insertFields[] = 'urgency';
            $insertValues[] = $urgency !== '' ? $urgency : 'Normal';
        }
        if (in_array('equipment_details', $schemaColumns, true)) {
            $insertFields[] = 'equipment_details';
            $insertValues[] = $equipmentDetails !== '' ? $equipmentDetails : null;
        }
        if (in_array('problem_summary', $schemaColumns, true)) {
            $insertFields[] = 'problem_summary';
            $insertValues[] = $problemSummary;
        }
        if (in_array('description', $schemaColumns, true)) {
            $insertFields[] = 'description';
            $insertValues[] = $description;
        }
        if (in_array('special_instructions', $schemaColumns, true)) {
            $insertFields[] = 'special_instructions';
            $insertValues[] = $specialInstructions !== '' ? $specialInstructions : null;
        }
        if (in_array('status', $schemaColumns, true)) {
            $insertFields[] = 'status';
            $insertValues[] = 'Pending';
        }
        $insertFields[] = 'created_at';
        $insertValues[] = date('Y-m-d H:i:s');

        $stmt = $conn->prepare('INSERT INTO customer_service_requests (' . implode(', ', $insertFields) . ') VALUES (' . implode(', ', array_fill(0, count($insertFields), '?')) . ')');
        $success = $stmt->execute($insertValues);

            if ($success) {
                $requestId = (int)$conn->lastInsertId();
                $requestLabel = 'SR-' . str_pad((string)$requestId, 5, '0', STR_PAD_LEFT);

                if (in_array('request_number', $schemaColumns, true)) {
                    $updateRequestNumber = $conn->prepare('UPDATE customer_service_requests SET request_number = ? WHERE id = ?');
                    $updateRequestNumber->execute([$requestLabel, $requestId]);
                }

                $requestMessage = 'A new customer service request was submitted by ' . htmlspecialchars($customerProfile['name'] ?? 'Customer', ENT_QUOTES, 'UTF-8') . '. Review and approve the request in the service queue.';
                notify_staff_roles(
                    $conn,
                    ['admin', 'office'],
                    'new_service_request',
                    'New service request: ' . $requestLabel,
                    $requestMessage,
                    '/sps/pages/manage_service_requests.php'
                );
                header('Location: /sps/pages/customer_dashboard.php?request_submitted=1');
                exit;
            }

            $message = 'Unable to submit your service request right now. Please try again.';
        }
    }
}

$title = 'Request New Service';
require_once '../includes/header.php';
?>

<div style="max-width: 760px; margin: 40px auto 48px; padding: 0 18px;">
    <div style="background:#fff; border:1px solid #e5e7eb; border-radius:12px; box-shadow:0 1px 12px rgba(0,0,0,0.05); padding:24px;">
        <h2 style="margin-top:0; margin-bottom:8px;">Request a New Service</h2>
        <p style="margin-top:0; color:#475569;">Tell us what you need, and our office team will review it and convert it into a work order when approved.</p>

        <?php if ($message !== ''): ?>
            <div style="background:#fee2e2; color:#991b1b; border:1px solid #fecaca; border-radius:8px; padding:12px 14px; margin-bottom:18px; font-weight:700;">
                <?php echo htmlspecialchars($message, ENT_QUOTES, 'UTF-8'); ?>
            </div>
        <?php endif; ?>

        <form method="post" action="/sps/pages/customer_request_job.php">
            <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap:16px;">
                <div>
                    <label for="service_type" style="display:block; font-weight:700; margin-bottom:6px; color:#334155;">Service Type</label>
                    <select name="service_type" id="service_type" required style="width:100%; padding:10px 12px; border:1px solid #cbd5e1; border-radius:8px; box-sizing:border-box;">
                        <option value="">Select a service</option>
                        <option value="Repair">Repair</option>
                        <option value="Inspection">Inspection</option>
                        <option value="Maintenance">Maintenance</option>
                        <option value="Installation">Installation</option>
                        <option value="Service Call">Service Call</option>
                        <option value="Other">Other</option>
                    </select>
                </div>

                <div>
                    <label for="urgency" style="display:block; font-weight:700; margin-bottom:6px; color:#334155;">Urgency</label>
                    <select name="urgency" id="urgency" style="width:100%; padding:10px 12px; border:1px solid #cbd5e1; border-radius:8px; box-sizing:border-box;">
                        <option value="Normal">Normal</option>
                        <option value="Urgent">Urgent</option>
                        <option value="Emergency">Emergency</option>
                    </select>
                </div>

                <div>
                    <label for="preferred_date" style="display:block; font-weight:700; margin-bottom:6px; color:#334155;">Preferred Date</label>
                    <input type="date" name="preferred_date" id="preferred_date" style="width:100%; padding:10px 12px; border:1px solid #cbd5e1; border-radius:8px; box-sizing:border-box;">
                </div>
            </div>

            <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap:16px; margin-top:16px;">
                <div>
                    <label for="customer_phone" style="display:block; font-weight:700; margin-bottom:6px; color:#334155;">Best Contact Phone</label>
                    <input type="tel" name="customer_phone" id="customer_phone" value="<?php echo htmlspecialchars($customerProfile['phone'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" style="width:100%; padding:10px 12px; border:1px solid #cbd5e1; border-radius:8px; box-sizing:border-box;">
                </div>

                <div>
                    <label for="equipment_details" style="display:block; font-weight:700; margin-bottom:6px; color:#334155;">Equipment / Asset Details</label>
                    <input type="text" name="equipment_details" id="equipment_details" placeholder="Boat, motor, unit, model, or equipment name" style="width:100%; padding:10px 12px; border:1px solid #cbd5e1; border-radius:8px; box-sizing:border-box;">
                </div>
            </div>

            <div style="margin-top:16px;">
                <label for="location" style="display:block; font-weight:700; margin-bottom:6px; color:#334155;">Service Location</label>
                <input type="text" name="location" id="location" value="<?php echo htmlspecialchars($customerProfile['address'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" required style="width:100%; padding:10px 12px; border:1px solid #cbd5e1; border-radius:8px; box-sizing:border-box;">
            </div>

            <div style="margin-top:16px;">
                <label for="problem_summary" style="display:block; font-weight:700; margin-bottom:6px; color:#334155;">Problem Summary</label>
                <input type="text" name="problem_summary" id="problem_summary" placeholder="Example: Engine won't start and fuel system is acting up" required style="width:100%; padding:10px 12px; border:1px solid #cbd5e1; border-radius:8px; box-sizing:border-box;">
            </div>

            <div style="margin-top:16px;">
                <label for="description" style="display:block; font-weight:700; margin-bottom:6px; color:#334155;">Detailed Problem Description</label>
                <textarea name="description" id="description" rows="6" required placeholder="Please describe what is happening, when it started, symptoms, noises, errors, and any relevant details we need to know before the service call." style="width:100%; resize:vertical; padding:10px 12px; border:1px solid #cbd5e1; border-radius:8px; box-sizing:border-box;"></textarea>
            </div>

            <div style="margin-top:16px;">
                <label for="special_instructions" style="display:block; font-weight:700; margin-bottom:6px; color:#334155;">Special Instructions / Access Notes</label>
                <textarea name="special_instructions" id="special_instructions" rows="4" placeholder="Gate code, access instructions, preferred arrival times, jobsite notes, or anything else the team should know." style="width:100%; resize:vertical; padding:10px 12px; border:1px solid #cbd5e1; border-radius:8px; box-sizing:border-box;"></textarea>
            </div>

            <div style="margin-top:20px; display:flex; gap:12px; flex-wrap:wrap;">
                <button type="submit" style="background:#007BFF; color:#fff; border:none; border-radius:8px; padding:12px 20px; font-weight:700; cursor:pointer;">Submit Request</button>
                <a href="/sps/pages/customer_dashboard.php" style="display:inline-block; background:#6b7280; color:#fff; text-decoration:none; border-radius:8px; padding:12px 20px; font-weight:700;">Back to Dashboard</a>
            </div>
        </form>
    </div>
</div>

<?php require_once '../includes/footer.php'; ?>
