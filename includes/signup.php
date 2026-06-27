<?php
$firstname = $middlename = $lastname = $gender = $dob = $email = $telephone = $address = $password = $confirm_password = null;
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
                print_r($userdata);
        }
            elseif(!preg_match("/^[a-zA-Z]+(?: [a-zA-Z]+)*$/", $userdata['firstname'] )) {
                
                
                $firstnameError = "Letters & Space only";
                print_r($userdata);
            } else{
                    $firstname = cleanInputData($userdata['firstname']);
                    print_r($userdata);
            }
    //middlename validation//     

  if(empty($userdata['middlename'])){
                $middlenameError = "Middle name required.";
                print_r($userdata);
        }
            elseif(!preg_match("/^[a-zA-Z]+(?: [a-zA-Z]+)*$/", $userdata['middlename'] )) {
                
                
                $middlenameError = "Letters & Space only";
                print_r($userdata);
            } else{
                    $middlename = cleanInputData($userdata['middlename']);
                    print_r($userdata);
            }

    //lastname validation

        if(empty($userdata['lastename'])){
                $lastenameError = "Last name required.";
                print_r($userdata);
        }
            elseif(!preg_match("/^[a-zA-Z]+(?: [a-zA-Z]+)*$/", $userdata['lastname'] )) {
                
                
                $lastnameError = "Letters & Space only";
                print_r($userdata);
            } else{
                    $lastname = cleanInputData($userdata['lastname']);
                    print_r($userdata);
            }

    //gender validation//



        if(empty($userdata['gender'])){
                $genderError = "Gender required.";
                print_r($userdata);
        }
            elseif(!preg_match("/^[a-zA-Z]+(?: [a-zA-Z]+)*$/", $userdata['gender'] )) {
                
                
                $genderError = "Letters & Space only";
                print_r($userdata);
            } else{
                    $middlename = cleanInputData($userdata['gender']);
                    print_r($userdata);
            }



    //dob validation

        if(empty($userdata['dob'])){
                $dobError = "Date of Birth required.";
                print_r($userdata);
        }
        else{
                    $dob = cleanInputData($userdata['dob']);
                    print_r($userdata);
            }


    //email validation//

        if(empty($userdata['email'])){
                $emailError = "Email required.";
        }else if(!filter_var($userdata['email'], FILTER_VALIDATE_EMAIL)){
                $emailError = "Invalid email format.";
        }else{
            $email = cleanInputData($userdata['email']);

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
            $address = cleanInputData($userdata['address']);
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
    $hasErrors = !empty($firstnameError) || !empty($middlenameError) || !empty($lastnameError) || 
                 !empty($genderError) || !empty($dobError) || !empty($emailError) || 
                 !empty($telephoneError) || !empty($addressError) || !empty($passwordError) || 
                 !empty($confirm_passwordError);
    
    // If no errors, process the form and inject into database
    if(!$hasErrors) {
        // Database connection
        $servername = "localhost";
        $username = "root";
        $password_db = "";
        $database = "sps"; // Change to your database name
        
        $conn = new mysqli($servername, $username, $password_db, $database);
        
        // Check connection
        if ($conn->connect_error) {
            die("Connection failed: " . $conn->connect_error);
        }
        
        // Prepare SQL statement
        $sql = "INSERT INTO users (firstname, middlename, lastname, gender, dob, email, telephone, address, password) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)";
        
        $stmt = $conn->prepare($sql);
        
        // Hash password for security
        $hashed_password = password_hash($password, PASSWORD_DEFAULT);
        
        // Bind parameters
        $stmt->bind_param("sssssssss", $firstname, $middlename, $lastname, $gender, $dob, $email, $telephone, $address, $hashed_password);
        
        // Execute statement
        if ($stmt->execute()) {
            $successMessage = "Registration successful! User account created.";
            // Clear form data
            $firstname = $middlename = $lastname = $gender = $dob = $email = $telephone = $address = $password = $confirm_password = null;
        } else {
            $emailError = "Error: " . $stmt->error;
        }
        
        $stmt->close();
        $conn->close();
    }
}











