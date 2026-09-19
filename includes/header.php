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
        <div class="nav-shell">
            <ul class="nav-main-links">
                <li><a href="/sps/home.php">Home</a></li>
                <?php if (isset($_SESSION['logged_in']) && $_SESSION['logged_in'] === true): ?>
                    <li>
                        <?php
                        $role = strtolower($_SESSION['role'] ?? '');
                        $dashboardUrl = ($role === 'customer') ? '/sps/pages/customer_dashboard.php' : '/sps/pages/dashboard.php';
                        ?>
                        <a href="<?php echo $dashboardUrl; ?>">Dashboard</a>
                    </li>
                    <?php if ($role === 'customer'): ?>
                        <li><a href="/sps/pages/customer_profile.php">Profile</a></li>
                        <li><a href="/sps/pages/customer_support.php">Support</a></li>
                    <?php endif; ?>
                    <li><a href="/sps/logout.php">Logout</a></li>
                <?php else: ?>
                    <li><a href="/sps/index.php">Login</a></li>
                    <li><a href="/sps/customer_login.php">Customer Portal</a></li>
                <?php endif; ?>
                <li><a href="/sps/pages/customer_support.php">Support</a></li>
            </ul>
            <?php if (isset($_SESSION['logged_in']) && $_SESSION['logged_in'] === true): ?>
                <?php
                    $welcomeName = $_SESSION['full_name'] ?? $_SESSION['customer_name'] ?? ($_SESSION['email'] ?? 'User');
                    $profileHref = ($role ?? '') === 'customer' ? '/sps/pages/customer_profile.php' : '/sps/pages/profile.php';
                ?>
                <div class="nav-profile-menu">
                    <button type="button" class="nav-profile-button" aria-expanded="false" aria-label="Open profile menu">
                        <img src="/sps/images/profile-avatar.svg" alt="Profile" class="nav-user-avatar">
                        <span class="nav-user-name"><?php echo htmlspecialchars($welcomeName, ENT_QUOTES, 'UTF-8'); ?></span>
                    </button>
                    <div class="nav-profile-dropdown">
                        <a href="<?php echo $profileHref; ?>">Profile</a>
                        <a href="/sps/pages/customer_support.php">Support</a>
                        <a href="/sps/logout.php">Logout</a>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </nav>
    <section class="hero">
            <img src="/sps/images/sportsmarine.jpg" alt="Employee Registration" class="hero-image">
            <div class="hero-content">
            
            
        </div>
    </section>
    <div class="page-body">