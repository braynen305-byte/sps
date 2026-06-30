<?php
session_start();

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true || strtolower($_SESSION['role'] ?? '') !== 'admin') {
    header('Location: /sps/login.php');
    exit;
}

$title = 'Admin Dashboard';
require_once '../includes/header.php';
?>

<h2>Admin Dashboard</h2>
<p>Welcome, administrator.</p>

<?php require_once '../includes/footer.php'; ?>