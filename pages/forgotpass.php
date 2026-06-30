<?php
$title = "Forgot Password";
require_once '../includes/header.php';
require_once '../includes/dbh.inc.php';

$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');

    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $message = 'Please enter a valid email address.';
    } else {
        $stmt = $conn->prepare('SELECT id FROM staff WHERE LOWER(email) = LOWER(?) LIMIT 1');
        $stmt->execute([$email]);

        if ($stmt->fetch()) {
            $subject = 'Password reminder';
            $body = "Hello,\n\nYou requested a password reminder for your account.\nPlease contact the administrator or use the password reset process.\n\nIf you did not request this, you can ignore this email.\n";
            $headers = "From: no-reply@sps.local\r\n";
            $headers .= "X-Mailer: PHP/" . phpversion();

            $mailSent = mail($email, $subject, $body, $headers);

            if ($mailSent) {
                $message = 'If an account exists, a password reminder has been sent.';
            } else {
                $message = 'The email could not be sent right now. Please contact support.';
            }
        } else {
            $message = 'If an account exists, a password reminder has been sent.';
        }
    }
}
?>

<p id="login-instructions">Please login using the form below.</p>

<div class="forgotcontainer">
    <p class="form-description">Enter your email address to receive a password reminder.</p>

    <?php if ($message !== ''): ?>
        <p style="color: #007BFF; text-align: center; margin: 10px 0;"><?php echo htmlspecialchars($message, ENT_QUOTES, 'UTF-8'); ?></p>
    <?php endif; ?>

    <form id="forgotpass-form" action="/sps/pages/forgotpass.php" method="post">
        <label id="form-label" for="forgotpass-email">Enter your email address:</label>
        <input type="email" name="email" id="forgotpass-email" placeholder="Email" required>
        <button type="submit">Reset Password</button>
    </form>
</div>

<?php require_once '../includes/footer.php'; ?>