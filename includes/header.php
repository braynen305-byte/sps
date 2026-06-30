<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="/sps/styles/main.css">
   <?php
    if ($title === "Employee Registration") { echo "<link rel='stylesheet' href='/sps/styles/regs.css'>";}
    ?>
    <title><?php echo isset($title) ? $title : "Header"; ?></title>
</head>


<body>

<nav>
        <ul>
            <li><a href="/sps/home.php">Home</a></li>
            <?php if (isset($_SESSION['logged_in']) && $_SESSION['logged_in'] === true): ?>
                <li>
                    <?php
                    $role = strtolower($_SESSION['role'] ?? '');
                    $dashboardUrl = '/sps/pages/dashboard.php';
                    if ($role === 'technician') {
                        $dashboardUrl = '/sps/pages/technician_dashboard.php';
                    } elseif ($role === 'staff') {
                        $dashboardUrl = '/sps/pages/staff_dashboard.php';
                    } elseif ($role === 'admin') {
                        $dashboardUrl = '/sps/pages/admin_dashboard.php';
                    }
                    ?>
                    <a href="<?php echo $dashboardUrl; ?>">Dashboard</a>
                </li>
                <li><a href="/sps/logout.php">Logout</a></li>
            <?php else: ?>
                <li><a href="/sps/login.php">Login</a></li>
            <?php endif; ?>
            <li><a href=" ">Support</a></li>

        </ul>
    </nav>
    <section class="hero">
            <img src="/sps/images/sportsmarine.jpg" alt="Employee Registration" class="hero-image">
            <div class="hero-content">
            <h1>Welcome to the Employee SPS</h1>
            
        </div>
    </section>