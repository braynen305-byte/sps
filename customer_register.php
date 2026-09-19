<?php
session_start();
if (!empty($_SESSION['logged_in']) && $_SESSION['logged_in'] === true && strtolower($_SESSION['role'] ?? '') === 'customer') {
    header('Location: /sps/pages/customer_dashboard.php');
    exit;
}

$title = 'Customer Registration';
require_once 'includes/dbh.inc.php';

try {
    $customerColumns = $conn->query("SHOW COLUMNS FROM customers")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('password', $customerColumns, true)) {
        $conn->exec('ALTER TABLE customers ADD COLUMN password VARCHAR(255) NULL AFTER email');
    }
    if (!in_array('company_name', $customerColumns, true)) {
        $conn->exec('ALTER TABLE customers ADD COLUMN company_name VARCHAR(150) NULL AFTER name');
    }
    if (!in_array('company_contact', $customerColumns, true)) {
        $conn->exec('ALTER TABLE customers ADD COLUMN company_contact VARCHAR(150) NULL AFTER company_name');
    }
} catch (Exception $e) {
    // Ignore schema warnings here; the form will still show a useful error if needed.
}

require_once 'includes/header.php';

$message = '';
$messageType = 'error';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $accountType = trim((string)($_POST['account_type'] ?? 'person'));
    $name = trim((string)($_POST['name'] ?? ''));
    $companyName = trim((string)($_POST['company_name'] ?? ''));
    $companyContact = trim((string)($_POST['company_contact'] ?? ''));
    $email = trim((string)($_POST['email'] ?? ''));
    $phone = trim((string)($_POST['phone'] ?? ''));
    $address = trim((string)($_POST['address'] ?? ''));
    $city = trim((string)($_POST['city'] ?? ''));
    $state = trim((string)($_POST['state'] ?? ''));
    $zip = trim((string)($_POST['zip'] ?? ''));
    $password = (string)($_POST['password'] ?? '');
    $confirmPassword = (string)($_POST['confirm_password'] ?? '');

    if ($accountType === 'company' && $companyName === '') {
        $message = 'Company name is required for company accounts.';
    } elseif ($accountType === 'company' && $companyContact === '') {
        $message = 'Please enter the person representing the company.';
    } elseif ($name === '' || $email === '' || $password === '') {
        $message = 'Name, email, and password are required.';
    } elseif ($password !== $confirmPassword) {
        $message = 'Passwords do not match.';
    } else {
        $existing = $conn->prepare('SELECT id FROM customers WHERE email = ? LIMIT 1');
        $existing->execute([$email]);
        if ($existing->fetch(PDO::FETCH_ASSOC)) {
            $message = 'An account already exists for this email address.';
        } else {
            $hash = password_hash($password, PASSWORD_DEFAULT);
            $displayName = $accountType === 'company' ? $companyName : $name;
            $stmt = $conn->prepare('INSERT INTO customers (name, company_name, company_contact, phone, email, password, address, city, state, zip, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())');
            if ($stmt->execute([$displayName, $companyName, $companyContact, $phone, $email, $hash, $address, $city, $state, $zip])) {
                $message = 'Registration successful. You can now sign in to the customer portal.';
                $messageType = 'success';
                $_POST = [];
            } else {
                $message = 'Unable to create your customer account.';
            }
        }
    }
}
?>

<div class="login-selection" style="max-width: 760px; margin: 80px auto 40px;">
    <div class="login-card" style="width: 100%; max-width: 100%; text-align: left;">
        <h1>Register for the Customer Portal</h1>
        <p>Create an account to view your assigned work orders, check status updates, and contact support.</p>

        <?php if ($message !== ''): ?>
            <p style="color: <?php echo $messageType === 'success' ? '#166534' : '#b91c1c'; ?>; font-weight: 600; margin-bottom: 16px;">
                <?php echo htmlspecialchars($message, ENT_QUOTES, 'UTF-8'); ?>
            </p>
        <?php endif; ?>

        <form method="post" action="/sps/customer_register.php" class="customer-registration-form">
            <div class="customer-registration-grid">
                <div class="customer-registration-field full-width">
                    <label for="account_type">Account Type <span class="field-help" title="Choose whether this account is for an individual person or a company.">?</span></label>
                    <select name="account_type" id="account_type" onchange="toggleCustomerAccountFields()">
                        <option value="person" <?php echo ((($_POST['account_type'] ?? 'person') === 'person') ? 'selected' : ''); ?>>Person</option>
                        <option value="company" <?php echo ((($_POST['account_type'] ?? 'person') === 'company') ? 'selected' : ''); ?>>Company</option>
                    </select>
                </div>

                <div class="customer-registration-field" id="name_field">
                    <label for="name">Full Name <span class="field-help" title="Enter the full legal name of the person creating this account.">?</span></label>
                    <input type="text" name="name" id="name" value="<?php echo htmlspecialchars($_POST['name'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" required>
                </div>

                <div class="customer-registration-field hidden" id="company_name_field">
                    <label for="company_name">Company Name <span class="field-help" title="Enter the full company name as it should appear on customer records.">?</span></label>
                    <input type="text" name="company_name" id="company_name" value="<?php echo htmlspecialchars($_POST['company_name'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                </div>

                <div class="customer-registration-field hidden" id="company_contact_field">
                    <label for="company_contact">Person Representing Company <span class="field-help" title="Enter the name of the individual at the company who is authorized to manage this account.">?</span></label>
                    <input type="text" name="company_contact" id="company_contact" value="<?php echo htmlspecialchars($_POST['company_contact'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                </div>

                <div class="customer-registration-field">
                    <label for="email">Email <span class="field-help" title="Use a valid email address that you check regularly for work-order updates.">?</span></label>
                    <input type="email" name="email" id="email" value="<?php echo htmlspecialchars($_POST['email'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" required>
                </div>

                <div class="customer-registration-field">
                    <label for="phone">Phone <span class="field-help" title="Enter the best contact phone number for service updates and scheduling.">?</span></label>
                    <input type="tel" name="phone" id="phone" value="<?php echo htmlspecialchars($_POST['phone'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                </div>

                <div class="customer-registration-field full-width">
                    <label for="address">Address <span class="field-help" title="Enter the service address or billing address for this account.">?</span></label>
                    <input type="text" name="address" id="address" value="<?php echo htmlspecialchars($_POST['address'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                </div>

                <div class="customer-registration-field">
                    <label for="city">City <span class="field-help" title="Enter the city for the address listed above.">?</span></label>
                    <input type="text" name="city" id="city" value="<?php echo htmlspecialchars($_POST['city'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                </div>

                <div class="customer-registration-field">
                    <label for="state">State <span class="field-help" title="Enter the state for the address listed above.">?</span></label>
                    <input type="text" name="state" id="state" value="<?php echo htmlspecialchars($_POST['state'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                </div>

                <div class="customer-registration-field">
                    <label for="zip">ZIP <span class="field-help" title="Enter the ZIP or postal code for the address listed above.">?</span></label>
                    <input type="text" name="zip" id="zip" value="<?php echo htmlspecialchars($_POST['zip'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                </div>

                <div class="customer-registration-field">
                    <label for="password">Password <span class="field-help" title="Choose a secure password with at least 8 characters for your account.">?</span></label>
                    <input type="password" name="password" id="password" required>
                </div>

                <div class="customer-registration-field">
                    <label for="confirm_password">Confirm Password <span class="field-help" title="Re-enter the same password to confirm it matches exactly.">?</span></label>
                    <input type="password" name="confirm_password" id="confirm_password" required>
                </div>
            </div>

            <div class="customer-registration-actions">
                <button type="submit">Create Account</button>
                <button type="button" class="secondary-button" onclick="window.location.href='/sps/customer_login.php'">Already Registered</button>
            </div>
        </form>
    </div>
</div>

<script>
function toggleCustomerAccountFields() {
    const type = document.getElementById('account_type').value;
    const nameField = document.getElementById('name_field');
    const companyNameField = document.getElementById('company_name_field');
    const companyContactField = document.getElementById('company_contact_field');

    if (type === 'company') {
        companyNameField.classList.remove('hidden');
        companyContactField.classList.remove('hidden');
        nameField.classList.add('hidden');
    } else {
        companyNameField.classList.add('hidden');
        companyContactField.classList.add('hidden');
        nameField.classList.remove('hidden');
    }
}

document.addEventListener('DOMContentLoaded', function() {
    toggleCustomerAccountFields();
});
</script>

<?php require_once 'includes/footer.php'; ?>
