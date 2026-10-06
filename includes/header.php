<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (!empty($_SESSION['logged_in']) && $_SESSION['logged_in'] === true && empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="/sps/styles/main.css?v=<?php echo (int)@filemtime(__DIR__ . '/../styles/main.css'); ?>">
    <link rel="stylesheet" href="/sps/styles/mobile.css?v=<?php echo (int)@filemtime(__DIR__ . '/../styles/mobile.css'); ?>">
   <?php
    if ($title === "Employee Registration") { echo "<link rel='stylesheet' href='/sps/styles/regs.css'>";}
    ?>
    <title><?php echo isset($title) ? $title : "Header"; ?></title>
</head>


<body class="<?php echo in_array((string)($title ?? ''), ['Service Portal - Login', 'Customer Portal Login'], true) ? 'portal-login-page' : ''; ?>">

<nav class="site-nav">
        <div class="nav-shell">
            <button type="button" class="nav-menu-toggle" aria-expanded="false" aria-controls="primary-nav-links" aria-label="Open navigation menu">
                <svg aria-hidden="true" viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M4 6h16M4 12h16M4 18h16"></path></svg>
            </button>
            <ul class="nav-main-links" id="primary-nav-links">
                <li><a href="/sps/index.php">Home</a></li>
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
                    <?php endif; ?>
                    <li><a href="/sps/logout.php">Logout</a></li>
                <?php else: ?>
                    <li><a href="/sps/login.php">Staff Portal</a></li>
                    <li><a href="/sps/customer_login.php">Customer Portal</a></li>
                <?php endif; ?>
                <li><a href="/sps/pages/customer_support.php">Support</a></li>
                <li><a href="/sps/pages/about_us.php">About Us</a></li>
            </ul>
            <?php if (isset($_SESSION['logged_in']) && $_SESSION['logged_in'] === true): ?>
                <?php
                    $welcomeName = $_SESSION['full_name'] ?? $_SESSION['customer_name'] ?? ($_SESSION['email'] ?? 'User');
                    $profileHref = ($role ?? '') === 'customer' ? '/sps/pages/customer_profile.php' : '/sps/pages/profile.php';
                ?>
                <div class="nav-profile-menu">
                    <div class="nav-notification-menu">
                        <button type="button" class="nav-notification-button" aria-expanded="false" aria-label="Open notifications" title="Notifications">
                            <svg aria-hidden="true" viewBox="0 0 24 24" width="21" height="21" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9"></path><path d="M10 21h4"></path></svg>
                            <span class="nav-notification-count" hidden>0</span>
                        </button>
                        <div class="nav-notification-dropdown" aria-label="Recent notifications">
                            <div class="nav-notification-heading">Notifications</div>
                            <div class="nav-notification-items"><div class="nav-notification-empty">Loading notifications…</div></div>
                            <a class="nav-notification-all" href="/sps/pages/notifications.php">View all notifications</a>
                        </div>
                    </div>
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