<?php

$host = "localhost";
$dbname = "sps";
$username = "root"; 
$dbpassword = "";


try {
    $conn = new PDO("mysql:host=$host;dbname=$dbname", $username, $dbpassword);
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $error) {
    echo "Connection failed: " . $error->getMessage();
}
