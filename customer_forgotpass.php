<?php
session_start();
if (!empty($_SESSION['logged_in']) && $_SESSION['logged_in'] === true && strtolower($_SESSION['role'] ?? '') === 'customer') {
    header('Location: /sps/pages/customer_dashboard.php');
    exit;
}

$title = 'Customer Password Reminder';
require_once 'includes/dbh.inc.php';
require_once 'includes/header.php';

$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim((string)($_POST['email'] ?? ''));

    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $message = 'Please enter a valid email address.';
    } else {
        $stmt = $conn->prepare('SELECT id FROM customers WHERE LOWER(email) = LOWER(?) LIMIT 1');
        $stmt->execute([$email]);

        if ($stmt->fetch(PDO::FETCH_ASSOC)) {
            $message = 'If an account exists for that email address, a password reminder has been sent.';
        } else {
            $message = 'No account exists for that email address. Please register for a new customer account.';
        }
    }
}
?>

<div class="container" style="margin-top: 80px;">
    <h1>CUSTOMER PASSWORD REMINDER</h1>
    <p class="form-description">Enter your email address to request a password reminder.</p>

    <?php if ($message !== ''): ?>
        <p style="color: #b91c1c; font-weight: 600; margin: 16px auto 0; width: 90%;"><?php echo htmlspecialchars($message, ENT_QUOTES, 'UTF-8'); ?></p>
    <?php endif; ?>

    <form action="/sps/customer_forgotpass.php" method="post">
        <label for="email">Email</label>
        <input type="email" name="email" id="email" placeholder="Email" required>

        <button type="submit">Send Reminder</button>
        <button type="button" onclick="window.location.href='/sps/customer_login.php'">Back to Login</button>
    </form>
</div>

<?php require_once 'includes/footer.php'; ?>
