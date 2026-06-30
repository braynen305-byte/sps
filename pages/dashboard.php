<?php
session_start();

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header('Location: /sps/login.php');
    exit;
}

$title = 'Dashboard';
require_once '../includes/header.php';
?>

<h2>Welcome to your dashboard</h2>
<p>You are logged in successfully.</p>






<?php
require_once '../includes/footer.php';
?>