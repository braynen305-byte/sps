<?php
$title = "Service Portal";
require_once 'includes/header.php';
?>

<div class="login-selection">
    <h1>Welcome to the Service Portal</h1>
    <p>Access your account, manage work orders, and stay connected with the team.</p>
    <div class="login-options">
        <div class="login-card">
            <h2>Sign In</h2>
            <p>Already have an account? Log in and continue to your dashboard.</p>
            <a href="login.php">Login</a>
        </div>
        <div class="login-card">
            <h2>Register</h2>
            <p>Create an account to get started with the service portal.</p>
            <a href="register.php">Create Account</a>
        </div>
    </div>
</div>

<?php
require_once 'includes/footer.php';
?>
