<?php
session_start();

// Only admin or office staff may create work orders
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true || !in_array(strtolower($_SESSION['role'] ?? ''), ['admin', 'office'])) {
    header('Location: /sps/login.php');
    exit;
}

require_once '../includes/dbh.inc.php';
require_once '../includes/customer_assets.inc.php';
require_once '../includes/workorder_technicians.inc.php';
require_once '../includes/notifications.php';
ensure_customer_asset_schema($conn);
ensure_workorder_technicians_schema($conn);

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// ensure priority column exists
try {
    $conn->exec("ALTER TABLE workorders ADD COLUMN IF NOT EXISTS `priority` VARCHAR(20) DEFAULT 'Normal'");
} catch (Exception $ex) {
    // ignore
}
// ensure order_number column exists (optional manual number)
try {
    $conn->exec("ALTER TABLE workorders ADD COLUMN IF NOT EXISTS `order_number` VARCHAR(100) DEFAULT NULL");
    $conn->exec("ALTER TABLE workorders ADD COLUMN IF NOT EXISTS `service_type` VARCHAR(100) DEFAULT NULL");
    $conn->exec("ALTER TABLE workorders ADD COLUMN IF NOT EXISTS `equipment_details` VARCHAR(255) DEFAULT NULL");
    $conn->exec("ALTER TABLE workorders ADD COLUMN IF NOT EXISTS `work_location` VARCHAR(30) DEFAULT NULL");
    $conn->exec("ALTER TABLE workorders ADD COLUMN IF NOT EXISTS `service_call_fee` DECIMAL(10,2) NOT NULL DEFAULT 0");
    $conn->exec("ALTER TABLE workorders ADD COLUMN IF NOT EXISTS `special_instructions` LONGTEXT DEFAULT NULL");
    $conn->exec("ALTER TABLE workorders ADD COLUMN IF NOT EXISTS `permission_time_relation` VARCHAR(10) DEFAULT NULL");
    $conn->exec("ALTER TABLE workorders ADD COLUMN IF NOT EXISTS `permission_anytime_with_time` TINYINT(1) NOT NULL DEFAULT 0");
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

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !hash_equals($_SESSION['csrf_token'], (string)($_POST['csrf_token'] ?? ''))) {
    $message = 'Your session could not be verified. Please reload the form and try again.';
    $messageType = 'error';
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $customerId = (int)($_POST['customer_id'] ?? 0);
    $assetId = (int)($_POST['asset_id'] ?? 0);
    $clientName = trim($_POST['client_name'] ?? '');
    $clientPhone = trim($_POST['client_phone'] ?? '');
    $location = trim($_POST['location'] ?? '');
    $workLocation = trim((string)($_POST['work_location'] ?? 'customer_property'));
    $orderDate = $_POST['order_date'] ?? '';
    $serviceType = trim($_POST['service_type'] ?? '');
    $serviceCallFee = ($serviceType === 'Service Call' && $workLocation === 'customer_property') ? '75.00' : '0.00';
    $equipmentDetails = trim($_POST['equipment_details'] ?? '');
    $specialInstructions = trim($_POST['special_instructions'] ?? '');
    $expectedStartDate = $_POST['expected_start_date'] ?? '';
    $expectedEndDate = $_POST['expected_end_date'] ?? '';
    $requestedWork = trim($_POST['requested_work'] ?? '');
    $additionalComments = trim($_POST['additional_comments'] ?? '');
    $workDescription = trim($_POST['work_description'] ?? '');
    $vesselVin = trim($_POST['vessel_vin'] ?? '');
    $vesselHours = trim($_POST['vessel_hours'] ?? '');
    $laborTime = '0h 00m';
    $partsCost = trim($_POST['parts_cost'] ?? '');
    $chargeableTo = trim($_POST['chargeable_to'] ?? '');
    $orderReceivedBy = $_POST['order_received_by'] ?? $_SESSION['user_id'];
    $workPerformedBy = $_POST['work_performed_by'] ?? '';
    $additionalTechnicianIds = array_values(array_unique(array_filter(array_map('intval', (array)($_POST['additional_technicians'] ?? [])))));
    $priority = $_POST['priority'] ?? 'Normal';
    $orderNumber = normalizeWorkOrderNumber($_POST['order_number'] ?? '');
    $permissionAnytime = isset($_POST['permission_anytime']) ? 1 : 0;
    $permissionDate = $_POST['permission_date'] ?? '';
    $permissionTime = $_POST['permission_time'] ?? '';
    $permissionTimeChoice = ($_POST['permission_time_choice'] ?? 'no') === 'yes';
    if (!$permissionTimeChoice || trim((string)$permissionTime) === '') {
        $permissionTime = '';
    }
    if ($permissionAnytime) {
        $permissionDate = '';
    }
    $permissionAnytimeWithTime = ($permissionAnytime && $permissionTimeChoice && trim((string)$permissionTime) !== '') ? 1 : 0;
    $permissionTimeRelation = strtolower(trim((string)($_POST['permission_time_relation'] ?? '')));
    if (!in_array($permissionTimeRelation, ['at', 'before', 'after'], true)) {
        $permissionTimeRelation = '';
    }
    if (trim((string)$permissionTime) === '' || !$permissionTimeChoice) {
        $permissionTimeRelation = '';
    }

    $selectedAsset = null;
    if ($assetId > 0 && $customerId > 0) {
        $assetStmt = $conn->prepare('SELECT * FROM customer_assets WHERE id = ? AND customer_id = ? LIMIT 1');
        $assetStmt->execute([$assetId, $customerId]);
        $selectedAsset = $assetStmt->fetch(PDO::FETCH_ASSOC);
        if ($selectedAsset) {
            $equipmentDetails = customer_asset_name_label($selectedAsset);
            $vesselVin = trim((string)($selectedAsset['serial_number'] ?? '')) ?: $vesselVin;
            $vesselHours = ($selectedAsset['hours'] !== null && $selectedAsset['hours'] !== '') ? (string)$selectedAsset['hours'] : $vesselHours;
        }
    }

    $validAdditionalTechnicians = true;
    foreach ($additionalTechnicianIds as $additionalTechnicianId) {
        if ($additionalTechnicianId <= 0 || $additionalTechnicianId === (int)$workPerformedBy) {
            continue;
        }
        $additionalTechnicianCheck = $conn->prepare("SELECT id FROM staff WHERE id = ? AND LOWER(TRIM(COALESCE(role, ''))) IN ('admin', 'technician', 'staff', '') LIMIT 1");
        $additionalTechnicianCheck->execute([$additionalTechnicianId]);
        if (!$additionalTechnicianCheck->fetchColumn()) {
            $validAdditionalTechnicians = false;
            break;
        }
    }

    if ($clientName === '' || $location === '' || $orderDate === '') {
        $message = 'Please fill in required fields: Client Name, Location, and Order Date.';
        $messageType = 'error';
    } elseif (!in_array($workLocation, ['shop', 'customer_property'], true)) {
        $message = 'Select where the work will be performed.';
        $messageType = 'error';
    } elseif (!$validAdditionalTechnicians) {
        $message = 'Select only valid staff members as additional technicians.';
        $messageType = 'error';
    } elseif ($assetId > 0 && !$selectedAsset) {
        $message = 'The selected asset does not belong to the chosen customer.';
        $messageType = 'error';
    } elseif (!in_array((string)($_POST['permission_time_choice'] ?? ''), ['yes', 'no'], true)) {
        $message = 'Please indicate whether a permission time should be set.';
        $messageType = 'error';
    } elseif ($permissionTimeChoice && trim((string)($_POST['permission_time'] ?? '')) === '') {
        $message = 'Please enter the permission time, or choose “No specific time.”';
        $messageType = 'error';
    } else {
        try {
            $stmt = $conn->prepare('

                INSERT INTO workorders (
                    customer_id, asset_id, client_name, client_phone, location, order_date,
                    work_location, service_call_fee, service_type, equipment_details, special_instructions,
                    expected_start_date, expected_end_date, requested_work, 
                    additional_comments, work_description, vessel_vin, vessel_hours, labor_time, 
                    parts_cost, chargeable_to, order_received_by, work_performed_by,
                    permission_anytime, permission_anytime_with_time, permission_date, permission_time,
                    permission_time_relation, priority, order_number, created_at
                ) VALUES (
                    ?, ?, ?, ?, ?, ?, ?, ?, ?, ?,
                    ?, ?, ?, ?, ?, ?, ?, ?, ?, ?,
                    ?, ?, ?, ?, ?, ?, ?, ?, ?, ?,
                    NOW()
                )
            ');
            
                if (!empty($workPerformedBy)) {
                    $techCheck = $conn->prepare('SELECT id, role FROM staff WHERE id = ? LIMIT 1');
                    $techCheck->execute([(int)$workPerformedBy]);
                    $techRow = $techCheck->fetch(PDO::FETCH_ASSOC);
                    $techRole = strtolower(trim((string)($techRow['role'] ?? '')));
                    if (!$techRow || !in_array($techRole, ['admin', 'technician', 'staff', ''], true)) {
                        $workPerformedBy = null;
                    }
                }

                $result = $stmt->execute([
                $customerId ?: null, $selectedAsset ? (int)$selectedAsset['id'] : null, $clientName, $clientPhone, $location, $orderDate,
                $workLocation, $serviceCallFee, $serviceType, $equipmentDetails, $specialInstructions,
                $expectedStartDate ?: null, $expectedEndDate ?: null, $requestedWork,
                $additionalComments, $workDescription, $vesselVin, $vesselHours, $laborTime,
                $partsCost ?: null, $chargeableTo, $orderReceivedBy, $workPerformedBy ?: null,
                $permissionAnytime, $permissionAnytimeWithTime, $permissionDate ?: null, $permissionTime ?: null,
                $permissionTimeRelation ?: null, $priority ?: 'Normal', $orderNumber
            ]);

                if ($result) {
                $workOrderId = (int)$conn->lastInsertId();
                    if (!empty($workPerformedBy)) {
                        sync_primary_workorder_technician($conn, $workOrderId, $workPerformedBy);
                    }
                    $saveAdditionalTechnician = $conn->prepare('INSERT IGNORE INTO workorder_technicians (workorder_id, technician_id, assigned_by) VALUES (?, ?, ?)');
                    foreach ($additionalTechnicianIds as $additionalTechnicianId) {
                        if ($additionalTechnicianId <= 0 || $additionalTechnicianId === (int)$workPerformedBy) {
                            continue;
                        }
                        $saveAdditionalTechnician->execute([$workOrderId, $additionalTechnicianId, (int)($_SESSION['user_id'] ?? 0) ?: null]);
                    }
                    $assignedTechnicianIds = array_values(array_unique(array_filter(array_merge(
                        [(int)$workPerformedBy],
                        $additionalTechnicianIds
                    ))));
                    $workOrderLabel = $orderNumber ?: ('WO' . str_pad((string)$workOrderId, 4, '0', STR_PAD_LEFT));
                    foreach ($assignedTechnicianIds as $assignedTechnicianId) {
                        try {
                            notify_assigned_technician($conn, $workOrderId, $assignedTechnicianId, $workOrderLabel);
                        } catch (Throwable $notificationError) {
                            error_log('Work-order assignment notification failed: ' . $notificationError->getMessage());
                        }
                    }
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
$allCustomerAssets = $conn->query('SELECT * FROM customer_assets ORDER BY customer_id, asset_name, id')->fetchAll(PDO::FETCH_ASSOC);
$currentRole = strtolower($_SESSION['role'] ?? '');
$customerData = [];
foreach ($customers as $cust) {
    $assetsForCustomer = [];
    foreach ($allCustomerAssets as $asset) {
        if ((int)$asset['customer_id'] === (int)$cust['id']) {
            $assetsForCustomer[] = [
                'id' => (int)$asset['id'],
                'label' => customer_asset_selection_label($asset),
                'details' => customer_asset_name_label($asset),
                'serial' => (string)($asset['serial_number'] ?? ''),
                'hours' => (string)($asset['hours'] ?? '')
            ];
        }
    }
    $customerData[(int)$cust['id']] = [
        'name' => (string)($cust['name'] ?? ''),
        'phone' => (string)($cust['phone'] ?? ''),
        'address' => (string)($cust['address'] ?? ''),
        'assets' => $assetsForCustomer
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
    const assetSelect = document.getElementById('customer_asset_id');

    if (!nameField || !phoneField || !locationField) {
        return;
    }

    if (!selected) {
        nameField.value = '';
        phoneField.value = '';
        locationField.value = '';
        const equipmentField = document.getElementById('equipment_details');
        const serialField = document.querySelector('input[name="vessel_vin"]');
        const hoursField = document.querySelector('input[name="vessel_hours"]');
        if (equipmentField) equipmentField.value = '';
        if (serialField) serialField.value = '';
        if (hoursField) hoursField.value = '';
        if (assetSelect) assetSelect.innerHTML = '<option value="">-- Select a customer first --</option>';
        return;
    }

    nameField.value = selected.name || '';
    phoneField.value = selected.phone || '';
    locationField.value = selected.address || '';
    const equipmentField = document.getElementById('equipment_details');
    const serialField = document.querySelector('input[name="vessel_vin"]');
    const hoursField = document.querySelector('input[name="vessel_hours"]');
    if (equipmentField) equipmentField.value = '';
    if (serialField) serialField.value = '';
    if (hoursField) hoursField.value = '';
    if (assetSelect) {
        assetSelect.innerHTML = '<option value="">-- Enter details manually / None --</option>';
        (selected.assets || []).forEach(function (asset) {
            const option = document.createElement('option');
            option.value = String(asset.id);
            option.textContent = asset.label;
            option.dataset.details = asset.details;
            option.dataset.serial = asset.serial;
            option.dataset.hours = asset.hours;
            assetSelect.appendChild(option);
        });
        assetSelect.value = '';
    }
}

document.addEventListener('DOMContentLoaded', function () {
    const customerSelect = document.querySelector('select[name="customer_id"]');
    const assetSelect = document.getElementById('customer_asset_id');
    if (customerSelect) {
        customerSelect.addEventListener('change', function () {
            populateCustomerFields(this.value);
        });
    }
    if (assetSelect) {
        assetSelect.addEventListener('change', function () {
            const option = this.options[this.selectedIndex];
            const equipmentField = document.getElementById('equipment_details');
            const serialField = document.querySelector('input[name="vessel_vin"]');
            const hoursField = document.querySelector('input[name="vessel_hours"]');
            if (equipmentField) equipmentField.value = this.value && option ? (option.dataset.details || '') : '';
            if (serialField) serialField.value = this.value && option ? (option.dataset.serial || '') : '';
            if (hoursField) hoursField.value = this.value && option ? (option.dataset.hours || '') : '';
        });
    }
});
</script>

<form method="post" class="work-order-form">
    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8'); ?>">
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
        <div class="form-row three">
            <div class="form-group">
                <label for="service_type" style="display:block; font-weight:700; margin-bottom:6px; color:#334155;">Service Type</label>
                    <select name="service_type" id="service_type" required style="width:100%; padding:10px 12px; border:1px solid #cbd5e1; border-radius:8px; box-sizing:border-box;">
                        <?php foreach (['Repair','Inspection','Maintenance','Installation','Service Call','Other'] as $opt): ?>
                            <option value="<?php echo htmlspecialchars($opt, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($opt, ENT_QUOTES, 'UTF-8'); ?></option>
                        <?php endforeach; ?>
                    </select>
            </div>
            <div class="form-group">
                <label for="work_location">Work Performed At</label>
                <select name="work_location" id="work_location">
                    <option value="customer_property" selected>Customer Property</option>
                    <option value="shop">Our Shop</option>
                </select>
            </div>
            <div class="form-group">
                <label>Customer Vessel / Asset</label>
                <select name="asset_id" id="customer_asset_id">
                    <option value="">-- Select a customer first --</option>
                </select>
                <small><a href="/sps/pages/manage_customers.php">Manage customer records</a></small>
                <label style="margin-top:8px;">Asset Details (manual / unsaved)<br>
                    <input type="text" name="equipment_details" id="equipment_details" placeholder="Boat, motor, unit, model or equipment">
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
        <h3>PROPERTY ENTRY PERMISSIONS / LOGS</h3>
        <p style="margin:0 0 14px; color:#64748b;">Set access permission here. Record actual entry and departure times later in the work order's Property Entry Permissions / Logs section.</p>
        <div class="form-row full">
            <div class="form-group">
                <label><input type="checkbox" name="permission_anytime" id="permission_anytime"> Permission Anytime (any date)</label>
            </div>
        </div>
        <div class="form-row">
            <div class="form-group">
                <label>By Appointment - Date</label>
                <input type="date" name="permission_date" id="permission_date">
            </div>
        </div>
        <div class="form-row full">
            <div class="form-group">
                <label for="permission_time_choice" id="permission-time-question">Would you like to specify a permission time?</label>
                <select name="permission_time_choice" id="permission_time_choice" required>
                    <option value="" selected>-- Choose Yes or No --</option>
                    <option value="no">No specific time</option>
                    <option value="yes">Yes, specify a time</option>
                </select>
            </div>
        </div>
        <div class="form-row" id="permission-time-details" style="display:none;">
            <div class="form-group">
                <label for="permission_time">Permission Time</label>
                <input type="time" name="permission_time" id="permission_time">
            </div>
            <div class="form-group">
                <label for="permission_time_relation">Timing</label>
                <select name="permission_time_relation" id="permission_time_relation">
                    <option value="at">At the set time</option>
                    <option value="before">Before the set time</option>
                    <option value="after">After the set time</option>
                </select>
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
        <div class="form-row full">
    <div class="form-group">
        <label>Special Instructions</label>
        <textarea name="special_instructions" placeholder="Access notes, gate codes, customer instructions, etc."></textarea>
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
            <div class="form-group">
                <label>Additional Technicians (optional)</label>
                <select name="additional_technicians[]" multiple size="4" style="min-height:96px;">
                    <?php foreach ($technicians as $tech): ?>
                        <?php if ((int)$tech['id'] !== (int)($_POST['work_performed_by'] ?? 0)): ?>
                            <option value="<?php echo (int)$tech['id']; ?>" <?php echo in_array((int)$tech['id'], array_map('intval', (array)($_POST['additional_technicians'] ?? [])), true) ? 'selected' : ''; ?>><?php echo htmlspecialchars($tech['firstname'] . ' ' . $tech['lastname'], ENT_QUOTES, 'UTF-8'); ?></option>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </select>
                <small>Select multiple technicians with Ctrl-click (Windows) or Command-click (Mac).</small>
            </div>
            <?php if ($currentRole !== 'office'): ?>
            <div class="form-group">
                <label>Vessel(Unit) identification #</label>
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
        <div class="form-row">
            <div class="form-group">
                <label>Vessel Hours</label>
                <input type="number" name="vessel_hours" step="0.5">
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

<script>
document.addEventListener('DOMContentLoaded', function () {
    const anytime = document.getElementById('permission_anytime');
    const dateField = document.getElementById('permission_date');
    const dateFieldGroup = dateField ? dateField.closest('.form-group') : null;
    const timeChoice = document.getElementById('permission_time_choice');
    const timeQuestion = document.getElementById('permission-time-question');
    const timeDetails = document.getElementById('permission-time-details');
    const permissionTime = document.getElementById('permission_time');
    const timeRelation = document.getElementById('permission_time_relation');

    function updatePermissionTimeFields() {
        const isAnytime = anytime && anytime.checked;
        const wantsTime = timeChoice && timeChoice.value === 'yes';
        if (dateFieldGroup) {
            dateFieldGroup.style.display = isAnytime ? 'none' : '';
            if (isAnytime) dateField.value = '';
        }
        if (timeQuestion) {
            timeQuestion.textContent = isAnytime
                ? 'Would you like to include a specific time with Anytime permission?'
                : 'Would you like to specify a permission time?';
        }
        if (timeDetails) timeDetails.style.display = wantsTime ? '' : 'none';
        if (permissionTime) permissionTime.required = wantsTime;
        if (!wantsTime) {
            if (permissionTime) permissionTime.value = '';
            if (timeRelation) timeRelation.value = 'at';
        }
    }

    if (anytime) anytime.addEventListener('change', updatePermissionTimeFields);
    if (timeChoice) timeChoice.addEventListener('change', updatePermissionTimeFields);
    updatePermissionTimeFields();
});
</script>

<?php require_once '../includes/footer.php'; ?>