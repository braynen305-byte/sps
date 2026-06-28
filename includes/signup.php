<?php
require 'dbh.inc.php';
$firstname = $middlename = $lastname = $gender = $dob = $email = $telephone = $address = $password = $confirm_password = "";
$firstnameError = $middlenameError = $lastnameError = $genderError = $dobError = $emailError = $telephoneError = $addressError = $passwordError = $confirm_passwordError = "";
$successMessage = "";



function cleanInputData($data){
    $data = trim($data);
    $data = stripslashes($data);
    $data = htmlspecialchars($data);
    return $data;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Retrieve form data
    $userdata = $_POST;

    //firstname validation//
        if(empty($userdata['firstname'])){
                $firstnameError = "First name required.";
                
        }
            elseif(!preg_match("/^[a-zA-Z]+(?: [a-zA-Z]+)*$/", $userdata['firstname'] )) {
                
                
                $firstnameError = "Letters & Space only";
                
            } else{
                    $firstname = cleanInputData($userdata['firstname']);
                    
            }
    //middlename validation//     

  if(empty($userdata['middlename'])){
                $middlenameError = "Middle name required.";
                
        }
            elseif(!preg_match("/^[a-zA-Z]+(?: [a-zA-Z]+)*$/", $userdata['middlename'] )) {
                
                
                $middlenameError = "Letters & Space only";
                
            } else{
                    $middlename = cleanInputData($userdata['middlename']);
                    
            }

    //lastname validation

        if(empty($userdata['lastname'])){
                $lastnameError = "Last name required.";
                
        }
            elseif(!preg_match("/^[a-zA-Z]+(?: [a-zA-Z]+)*$/", $userdata['lastname'] )) {
                
                
                $lastnameError = "Letters & Space only";
                
            } else{
                    $lastname = cleanInputData($userdata['lastname']);
                        
            }

    //gender validation//



        if(empty($userdata['gender'])){
                $genderError = "Gender required.";
                
        }
            elseif(!preg_match("/^[a-zA-Z]+(?: [a-zA-Z]+)*$/", $userdata['gender'] )) {
                
                
                $genderError = "Letters & Space only";
                
            } else{
                    $gender = cleanInputData($userdata['gender']);
                    
            }



    //dob validation

        if(empty($userdata['dob'])){
                $dobError = "Date of Birth required.";
               
        }
        else{
                    $dob = cleanInputData($userdata['dob']);
                    ;
            }


    //email validation//

        if(empty($userdata['email'])){
                $emailError = "Email required.";
        }else if(!filter_var($userdata['email'], FILTER_VALIDATE_EMAIL)){
                $emailError = "Invalid email format.";
        }else{
            $email = strtolower(trim(cleanInputData($userdata['email'])));

        }



    //telephone validation//

        if(empty($userdata['telephone'])){
                $telephoneError = "Telephone required.";
        }else{
            $telephone = cleanInputData($userdata['telephone']);
        }


    //address validation//

        if(empty($userdata['address'])){
                $addressError = "Address required.";
        }else{
            $address = $userdata['address'];
        }



    //password validation//

        if(empty($userdata['password'])){
                $passwordError = "Password required.";
        }else{
            $password = cleanInputData($userdata['password']);
        }


    //confirm password validation//
            if(empty($userdata['confirm_password'])){
                    $confirm_passwordError = "Please confirm your password.";
            }else{
                $confirm_password = cleanInputData($userdata['confirm_password']);
            }

            // Ensure both passwords are provided and match exactly before processing
            if($password === null || $confirm_password === null){
                // one of the passwords is missing; errors already set above
            } else {
                if($password !== $confirm_password){
                    $confirm_passwordError = "Passwords do not match.";
                } else {
                    // Optional: enforce minimum password policy
                    if(strlen($password) < 8){
                        $passwordError = "Password must be at least 8 characters.";
                    } elseif(!preg_match('/[A-Za-z]/', $password) || !preg_match('/[0-9]/', $password)){
                        $passwordError = "Password must contain letters and numbers.";
                    }
                }
            }


    
    // Check if there are any errors
    $errorList = [
        $firstnameError,
        $middlenameError,
        $lastnameError,
        $genderError,
        $dobError,
        $emailError,
        $telephoneError,
        $addressError,
        $passwordError,
        $confirm_passwordError,
    ];
   
    
    $hasErrors = count(array_filter($errorList, 'strlen')) > 0;
   

    
    // If no validation errors, insert user into database
    if (!$hasErrors) {
        $checkStmt = $conn->prepare("SELECT id FROM staff WHERE LOWER(email) = LOWER(?) LIMIT 1");
        $checkStmt->execute([$email]);

        if ($checkStmt->fetch()) {
            $emailError = 'An account with this email already exists.';
        } else {
            // Hash the password
            $password_hashed = password_hash($password, PASSWORD_DEFAULT);

            // Prepare insert statement using the actual staff table columns
            $sql = "INSERT INTO staff (firstname, middlename, lastname, email, gender, date_of_birth, telephone, residence, role, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())";
            $stmt = $conn->prepare($sql);
            if ($stmt) {
                $params = [$firstname, $middlename, $lastname, $email, $gender, $dob, $telephone, $address, 'technician'];
                try {
                    if ($stmt->execute($params)) {
                        $successMessage = 'Registration successful.';
                        header('Location: /sps/register.php?success=1');
                        exit;
                    }
                } catch (PDOException $e) {
                    if ($e->getCode() === '23000') {
                        $emailError = 'An account with this email already exists.';
                    } else {
                        $emailError = 'Failed to register user.';
                    }
                }
                $stmt = null;
            } else {
                $emailError = 'Database error.';
            }
        }
    }
    
}











