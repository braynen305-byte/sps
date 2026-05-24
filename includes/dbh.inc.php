<?php

$host = "localhost";
$dbname = "serviceportalsystem";
$username = "root"; 
$dbpassword = "";


try {
    $conn = new PDO("mysql:host=$host;dbname=$dbname", $username, $dbpassword);
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    echo "Connection failed: " . $e->getMessage();
}
