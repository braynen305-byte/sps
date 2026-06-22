<?php

$title = "Employee Registration";
require_once 'includes/header.php';

?>

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
                    <label for="dob">Date of Birth</label>
                    <input type="date" name="dob" id="dob" placeholder="Date of Birth">
</div>
                        <div class="form-group">
                    <label for="email">Email</label>
                    <input type="email" name="email" id="email" placeholder="Email">
</div>
</div>                


<div class="form-row2">
                        <div class="form-group">
                    <label for="telephone">Telephone</label>
                    <input type="tel" name="telephone" id="telephone" placeholder="Telephone">
</div>
                    <div class="form-group">
                    <label for="address">Address</label>
                    <textarea name="address" id="address" placeholder="Address"></textarea>
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

                    
                    <p class="account-exists">Already have an account? <a href="../index.php">Login here</a>.</p>


                </form>

            </div>


<?php require_once '../includes/footer.php'; ?>