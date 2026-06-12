<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="../styles/regs.css">
    <link rel="stylesheet" href="../styles/main.css">
    <title>Employee Registration</title>
</head>


<body>
    <nav>
        <ul>
            <li><a href=" ">Home</a></li>
            <li><a href="../index.php">Login</a></li> 
            <li><a href=" ">Support</a></li>

        </ul>
    </nav>
    <section class="hero">
            <img src="/sps/images/sportsmarine.jpg" alt="Employee Registration" class="hero-image">
            <div class="hero-content">
            <h1>Welcome to the Employee Registration Page</h1>
            
        </div>
    </section>

<p id="login-instructions">Please login using the form below.</p>

            <div class="regcontainer">

                <h1>Employee Registration</h1>

                <form action="../includes/signup.php" method="post">
                    <div class="form-row">
                        <div class="form-group">
                    <label for="firstname">First Name</label>
                    <input type="text" name="firstname" id="firstname" placeholder="First Name">
</div>
<div class="form-group">
                    <label for="middlename">Middle Name</label>
                    <input type="text" name="middlename" id="middlename" placeholder="Middle Name">
</div>
<div class="form-group">
                    <label for="lastname">Last Name</label>
                    <input type="text" name="lastname" id="lastname" placeholder="Last Name">
</div>
</div>

                    <div class="form-row">
                        <div class="form-group">
                    <label for="gender">Gender</label>
                    <select name="gender" id="gender">
                        <option value="male">Male</option>
                        <option value="female">Female</option>
                    </select>
                    </div>


                    <div class="form-group">
                    <label for="contact">Date of Birth</label>
                    <input type="date" name="dob" id="dob" placeholder="Date of Birth">
</div>
                        <div class="form-group">
                    <label for="email">Email</label>
                    <input type="email" name="email" id="email" placeholder="Email">
</div>
</div>                


                    <div class="form-row">
                        <div class="form-group">
                    <label for="password">Password</label>
                    <input type="password" name="password" id="password" placeholder="Password">
</div>
                    <div class="form-group">
                    <label for="confirm_password">Confirm Password</label>
                    <input type="password" name="confirm_password" id="confirm_password" placeholder="Confirm Password">
</div>
</div>


                    

                    <button id="formbutton" type="submit">Register</button>
                </form>

            </div>
<footer>
    <p>&copy; 2024 Service Portal. All rights reserved.</p> 
</footer>

</body>
</html>