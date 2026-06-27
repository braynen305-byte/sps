<?php
$title = "Service Portal - Login";
require_once 'includes/header.php';
?>

<div class="login-selection">
    <h1>Choose who is logging in</h1>
    <div class="login-options">
        <div class="login-card">
            <h2>Customer</h2>
            <p>Login with customer credentials to access your account.</p>
            <a href="customer-login.php">Customer Login</a>
        </div>
        <div class="login-card">
            <h2>Service Technician</h2>
            <p>Login as a service technician to access the service portal.</p>
            <a href="login.php">Technician Login</a>
        </div>
    </div>
</div>



<?php
require_once 'includes/footer.php';
?>
