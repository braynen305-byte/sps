<?php
session_start();

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header('Location: /sps/login.php');
    exit;
}

 $title = 'Dashboard';
 require_once '../includes/dbh.inc.php';

// ensure session role is populated (use DB if session missing)
 $userId = (int)($_SESSION['user_id'] ?? 0);
 $dbRoleFound = null;
 if (empty($_SESSION['role'])) {
     try {
         if ($userId) {
             $r = $conn->prepare('SELECT role FROM staff WHERE id = ? LIMIT 1');
             $r->execute([$userId]);
             $row = $r->fetch(PDO::FETCH_ASSOC);
             if ($row && strlen(trim((string)$row['role']))) {
                 $dbRoleFound = strtolower(trim((string)$row['role']));
             }
         }
         // fallback: try email from session
         if ($dbRoleFound === null && !empty($_SESSION['email'])) {
             $r2 = $conn->prepare('SELECT role FROM staff WHERE LOWER(email) = LOWER(?) LIMIT 1');
             $r2->execute([$_SESSION['email']]);
             $row2 = $r2->fetch(PDO::FETCH_ASSOC);
             if ($row2 && strlen(trim((string)$row2['role']))) {
                 $dbRoleFound = strtolower(trim((string)$row2['role']));
             }
         }
     } catch (Exception $e) {
         // ignore DB errors here; we'll show diagnostic info below
     }

    if ($dbRoleFound !== null) {
        $_SESSION['role'] = $dbRoleFound;
    }
}

 $currentRole = strtolower($_SESSION['role'] ?? '');

// If user is an admin, send them to the dedicated admin dashboard
if ($currentRole === 'admin') {
    header('Location: /sps/pages/admin_dashboard.php');
    exit;
}
 $message = '';

 require_once '../includes/header.php';

// detect whether workorders has a `status` column
$hasStatus = false;
try {
    $col = $conn->query("SHOW COLUMNS FROM workorders LIKE 'status'")->fetch(PDO::FETCH_ASSOC);
    $hasStatus = !empty($col);
} catch (Exception $e) {
    $hasStatus = false;
}

// Technician quick-update POST handler
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'quick_update') {
    $woId = (int)($_POST['workorder_id'] ?? 0);
    if ($woId) {
        $s = $conn->prepare('SELECT * FROM workorders WHERE id = ?');
        $s->execute([$woId]);
        $target = $s->fetch(PDO::FETCH_ASSOC);
        if ($target && $currentRole === 'technician' && (int)($target['work_performed_by'] ?? 0) === $userId) {
            $allowed = ['status','work_description','entry_date','time_entered','time_departed','vessel_hours','labor_time','parts_cost'];
            if (!$hasStatus) {
                // remove status from allowed updates when column is missing
                $allowed = array_values(array_filter($allowed, function($f){ return $f !== 'status'; }));
            }
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
            $message = 'Work order not found or you are not assigned to it.';
        }
    }
}

// Ensure priorities reflect latest edits: sync workorders.priority from workorder_edits (if table exists)
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
        // ignore sync errors
}

?>

<?php if ($currentRole === 'technician'): ?>
    <h2>Technician Dashboard</h2>
    <p>Welcome, <?php echo htmlspecialchars($_SESSION['full_name'] ?? 'Technician', ENT_QUOTES, 'UTF-8'); ?>.</p>

    <style>
        .dashboard-container { background-color: rgb(234, 234, 234); border-radius: 10px; padding: 20px; box-shadow: 0 0 10px rgba(0,0,0,0.06); margin-top:20px; width:85%; margin:0 auto 18px; }
        .dashboard-container h3 { background:#007BFF; color:#fff; padding:12px 15px; margin:-20px -20px 16px -20px; border-radius:8px 8px 0 0; font-size:16px; }
        .quick-actions-grid { display:grid; grid-template-columns: repeat(auto-fit,minmax(160px,1fr)); gap:12px; }
        .action-card { background:#fff; padding:16px; border-radius:8px; text-align:center; box-shadow:0 2px 6px rgba(0,0,0,0.06); border-left:4px solid #007BFF; text-decoration:none; color:#333; display:flex; align-items:center; justify-content:center; }
        .action-card a { color:inherit; text-decoration:none; font-weight:700; }
        .action-card.primary { border-left-color:#28a745; }
    </style>

    <div class="dashboard-container">
        <h3>Quick Actions</h3>
        <div class="quick-actions-grid">
            <div class="action-card primary"><a href="#assigned-list">View Assigned Work Orders</a></div>
            <div class="action-card">
                <?php if ($currentRole === 'technician'): ?>
                    <a href="#assigned-list">Browse Work Orders</a>
                <?php else: ?>
                    <a href="/sps/pages/manage_workorders.php">Browse Work Orders</a>
                <?php endif; ?>
            </div>
            <div class="action-card"><a href="#" onclick="promptOpenById(event);">Open Work Order (by ID)</a></div>
            <div class="action-card"><a href="/sps/pages/profile.php">My Profile</a></div>
            <div class="action-card"><a href="#">Report Issue</a></div>
            <div class="action-card"><a href="#">Support</a></div>
        </div>
    </div>

    <?php if ($message): ?><p style="color:green"><?php echo htmlspecialchars($message, ENT_QUOTES, 'UTF-8'); ?></p><?php endif; ?>

    <?php
    // search support (role-restricted to assigned WOs)
    $q = trim($_GET['q'] ?? '');
    $searchResults = [];
    if ($q !== '') {
        $like = "%$q%";
        $searchStmt = $conn->prepare('SELECT id, client_name, location, order_date, status, priority, updated_at FROM workorders WHERE work_performed_by = ? AND (client_name LIKE ? OR location LIKE ? OR id = ?) ORDER BY updated_at DESC LIMIT 200');
        try {
            $searchStmt->execute([$userId, $like, $like, (int)$q]);
            $searchResults = $searchStmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            // handle missing `status` column by retrying without it
            if ($e->getCode() === '42S22') {
                $searchStmt = $conn->prepare('SELECT id, client_name, location, order_date, updated_at FROM workorders WHERE work_performed_by = ? AND (client_name LIKE ? OR location LIKE ? OR id = ?) ORDER BY updated_at DESC LIMIT 200');
                $searchStmt->execute([$userId, $like, $like, (int)$q]);
                $rows = $searchStmt->fetchAll(PDO::FETCH_ASSOC);
                foreach ($rows as &$r) { $r['status'] = 'Open'; $r['priority'] = 'Normal'; }
                $searchResults = $rows;
            } else {
                throw $e;
            }
        }
    }

    // assigned workorders list
    $stmt = $conn->prepare('SELECT id, client_name, location, order_date, status, priority, updated_at FROM workorders WHERE work_performed_by = ? ORDER BY updated_at DESC');
    try {
        $stmt->execute([$userId]);
        $workorders = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        if ($e->getCode() === '42S22') {
            $stmt = $conn->prepare('SELECT id, client_name, location, order_date, updated_at FROM workorders WHERE work_performed_by = ? ORDER BY updated_at DESC');
            $stmt->execute([$userId]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as &$r) { $r['status'] = 'Open'; $r['priority'] = 'Normal'; }
            $workorders = $rows;
        } else {
            throw $e;
        }
    }

    // prepare history statements (assignment and work-performed)
    $assignStmt = $conn->prepare('SELECT woe.*, s.full_name AS editor_name, s2.full_name AS assignee_name FROM workorder_edits woe LEFT JOIN staff s ON s.id = woe.edited_by LEFT JOIN staff s2 ON s2.id = woe.new_value WHERE woe.workorder_id = ? AND woe.field_name = ? ORDER BY woe.edited_at DESC');
    $wpStmt = $conn->prepare('SELECT woe.*, s.full_name AS editor_name FROM workorder_edits woe LEFT JOIN staff s ON s.id = woe.edited_by WHERE woe.workorder_id = ? AND woe.field_name IN ("work_description","time_entered","time_departed","vessel_hours","labor_time","parts_cost") ORDER BY woe.edited_at DESC');
    ?>

    <div style="max-width:1100px;margin:12px auto;display:flex;gap:12px;align-items:center;">
        <a class="btn" href="#assigned-list">View Assigned Work Orders</a>
        <a class="btn btn-secondary" href="/sps/pages/manage_workorders.php">All Work Orders</a>
        <form method="get" style="margin-left:auto;" onsubmit="return !!document.getElementById('search-q').value;">
            <input id="search-q" type="search" name="q" placeholder="Search by ID, client or location" value="<?php echo htmlspecialchars($q, ENT_QUOTES, 'UTF-8'); ?>">
            <button class="btn" type="submit">Search</button>
            <?php if ($q !== ''): ?><a href="/sps/pages/dashboard.php" class="btn btn-secondary">Clear</a><?php endif; ?>
        </form>
    </div>

    <style>
        .tech-wo-table { width:100%; max-width:1100px; margin:12px auto 80px; border-collapse:collapse; font-family: Arial, sans-serif; }
        .tech-wo-table th, .tech-wo-table td { padding:10px; border:1px solid #e6e6e6; text-align:left; }
        .quick-update { display:none; margin-top:8px; background:#f9f9fb; padding:10px; border-radius:6px; }
        .btn { padding:6px 10px; border-radius:4px; background:#007BFF; color:#fff; text-decoration:none; }
        .btn-secondary { background:#6c757d; }
        .priority-high { color:#c00; font-weight:700; }
        /* highlight entire row for high priority workorders */
        .priority-row td { background: #fff0f0; }
        .priority-row td.priority-cell { color:#b10000; font-weight:800; }
    </style>

    <?php if (empty($workorders)): ?>
        <p style="max-width:900px;margin:12px auto;">You have no assigned work orders at this time.</p>
    <?php else: ?>
        <?php if (!empty($searchResults)): ?>
            <h3 style="max-width:1100px;margin:12px auto 0;">Search results for "<?php echo htmlspecialchars($q, ENT_QUOTES, 'UTF-8'); ?>"</h3>
            <table class="tech-wo-table" style="max-width:1100px;margin:12px auto 20px;">
                <thead>
                            <tr><th>#</th><th>Client</th><th>Location</th><th>Assigned By</th><th>Order Date</th><th>Priority</th><th>Status</th><th>Updated</th><th>Actions</th></tr>
                </thead>
                <tbody>
                <?php foreach ($searchResults as $sres): ?>
                    <?php $rowClass = (isset($sres['priority']) && strtolower(trim((string)$sres['priority'])) === 'high') ? 'priority-row' : ''; ?>
                    <tr class="<?php echo $rowClass; ?>">
                        <td><?php echo (int)$sres['id']; ?></td>
                        <td><?php echo htmlspecialchars($sres['client_name'] ?? '', ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><?php echo htmlspecialchars($sres['location'] ?? '', ENT_QUOTES, 'UTF-8'); ?></td>
                            <?php
                                $assignedBy = '';
                                try {
                                    $ax = $conn->prepare("SELECT e.*, s.firstname, s.lastname FROM workorder_edits e LEFT JOIN staff s ON s.id = e.edited_by WHERE e.workorder_id = ? AND e.field_name = 'work_performed_by' ORDER BY e.edited_at DESC LIMIT 1");
                                    $ax->execute([(int)$sres['id']]);
                                    $ar = $ax->fetch(PDO::FETCH_ASSOC);
                                    if ($ar) $assignedBy = trim(($ar['firstname'] ?? '') . ' ' . ($ar['lastname'] ?? '')) ?: ('User '.($ar['edited_by'] ?? ''));
                                } catch (Exception $ex) { $assignedBy = ''; }
                            ?>
                            <td><?php echo htmlspecialchars($assignedBy ?: '(none)', ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><?php echo htmlspecialchars($sres['order_date'] ?? '', ENT_QUOTES, 'UTF-8'); ?></td>
                        <td class="priority-cell <?php echo (isset($sres['priority']) && strtolower(trim((string)$sres['priority'])) === 'high') ? 'priority-high' : ''; ?>"><?php echo htmlspecialchars($sres['priority'] ?? 'Normal', ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><?php echo htmlspecialchars($sres['status'] ?? 'Open', ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><?php echo htmlspecialchars($sres['updated_at'] ?? '', ENT_QUOTES, 'UTF-8'); ?></td>
                        <td>
                            <a class="btn" href="/sps/pages/view_workorder.php?id=<?php echo (int)$sres['id']; ?>">View</a>
                            <a class="btn btn-secondary" href="/sps/pages/edit_workorder.php?id=<?php echo (int)$sres['id']; ?>">Edit</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>

        <table class="tech-wo-table" id="assigned-list">
            <thead>
                <tr><th>#</th><th>Client</th><th>Location</th><th>Assigned By</th><th>Order Date</th><th>Priority</th><th>Status</th><th>Updated</th><th>Actions</th></tr>
            </thead>
            <tbody>
            <?php foreach ($workorders as $wo): ?>
                <?php
                    // force-fetch current priority from DB for accuracy
                    $rawPriority = 'Normal';
                    try {
                        $pstmt = $conn->prepare('SELECT priority FROM workorders WHERE id = ? LIMIT 1');
                        $pstmt->execute([(int)$wo['id']]);
                        $prow = $pstmt->fetch(PDO::FETCH_ASSOC);
                        if ($prow && isset($prow['priority'])) $rawPriority = (string)$prow['priority'];
                    } catch (Exception $e) { }
                    $rowClass = (strtolower(trim((string)$rawPriority)) === 'high') ? 'priority-row' : '';
                ?>
                <tr class="<?php echo $rowClass; ?>">
                    <td><?php echo (int)$wo['id']; ?></td>
                    <td><?php echo htmlspecialchars($wo['client_name'] ?? '', ENT_QUOTES, 'UTF-8'); ?></td>
                    <td><?php echo htmlspecialchars($wo['location'] ?? '', ENT_QUOTES, 'UTF-8'); ?></td>
                        <?php
                            $assignedBy = '';
                            try {
                                $ax = $conn->prepare("SELECT e.*, s.firstname, s.lastname FROM workorder_edits e LEFT JOIN staff s ON s.id = e.edited_by WHERE e.workorder_id = ? AND e.field_name = 'work_performed_by' ORDER BY e.edited_at DESC LIMIT 1");
                                $ax->execute([(int)$wo['id']]);
                                $ar = $ax->fetch(PDO::FETCH_ASSOC);
                                if ($ar) $assignedBy = trim(($ar['firstname'] ?? '') . ' ' . ($ar['lastname'] ?? '')) ?: ('User '.($ar['edited_by'] ?? ''));
                            } catch (Exception $ex) { $assignedBy = ''; }
                        ?>
                        <td><?php echo htmlspecialchars($assignedBy ?: '(none)', ENT_QUOTES, 'UTF-8'); ?></td>
                    <td><?php echo htmlspecialchars($wo['order_date'] ?? '', ENT_QUOTES, 'UTF-8'); ?></td>
                    <td class="priority-cell <?php echo (strtolower(trim((string)$rawPriority)) === 'high') ? 'priority-high' : ''; ?>"><?php echo htmlspecialchars($rawPriority ?? 'Normal', ENT_QUOTES, 'UTF-8'); ?></td>
                    <td><?php echo htmlspecialchars($wo['status'] ?? 'Open', ENT_QUOTES, 'UTF-8'); ?></td>
                    <td><?php echo htmlspecialchars($wo['updated_at'] ?? '', ENT_QUOTES, 'UTF-8'); ?></td>
                    <td>
                        <a class="btn" href="/sps/pages/view_workorder.php?id=<?php echo (int)$wo['id']; ?>">View</a>
                        <a class="btn btn-secondary" href="/sps/pages/edit_workorder.php?id=<?php echo (int)$wo['id']; ?>">Edit</a>
                        <a class="btn" href="#" onclick="toggleQuick(<?php echo (int)$wo['id']; ?>);return false;">Quick Update</a>
                    </td>
                </tr>
                <tr id="quick-row-<?php echo (int)$wo['id']; ?>" style="display:none;"><td colspan="9">
                    <div class="quick-update" id="quick-<?php echo (int)$wo['id']; ?>">
                        <form method="post" onsubmit="return confirm('Apply quick update?');">
                            <input type="hidden" name="action" value="quick_update">
                            <input type="hidden" name="workorder_id" value="<?php echo (int)$wo['id']; ?>">
                            <?php if ($hasStatus): ?>
                            <label>Status: <select name="status">
                                <?php $statuses = ['Open','In Progress','Completed','Closed','On Hold']; foreach ($statuses as $st): ?>
                                    <option value="<?php echo htmlspecialchars($st, ENT_QUOTES, 'UTF-8'); ?>" <?php echo (($wo['status'] ?? '') === $st) ? 'selected' : ''; ?>><?php echo htmlspecialchars($st, ENT_QUOTES, 'UTF-8'); ?></option>
                                <?php endforeach; ?>
                            </select></label><br>
                            <?php endif; ?>
                            <label>Work Done / Notes:<br><textarea name="work_description" style="width:100%;min-height:100px;"><?php echo htmlspecialchars($wo['work_description'] ?? '', ENT_QUOTES, 'UTF-8'); ?></textarea></label>
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
    function promptOpenById(e){
        e = e || window.event;
        if(e && e.preventDefault) e.preventDefault();
        var id = prompt('Enter Work Order ID to open:');
        if(!id) return false;
        id = parseInt(id,10);
        if(!id || id <= 0){ alert('Invalid ID'); return false; }
        window.location.href = '/sps/pages/view_workorder.php?id=' + id;
        return false;
    }
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

<?php else: ?>

    <h2>Welcome to your dashboard</h2>
    <p>You are logged in successfully.</p>

<?php endif; ?>

<?php
require_once '../includes/footer.php';
?>