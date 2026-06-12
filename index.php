<!DOCTYPE html>
<html lang="en">

<head>
    
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="styles/main.css">
    <title>Service Portal - Login</title>
</head>


<body>

<nav>
        <ul>
            <li><a href="/sps/index.php">Home</a></li>
            <li><a href="/sps/index.php">Login</a></li> 
            <li><a href=" ">Support</a></li>

        </ul>
    </nav>
    <section class="hero">
            <img src="/sps/images/sportsmarine.jpg" alt="Employee Registration" class="hero-image">
            <div class="hero-content">
            <h1>Welcome to the Employee SPS</h1>
            
        </div>
    </section>

<p id="login-instructions">Please login using the form below.</p>



    <div class="container">
        
    <h1>SERVICE PORTAL LOGIN</h1>

        <form action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>" method="post">


            <label for="username">Username</label>
            <input type="text" name="username" id="username" placeholder="Username">

            <label for="password">Password</label>
            <input type="password" name="password" id="password" placeholder="Password">

            <button type="submit">Login</button>

            <button type="button" onclick="window.location.href='/sps/pages/register.php'">Create Account</button>

            <div id="options">  
                    <p id="forgtPasswordBttn"><a href="/sps/pages/forgotpass.php">Forgot Password</a></p>
            </div>


        </form>
         
    </div>
<footer>
    <p>&copy; 2024 Service Portal. All rights reserved.</p> 
</footer>
</body>
</html>
