<?php
session_start();
if (!empty($_SESSION['logged_in']) && $_SESSION['logged_in'] === true && strtolower($_SESSION['role'] ?? '') === 'customer') {
    header('Location: /sps/pages/customer_dashboard.php');
    exit;
}

$title = 'Customer Portal Login';
require_once 'includes/dbh.inc.php';
require_once 'includes/header.php';

$message = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim((string)($_POST['email'] ?? ''));
    $password = (string)($_POST['password'] ?? '');

    if ($email === '' || $password === '') {
        $message = 'Please enter your email and password.';
    } else {
        $columns = $conn->query("SHOW COLUMNS FROM customers")->fetchAll(PDO::FETCH_COLUMN);
        $hasPasswordColumn = in_array('password', $columns, true);

        if (!$hasPasswordColumn) {
            $message = 'No customer account is available for this email address. Please register first or contact support.';
        } else {
            $stmt = $conn->prepare('SELECT id, name, email, password FROM customers WHERE email = ? LIMIT 1');
            $stmt->execute([$email]);
            $customer = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($customer && isset($customer['password']) && password_verify($password, $customer['password'])) {
                $_SESSION['logged_in'] = true;
                $_SESSION['role'] = 'customer';
                $_SESSION['customer_id'] = (int)$customer['id'];
                $_SESSION['customer_name'] = (string)$customer['name'];
                $_SESSION['email'] = (string)$customer['email'];
                $_SESSION['full_name'] = (string)$customer['name'];

                header('Location: /sps/pages/customer_dashboard.php');
                exit;
            }

            if ($customer) {
                $message = 'Invalid password. Please try again or use the password reminder link.';
            } else {
                $message = 'No account exists for this email address. Please register or use the password reminder link below.';
            }
        }
    }
}
?>

<div class="container" style="margin-top: 80px;">
    <h1>CUSTOMER PORTAL LOGIN</h1>
    <p class="form-description">Track your work orders, update your profile, and contact support for your service requests.</p>

    <?php if ($message !== ''): ?>
        <p style="color:#b91c1c; font-weight:600; margin: 16px auto 0; width: 90%;"><?php echo htmlspecialchars($message, ENT_QUOTES, 'UTF-8'); ?></p>
    <?php endif; ?>

    <form method="post" action="/sps/customer_login.php">
        <label for="email">Email</label>
        <input type="email" name="email" id="email" value="<?php echo htmlspecialchars($_POST['email'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" required>

        <label for="password">Password</label>
        <input type="password" name="password" id="password" required>

        <button type="submit">Login</button>
        <button type="button" onclick="window.location.href='/sps/customer_register.php'">Register</button>

        <div id="options">
            <p id="forgtPasswordBttn"><a href="/sps/customer_forgotpass.php">Forgot Password</a></p>
            <p id="forgtPasswordBttn"><a href="/sps/index.php">Back to main portal</a></p>
        </div>
    </form>
</div>

<?php require_once 'includes/footer.php'; ?>
