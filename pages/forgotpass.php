<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="../styles/main.css">
    
    <title>Forgot Password</title>
</head>
<body>

<nav>
        <ul>
            <li><a href=" ">Home</a></li>
            <li><a href="../index.php">Login</a></li> 
            <li><a href=" ">Support</a></li>

        </ul>
    </nav>

    <img src="../images/sportsmarine.jpg" alt="Employee Registration" class="hero-image">
    <section class="hero">
        
    </section>
    

    <div class="forgotcontainer">
       <p>Enter your email address to receive a password reset link.</p>
        <form id="forgotpass-form" action="/pages/forgotpass.php" method="post">
            <label id="form-label" for="forgotpass-email">Enter your email address:</label>
            <input type="email" name="email" id="forgotpass-email" placeholder="Email">
            <button type="submit">Reset Password</button>
        </form>
    </div>

<footer>
    <p>&copy; 2024 Service Portal. All rights reserved.</p> 
</footer

</body>
</html>