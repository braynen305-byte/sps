<?php
session_start();

// Only admin or office staff may create work orders
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true || !in_array(strtolower($_SESSION['role'] ?? ''), ['admin', 'office'])) {
    header('Location: /sps/login.php');
    exit;
}

require_once '../includes/dbh.inc.php';

// ensure priority column exists
try {
    $conn->exec("ALTER TABLE workorders ADD COLUMN IF NOT EXISTS `priority` VARCHAR(20) DEFAULT 'Normal'");
} catch (Exception $ex) {
    // ignore
}
// ensure order_number column exists (optional manual number)
try {
    $conn->exec("ALTER TABLE workorders ADD COLUMN IF NOT EXISTS `order_number` VARCHAR(100) DEFAULT NULL");
} catch (Exception $ex) {
    // ignore
}

function normalizeWorkOrderNumber($value) {
    $raw = trim((string)($value ?? ''));
    if ($raw === '') {
        return null;
    }

    $withoutPrefix = preg_replace('/^WO/i', '', $raw);
    $digitsOnly = preg_replace('/\D+/', '', $withoutPrefix ?? '');
    if ($digitsOnly === '') {
        return null;
    }

    $numeric = (int)$digitsOnly;
    return 'WO' . str_pad((string)$numeric, 4, '0', STR_PAD_LEFT);
}

$title = 'Create Work Order';
require_once '../includes/header.php';

$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $customerId = (int)($_POST['customer_id'] ?? 0);
    $clientName = trim($_POST['client_name'] ?? '');
    $clientPhone = trim($_POST['client_phone'] ?? '');
    $location = trim($_POST['location'] ?? '');
    $orderDate = $_POST['order_date'] ?? '';
    $expectedStartDate = $_POST['expected_start_date'] ?? '';
    $expectedEndDate = $_POST['expected_end_date'] ?? '';
    $requestedWork = trim($_POST['requested_work'] ?? '');
    $additionalComments = trim($_POST['additional_comments'] ?? '');
    $workDescription = trim($_POST['work_description'] ?? '');
    $vesselVin = trim($_POST['vessel_vin'] ?? '');
    $vesselHours = trim($_POST['vessel_hours'] ?? '');
    $laborTime = trim($_POST['labor_time'] ?? '');
    $partsCost = trim($_POST['parts_cost'] ?? '');
    $chargeableTo = trim($_POST['chargeable_to'] ?? '');
    $orderReceivedBy = $_POST['order_received_by'] ?? $_SESSION['user_id'];
    $workPerformedBy = $_POST['work_performed_by'] ?? '';
    $priority = $_POST['priority'] ?? 'Normal';
    $orderNumber = normalizeWorkOrderNumber($_POST['order_number'] ?? '');
    $permissionAnytime = isset($_POST['permission_anytime']) ? 1 : 0;
    $permissionDate = $_POST['permission_date'] ?? '';
    $permissionTime = $_POST['permission_time'] ?? '';
    $entryDate = $_POST['entry_date'] ?? '';
    $timeEntered = $_POST['time_entered'] ?? '';
    $timeDeparted = $_POST['time_departed'] ?? '';

    if ($clientName === '' || $location === '' || $orderDate === '') {
        $message = 'Please fill in required fields: Client Name, Location, and Order Date.';
        $messageType = 'error';
    } else {
        try {
            $stmt = $conn->prepare('
                INSERT INTO workorders (
                    customer_id, client_name, client_phone, location, order_date, 
                    expected_start_date, expected_end_date, requested_work, 
                    additional_comments, work_description, vessel_vin, vessel_hours, labor_time, 
                    parts_cost, chargeable_to, order_received_by, work_performed_by,
                    permission_anytime, permission_date, permission_time,
                    entry_date, time_entered, time_departed, priority, order_number, created_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
            ');
            
                if (!empty($workPerformedBy)) {
                    $techCheck = $conn->prepare('SELECT id, role FROM staff WHERE id = ? LIMIT 1');
                    $techCheck->execute([(int)$workPerformedBy]);
                    $techRow = $techCheck->fetch(PDO::FETCH_ASSOC);
                    $techRole = strtolower(trim((string)($techRow['role'] ?? '')));
                    if (!$techRow || !in_array($techRole, ['technician', 'staff', ''], true)) {
                        $workPerformedBy = null;
                    }
                }

                $result = $stmt->execute([
                $customerId ?: null, $clientName, $clientPhone, $location, $orderDate,
                $expectedStartDate ?: null, $expectedEndDate ?: null, $requestedWork,
                $additionalComments, $workDescription, $vesselVin, $vesselHours, $laborTime,
                $partsCost ?: null, $chargeableTo, $orderReceivedBy, $workPerformedBy ?: null,
                $permissionAnytime, $permissionDate ?: null, $permissionTime ?: null,
                $entryDate ?: null, $timeEntered ?: null, $timeDeparted ?: null, $priority ?: 'Normal', $orderNumber
            ]);

                if ($result) {
                $workOrderId = (int)$conn->lastInsertId();
                try {
                    $conn->exec("CREATE TABLE IF NOT EXISTS workorder_edits (
                        id INT AUTO_INCREMENT PRIMARY KEY,
                        workorder_id INT NOT NULL,
                        field_name VARCHAR(255) NOT NULL,
                        old_value LONGTEXT,
                        new_value LONGTEXT,
                        edited_by INT,
                        edited_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                        INDEX(workorder_id)
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
                    $creatorId = (int)($_POST['order_received_by'] ?? $_SESSION['user_id'] ?? 0);
                    $historyStmt = $conn->prepare('INSERT INTO workorder_edits (workorder_id, field_name, old_value, new_value, edited_by) VALUES (?, ?, ?, ?, ?)');
                    $historyStmt->execute([$workOrderId, 'order_received_by', null, (string)$creatorId, $creatorId]);
                    if (!empty($workPerformedBy) && (int)$workPerformedBy > 0) {
                        $historyStmt->execute([$workOrderId, 'work_performed_by', null, (string)$workPerformedBy, $creatorId]);
                    }
                } catch (Exception $historyEx) {
                    // Ignore history logging failures so the work order still creates successfully.
                }
                $message = 'Work order created successfully.';
                $messageType = 'success';
                header('Location: /sps/pages/dashboard.php');
                exit;
            } else {
                $message = 'Unable to create work order.';
                $messageType = 'error';
            }
        } catch (PDOException $e) {
            $message = 'Database error: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8');
            $messageType = 'error';
        }
    }
}

$staff = $conn->query('SELECT id, firstname, lastname, role FROM staff ORDER BY firstname, lastname')->fetchAll(PDO::FETCH_ASSOC);
$technicians = [];
foreach ($staff as $person) {
    $personRole = strtolower(trim((string)($person['role'] ?? '')));
    if ($personRole === 'admin' || $personRole === 'technician' || $personRole === 'staff' || $personRole === '') {
        $technicians[] = $person;
    }
}
$customers = $conn->query('SELECT id, name, phone, address FROM customers ORDER BY name')->fetchAll(PDO::FETCH_ASSOC);
$currentRole = strtolower($_SESSION['role'] ?? '');
$customerData = [];
foreach ($customers as $cust) {
    $customerData[(int)$cust['id']] = [
        'name' => (string)($cust['name'] ?? ''),
        'phone' => (string)($cust['phone'] ?? ''),
        'address' => (string)($cust['address'] ?? '')
    ];
}
?>

<style>
    .work-order-form {
        max-width: 900px;
        margin: 20px auto;
        border: 1px solid #ccc;
        padding: 20px;
        background-color: rgb(234, 234, 234);
        border-radius: 10px;
        box-shadow: 0 0 10px rgba(0, 0, 0, 0.1);
    }
    .form-section {
        margin-bottom: 25px;
    }
    .form-section h3 {
        background-color: #007BFF;
        padding: 10px;
        margin: 0 0 15px 0;
        font-size: 14px;
        color: white;
        border-radius: 5px;
        font-weight: bold;
    }
    .form-row {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 20px;
        margin-bottom: 15px;
    }
    .form-row.full {
        grid-template-columns: 1fr;
    }
    .form-group {
        display: flex;
        flex-direction: column;
    }
    .form-group label {
        font-weight: bold;
        margin-bottom: 5px;
        font-size: 13px;
        color: #333;
    }
    .form-group input,
    .form-group textarea,
    .form-group select {
        padding: 10px;
        border: 1px solid #ccc;
        font-size: 13px;
        font-family: Arial, sans-serif;
        border-radius: 5px;
        background-color: #fff;
    }
    .form-group input:focus,
    .form-group textarea:focus,
    .form-group select:focus {
        outline: none;
        border-color: #007BFF;
        box-shadow: 0 0 5px rgba(0, 123, 255, 0.25);
    }
    .form-group textarea {
        resize: vertical;
        min-height: 80px;
    }
    .form-row.three {
        grid-template-columns: 1fr 1fr 1fr;
    }
    .form-buttons {
        display: flex;
        gap: 15px;
        margin-top: 20px;
    }
    .form-buttons button,
    .form-buttons a {
        padding: 12px 30px;
        border: none;
        border-radius: 5px;
        cursor: pointer;
        font-size: 15px;
        font-weight: bold;
        flex: 1;
        text-align: center;
        text-decoration: none;
        display: inline-block;
    }
    .form-buttons button[type="submit"] {
        background-color: #28a745;
        color: white;
    }
    .form-buttons button[type="submit"]:hover {
        background-color: #218838;
    }
    .form-buttons .cancel-btn {
        background-color: #6c757d;
        color: white;
    }
    .form-buttons .cancel-btn:hover {
        background-color: #5a6268;
    }
    p[style*="color: green"] {
        background-color: #d4edda;
        color: #155724;
        padding: 10px;
        border-radius: 5px;
        border: 1px solid #c3e6cb;
    }
    p[style*="color: red"] {
        background-color: #f8d7da;
        color: #721c24;
        padding: 10px;
        border-radius: 5px;
        border: 1px solid #f5c6cb;
    }
</style>

<h2>Create Work Order</h2>
<p><a href="/sps/pages/dashboard.php">← Back to Dashboard</a></p>

<?php if ($message !== ''): ?>
    <p style="color: <?php echo $messageType === 'success' ? 'green' : 'red'; ?>;">
        <?php echo htmlspecialchars($message, ENT_QUOTES, 'UTF-8'); ?>
    </p>
<?php endif; ?>

<?php
$createdByName = 'You';
foreach ($staff as $s) {
    if ((int)$s['id'] === (int)($_SESSION['user_id'] ?? 0)) {
        $createdByName = trim(($s['firstname'] ?? '') . ' ' . ($s['lastname'] ?? '')) ?: 'You';
        break;
    }
}
$assignedToLabel = 'Not Assigned';
?>
<div class="work-order-meta" style="max-width:900px;margin:18px auto 0;background:#f4f8ff;border:1px solid #d6e7ff;border-radius:8px;padding:12px 16px;display:flex;gap:20px;flex-wrap:wrap;">
    <div><strong>Created By:</strong> <?php echo htmlspecialchars($createdByName, ENT_QUOTES, 'UTF-8'); ?></div>
    <div><strong>Assigned To:</strong> <?php echo htmlspecialchars($assignedToLabel, ENT_QUOTES, 'UTF-8'); ?></div>
</div>

<script>
const customerData = <?php echo json_encode($customerData, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;
function populateCustomerFields(selectedId) {
    const selected = customerData[selectedId] || null;
    const nameField = document.querySelector('input[name="client_name"]');
    const phoneField = document.querySelector('input[name="client_phone"]');
    const locationField = document.querySelector('input[name="location"]');

    if (!nameField || !phoneField || !locationField) {
        return;
    }

    if (!selected) {
        nameField.value = '';
        phoneField.value = '';
        locationField.value = '';
        return;
    }

    nameField.value = selected.name || '';
    phoneField.value = selected.phone || '';
    locationField.value = selected.address || '';
}

document.addEventListener('DOMContentLoaded', function () {
    const customerSelect = document.querySelector('select[name="customer_id"]');
    if (customerSelect) {
        customerSelect.addEventListener('change', function () {
            populateCustomerFields(this.value);
        });
    }
});
</script>

<form method="post" class="work-order-form">
    <!-- Header Section -->
    <div class="form-section">
        <h3>CLIENT INFORMATION</h3>
        <div class="form-row">
            <div class="form-group">
                <label>Select Customer</label>
                <select name="customer_id" id="customer_id_select">
                    <option value="">-- Select a Customer --</option>
                    <?php foreach ($customers as $cust): ?>
                        <option value="<?php echo (int)$cust['id']; ?>">
                            <?php echo htmlspecialchars($cust['name'], ENT_QUOTES, 'UTF-8'); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
        <div class="form-row">
            <div class="form-group">
                <label>Client Name *</label>
                <input type="text" name="client_name" readonly required>
            </div>
            <div class="form-group">
                <label>Client Phone</label>
                <input type="tel" name="client_phone" readonly>
            </div>
        </div>
        <div class="form-row">
            <div class="form-group">
                <label>Location *</label>
                <input type="text" name="location" readonly required>
            </div>
        </div>
    </div>

    <!-- Dates and Authorization -->
    <div class="form-section">
        <h3>ORDER DETAILS</h3>
        <div class="form-row">
            <div class="form-group">
                <label>Created By</label>
                <?php if ($currentRole === 'admin'): ?>
                    <select name="order_received_by">
                        <option value="">-- Select Staff --</option>
                        <?php foreach ($staff as $s): ?>
                            <option value="<?php echo (int)$s['id']; ?>" <?php echo (int)$s['id'] === (int)$_SESSION['user_id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($s['firstname'] . ' ' . $s['lastname'], ENT_QUOTES, 'UTF-8'); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                <?php else: ?>
                    <?php
                        // office staff: lock to current user
                        $me = null;
                        foreach ($staff as $s) {
                            if ((int)$s['id'] === (int)$_SESSION['user_id']) { $me = $s; break; }
                        }
                    ?>
                    <input type="hidden" name="order_received_by" value="<?php echo (int)$_SESSION['user_id']; ?>">
                    <div style="background:#fff;padding:10px;border:1px solid #dfe3e8;border-radius:4px; font-weight:600;">
                        <?php echo $me ? htmlspecialchars($me['firstname'] . ' ' . $me['lastname'], ENT_QUOTES, 'UTF-8') : 'You'; ?>
                    </div>
                <?php endif; ?>
            </div>
            <div class="form-group">
                <label>Order Date *</label>
                <input type="date" name="order_date" required>
            </div>
            <div class="form-group">
                <label>Priority</label>
                <select name="priority">
                    <?php $curPr = 'Normal'; $opts = ['Low','Normal','High']; foreach ($opts as $op): ?>
                        <option value="<?php echo htmlspecialchars($op, ENT_QUOTES, 'UTF-8'); ?>" <?php echo ($curPr === $op) ? 'selected' : ''; ?>><?php echo htmlspecialchars($op, ENT_QUOTES, 'UTF-8'); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label>Work Order Number (optional)<br>
                    <input type="text" name="order_number" placeholder="WO123 or 123">
                </label>
            </div>
        </div>
        <div class="form-row">
            <div class="form-group">
                <label>Expected Start Date</label>
                <input type="date" name="expected_start_date">
            </div>
            <div class="form-group">
                <label>Expected End Date</label>
                <input type="date" name="expected_end_date">
            </div>
        </div>
    </div>

    <!-- Permission to Enter -->
    <div class="form-section">
        <h3>PERMISSION TO ENTER SPACE</h3>
        <div class="form-row full">
            <div class="form-group">
                <label><input type="checkbox" name="permission_anytime"> Anytime</label>
            </div>
        </div>
        <div class="form-row">
            <div class="form-group">
                <label>By Appointment - Date</label>
                <input type="date" name="permission_date">
            </div>
            <div class="form-group">
                <label>Time</label>
                <input type="time" name="permission_time">
            </div>
        </div>
    </div>

    <!-- Property Entry Notice -->
    <div class="form-section">
        <h3>PROPERTY ENTRY NOTICE</h3>
        <div class="form-row">
            <div class="form-group">
                <label>Entry Date</label>
                <input type="date" name="entry_date">
            </div>
            <div class="form-group">
                <label>Time Entered</label>
                <input type="time" name="time_entered">
            </div>
        </div>
        <div class="form-row full">
            <div class="form-group">
                <label>Time Departed</label>
                <input type="time" name="time_departed">
            </div>
        </div>
    </div>

    <!-- Work Description -->
    <div class="form-section">
        <h3>WORK DESCRIPTION</h3>
        <div class="form-row full">
            <div class="form-group">
                <label>Requested Work Description</label>
                <textarea name="requested_work"></textarea>
            </div>
        </div>
        <div class="form-row full">
            <div class="form-group">
                <label>Additional Comments</label>
                <textarea name="additional_comments"></textarea>
            </div>
        </div>
    </div>

    <!-- Work Performed -->
    <div class="form-section">
        <h3>WORK ASSIGNMENT</h3>
        <div class="form-row">
            <div class="form-group">
                <label>Assigned Technician</label>
                <select name="work_performed_by">
                    <option value="">-- Select Technician --</option>
                    <?php foreach ($technicians as $tech): ?>
                        <option value="<?php echo (int)$tech['id']; ?>">
                            <?php echo htmlspecialchars($tech['firstname'] . ' ' . $tech['lastname'], ENT_QUOTES, 'UTF-8'); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php if ($currentRole !== 'office'): ?>
            <div class="form-group">
                <label>Vessel VIN #</label>
                <input type="text" name="vessel_vin">
            </div>
            <?php endif; ?>
        </div>
        <?php if ($currentRole !== 'office'): ?>
        <?php endif; ?>
    </div>

    <!-- Costs and Hours -->
    <div class="form-section">
        <h3>COSTS AND LABOR</h3>
        <div class="form-row three">
            <div class="form-group">
                <label>Vessel Hours</label>
                <input type="number" name="vessel_hours" step="0.5">
            </div>
            <div class="form-group">
                <label>Labor Time</label>
                <input type="text" name="labor_time" placeholder="e.g., 2 hours">
            </div>
            <div class="form-group">
                <label>Parts/Material Cost ($)</label>
                <input type="number" name="parts_cost" step="0.01">
            </div>
        </div>
        <div class="form-row">
            <div class="form-group">
                <label>Chargeable To</label>
                <input type="text" name="chargeable_to">
            </div>
        </div>
    </div>

    <!-- Submit -->
    <div class="form-section">
        <div class="form-buttons">
            <button type="submit">Create Work Order</button>
            <a href="/sps/pages/dashboard.php" class="cancel-btn">Cancel</a>
        </div>
    </div>
</form>

<?php require_once '../includes/footer.php'; ?>