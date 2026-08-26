<?php
// Redirect to central dashboard for backward compatibility / avoid admin-only page
header('Location: /sps/pages/dashboard.php');
exit;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
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
                    client_name, client_phone, location, order_date, 
                    expected_start_date, expected_end_date, requested_work, 
                    additional_comments, work_description, vessel_vin, vessel_hours, labor_time, 
                    parts_cost, chargeable_to, order_received_by, work_performed_by,
                    permission_anytime, permission_date, permission_time,
                    entry_date, time_entered, time_departed, created_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
            ');
            
            $result = $stmt->execute([
                $clientName, $clientPhone, $location, $orderDate,
                $expectedStartDate ?: null, $expectedEndDate ?: null, $requestedWork,
                $additionalComments, $workDescription, $vesselVin, $vesselHours, $laborTime,
                $partsCost ?: null, $chargeableTo, $orderReceivedBy, $workPerformedBy ?: null,
                $permissionAnytime, $permissionDate ?: null, $permissionTime ?: null,
                $entryDate ?: null, $timeEntered ?: null, $timeDeparted ?: null
            ]);

            if ($result) {
                $message = 'Work order created successfully.';
                $messageType = 'success';
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

$staff = $conn->query('SELECT id, firstname, lastname FROM staff ORDER BY firstname, lastname')->fetchAll(PDO::FETCH_ASSOC);
?>

<h2>Create Work Order</h2>
<p><a href="/sps/pages/admin_dashboard.php">Back to dashboard</a></p>

<?php if ($message !== ''): ?>
    <p style="color: <?php echo $messageType === 'success' ? 'green' : 'red'; ?>;">
        <?php echo htmlspecialchars($message, ENT_QUOTES, 'UTF-8'); ?>
    </p>
<?php endif; ?>

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
    button {
        background-color: #007BFF;
        color: white;
        padding: 12px 30px;
        border: none;
        border-radius: 5px;
        cursor: pointer;
        font-size: 15px;
        font-weight: bold;
        width: 100%;
    }
    button:hover {
        background-color: #0056b3;
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

<form method="post" class="work-order-form">
    <!-- Header Section -->
    <div class="form-section">
        <h3>CLIENT INFORMATION</h3>
        <div class="form-row">
            <div class="form-group">
                <label>Client Name *</label>
                <input type="text" name="client_name" required>
            </div>
            <div class="form-group">
                <label>Order Number</label>
                <input type="text" name="order_number" readonly>
            </div>
        </div>
        <div class="form-row">
            <div class="form-group">
                <label>Client Phone</label>
                <input type="tel" name="client_phone">
            </div>
            <div class="form-group">
                <label>Location *</label>
                <input type="text" name="location" required>
            </div>
        </div>
    </div>

    <!-- Dates and Authorization -->
    <div class="form-section">
        <h3>ORDER DETAILS</h3>
        <div class="form-row">
            <div class="form-group">
                <label>Order Received By</label>
                <select name="order_received_by">
                    <option value="">-- Select Staff --</option>
                    <?php foreach ($staff as $s): ?>
                        <option value="<?php echo (int)$s['id']; ?>" <?php echo (int)$s['id'] === (int)$_SESSION['user_id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($s['firstname'] . ' ' . $s['lastname'], ENT_QUOTES, 'UTF-8'); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label>Order Date *</label>
                <input type="date" name="order_date" required>
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
        <h3>WORK PERFORMED</h3>
        <div class="form-row">
            <div class="form-group">
                <label>Work Performed By</label>
                <select name="work_performed_by">
                    <option value="">-- Select Staff --</option>
                    <?php foreach ($staff as $s): ?>
                        <option value="<?php echo (int)$s['id']; ?>">
                            <?php echo htmlspecialchars($s['firstname'] . ' ' . $s['lastname'], ENT_QUOTES, 'UTF-8'); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label>Vessel VIN #</label>
                <input type="text" name="vessel_vin">
            </div>
        </div>
        <div class="form-row full">
            <div class="form-group">
                <label>Description of Work Completed and Materials Used</label>
                <textarea name="work_description"></textarea>
            </div>
        </div>
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
        <button type="submit">Create Work Order</button>
    </div>
</form>

<?php require_once '../includes/footer.php'; ?>