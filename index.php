<!DOCTYPE html>
<html lang="en">
<head>
    <link rel="stylesheet" href="styles/maIn.css">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Service Portal - Login</title>
</head>
<body>
    <div class="container">
        <h1>SERVICE PORTAL LOGIN</h1>
        <form action="login.php" method="post">
            <label for="username">Username</label>
            <input type="text" name="username" id="username" placeholder="Username">
            <label for="password">Password</label>
            <input type="password" name="password" id="password" placeholder="Password">
            <button type="submit">Login</button>
            <button type="submit">Create Account</button>
            <div id="options">  
        <p id="forgtPasswordBttn"><a href="forgot_password.php">Forgot Password</a></p>
        </div>
        </form>
            
    </div>
</body>
</html> 
</body>
</html>