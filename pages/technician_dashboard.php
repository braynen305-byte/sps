<?php
session_start();

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true || strtolower($_SESSION['role'] ?? '') !== 'technician') {
    header('Location: /sps/login.php');
    exit;
}

$title = 'Technician Dashboard';
require_once '../includes/header.php';
?>

<?php
require_once '../includes/dbh.inc.php';
require_once '../includes/property_entry_logs.inc.php';
require_once '../includes/workorder_technicians.inc.php';
require_once '../includes/customer_update_state.inc.php';
ensure_property_entry_log_schema($conn);
ensure_workorder_technicians_schema($conn);
ensure_staff_update_state_schema($conn);

$userId = (int)($_SESSION['user_id'] ?? 0);
$message = '';

// handle quick updates from technician (inline form)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'quick_update') {
    $woId = (int)($_POST['workorder_id'] ?? 0);
    if ($woId) {
        // load workorder and ensure assigned to this technician
        $s = $conn->prepare('SELECT * FROM workorders WHERE id = ?');
        $s->execute([$woId]);
        $target = $s->fetch(PDO::FETCH_ASSOC);
        if ($target && is_workorder_technician_assigned($conn, $woId, $userId, $target['work_performed_by'] ?? 0)) {
            // fields technicians may update via quick form
            $allowed = ['status','vessel_hours','parts_cost'];
            $changes = [];
            $updateParts = [];
            $params = [];

            foreach ($allowed as $f) {
                $new = $_POST[$f] ?? null;
                if ($new === '') $new = null;
                $old = $target[$f] ?? null;
                if ((string)$old !== (string)$new) {
                    $changes[] = ['field'=>$f, 'old'=>$old, 'new'=>$new];
                    $updateParts[] = "$f = ?";
                    $params[] = $new;
                }
            }

            if (!empty($updateParts)) {
                $params[] = $woId;
                $upd = $conn->prepare('UPDATE workorders SET ' . implode(', ', $updateParts) . ' WHERE id = ?');
                $upd->execute($params);

                // ensure edits table exists
                $conn->exec("CREATE TABLE IF NOT EXISTS workorder_edits (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    workorder_id INT NOT NULL,
                    field_name VARCHAR(255) NOT NULL,
                    old_value LONGTEXT,
                    new_value LONGTEXT,
                    edited_by INT,
                    edited_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    INDEX(workorder_id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

                $ins = $conn->prepare('INSERT INTO workorder_edits (workorder_id, field_name, old_value, new_value, edited_by) VALUES (?, ?, ?, ?, ?)');
                foreach ($changes as $c) {
                    $ins->execute([$woId, $c['field'], $c['old'], $c['new'], $userId]);
                }

                $message = 'Work order updated.';
            } else {
                $message = 'No changes detected.';
            }
        } else {
            $message = 'Work order not found or not assigned to you.';
        }
    }
}

$stmt = null;
// Sync priorities from edits so the list reflects recent changes immediately
try {
        $tbl = $conn->query("SHOW TABLES LIKE 'workorder_edits'");
        if ($tbl && $tbl->rowCount() > 0) {
                $syncSql = "UPDATE workorders w
JOIN (
    SELECT we1.workorder_id, we1.new_value
    FROM workorder_edits we1
    JOIN (
        SELECT workorder_id, MAX(id) AS max_id
        FROM workorder_edits
        WHERE field_name = 'priority'
        GROUP BY workorder_id
    ) we2 ON we1.workorder_id = we2.workorder_id AND we1.id = we2.max_id
    WHERE we1.field_name = 'priority'
) latest ON w.id = latest.workorder_id
SET w.priority = latest.new_value
WHERE IFNULL(w.priority, '') <> IFNULL(latest.new_value, '')";
                $conn->exec($syncSql);
        }
} catch (Exception $ex) {
        // ignore
}

$stmt = $conn->prepare('SELECT w.id, w.client_name, w.location, w.order_date, w.created_at, w.expected_end_date, w.status, w.priority, w.updated_at, w.work_performed_by FROM workorders w WHERE w.work_performed_by = ? OR EXISTS (SELECT 1 FROM workorder_technicians wt WHERE wt.workorder_id = w.id AND wt.technician_id = ?) ORDER BY w.updated_at DESC');
$stmt->execute([$userId, $userId]);
$workorders = $stmt->fetchAll(PDO::FETCH_ASSOC);
$unreadWorkorders = [];
foreach ($workorders as $workorder) {
    $workorderId = (int)$workorder['id'];
    $latestActivity = customer_workorder_latest_activity($conn, $workorderId, (string)($workorder['created_at'] ?? ''));
    $lastSeen = staff_update_seen_at($conn, $userId, 'workorder', $workorderId);
    $baseline = $lastSeen ?? (string)($workorder['created_at'] ?? '');
    if ($latestActivity !== '' && $baseline !== '' && $latestActivity > $baseline) {
        $unreadWorkorders[$workorderId] = true;
    }
}
$activePropertyVisitsByWorkorder = [];
$activeVisitStmt = $conn->prepare('SELECT workorder_id, entry_date, time_entered FROM workorder_property_entry_logs WHERE COALESCE(technician_id, logged_by) = ? AND time_entered IS NOT NULL AND time_departed IS NULL ORDER BY id DESC');
$activeVisitStmt->execute([$userId]);
foreach ($activeVisitStmt->fetchAll(PDO::FETCH_ASSOC) as $activeVisit) {
    $activeWorkorderId = (int)$activeVisit['workorder_id'];
    if (!isset($activePropertyVisitsByWorkorder[$activeWorkorderId])) {
        $activePropertyVisitsByWorkorder[$activeWorkorderId] = $activeVisit;
    }
}

?>

<h2>Technician Dashboard</h2>
<p>Welcome, <?php echo htmlspecialchars($_SESSION['full_name'] ?? 'Technician', ENT_QUOTES, 'UTF-8'); ?>. Here are your assigned work orders.</p>

<?php if ($message): ?><p style="color:green"><?php echo htmlspecialchars($message, ENT_QUOTES, 'UTF-8'); ?></p><?php endif; ?>

<style>
    .tech-wo-table { width:100%; max-width:1100px; margin:12px auto 80px; border-collapse:collapse; font-family: Arial, sans-serif; }
    .tech-wo-table th, .tech-wo-table td { padding:10px; border:1px solid #e6e6e6; text-align:left; }
    .quick-update { display:none; margin-top:8px; background:#f9f9fb; padding:10px; border-radius:6px; }
    .btn { padding:6px 10px; border-radius:4px; background:#007BFF; color:#fff; text-decoration:none; }
    .btn-secondary { background:#6c757d; }
    .priority-row td { background:#fff0f0; }
    .priority-row .priority-cell { color:#b10000; font-weight:800; }
    .tech-wo-table tr.tech-unread-update td { background:#eff6ff; }
    .tech-wo-table tr.tech-unread-update td:first-child { box-shadow:inset 3px 0 #2563eb; }
</style>

<?php if (empty($workorders)): ?>
    <p style="max-width:900px;margin:12px auto;">You have no assigned work orders at this time.</p>
<?php else: ?>
    <div class="tech-wo-table-wrap">
    <table class="tech-wo-table">
        <thead>
            <tr>
                <th>#</th>
                <th>Client</th>
                <th>Location</th>
                <th>Order Date</th>
                <th>Date Created</th>
                <th>Expected End Date</th>
                <th>Status</th>
                <th>Priority</th>
                <th>Updated</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($workorders as $wo): ?>
            <?php
                // force-fetch current priority from DB to avoid stale/overwritten values
                $rawPriority = 'Normal';
                try {
                    $pstmt = $conn->prepare('SELECT priority FROM workorders WHERE id = ? LIMIT 1');
                    $pstmt->execute([(int)$wo['id']]);
                    $prow = $pstmt->fetch(PDO::FETCH_ASSOC);
                    if ($prow && isset($prow['priority'])) $rawPriority = (string)$prow['priority'];
                } catch (Exception $e) { /* ignore and use fallback */ }
                $rowClass = in_array(strtolower(trim($rawPriority)), ['high', 'urgent', 'emergency'], true) ? 'priority-row' : '';
                $activePropertyVisit = $activePropertyVisitsByWorkorder[(int)$wo['id']] ?? null;
                $isCompletedWorkOrder = in_array(strtolower(trim((string)($wo['status'] ?? ''))), ['completed', 'closed'], true);
                $hasUnreadUpdate = !empty($unreadWorkorders[(int)$wo['id']]);
            ?>
            <tr class="<?php echo trim($rowClass . ($hasUnreadUpdate ? ' tech-unread-update' : '')); ?>">
                <td><?php echo (int)$wo['id']; ?><?php if ($hasUnreadUpdate): ?><span style="margin-left:5px;color:#1d4ed8;font-size:10px;font-weight:800;">NEW</span><?php endif; ?></td>
                <td><?php echo htmlspecialchars($wo['client_name'] ?? '', ENT_QUOTES, 'UTF-8'); ?></td>
                <td><?php echo htmlspecialchars($wo['location'] ?? '', ENT_QUOTES, 'UTF-8'); ?></td>
                <td><?php echo htmlspecialchars($wo['order_date'] ?? '', ENT_QUOTES, 'UTF-8'); ?></td>
                <td><?php echo htmlspecialchars($wo['created_at'] ?? '', ENT_QUOTES, 'UTF-8'); ?></td>
                <td><?php echo htmlspecialchars($wo['expected_end_date'] ?? '', ENT_QUOTES, 'UTF-8'); ?></td>
                <td><?php echo htmlspecialchars($wo['status'] ?? 'Open', ENT_QUOTES, 'UTF-8'); ?>
                    <?php if (!$isCompletedWorkOrder && is_workorder_technician_assigned($conn, (int)$wo['id'], $userId, $wo['work_performed_by'] ?? 0)): ?>
                        <div style="margin-top:4px; color:<?php echo $activePropertyVisit ? '#b91c1c' : '#1d4ed8'; ?>; font-size:11px; font-weight:700;">
                            <?php echo $activePropertyVisit ? 'STILL CHECKED IN — CHECK OUT BEFORE LEAVING' : 'REMINDER: CHECK IN WHEN YOU ARRIVE'; ?>
                        </div>
                    <?php endif; ?>
                </td>
                <td class="priority-cell"><?php echo htmlspecialchars($rawPriority, ENT_QUOTES, 'UTF-8'); ?></td>
                <td><?php echo htmlspecialchars($wo['updated_at'] ?? '', ENT_QUOTES, 'UTF-8'); ?></td>
                <td>
                    <a class="btn" href="/sps/pages/view_workorder.php?id=<?php echo (int)$wo['id']; ?>">View</a>
                    <a class="btn btn-secondary" href="/sps/pages/edit_workorder.php?id=<?php echo (int)$wo['id']; ?>">Edit</a>
                    <a class="btn" href="#" onclick="toggleQuick(<?php echo (int)$wo['id']; ?>);return false;">Quick Update</a>
                </td>
            </tr>
            <tr id="quick-row-<?php echo (int)$wo['id']; ?>" style="display:none;"><td colspan="10">
                <div class="quick-update" id="quick-<?php echo (int)$wo['id']; ?>">
                    <form method="post" onsubmit="return confirm('Apply quick update?');">
                        <input type="hidden" name="action" value="quick_update">
                        <input type="hidden" name="workorder_id" value="<?php echo (int)$wo['id']; ?>">
                        <label>Status: <select name="status">
                            <?php $statuses = ['Pending','Open','In Progress','Waiting for Parts','Completed','Closed','On Hold']; foreach ($statuses as $st): ?>
                                <option value="<?php echo htmlspecialchars($st, ENT_QUOTES, 'UTF-8'); ?>" <?php echo (($wo['status'] ?? '') === $st) ? 'selected' : ''; ?>><?php echo htmlspecialchars($st, ENT_QUOTES, 'UTF-8'); ?></option>
                            <?php endforeach; ?>
                        </select></label><br>
                        <div style="margin-top:8px;"><button class="btn" type="submit">Save</button> <a href="#" onclick="toggleQuick(<?php echo (int)$wo['id']; ?>);return false;" class="btn btn-secondary">Cancel</a></div>
                    </form>
                </div>
            </td></tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
<?php endif; ?>

<script>
function toggleQuick(id){
    var row = document.getElementById('quick-row-'+id);
    if(!row) return;
    if(row.style.display === 'none' || row.style.display === ''){
        row.style.display = 'table-row';
    } else {
        row.style.display = 'none';
    }
}
</script>

<?php require_once '../includes/footer.php'; ?>