<?php
session_start();

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true || strtolower($_SESSION['role'] ?? '') !== 'admin') {
    header('Location: /sps/login.php');
    exit;
}

require_once '../includes/dbh.inc.php';

$title = 'Manage Customers';
require_once '../includes/header.php';

$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'create') {
        $name = trim($_POST['name'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $address = trim($_POST['address'] ?? '');
        $city = trim($_POST['city'] ?? '');
        $state = trim($_POST['state'] ?? '');
        $zip = trim($_POST['zip'] ?? '');

        if ($name === '') {
            $message = 'Customer name is required.';
            $messageType = 'error';
        } else {
            $stmt = $conn->prepare('INSERT INTO customers (name, phone, email, address, city, state, zip, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())');
            if ($stmt->execute([$name, $phone, $email, $address, $city, $state, $zip])) {
                $message = 'Customer added successfully.';
                $messageType = 'success';
            } else {
                $message = 'Unable to add customer.';
                $messageType = 'error';
            }
        }
    } elseif ($action === 'update') {
        $customerId = (int)($_POST['customer_id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $address = trim($_POST['address'] ?? '');
        $city = trim($_POST['city'] ?? '');
        $state = trim($_POST['state'] ?? '');
        $zip = trim($_POST['zip'] ?? '');

        if ($customerId > 0 && $name !== '') {
            $stmt = $conn->prepare('UPDATE customers SET name=?, phone=?, email=?, address=?, city=?, state=?, zip=?, updated_at=NOW() WHERE id=?');
            if ($stmt->execute([$name, $phone, $email, $address, $city, $state, $zip, $customerId])) {
                $message = 'Customer updated successfully.';
                $messageType = 'success';
            } else {
                $message = 'Unable to update customer.';
                $messageType = 'error';
            }
        }
    } elseif ($action === 'delete') {
        $customerId = (int)($_POST['customer_id'] ?? 0);
        if ($customerId > 0) {
            $stmt = $conn->prepare('DELETE FROM customers WHERE id = ?');
            if ($stmt->execute([$customerId])) {
                $message = 'Customer deleted successfully.';
                $messageType = 'success';
            } else {
                $message = 'Unable to delete customer.';
                $messageType = 'error';
            }
        }
    }
}

$editingId = (int)($_GET['edit_id'] ?? 0);
$editingCustomer = null;
if ($editingId > 0) {
    $stmt = $conn->prepare('SELECT * FROM customers WHERE id = ?');
    $stmt->execute([$editingId]);
    $editingCustomer = $stmt->fetch(PDO::FETCH_ASSOC);
}

$customers = $conn->query('SELECT * FROM customers ORDER BY name')->fetchAll(PDO::FETCH_ASSOC);
?>

<style>
    .page-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 20px;
        gap: 20px;
    }
    .toggle-form-btn {
        background-color: #28a745;
        color: white;
        padding: 8px 12px;
        border: none;
        border-radius: 50px;
        cursor: pointer;
        font-weight: bold;
        font-size: 13px;
        white-space: nowrap;
        max-width: 300px;
    }
    .toggle-form-btn:hover {
        background-color: #218838;
    }
    .form-container {
        background-color: rgb(234, 234, 234);
        padding: 20px;
        border-radius: 10px;
        margin-bottom: 30px;
        box-shadow: 0 0 10px rgba(0, 0, 0, 0.1);
        display: none;
    }
    .form-container.show {
        display: block;
    }
    .form-container h3 {
        background-color: #007BFF;
        color: white;
        padding: 10px;
        margin: -20px -20px 15px -20px;
        border-radius: 10px 10px 0 0;
    }
    .form-row {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 15px;
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
    }
    .form-group input {
        padding: 10px;
        border: 1px solid #ccc;
        border-radius: 5px;
        font-size: 13px;
    }
    .form-group input:focus {
        outline: none;
        border-color: #007BFF;
        box-shadow: 0 0 5px rgba(0, 123, 255, 0.25);
    }
    .form-buttons {
        display: flex;
        gap: 10px;
    }
    .form-buttons button {
        padding: 10px 20px;
        border: none;
        border-radius: 5px;
        cursor: pointer;
        font-weight: bold;
    }
    .btn-primary {
        background-color: #28a745;
        color: white;
    }
    .btn-primary:hover {
        background-color: #218838;
    }
    .cancel-btn {
        background-color: #6c757d;
        color: white;
        cursor: pointer;
    }
    .cancel-btn:hover {
        background-color: #5a6268;
    }
    .customer-table {
        width: 100%;
        border-collapse: collapse;
        background-color: white;
        border-radius: 5px;
        overflow: hidden;
        box-shadow: 0 0 10px rgba(0, 0, 0, 0.1);
    }
    .customer-table thead {
        background-color: #007BFF;
        color: white;
    }
    .customer-table th {
        padding: 12px;
        text-align: left;
        font-weight: bold;
    }
    .customer-table td {
        padding: 12px;
        border-bottom: 1px solid #ddd;
    }
    .customer-table tbody tr:hover {
        background-color: #f5f5f5;
    }
    .action-links {
        display: flex;
        gap: 8px;
        align-items: center;
    }
    .action-links form {
        display: inline;
        margin: 0;
    }
    .action-links a, .action-links button {
        padding: 6px 12px;
        border: none;
        border-radius: 4px;
        cursor: pointer;
        font-size: 12px;
        color: white;
        text-decoration: none;
        display: inline-block;
        white-space: nowrap;
    }
    .edit-btn {
        background-color: #007BFF;
    }
    .edit-btn:hover {
        background-color: #0056b3;
    }
    .delete-btn {
        background-color: #dc3545;
        padding: 6px 10px;
        font-size: 11px;
    }
    .delete-btn:hover {
        background-color: #c82333;
    }
    .message {
        padding: 10px;
        border-radius: 5px;
        margin-bottom: 20px;
    }
    .message.success {
        background-color: #d4edda;
        color: #155724;
        border: 1px solid #c3e6cb;
    }
    .message.error {
        background-color: #f8d7da;
        color: #721c24;
        border: 1px solid #f5c6cb;
    }
    
    .customers-header {
        display: flex;
        align-items: center;
        margin-top: 30px;
        margin-bottom: 20px;
        width: 100%;
    }
    .customers-header h3 {
        margin: 0;
        flex-grow: 1;
    }
</style>

<script>
    function toggleForm() {
        const form = document.getElementById('addCustomerForm');
        form.classList.toggle('show');
        const btn = document.getElementById('toggleFormBtn');
        btn.textContent = form.classList.contains('show') ? '- Hide Form' : '+ Add New Customer';
    }

    // Auto-show form if editing
    <?php if ($editingCustomer): ?>
        document.addEventListener('DOMContentLoaded', function() {
            document.getElementById('addCustomerForm').classList.add('show');
            document.getElementById('toggleFormBtn').textContent = '- Hide Form';
        });
    <?php endif; ?>

    function closeForm() {
        const form = document.getElementById('addCustomerForm');
        form.classList.remove('show');
        document.getElementById('toggleFormBtn').textContent = '+ Add New Customer';
    }
</script>

<div class="page-header">
    <h2 style="margin: 0;">Manage Customers</h2>
    <a href="/sps/pages/admin_dashboard.php" style="color: #007BFF; text-decoration: none;">← Back</a>
</div>

<?php if ($message !== ''): ?>
    <div class="message <?php echo $messageType; ?>">
        <?php echo htmlspecialchars($message, ENT_QUOTES, 'UTF-8'); ?>
    </div>
<?php endif; ?>

<div class="form-container" id="addCustomerForm">
    <h3><?php echo $editingCustomer ? 'Edit Customer' : 'Add New Customer'; ?></h3>
    <form method="post">
        <input type="hidden" name="action" value="<?php echo $editingCustomer ? 'update' : 'create'; ?>">
        <?php if ($editingCustomer): ?>
            <input type="hidden" name="customer_id" value="<?php echo (int)$editingCustomer['id']; ?>">
        <?php endif; ?>
        <div class="form-row">
            <div class="form-group">
                <label>Customer Name *</label>
                <input type="text" name="name" value="<?php echo htmlspecialchars($editingCustomer['name'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" required>
            </div>
            <div class="form-group">
                <label>Phone</label>
                <input type="tel" name="phone" value="<?php echo htmlspecialchars($editingCustomer['phone'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
            </div>
        </div>
        <div class="form-row">
            <div class="form-group">
                <label>Email</label>
                <input type="email" name="email" value="<?php echo htmlspecialchars($editingCustomer['email'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
            </div>
            <div class="form-group">
                <label>City</label>
                <input type="text" name="city" value="<?php echo htmlspecialchars($editingCustomer['city'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
            </div>
        </div>
        <div class="form-row full">
            <div class="form-group">
                <label>Address</label>
                <input type="text" name="address" value="<?php echo htmlspecialchars($editingCustomer['address'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
            </div>
        </div>
        <div class="form-row">
            <div class="form-group">
                <label>State</label>
                <input type="text" name="state" value="<?php echo htmlspecialchars($editingCustomer['state'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
            </div>
            <div class="form-group">
                <label>ZIP</label>
                <input type="text" name="zip" value="<?php echo htmlspecialchars($editingCustomer['zip'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
            </div>
        </div>
        <div class="form-buttons">
            <button type="submit" class="btn-primary"><?php echo $editingCustomer ? 'Update Customer' : 'Add Customer'; ?></button>
            <?php if ($editingCustomer): ?>
                <a href="/sps/pages/manage_customers.php" class="cancel-btn" style="padding: 10px 20px; text-align: center; text-decoration: none; display: inline-block;">Cancel</a>
            <?php endif; ?>
        </div>
    </form>
</div>

<div class="customers-header">
    <h3>Customers</h3>
    <button class="toggle-form-btn" id="toggleFormBtn" onclick="toggleForm()">+ Add New Customer</button>
</div>
<?php if (empty($customers)): ?>
    <p>No customers found. Create one above.</p>
<?php else: ?>
    <table class="customer-table">
        <thead>
            <tr>
                <th>Name</th>
                <th>Phone</th>
                <th>Email</th>
                <th>City</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($customers as $customer): ?>
                <tr>
                    <td><?php echo htmlspecialchars($customer['name'], ENT_QUOTES, 'UTF-8'); ?></td>
                    <td><?php echo htmlspecialchars($customer['phone'] ?? '', ENT_QUOTES, 'UTF-8'); ?></td>
                    <td><?php echo htmlspecialchars($customer['email'] ?? '', ENT_QUOTES, 'UTF-8'); ?></td>
                    <td><?php echo htmlspecialchars($customer['city'] ?? '', ENT_QUOTES, 'UTF-8'); ?></td>
                    <td>
                        <div class="action-links">
                            <a href="/sps/pages/manage_customers.php?edit_id=<?php echo (int)$customer['id']; ?>" class="edit-btn">Edit</a>
                            <form method="post" style="display:inline;">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="customer_id" value="<?php echo (int)$customer['id']; ?>">
                                <button type="submit" class="delete-btn" onclick="return confirm('Delete this customer?');">Delete</button>
                            </form>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>

<?php require_once '../includes/footer.php'; ?>