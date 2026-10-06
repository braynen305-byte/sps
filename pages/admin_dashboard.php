<?php
session_start();

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true || strtolower($_SESSION['role'] ?? '') !== 'admin') {
    header('Location: /sps/login.php');
    exit;
}

require_once '../includes/dbh.inc.php';
require_once '../includes/property_entry_logs.inc.php';
ensure_property_entry_log_schema($conn);

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

$conn->exec("CREATE TABLE IF NOT EXISTS customer_service_requests (
    id INT AUTO_INCREMENT PRIMARY KEY,
    customer_id INT NOT NULL,
    request_number VARCHAR(30) DEFAULT NULL,
    service_type VARCHAR(100) NOT NULL,
    location VARCHAR(255) NOT NULL,
    preferred_date DATE DEFAULT NULL,
    customer_phone VARCHAR(30) DEFAULT NULL,
    urgency VARCHAR(30) NOT NULL DEFAULT 'Normal',
    equipment_details VARCHAR(255) DEFAULT NULL,
    problem_summary VARCHAR(255) DEFAULT NULL,
    description LONGTEXT NOT NULL,
    special_instructions LONGTEXT DEFAULT NULL,
    status VARCHAR(30) NOT NULL DEFAULT 'Pending',
    approved_workorder_id INT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX(customer_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

try {
    $conn->exec("ALTER TABLE customer_service_requests ADD COLUMN IF NOT EXISTS request_number VARCHAR(30) DEFAULT NULL");
    $conn->exec("ALTER TABLE customer_service_requests ADD COLUMN IF NOT EXISTS customer_phone VARCHAR(30) DEFAULT NULL");
    $conn->exec("ALTER TABLE customer_service_requests ADD COLUMN IF NOT EXISTS urgency VARCHAR(30) NOT NULL DEFAULT 'Normal'");
    $conn->exec("ALTER TABLE customer_service_requests ADD COLUMN IF NOT EXISTS equipment_details VARCHAR(255) DEFAULT NULL");
    $conn->exec("ALTER TABLE customer_service_requests ADD COLUMN IF NOT EXISTS problem_summary VARCHAR(255) DEFAULT NULL");
    $conn->exec("ALTER TABLE customer_service_requests ADD COLUMN IF NOT EXISTS special_instructions LONGTEXT DEFAULT NULL");
} catch (Exception $e) {
    // ignore migration issues
}

$pendingServiceRequests = $conn->query(
    "SELECT r.*, c.name AS customer_name, c.email AS customer_email FROM customer_service_requests r LEFT JOIN customers c ON c.id = r.customer_id WHERE LOWER(COALESCE(r.status, 'pending')) NOT IN ('accepted', 'rejected', 'closed') ORDER BY r.created_at DESC LIMIT 6"
)->fetchAll(PDO::FETCH_ASSOC);
$adminDashboardStats = ['workorders' => 0, 'open' => 0, 'urgent' => 0, 'requests' => count($pendingServiceRequests)];
try {
    $adminDashboardStats['workorders'] = (int)$conn->query('SELECT COUNT(*) FROM workorders')->fetchColumn();
    $adminDashboardStats['open'] = (int)$conn->query("SELECT COUNT(*) FROM workorders WHERE LOWER(COALESCE(status, 'open')) NOT IN ('completed', 'closed')")->fetchColumn();
    $adminDashboardStats['urgent'] = (int)$conn->query("SELECT COUNT(*) FROM workorders WHERE LOWER(COALESCE(priority, 'normal')) IN ('high', 'urgent', 'emergency')")->fetchColumn();
} catch (Throwable $statsError) {
    // Keep dashboard available if a legacy schema lacks a counter column.
}
$openVisitsForAdminReview = $conn->query("SELECT l.workorder_id, l.entry_date, l.time_entered, w.client_name, w.location, s.firstname, s.lastname
    FROM workorder_property_entry_logs l
    INNER JOIN workorders w ON w.id = l.workorder_id
    LEFT JOIN staff s ON s.id = COALESCE(l.technician_id, w.work_performed_by)
    WHERE COALESCE(l.departure_date, l.entry_date) < CURDATE() AND l.time_entered IS NOT NULL AND l.time_departed IS NULL
    ORDER BY l.entry_date ASC, l.time_entered ASC")->fetchAll(PDO::FETCH_ASSOC);

require_once '../includes/header.php';
?>

<style>
    .admin-dashboard-shell { width:min(1164px, calc(100% - 36px)); margin:30px auto 48px; color:#0f172a; }
    .admin-dashboard-shell .page-header { width:100%; display:flex; align-items:center; justify-content:space-between; gap:14px; margin:0 0 8px; padding:0; text-align:left; }
    .admin-dashboard-shell .greeting { width:100%; text-align:left; margin:6px 0 22px; color:#64748b; font-size:14px; }
    .dashboard-container { width:100%; margin:0 0 20px; padding:0; border:1px solid #e5e7eb; border-radius:12px; background:#fff; box-shadow:0 3px 14px rgba(15,23,42,.05); overflow:hidden; box-sizing:border-box; }
    .dashboard-container h3 { margin:0; padding:13px 16px; border-radius:0; background:linear-gradient(135deg,#123d71,#1d4ed8); color:#fff; font-size:15px; }
    .quick-actions-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(170px,1fr)); gap:12px; padding:16px; }
    .admin-stat-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(160px,1fr)); gap:12px; margin:0 0 20px; }
    .admin-stat-card { padding:15px 17px; border:1px solid #e5e7eb; border-radius:12px; background:#fff; box-shadow:0 3px 14px rgba(15,23,42,.05); }
    .admin-stat-card span { display:block; color:#64748b; font-size:11px; font-weight:800; text-transform:uppercase; letter-spacing:.045em; }
    .admin-stat-card strong { display:block; margin-top:5px; color:#123d71; font-size:28px; }
    .action-card { min-height:70px; display:flex; align-items:center; padding:14px 16px; border:1px solid #dbeafe; border-left:4px solid #2563eb; border-radius:10px; background:#f8fbff; box-shadow:0 2px 8px rgba(15,23,42,.04); transition:transform .16s ease,box-shadow .16s ease; }
    .action-card:hover { transform:translateY(-2px); box-shadow:0 8px 18px rgba(15,23,42,.1); }
    .action-card a { color:#123d71; text-decoration:none; font-size:14px; font-weight:700; }
    .action-card a:hover { color:#2563eb; }
    .action-card.primary { border-left-color:#0f766e; }
    .action-card.danger { border-left-color:#dc2626; }
    .action-card.info { border-left-color:#0284c7; }
    .dashboard-container > div:not(.quick-actions-grid), .dashboard-container > p, .dashboard-container > table { margin-left:16px; margin-right:16px; }
    .dashboard-container > div:not(.quick-actions-grid) { margin-top:14px; margin-bottom:14px; }
    .dashboard-container > p { padding:0 0 16px; color:#64748b; }
    .dashboard-container table { width:calc(100% - 32px) !important; margin-bottom:16px; border-collapse:collapse; }
    .dashboard-container table th { background:#f8fafc; color:#475569; text-transform:uppercase; letter-spacing:.04em; font-size:11px; }
    .dashboard-container table td,.dashboard-container table th { padding:9px 10px !important; border-bottom:1px solid #eef2f7; }
    @media(max-width:640px) { .admin-dashboard-shell { width:calc(100% - 36px); margin-top:22px; } .quick-actions-grid { padding:12px; gap:9px; } }
</style>

<main class="admin-dashboard-shell">
<div class="page-header">
    <h2 style="margin: 0;">Admin Dashboard</h2>
    <a href="/sps/pages/admin_dashboard.php" style="color: #007BFF; text-decoration: none;">← Back</a>
</div>

<p class="greeting">Welcome back, <?php echo htmlspecialchars($adminName, ENT_QUOTES, 'UTF-8'); ?>.</p>

<div class="admin-stat-grid">
    <div class="admin-stat-card"><span>Total Work Orders</span><strong><?php echo $adminDashboardStats['workorders']; ?></strong></div>
    <div class="admin-stat-card"><span>Open Queue</span><strong><?php echo $adminDashboardStats['open']; ?></strong></div>
    <div class="admin-stat-card"><span>High / Urgent Priority</span><strong><?php echo $adminDashboardStats['urgent']; ?></strong></div>
    <div class="admin-stat-card"><span>Requests to Review</span><strong><?php echo $adminDashboardStats['requests']; ?></strong></div>
</div>

<?php if (!empty($openVisitsForAdminReview)): ?>
    <div style="width:100%; margin:0 auto 20px; padding:14px 16px; border:1px solid #fca5a5; border-left:5px solid #dc2626; border-radius:12px; background:#fff7f7; box-sizing:border-box;">
        <h3 style="margin:0 0 6px; color:#991b1b;">Missed check-outs — admin follow-up (<?php echo count($openVisitsForAdminReview); ?>)</h3>
        <p style="margin:0 0 10px; color:#7f1d1d;">Contact the technician, record who called and when, then enter the reported departure time.</p>
        <div style="overflow-x:auto;">
            <table style="width:100%; border-collapse:collapse; background:#fff;">
                <thead><tr style="text-align:left; background:#fee2e2;">
                    <th style="padding:7px;">Work order</th><th style="padding:7px;">Customer / Location</th><th style="padding:7px;">Technician</th><th style="padding:7px;">Checked in</th><th style="padding:7px;">Action</th>
                </tr></thead>
                <tbody>
                    <?php foreach ($openVisitsForAdminReview as $openVisit): ?>
                        <tr style="border-top:1px solid #fecaca;">
                            <td style="padding:7px;">WO<?php echo str_pad((string)(int)$openVisit['workorder_id'], 4, '0', STR_PAD_LEFT); ?></td>
                            <td style="padding:7px;"><?php echo htmlspecialchars(trim((string)($openVisit['client_name'] ?? '') . ' — ' . (string)($openVisit['location'] ?? '')), ENT_QUOTES, 'UTF-8'); ?></td>
                            <td style="padding:7px;"><?php echo htmlspecialchars(trim((string)($openVisit['firstname'] ?? '') . ' ' . (string)($openVisit['lastname'] ?? '')) ?: 'Unassigned', ENT_QUOTES, 'UTF-8'); ?></td>
                            <td style="padding:7px;"><?php echo htmlspecialchars(date('M j, Y', strtotime($openVisit['entry_date'])) . ' at ' . date('g:i A', strtotime($openVisit['time_entered'])), ENT_QUOTES, 'UTF-8'); ?></td>
                            <td style="padding:7px;"><a href="/sps/pages/edit_workorder.php?id=<?php echo (int)$openVisit['workorder_id']; ?>" style="color:#b91c1c; font-weight:700;">Record departure</a></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php endif; ?>

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
            <a href="/sps/pages/manage_service_requests.php">Service Requests</a>
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

<div class="dashboard-container" style="margin-top: 18px;">
    <h3>Pending Customer Requests</h3>
    <div style="display:flex; justify-content:space-between; align-items:center; gap:10px; margin-bottom:12px; flex-wrap:wrap;">
        <strong style="font-size:14px; color:#1f2937;"><?php echo count($pendingServiceRequests); ?> waiting for review</strong>
        <a href="/sps/pages/manage_service_requests.php" style="color:#007BFF; text-decoration:none; font-weight:700;">Review all requests</a>
    </div>

    <?php if (empty($pendingServiceRequests)): ?>
        <p style="margin:0; color:#4b5563;">No customer requests are waiting for review.</p>
    <?php else: ?>
        <table style="width:100%; border-collapse:collapse; background:#fff;">
            <thead>
                <tr style="background:#f8fafc;">
                    <th style="padding:8px 10px; text-align:left; border-bottom:1px solid #e5e7eb; font-size:12px;">Request #</th>
                    <th style="padding:8px 10px; text-align:left; border-bottom:1px solid #e5e7eb; font-size:12px;">Customer</th>
                    <th style="padding:8px 10px; text-align:left; border-bottom:1px solid #e5e7eb; font-size:12px;">Issue</th>
                    <th style="padding:8px 10px; text-align:left; border-bottom:1px solid #e5e7eb; font-size:12px;">Status</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($pendingServiceRequests as $pending): ?>
                    <?php
                        $requestLabel = trim((string)($pending['request_number'] ?? ''));
                        if ($requestLabel === '') {
                            $requestLabel = 'SR-' . str_pad((string)(int)$pending['id'], 5, '0', STR_PAD_LEFT);
                        }
                    ?>
                    <tr style="border-bottom:1px solid #eef2f7;">
                        <td style="padding:8px 10px; font-size:12px; font-weight:700; color:#0f172a;"><?php echo htmlspecialchars($requestLabel, ENT_QUOTES, 'UTF-8'); ?></td>
                        <td style="padding:8px 10px; font-size:12px; color:#334155;"><?php echo htmlspecialchars($pending['customer_name'] ?? 'Customer', ENT_QUOTES, 'UTF-8'); ?></td>
                        <td style="padding:8px 10px; font-size:12px; color:#334155;"><?php echo htmlspecialchars(trim((string)($pending['problem_summary'] ?? $pending['description'] ?? 'Service request')), ENT_QUOTES, 'UTF-8'); ?></td>
                        <td style="padding:8px 10px; font-size:12px;">
                            <span style="display:inline-block; padding:4px 8px; border-radius:999px; background:rgba(59,130,246,0.12); color:#1d4ed8; font-weight:700;">
                                <?php echo htmlspecialchars($pending['status'] ?? 'Pending', ENT_QUOTES, 'UTF-8'); ?>
                            </span>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>
</main>

<?php require_once '../includes/footer.php'; ?>