<?php
session_start();

function formatHistoryEventText($fieldName, $oldValue, $newValue) {
    $field = trim((string)($fieldName ?? ''));
    $oldText = trim((string)($oldValue ?? ''));
    $newText = trim((string)($newValue ?? ''));

    if ($field === 'work_description') {
        return $newText === '' ? 'Cleared' : $newText;
    }

    if ($field === '') {
        return $newText === '' ? 'Event cleared' : $newText;
    }

    if ($newText === '') {
        return $field . ': Cleared';
    }

    if ($oldText === '') {
        return $field . ': Added: ' . $newText;
    }

    return $field . ': ' . $newText;
}

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header('Location: /sps/login.php');
    exit;
}

require_once '../includes/dbh.inc.php';

$id = (int)($_GET['id'] ?? 0);
if (!$id) {
    header('Location: /sps/pages/dashboard.php');
    exit;
}

$stmt = $conn->prepare('SELECT w.*, r.firstname AS received_first, r.lastname AS received_last, p.firstname AS performed_first, p.lastname AS performed_last FROM workorders w LEFT JOIN staff r ON w.order_received_by = r.id LEFT JOIN staff p ON w.work_performed_by = p.id WHERE w.id = ?');
$stmt->execute([$id]);
$wo = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$wo) {
    echo 'Work order not found.';
    exit;
}

$currentRole = strtolower($_SESSION['role'] ?? '');
$userId = (int)($_SESSION['user_id'] ?? 0);
$techActionMessage = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $currentRole === 'technician') {
    $assignedToMe = (int)($wo['work_performed_by'] ?? 0) === $userId;
    if ($assignedToMe && isset($_POST['tech_action'])) {
        $techAction = $_POST['tech_action'];
        $targetTech = isset($_POST['new_tech']) ? (int)$_POST['new_tech'] : 0;

        if ($techAction === 'opt_out') {
            $oldValue = $wo['work_performed_by'];
            $upd = $conn->prepare('UPDATE workorders SET work_performed_by = NULL WHERE id = ?');
            $upd->execute([$id]);
            $ins = $conn->prepare('INSERT INTO workorder_edits (workorder_id, field_name, old_value, new_value, edited_by) VALUES (?, ?, ?, ?, ?)');
            $ins->execute([$id, 'work_performed_by', (string)$oldValue, null, $userId]);
            $techActionMessage = 'You have opted out of this work order.';
            $wo['work_performed_by'] = null;
            $stmt = $conn->prepare('SELECT w.*, r.firstname AS received_first, r.lastname AS received_last, p.firstname AS performed_first, p.lastname AS performed_last FROM workorders w LEFT JOIN staff r ON w.order_received_by = r.id LEFT JOIN staff p ON w.work_performed_by = p.id WHERE w.id = ?');
            $stmt->execute([$id]);
            $wo = $stmt->fetch(PDO::FETCH_ASSOC);
        } elseif ($techAction === 'transfer' && $targetTech > 0 && $targetTech !== $userId) {
            $oldValue = $wo['work_performed_by'];
            $upd = $conn->prepare('UPDATE workorders SET work_performed_by = ? WHERE id = ?');
            $upd->execute([$targetTech, $id]);
            $ins = $conn->prepare('INSERT INTO workorder_edits (workorder_id, field_name, old_value, new_value, edited_by) VALUES (?, ?, ?, ?, ?)');
            $ins->execute([$id, 'work_performed_by', (string)$oldValue, (string)$targetTech, $userId]);
            $techActionMessage = 'This work order has been transferred to the selected technician.';
            $wo['work_performed_by'] = $targetTech;
            $stmt = $conn->prepare('SELECT w.*, r.firstname AS received_first, r.lastname AS received_last, p.firstname AS performed_first, p.lastname AS performed_last FROM workorders w LEFT JOIN staff r ON w.order_received_by = r.id LEFT JOIN staff p ON w.work_performed_by = p.id WHERE w.id = ?');
            $stmt->execute([$id]);
            $wo = $stmt->fetch(PDO::FETCH_ASSOC);
        }
    }
}

$techs = $conn->query('SELECT id, firstname, lastname FROM staff ORDER BY firstname, lastname')->fetchAll(PDO::FETCH_ASSOC);

$title = 'View Work Order';
require_once '../includes/header.php';
?>

<style>
    .wo-card { max-width: 960px; margin: 64px auto 24px; padding: 18px 18px 30px; border-radius: 8px; background: #fff; box-shadow: 0 1px 12px rgba(0,0,0,0.06); font-family: Arial, sans-serif; box-sizing: border-box; }
    .wo-card h2 { margin-top: 0; background: #007BFF; color: #fff; padding: 10px 14px; border-radius: 6px; }
    .wo-table { width: 100%; border-collapse: collapse; margin-top: 12px; }
    .wo-table td { padding: 12px; vertical-align: top; }
    .wo-table tbody tr:nth-child(odd) td { background: #f5f5f5; }
    .wo-table tbody tr:nth-child(even) td { background: #efefef; }
    .wo-meta { width: 240px; font-weight: 700; color: #333; padding-right: 8px; }
    .edit-link { background-color: #007BFF; color: white; padding:6px 10px; border-radius:4px; text-decoration:none; }
    .view-link { background-color: #17a2b8; color: white; padding:6px 10px; border-radius:4px; text-decoration:none; }
    .history-toggle-container { display:flex; justify-content:flex-end; margin-top:6px; }
    .small-toggle-btn { background: transparent; color: #007BFF; border: 1px solid #007BFF; padding: 6px 10px; border-radius: 6px; font-size: 13px; cursor: pointer; transition: all .15s ease; }
    .small-toggle-btn:hover { background: #007BFF; color: #fff; }
</style>

<div class="wo-card">
<h2>View Work Order #<?php echo (int)$wo['id']; ?></h2>
<p><a href="/sps/pages/dashboard.php">← Back to Dashboard</a>
<?php if (in_array($currentRole, ['admin','office','technician'])): ?>
    | <a class="edit-link" href="/sps/pages/edit_workorder.php?id=<?php echo (int)$wo['id']; ?>">Edit</a>
<?php endif; ?></p>

<?php if ($techActionMessage !== ''): ?>
    <p style="color:#0b5a2c; font-weight:600; margin:10px 0;"><?php echo htmlspecialchars($techActionMessage, ENT_QUOTES, 'UTF-8'); ?></p>
<?php endif; ?>

<?php if ($currentRole === 'technician' && (int)($wo['work_performed_by'] ?? 0) === $userId): ?>
    <div style="margin: 12px 0 18px; padding: 12px; border: 1px solid #dfe7f1; border-radius: 6px; background: #f8fbff;">
        <form method="post" style="display:flex; flex-wrap:wrap; gap:10px; align-items:center; margin:0;">
            <input type="hidden" name="tech_action" value="transfer">
            <label for="new_tech" style="font-weight:700; margin:0;">Transfer to:</label>
            <select id="new_tech" name="new_tech" style="min-width:220px; padding:8px; border:1px solid #cbd5e0; border-radius:4px;">
                <option value="">Select technician</option>
                <?php foreach ($techs as $tech): ?>
                    <?php if ((int)$tech['id'] !== $userId): ?>
                        <option value="<?php echo (int)$tech['id']; ?>"><?php echo htmlspecialchars($tech['firstname'] . ' ' . $tech['lastname'], ENT_QUOTES, 'UTF-8'); ?></option>
                    <?php endif; ?>
                <?php endforeach; ?>
            </select>
            <button type="submit" class="edit-link" style="border:none; cursor:pointer;">Transfer Technician</button>
        </form>

        <form method="post" style="margin-top:10px;">
            <input type="hidden" name="tech_action" value="opt_out">
            <button type="submit" class="view-link" style="border:none; cursor:pointer; background:#6c757d;">Opt Out of This Work Order</button>
        </form>
    </div>
<?php endif; ?>

<?php
// determine last updated by: latest edit or creator
$lastEditor = null;
$lastEditedAt = null;
try {
    $le = $conn->prepare('SELECT e.*, s.firstname, s.lastname FROM workorder_edits e LEFT JOIN staff s ON e.edited_by = s.id WHERE e.workorder_id = ? ORDER BY e.edited_at DESC LIMIT 1');
    $le->execute([(int)$wo['id']]);
    $last = $le->fetch(PDO::FETCH_ASSOC);
    if ($last) {
        $lastEditor = ($last['firstname'] ? $last['firstname'] . ' ' . $last['lastname'] : ('User ' . $last['edited_by']));
        $lastEditedAt = $last['edited_at'];
    } else {
        // fallback to creator
        if (!empty($wo['received_first'])) {
            $lastEditor = $wo['received_first'] . ' ' . ($wo['received_last'] ?? '');
            $lastEditedAt = $wo['created_at'] ?? null;
        }
    }
} catch (Exception $ex) {
    // ignore
}

?>
<table class="wo-table" style="width:100%;">
    <tbody>
    <tr><th class="wo-meta">Assigned To</th><td><?php echo htmlspecialchars((($wo['performed_first'] ?? '') ? ($wo['performed_first'].' '.($wo['performed_last'] ?? '')) : 'Unassigned'), ENT_QUOTES, 'UTF-8'); ?></td></tr>
    <tr><th class="wo-meta">Client Name</th><td><?php echo htmlspecialchars($wo['client_name'] ?? '', ENT_QUOTES, 'UTF-8'); ?></td></tr>
    <tr><th class="wo-meta">Client Phone</th><td><?php echo htmlspecialchars($wo['client_phone'] ?? '', ENT_QUOTES, 'UTF-8'); ?></td></tr>
    <tr><th class="wo-meta">Location</th><td><?php echo htmlspecialchars($wo['location'] ?? '', ENT_QUOTES, 'UTF-8'); ?></td></tr>
    <tr><th class="wo-meta">Order Date</th><td><?php echo htmlspecialchars($wo['order_date'] ?? '', ENT_QUOTES, 'UTF-8'); ?></td></tr>
    <tr><th class="wo-meta">Priority</th><td><?php $rawPriority = isset($wo['priority']) ? (string)$wo['priority'] : 'Normal'; $isHigh = (strtolower(trim($rawPriority)) === 'high'); ?><span style="<?php echo $isHigh ? 'color:#721c24;background:#f8d7da;padding:4px 8px;border-radius:4px;' : ''; ?>"><?php echo htmlspecialchars($rawPriority, ENT_QUOTES, 'UTF-8'); ?></span></td></tr>
    <tr><th class="wo-meta">Expected Start</th><td><?php echo htmlspecialchars($wo['expected_start_date'] ?? '', ENT_QUOTES, 'UTF-8'); ?></td></tr>
    <tr><th class="wo-meta">Expected End</th><td><?php echo htmlspecialchars($wo['expected_end_date'] ?? '', ENT_QUOTES, 'UTF-8'); ?></td></tr>
    <tr><th class="wo-meta">Requested Work</th><td><?php echo nl2br(htmlspecialchars($wo['requested_work'] ?? '', ENT_QUOTES, 'UTF-8')); ?></td></tr>
    <tr><th class="wo-meta">Additional Comments</th><td><?php echo nl2br(htmlspecialchars($wo['additional_comments'] ?? '', ENT_QUOTES, 'UTF-8')); ?></td></tr>
    <tr><th class="wo-meta">Vessel VIN</th><td><?php echo htmlspecialchars($wo['vessel_vin'] ?? '', ENT_QUOTES, 'UTF-8'); ?></td></tr>
    <tr><th class="wo-meta">Vessel Hours</th><td><?php echo htmlspecialchars($wo['vessel_hours'] ?? '', ENT_QUOTES, 'UTF-8'); ?></td></tr>
    <tr><th class="wo-meta">Labor Time</th><td><?php echo htmlspecialchars($wo['labor_time'] ?? '', ENT_QUOTES, 'UTF-8'); ?></td></tr>
    <tr><th class="wo-meta">Parts/Materials Cost</th><td><?php echo htmlspecialchars($wo['parts_cost'] ?? '', ENT_QUOTES, 'UTF-8'); ?></td></tr>
    <tr><th class="wo-meta">Chargeable To</th><td><?php echo htmlspecialchars($wo['chargeable_to'] ?? '', ENT_QUOTES, 'UTF-8'); ?></td></tr>
    <tr><th class="wo-meta">Order Received By</th><td><?php echo htmlspecialchars((($wo['received_first'] ?? '') ? ($wo['received_first'].' '.($wo['received_last'] ?? '')) : ''), ENT_QUOTES, 'UTF-8'); ?></td></tr>
    <tr><th class="wo-meta">Work Performed By</th><td><?php echo htmlspecialchars((($wo['performed_first'] ?? '') ? ($wo['performed_first'].' '.($wo['performed_last'] ?? '')) : ''), ENT_QUOTES, 'UTF-8'); ?></td></tr>
    <tr><th class="wo-meta">Permission Anytime</th><td><?php echo $wo['permission_anytime'] ? 'Yes' : 'No'; ?></td></tr>
    <tr><th class="wo-meta">Permission Date/Time</th><td><?php echo htmlspecialchars($wo['permission_date'] ?? '', ENT_QUOTES, 'UTF-8') . ' ' . htmlspecialchars($wo['permission_time'] ?? '', ENT_QUOTES, 'UTF-8'); ?></td></tr>
    <tr><th class="wo-meta">Entry Date / Time Entered / Departed</th><td><?php echo htmlspecialchars($wo['entry_date'] ?? '', ENT_QUOTES, 'UTF-8') . ' / ' . htmlspecialchars($wo['time_entered'] ?? '', ENT_QUOTES, 'UTF-8') . ' / ' . htmlspecialchars($wo['time_departed'] ?? '', ENT_QUOTES, 'UTF-8'); ?></td></tr>
    <tr><th class="wo-meta">Work Description</th><td><?php echo nl2br(htmlspecialchars($wo['work_description'] ?? '', ENT_QUOTES, 'UTF-8')); ?></td></tr>
    <tr><th class="wo-meta">Created At</th><td><?php echo htmlspecialchars($wo['created_at'] ?? '', ENT_QUOTES, 'UTF-8'); ?></td></tr>
    <tr><th class="wo-meta">Last Updated</th><td><?php
        $lu_time = $lastEditedAt ?? ($wo['updated_at'] ?? '');
        $lu_person = $lastEditor ?? '';
        echo htmlspecialchars($lu_time, ENT_QUOTES, 'UTF-8');
        if ($lu_person) echo ' by ' . htmlspecialchars($lu_person, ENT_QUOTES, 'UTF-8');
    ?></td></tr>
    </tbody>
</table>
</div>

<?php
// show full history (creation + edits) - only for non-technician roles
if ($currentRole !== 'technician') {
    try {
    // creator info
    $creatorName = ($wo['received_first'] ?? '') ? ($wo['received_first'] . ' ' . ($wo['received_last'] ?? '')) : '';
    $createdAt = $wo['created_at'] ?? '';
    $creatorDisplay = $creatorName ?: (!empty($wo['order_received_by']) ? ('User ' . $wo['order_received_by']) : 'System');
    $edStmt = $conn->prepare('SELECT e.*, s.firstname, s.lastname FROM workorder_edits e LEFT JOIN staff s ON e.edited_by = s.id WHERE e.workorder_id = ? ORDER BY e.edited_at DESC');
    $edStmt->execute([$id]);
    $edits = $edStmt->fetchAll(PDO::FETCH_ASSOC);
    if ($createdAt || $creatorName || $edits): ?>
        <div class="history-toggle-container">
            <button id="toggle-history-btn" class="small-toggle-btn" aria-expanded="false" aria-controls="history-wrapper">Show History</button>
        </div>
        <h3>Complete History</h3>
        <p style="margin:4px 0 8px; color:#666; font-size:0.95em;">Edits: <?php echo is_array($edits) ? count($edits) : 0; ?></p>

        <div id="history-wrapper" style="display:none; margin-top:6px; padding-bottom:24px;">
        <table id="history-table" style="width:100%; border-collapse: collapse;">
            <thead><tr><th style="text-align:left; padding:6px; width:170px;">When</th><th style="text-align:left; padding:6px; width:200px;">Who</th><th style="text-align:left; padding:6px;">Event</th></tr></thead>
            <tbody>
                <?php if ($createdAt): ?>
                    <tr>
                        <td style="padding:6px;"><?php echo htmlspecialchars($createdAt, ENT_QUOTES, 'UTF-8'); ?></td>
                        <td style="padding:6px;"><?php echo htmlspecialchars($creatorDisplay, ENT_QUOTES, 'UTF-8'); ?></td>
                        <td style="padding:6px;">Created work order</td>
                    </tr>
                <?php endif; ?>
            <?php foreach ($edits as $e): ?>
                <tr>
                    <td style="padding:6px;"><?php echo htmlspecialchars($e['edited_at'] ?? '', ENT_QUOTES, 'UTF-8'); ?></td>
                    <td style="padding:6px;"><?php echo htmlspecialchars((($e['firstname'] ?? '') ? ($e['firstname'].' '.($e['lastname'] ?? '')) : ('User '.($e['edited_by'] ?? ''))), ENT_QUOTES, 'UTF-8'); ?></td>
                    <td style="padding:6px;">
                        <?php $oldVal = $e['old_value'] ?? ''; $newVal = $e['new_value'] ?? ''; ?>
                        <?php echo htmlspecialchars(formatHistoryEventText($e['field_name'] ?? '', $oldVal, $newVal), ENT_QUOTES, 'UTF-8'); ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <script>
            (function(){
                var wrapper = document.getElementById('history-wrapper');
                var btn = document.getElementById('toggle-history-btn');
                if(!wrapper || !btn) return;
                var visible = false;
                btn.addEventListener('click', function(){
                    visible = !visible;
                    wrapper.style.display = visible ? 'block' : 'none';
                    btn.textContent = visible ? 'Hide History' : 'Show History';
                    if(visible){
                        // ensure history and button are visible above footer
                        setTimeout(function(){
                            try{ wrapper.scrollIntoView({behavior:'smooth', block:'end'}); }catch(e){}
                        }, 80);
                    }
                });
            })();
        </script>
    <?php endif;
    } catch (Exception $ex) {
        // ignore if edit table missing
    }
}

// Assignment and work-performed history
try {
    $assignStmt = $conn->prepare("SELECT e.*, s.firstname, s.lastname FROM workorder_edits e LEFT JOIN staff s ON e.edited_by = s.id WHERE e.workorder_id = ? AND e.field_name = 'work_performed_by' ORDER BY e.edited_at DESC");
    $assignStmt->execute([$id]);
    $assigns = $assignStmt->fetchAll(PDO::FETCH_ASSOC);

    $wpStmt = $conn->prepare("SELECT e.*, s.firstname, s.lastname FROM workorder_edits e LEFT JOIN staff s ON e.edited_by = s.id WHERE e.workorder_id = ? AND e.field_name IN ('work_description','time_entered','time_departed','vessel_hours','labor_time','parts_cost') ORDER BY e.edited_at DESC");
    $wpStmt->execute([$id]);
    $workPerformedEdits = $wpStmt->fetchAll(PDO::FETCH_ASSOC);

    if ($currentRole === 'technician') {
        // build merged, chronological technician-only history (assignments + work-performed edits)
        $techHistory = [];
        foreach ($assigns as $a) {
            $assigner = ($a['firstname'] ? ($a['firstname'].' '.($a['lastname'] ?? '')) : ('User '.($a['edited_by'] ?? '')));
            $assignedTo = '';
            $newVal = $a['new_value'] ?? '';
            if (is_numeric($newVal) && (int)$newVal > 0) {
                $tstmt = $conn->prepare('SELECT firstname, lastname FROM staff WHERE id = ? LIMIT 1');
                $tstmt->execute([(int)$newVal]);
                $trow = $tstmt->fetch(PDO::FETCH_ASSOC);
                if ($trow) $assignedTo = trim(($trow['firstname'] ?? '') . ' ' . ($trow['lastname'] ?? ''));
            } else {
                $assignedTo = $newVal ?: '(unset)';
            }
            $techHistory[] = ['edited_at'=>$a['edited_at'] ?? '', 'who'=>$assigner, 'event'=>'Assigned to: '.$assignedTo];
        }
        foreach ($workPerformedEdits as $e) {
            $who = (($e['firstname'] ?? '') ? ($e['firstname'].' '.($e['lastname'] ?? '')) : ('User '.($e['edited_by'] ?? '')));
            $oldVal = $e['old_value'] ?? '';
            $newVal = $e['new_value'] ?? '';
            $techHistory[] = ['edited_at'=>$e['edited_at'] ?? '', 'who'=>$who, 'event'=>formatHistoryEventText($e['field_name'] ?? '', $oldVal, $newVal)];
        }

        usort($techHistory, function($a,$b){
            return strcmp(($b['edited_at'] ?? ''), ($a['edited_at'] ?? ''));
        });

        echo "<h3>Technician History</h3>";
        if (empty($techHistory)) {
            echo '<p style="max-width:900px;margin:6px auto;">No technician history recorded.</p>';
        } else {
            echo '<table style="width:100%; border-collapse: collapse; max-width:960px; margin:6px auto 36px;">';
            echo '<thead><tr><th style="text-align:left; padding:6px; width:180px;">When</th><th style="text-align:left; padding:6px; width:200px;">Who</th><th style="text-align:left; padding:6px;">Event</th></tr></thead><tbody>';
            foreach ($techHistory as $h) {
                echo '<tr><td style="padding:6px;">'.htmlspecialchars($h['edited_at'] ?? '', ENT_QUOTES, 'UTF-8').'</td>';
                echo '<td style="padding:6px;">'.htmlspecialchars($h['who'] ?? '', ENT_QUOTES, 'UTF-8').'</td>';
                echo '<td style="padding:6px;">'.htmlspecialchars($h['event'] ?? '', ENT_QUOTES, 'UTF-8').'</td></tr>';
            }
            echo '</tbody></table>';
        }
    } else {
        // non-technician: show separate Assignment and Work Performed history as before
        if ((!empty($assigns) && count($assigns) > 0) || (!empty($workPerformedEdits) && count($workPerformedEdits) > 0)):
?>
    <h3>Assignment History</h3>
    <?php if (empty($assigns)): ?>
        <p style="max-width:900px;margin:6px auto;">No assignment events recorded.</p>
    <?php else: ?>
        <table style="width:100%; border-collapse: collapse; max-width:960px; margin:6px auto 18px;">
            <thead><tr><th style="text-align:left; padding:6px; width:180px;">When</th><th style="text-align:left; padding:6px; width:200px;">Assigned By</th><th style="text-align:left; padding:6px;">Assigned To</th></tr></thead>
            <tbody>
            <?php foreach ($assigns as $a):
                $assigner = ($a['firstname'] ? ($a['firstname'].' '.($a['lastname'] ?? '')) : ('User '.($a['edited_by'] ?? '')));
                $assignedTo = '';
                $newVal = $a['new_value'] ?? '';
                if (is_numeric($newVal) && (int)$newVal > 0) {
                    $tstmt = $conn->prepare('SELECT firstname, lastname FROM staff WHERE id = ? LIMIT 1');
                    $tstmt->execute([(int)$newVal]);
                    $trow = $tstmt->fetch(PDO::FETCH_ASSOC);
                    if ($trow) $assignedTo = trim(($trow['firstname'] ?? '') . ' ' . ($trow['lastname'] ?? ''));
                } else {
                    $assignedTo = $newVal ?: '(unset)';
                }
            ?>
                <tr>
                    <td style="padding:6px;"><?php echo htmlspecialchars($a['edited_at'] ?? '', ENT_QUOTES, 'UTF-8'); ?></td>
                    <td style="padding:6px;"><?php echo htmlspecialchars($assigner, ENT_QUOTES, 'UTF-8'); ?></td>
                    <td style="padding:6px;"><?php echo htmlspecialchars($assignedTo, ENT_QUOTES, 'UTF-8'); ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>

    <h3>Work Performed History</h3>
    <?php if (empty($workPerformedEdits)): ?>
        <p style="max-width:900px;margin:6px auto;">No work-performed edits recorded.</p>
    <?php else: ?>
        <table style="width:100%; border-collapse: collapse; max-width:960px; margin:6px auto 36px;">
            <thead><tr><th style="text-align:left; padding:6px; width:180px;">When</th><th style="text-align:left; padding:6px; width:200px;">Who</th><th style="text-align:left; padding:6px;">Change</th></tr></thead>
            <tbody>
            <?php foreach ($workPerformedEdits as $e): ?>
                <tr>
                    <td style="padding:6px;"><?php echo htmlspecialchars($e['edited_at'] ?? '', ENT_QUOTES, 'UTF-8'); ?></td>
                    <td style="padding:6px;"><?php echo htmlspecialchars((($e['firstname'] ?? '') ? ($e['firstname'].' '.($e['lastname'] ?? '')) : ('User '.($e['edited_by'] ?? ''))), ENT_QUOTES, 'UTF-8'); ?></td>
                    <td style="padding:6px;">
                        <?php echo htmlspecialchars(formatHistoryEventText($e['field_name'] ?? '', $e['old_value'] ?? '', $e['new_value'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
<?php
        endif;
    }
} catch (Exception $ex) {
    // ignore if workorder_edits missing or other DB errors
}

require_once '../includes/footer.php';
?>