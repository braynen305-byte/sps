<?php

if(isset($_POST['username']) && isset($_POST['password'])) {
    $username = $_POST['username'];
    $password = $_POST['password'];

    // Perform authentication logic here (e.g., check against a database)
    if($username === 'admin' && $password === 'password') {
        // Authentication successful
        echo "Login successful!";
        // You can set session variables or redirect the user to a dashboard
    } else {
        // Authentication failed
        echo "Invalid username or password.";
    }
} else {
    echo "Please enter both username and password.";
}

?>