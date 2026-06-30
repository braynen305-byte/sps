<?php
session_start();

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true || strtolower($_SESSION['role'] ?? '') !== 'technician') {
    header('Location: /sps/login.php');
    exit;
}

$title = 'Technician Dashboard';
require_once '../includes/header.php';
?>

<h2>Technician Dashboard</h2>
<p>Welcome, technician.</p>

<?php require_once '../includes/footer.php'; ?>