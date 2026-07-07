<?php
session_start();

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true || strtolower($_SESSION['role'] ?? '') !== 'admin') {
    header('Location: /sps/login.php');
    exit;
}

require_once '../includes/dbh.inc.php';

$adminName = trim($_SESSION['full_name'] ?? '');
if ($adminName === '') {
    $userId = $_SESSION['user_id'] ?? 0;

    if ($userId > 0) {
        $stmt = $conn->prepare('SELECT firstname, lastname FROM staff WHERE id = ? LIMIT 1');
        if ($stmt) {
            $stmt->execute([$userId]);
            $staff = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($staff) {
                $adminName = trim(($staff['firstname'] ?? '') . ' ' . ($staff['lastname'] ?? ''));
                if ($adminName !== '') {
                    $_SESSION['full_name'] = $adminName;
                }
            }
        }
    }
}

if ($adminName === '') {
    $adminName = $_SESSION['email'] ?? 'Administrator';
}

$title = 'Admin Dashboard';
require_once '../includes/header.php';
?>

<style>
    .dashboard-container {
        background-color: rgb(234, 234, 234);
        border-radius: 10px;
        padding: 30px;
        box-shadow: 0 0 10px rgba(0, 0, 0, 0.1);
        margin-top: 20px;
        width: 85%;
        margin: 0 auto;
    }
    .dashboard-container h3 {
        background-color: #007BFF;
        color: white;
        padding: 12px 15px;
        margin: -30px -30px 20px -30px;
        border-radius: 10px 10px 0 0;
        font-size: 16px;
    }
    .quick-actions-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
        gap: 15px;
    }
    .action-card {
        background-color: white;
        padding: 20px;
        border-radius: 8px;
        text-align: center;
        box-shadow: 0 2px 5px rgba(0, 0, 0, 0.1);
        transition: all 0.3s ease;
        text-decoration: none;
        display: flex;
        flex-direction: column;
        justify-content: center;
        border-left: 4px solid #007BFF;
    }
    .action-card:hover {
        box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
        transform: translateY(-2px);
    }
    .action-card a {
        color: #333;
        text-decoration: none;
        font-weight: bold;
        font-size: 14px;
    }
    .action-card a:hover {
        color: #007BFF;
    }
    .action-card.primary {
        border-left-color: #28a745;
    }
    .action-card.danger {
        border-left-color: #dc3545;
    }
    .action-card.info {
        border-left-color: #17a2b8;
    }
</style>

<div class="page-header">
    <h2 style="margin: 0;">Admin Dashboard</h2>
    <a href="/sps/pages/admin_dashboard.php" style="color: #007BFF; text-decoration: none;">← Back</a>
</div>

<p class="greeting">Welcome back, <?php echo htmlspecialchars($adminName, ENT_QUOTES, 'UTF-8'); ?>.</p>

<div class="dashboard-container">
    <h3>Quick Actions</h3>
    <div class="quick-actions-grid">
        <div class="action-card primary">
            <a href="/sps/pages/manage_customers.php">Manage Customers</a>
        </div>
        <div class="action-card">
            <a href="/sps/pages/manage_staff.php">Manage Staff</a>
        </div>
        <div class="action-card info">
            <a href="/sps/pages/workorder_dashboard.php">Manage Work Orders</a>
        </div>
        <div class="action-card">
            <a href="/sps/pages/users.php">View Users</a>
        </div>
        <div class="action-card">
            <a href="#">Reports</a>
        </div>
        <div class="action-card">
            <a href="#">Settings</a>
        </div>
        <div class="action-card danger">
            <a href="#">Support</a>
        </div>
    </div>
</div>

<?php require_once '../includes/footer.php'; ?>