<?php
session_start();

$role = strtolower($_SESSION['role'] ?? '');

session_unset();
session_destroy();

if ($role === 'customer') {
    header('Location: /sps/customer_login.php');
} else {
    header('Location: /sps/login.php');
}
exit;
