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
        if ($target && (int)($target['work_performed_by'] ?? 0) === $userId) {
            // fields technicians may update via quick form
            $allowed = ['status','work_description','entry_date','time_entered','time_departed','vessel_hours','labor_time','parts_cost'];
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
        SELECT workorder_id, MAX(edited_at) AS maxt
        FROM workorder_edits
        WHERE field_name = 'priority'
        GROUP BY workorder_id
    ) we2 ON we1.workorder_id = we2.workorder_id AND we1.edited_at = we2.maxt
    WHERE we1.field_name = 'priority'
) latest ON w.id = latest.workorder_id
SET w.priority = latest.new_value
WHERE IFNULL(w.priority, '') <> IFNULL(latest.new_value, '')";
                $conn->exec($syncSql);
        }
} catch (Exception $ex) {
        // ignore
}

$stmt = $conn->prepare('SELECT id, client_name, location, order_date, created_at, expected_end_date, status, priority, updated_at FROM workorders WHERE work_performed_by = ? ORDER BY updated_at DESC');
$stmt->execute([$userId]);
$workorders = $stmt->fetchAll(PDO::FETCH_ASSOC);

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
</style>

<?php if (empty($workorders)): ?>
    <p style="max-width:900px;margin:12px auto;">You have no assigned work orders at this time.</p>
<?php else: ?>
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
                $rowClass = (strtolower(trim($rawPriority)) === 'high') ? 'priority-row' : '';
            ?>
            <tr class="<?php echo $rowClass; ?>">
                <td><?php echo (int)$wo['id']; ?></td>
                <td><?php echo htmlspecialchars($wo['client_name'] ?? '', ENT_QUOTES, 'UTF-8'); ?></td>
                <td><?php echo htmlspecialchars($wo['location'] ?? '', ENT_QUOTES, 'UTF-8'); ?></td>
                <td><?php echo htmlspecialchars($wo['order_date'] ?? '', ENT_QUOTES, 'UTF-8'); ?></td>
                <td><?php echo htmlspecialchars($wo['created_at'] ?? '', ENT_QUOTES, 'UTF-8'); ?></td>
                <td><?php echo htmlspecialchars($wo['expected_end_date'] ?? '', ENT_QUOTES, 'UTF-8'); ?></td>
                <td><?php echo htmlspecialchars($wo['status'] ?? 'Open', ENT_QUOTES, 'UTF-8'); ?></td>
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
                            <?php $statuses = ['Open','In Progress','Completed','Closed','On Hold']; foreach ($statuses as $st): ?>
                                <option value="<?php echo htmlspecialchars($st, ENT_QUOTES, 'UTF-8'); ?>" <?php echo (($wo['status'] ?? '') === $st) ? 'selected' : ''; ?>><?php echo htmlspecialchars($st, ENT_QUOTES, 'UTF-8'); ?></option>
                            <?php endforeach; ?>
                        </select></label><br>
                        <label>Work Done / Notes:<br><textarea name="work_description" style="width:100%;min-height:100px;"></textarea></label>
                        <label>Entry Date: <input type="date" name="entry_date"></label>
                        <label>Time Entered: <input type="time" name="time_entered"></label>
                        <label>Time Departed: <input type="time" name="time_departed"></label>
                        <div style="margin-top:8px;"><button class="btn" type="submit">Save</button> <a href="#" onclick="toggleQuick(<?php echo (int)$wo['id']; ?>);return false;" class="btn btn-secondary">Cancel</a></div>
                    </form>
                </div>
            </td></tr>
        <?php endforeach; ?>
        </tbody>
    </table>
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