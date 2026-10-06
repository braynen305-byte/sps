<?php
session_start();

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true || strtolower($_SESSION['role'] ?? '') !== 'staff') {
    header('Location: /sps/login.php');
    exit;
}

require_once '../includes/dbh.inc.php';
require_once '../includes/workorder_technicians.inc.php';
ensure_workorder_technicians_schema($conn);
$staffId = (int)($_SESSION['user_id'] ?? 0);
$ordersStmt = $conn->prepare("SELECT w.id, w.order_number, w.client_name, w.location, w.order_date, w.status, w.priority, w.updated_at, w.work_performed_by
    FROM workorders w
    WHERE w.work_performed_by = ? OR EXISTS (SELECT 1 FROM workorder_technicians wt WHERE wt.workorder_id = w.id AND wt.technician_id = ?)
    ORDER BY w.updated_at DESC LIMIT 10");
$ordersStmt->execute([$staffId, $staffId]);
$assignedOrders = $ordersStmt->fetchAll(PDO::FETCH_ASSOC);
$openCount = 0;
$progressCount = 0;
$completedCount = 0;
foreach ($assignedOrders as $assignedOrder) {
    $statusValue = strtolower(trim((string)($assignedOrder['status'] ?? 'open')));
    if (in_array($statusValue, ['completed', 'closed'], true)) {
        $completedCount++;
    } elseif ($statusValue === 'in progress') {
        $progressCount++;
    } else {
        $openCount++;
    }
}
$staffDisplayName = trim((string)($_SESSION['full_name'] ?? '')) ?: 'Staff member';

$title = 'Staff Dashboard';
require_once '../includes/header.php';
?>

<style>
    .staff-dashboard { width:min(1164px,calc(100% - 36px)); margin:32px auto 48px; color:#0f172a; }
    .staff-dashboard h2 { margin:0; font-size:clamp(24px,3vw,32px); }
    .staff-dashboard .subtitle { margin:6px 0 22px; color:#64748b; font-size:14px; }
    .staff-stats { display:grid; grid-template-columns:repeat(auto-fit,minmax(170px,1fr)); gap:12px; margin-bottom:20px; }
    .staff-stat,.staff-panel { background:#fff; border:1px solid #e5e7eb; border-radius:12px; box-shadow:0 3px 14px rgba(15,23,42,.05); }
    .staff-stat { padding:16px; }
    .staff-stat span { color:#64748b; font-size:11px; font-weight:800; text-transform:uppercase; letter-spacing:.05em; }
    .staff-stat strong { display:block; margin-top:6px; font-size:28px; color:#123d71; }
    .staff-panel { overflow:hidden; margin-bottom:18px; }
    .staff-panel h3 { margin:0; padding:13px 16px; color:#fff; background:linear-gradient(135deg,#123d71,#1d4ed8); font-size:15px; }
    .staff-actions { display:grid; grid-template-columns:repeat(auto-fit,minmax(180px,1fr)); gap:10px; padding:14px; }
    .staff-action { display:flex; align-items:center; min-height:54px; padding:12px 14px; border:1px solid #dbeafe; border-left:4px solid #2563eb; border-radius:9px; background:#f8fbff; color:#123d71; text-decoration:none; font-size:13px; font-weight:700; }
    .staff-action:hover { background:#eff6ff; }
    .staff-orders { width:100%; border-collapse:collapse; }
    .staff-orders th,.staff-orders td { padding:9px 11px; border-bottom:1px solid #eef2f7; text-align:left; font-size:12px; }
    .staff-orders th { background:#f8fafc; color:#475569; text-transform:uppercase; letter-spacing:.04em; font-size:10px; }
    .staff-orders tr:hover td { background:#f8fbff; }
    @media(max-width:640px) { .staff-dashboard { width:calc(100% - 36px); margin-top:22px; } .staff-orders th,.staff-orders td { padding:7px 5px; font-size:10px; } }
</style>

<main class="staff-dashboard">
    <h2>Staff Dashboard</h2>
    <p class="subtitle">Welcome, <?php echo htmlspecialchars($staffDisplayName, ENT_QUOTES, 'UTF-8'); ?>. Here’s your assigned work at a glance.</p>

    <div class="staff-stats">
        <div class="staff-stat"><span>Assigned jobs</span><strong><?php echo count($assignedOrders); ?></strong></div>
        <div class="staff-stat"><span>Open</span><strong><?php echo $openCount; ?></strong></div>
        <div class="staff-stat"><span>In progress</span><strong><?php echo $progressCount; ?></strong></div>
        <div class="staff-stat"><span>Completed</span><strong><?php echo $completedCount; ?></strong></div>
    </div>

    <section class="staff-panel">
        <h3>Quick Actions</h3>
        <div class="staff-actions">
            <a class="staff-action" href="/sps/pages/dashboard.php?scope=assigned">View My Work Orders</a>
            <a class="staff-action" href="/sps/pages/profile.php">My Profile</a>
            <a class="staff-action" href="/sps/pages/customer_support.php">Support</a>
        </div>
    </section>

    <section class="staff-panel">
        <h3>Recently Updated Assigned Work Orders</h3>
        <?php if (empty($assignedOrders)): ?>
            <p style="padding:16px;color:#64748b;margin:0;">You do not have assigned work orders right now.</p>
        <?php else: ?>
            <div style="overflow-x:auto;">
                <table class="staff-orders">
                    <thead><tr><th>Work order</th><th>Customer</th><th>Location</th><th>Status</th><th>Priority</th><th>Updated</th><th>Action</th></tr></thead>
                    <tbody>
                        <?php foreach ($assignedOrders as $assignedOrder): ?>
                            <tr>
                                <td><?php echo htmlspecialchars(!empty($assignedOrder['order_number']) ? $assignedOrder['order_number'] : 'WO' . str_pad((string)(int)$assignedOrder['id'], 4, '0', STR_PAD_LEFT), ENT_QUOTES, 'UTF-8'); ?></td>
                                <td><?php echo htmlspecialchars($assignedOrder['client_name'] ?? '', ENT_QUOTES, 'UTF-8'); ?></td>
                                <td><?php echo htmlspecialchars($assignedOrder['location'] ?? '', ENT_QUOTES, 'UTF-8'); ?></td>
                                <td><?php echo htmlspecialchars($assignedOrder['status'] ?? 'Open', ENT_QUOTES, 'UTF-8'); ?></td>
                                <td><?php echo htmlspecialchars($assignedOrder['priority'] ?? 'Normal', ENT_QUOTES, 'UTF-8'); ?></td>
                                <td><?php echo htmlspecialchars($assignedOrder['updated_at'] ?? '', ENT_QUOTES, 'UTF-8'); ?></td>
                                <td><a href="/sps/pages/view_workorder.php?id=<?php echo (int)$assignedOrder['id']; ?>" style="color:#1d4ed8;font-weight:700;">Open</a></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>
</main>

<?php require_once '../includes/footer.php'; ?>