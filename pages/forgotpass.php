<?php
$title = "Forgot Password";
require_once '../includes/header.php';
?>


<p id="login-instructions">Please login using the form below.</p>
    

    <div class="forgotcontainer">
       <p class="form-description">Enter your email address to receive a password reset link.</p>
        <form id="forgotpass-form" action="/pages/forgotpass.php" method="post">
            <label id="form-label" for="forgotpass-email">Enter your email address:</label>
            <input type="email" name="email" id="forgotpass-email" placeholder="Email">
            <button type="submit">Reset Password</button>
        </form>
    </div>

<?php require_once '../includes/footer.php'; ?>