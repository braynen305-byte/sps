<?php
session_start();

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true || strtolower($_SESSION['role'] ?? '') !== 'staff') {
    header('Location: /sps/login.php');
    exit;
}

$title = 'Staff Dashboard';
require_once '../includes/header.php';
?>

<h2>Staff Dashboard</h2>
<p>Welcome, staff member.</p>

<?php require_once '../includes/footer.php'; ?>