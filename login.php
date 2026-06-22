<?php
$title = "Service Portal - Login";
require_once 'includes/header.php';
?>

<p id="login-instructions">Please login using the form below.</p>



    <div class="container">
        
    <h1>SERVICE PORTAL LOGIN</h1>

        <form action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>" method="post">


            <label for="username">Username</label>
            <input type="text" name="username" id="username" placeholder="Username">

            <label for="password">Password</label>
            <input type="password" name="password" id="password" placeholder="Password">

            <button type="submit">Login</button>

            <button type="button" onclick="window.location.href='/sps/register.php'">Create Account</button>

            <div id="options">  
                    <p id="forgtPasswordBttn"><a href="/sps/pages/forgotpass.php">Forgot Password</a></p>
            </div>


        </form>
         
    </div>
<?php
require_once 'includes/footer.php';
?>
