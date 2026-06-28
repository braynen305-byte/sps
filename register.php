<?php
$title = "Employee Registration";
require_once 'includes/header.php';
include "includes/signup.php";


?>


<p id="login-instructions">Please login using the form below.</p>

            <div class="regcontainer">

                <h1>Employee Registration</h1>

                <?php if (!empty($successMessage)) : ?>
                    <p style="color: green; font-weight: bold;"><?php echo htmlspecialchars($successMessage); ?></p>
                <?php endif; ?>

                <?php if (!empty($emailError) && !empty($email)) : ?>
                    <p style="color: red; font-weight: bold;"><?php echo htmlspecialchars($emailError); ?></p>
                <?php endif; ?>

                <form action="" method="post">
                    <div class="form-row">
                        <div class="form-group">
                    <label for="firstname">First Name <span style="display: inline-block; color: red;"><?php  echo @$firstnameError; ?></span></label>
                    <input type="text" name="firstname" id="firstname" placeholder="First Name" value="<?php echo @$_POST['firstname'] ?>">
                    </div>

<div class="form-group">
                    <label for="middlename">Middle Name <span style="display: inline-block; color: red;"><?php echo @$middlenameError; ?></span></label>
                    <input type="text" name="middlename" id="middlename" placeholder="Middle Name" value="<?php echo @$_POST['middlename'] ?>">
</div>
<div class="form-group">
                    <label for="lastname">Last Name <span style="display: inline-block; color: red;"><?php if (empty($lastname)){echo $lastnameError;} ?></span></label>
                    <input type="text" name="lastname" id="lastname" placeholder="Last Name" value="<?php echo @$_POST['lastname'] ?>">
</div>
</div>

                    <div class="form-row">
                        <div class="form-group">
                    <label for="gender">Gender <span style="display: inline-block; color: red;"><?php if (empty($gender)){echo $genderError;} ?></span></label>
                    <select name="gender" id="gender">
                        <option value="male" <?php echo @$_POST['gender'] === 'male' ? 'selected' : '' ?>>Male</option>
                        <option value="female" <?php echo @$_POST['gender'] === 'female' ? 'selected' : '' ?>>Female</option>
                    </select>
                    </div>


<div class="form-group">
                    <label for="dob">Date of Birth <span style="display: inline-block; color: red;"><?php if (empty($dob)){echo $dobError;} ?></span></label>
                    <input type="date" name="dob" id="dob" placeholder="Date of Birth" value="<?php echo @$_POST['dob'] ?>">
</div>
                        <div class="form-group">
                    <label for="email">Email <span style="display: inline-block; color: red;"><?php if (empty($email)){echo $emailError;} ?></span></label>
                    <input type="email" name="email" id="email" placeholder="Email" value="<?php echo @$_POST['email'] ?>">
</div>
</div>                


<div class="form-row2">
                        <div class="form-group">
                    <label for="telephone">Telephone <span style="display: inline-block; color: red;"><?php if (empty($telephone)){echo $telephoneError;} ?></span></label>
                    <input type="tel" name="telephone" id="telephone" placeholder="Telephone" value="<?php echo @$_POST['telephone'] ?>">
</div>
                    <div class="form-group">
                    <label for="address">Address <span style="display: inline-block; color: red;"><?php if (empty($address)){echo $addressError;} ?></span></label>
                    <textarea name="address" id="address" placeholder="Address"><?php echo @$_POST['address'] ?></textarea>
</div>
</div>


                    <div class="form-row">
                        <div class="form-group">
                    <label for="password">Password <span style="display: inline-block; color: red;"><?php echo $confirm_passwordError . $passwordError; ?></span></label>
                    <input type="password" name="password" id="password" placeholder="Password" value="<?php echo @$_POST['password'] ?>">
</div>
                    <div class="form-group">
                    <label for="confirm_password">Confirm Password <span style="display: inline-block; color: red;"><?php echo $confirm_passwordError . $passwordError; ?></span></label>
                    <input type="password" name="confirm_password" id="confirm_password" placeholder="Confirm Password" value="<?php echo @$_POST['confirm_password'] ?>">
</div>
</div>


                    
                    
                    <button id="formbutton" type="submit">Register</button> 

                    
                    <p class="account-exists">Already have an account? <a href="/sps/index.php">Login here</a>.</p>


                </form>

            </div>


<?php require_once 'includes/footer.php'; ?>