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
    <h3 class="customers-title">Customers</h3>
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