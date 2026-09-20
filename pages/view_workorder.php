<?php
session_start();

function format_work_duration($time) {
    $parts = explode(':', (string)$time);
    $hours = (int)($parts[0] ?? 0);
    $minutes = (int)($parts[1] ?? 0);
    return $hours . 'h ' . sprintf('%02d', $minutes) . 'm';
}

function format_entry_logged_at($timestamp) {
    $ts = strtotime((string)$timestamp);
    return $ts ? date('M j, Y \a\t g:ia', $ts) : '';
}

function format_entry_day($dateValue) {
    $ts = strtotime((string)$dateValue);
    return $ts ? date('M j, Y', $ts) : (string)$dateValue;
}

function format_duration_words($hours, $minutes) {
    $hours = (int)$hours;
    $minutes = (int)$minutes;
    $parts = [];
    if ($hours > 0) {
        $parts[] = $hours . ' ' . ($hours === 1 ? 'hour' : 'hours');
    }
    if ($minutes > 0 || empty($parts)) {
        $parts[] = $minutes . ' ' . ($minutes === 1 ? 'minute' : 'minutes');
    }
    return implode(' ', $parts);
}

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

try {
    $conn->exec("ALTER TABLE workorders ADD COLUMN IF NOT EXISTS created_via VARCHAR(30) DEFAULT NULL");
} catch (Exception $e) {
    // ignore if column already exists
}

$conn->exec("CREATE TABLE IF NOT EXISTS work_performed_entries (
    id INT AUTO_INCREMENT PRIMARY KEY,
    workorder_id INT NOT NULL,
    performed_date DATE NOT NULL,
    performed_time TIME NOT NULL,
    description LONGTEXT NOT NULL,
    added_by INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX(workorder_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

$id = (int)($_GET['id'] ?? 0);
if (!$id) {
    header('Location: /sps/pages/dashboard.php');
    exit;
}

$stmt = $conn->prepare('SELECT w.*, r.firstname AS received_first, r.lastname AS received_last, p.firstname AS performed_first, p.lastname AS performed_last, p.role AS performed_role FROM workorders w LEFT JOIN staff r ON w.order_received_by = r.id LEFT JOIN staff p ON w.work_performed_by = p.id WHERE w.id = ?');
$stmt->execute([$id]);
$wo = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$wo) {
    echo 'Work order not found.';
    exit;
}

$displayOrderNumber = !empty($wo['order_number']) ? $wo['order_number'] : 'WO' . str_pad((string)(int)$wo['id'], 4, '0', STR_PAD_LEFT);

$assignedTechDisplay = 'Not Assigned';
if (!empty($wo['work_performed_by'])) {
    $assignedTechStmt = $conn->prepare('SELECT firstname, lastname, role FROM staff WHERE id = ? LIMIT 1');
    $assignedTechStmt->execute([(int)$wo['work_performed_by']]);
    $assignedTechRow = $assignedTechStmt->fetch(PDO::FETCH_ASSOC);
    $assignedRole = strtolower(trim((string)($assignedTechRow['role'] ?? '')));
    if ($assignedTechRow && ($assignedRole === 'admin' || in_array($assignedRole, ['technician', 'staff', ''], true))) {
        $assignedTechDisplay = trim(($assignedTechRow['firstname'] ?? '') . ' ' . ($assignedTechRow['lastname'] ?? ''));
    }
}
if ($assignedTechDisplay === 'Not Assigned') {
    try {
        $latestAssignment = $conn->prepare("SELECT new_value FROM workorder_edits WHERE workorder_id = ? AND field_name = 'work_performed_by' ORDER BY edited_at DESC LIMIT 1");
        $latestAssignment->execute([$id]);
        $latestAssignmentRow = $latestAssignment->fetch(PDO::FETCH_ASSOC);
        if ($latestAssignmentRow && !empty($latestAssignmentRow['new_value'])) {
            $assignmentId = trim((string)$latestAssignmentRow['new_value']);
            if (is_numeric($assignmentId)) {
                $assignmentLookup = $conn->prepare('SELECT firstname, lastname, role FROM staff WHERE id = ? LIMIT 1');
                $assignmentLookup->execute([(int)$assignmentId]);
                $assignmentRow = $assignmentLookup->fetch(PDO::FETCH_ASSOC);
                $assignmentRole = strtolower(trim((string)($assignmentRow['role'] ?? '')));
                if ($assignmentRow && ($assignmentRole === 'admin' || in_array($assignmentRole, ['technician', 'staff', ''], true))) {
                    $assignedTechDisplay = trim(($assignmentRow['firstname'] ?? '') . ' ' . ($assignmentRow['lastname'] ?? ''));
                }
            }
        }
    } catch (Exception $ex) {
        // ignore assignment lookup errors
    }
}

if (empty($wo['order_received_by']) || empty($wo['received_first'])) {
    $creatorLookup = $conn->prepare('SELECT e.new_value, e.edited_by, s.firstname, s.lastname FROM workorder_edits e LEFT JOIN staff s ON s.id = e.edited_by WHERE e.workorder_id = ? AND e.field_name = ? ORDER BY e.edited_at DESC LIMIT 1');
    $creatorLookup->execute([$id, 'order_received_by']);
    $creatorRow = $creatorLookup->fetch(PDO::FETCH_ASSOC);
    if ($creatorRow) {
        $creatorValue = trim((string)($creatorRow['new_value'] ?? ''));
        if ($creatorValue !== '' && is_numeric($creatorValue)) {
            $wo['order_received_by'] = (int)$creatorValue;
            $creatorStaff = $conn->prepare('SELECT firstname, lastname FROM staff WHERE id = ? LIMIT 1');
            $creatorStaff->execute([$wo['order_received_by']]);
            $creatorStaffRow = $creatorStaff->fetch(PDO::FETCH_ASSOC);
            if ($creatorStaffRow) {
                $wo['received_first'] = $creatorStaffRow['firstname'] ?? '';
                $wo['received_last'] = $creatorStaffRow['lastname'] ?? '';
            }
        } elseif (!empty($creatorRow['firstname']) || !empty($creatorRow['lastname'])) {
            $wo['received_first'] = $creatorRow['firstname'] ?? '';
            $wo['received_last'] = $creatorRow['lastname'] ?? '';
        } elseif (!empty($creatorRow['edited_by'])) {
            $wo['order_received_by'] = (int)$creatorRow['edited_by'];
            $creatorStaff = $conn->prepare('SELECT firstname, lastname FROM staff WHERE id = ? LIMIT 1');
            $creatorStaff->execute([$wo['order_received_by']]);
            $creatorStaffRow = $creatorStaff->fetch(PDO::FETCH_ASSOC);
            if ($creatorStaffRow) {
                $wo['received_first'] = $creatorStaffRow['firstname'] ?? '';
                $wo['received_last'] = $creatorStaffRow['lastname'] ?? '';
            }
        }
    }
}

$currentRole = strtolower($_SESSION['role'] ?? '');
$userId = (int)($_SESSION['user_id'] ?? 0);
$techActionMessage = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $currentRole === 'technician') {
    $assignedToMe = (int)($wo['work_performed_by'] ?? 0) === $userId;
    if ($assignedToMe && isset($_POST['tech_action'])) {
        $techAction = $_POST['tech_action'];
        $targetTech = isset($_POST['new_tech']) ? (int)$_POST['new_tech'] : 0;

        if ($techAction === 'add_work_performed') {
            $performedDate = trim((string)($_POST['performed_date'] ?? ''));
            $performedHours = max(0, min(99, (int)($_POST['performed_hours'] ?? 0)));
            $performedMinutes = max(0, min(59, (int)($_POST['performed_minutes'] ?? 0)));
            $performedTime = sprintf('%02d:%02d:00', $performedHours, $performedMinutes);
            $description = trim((string)($_POST['performed_description'] ?? ''));

            if ($performedDate !== '' && ($performedHours > 0 || $performedMinutes > 0) && $description !== '') {
                $insertEntry = $conn->prepare('INSERT INTO work_performed_entries (workorder_id, performed_date, performed_time, description, added_by) VALUES (?, ?, ?, ?, ?)');
                $insertEntry->execute([$id, $performedDate, $performedTime, $description, $userId]);
                $techActionMessage = 'Work performed entry added successfully.';
            } else {
                $techActionMessage = 'Please enter a date, time spent, and description for the work performed entry.';
            }
        } elseif ($techAction === 'opt_out') {
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
$workPerformedEntries = $conn->prepare('SELECT wpe.*, s.firstname, s.lastname FROM work_performed_entries wpe LEFT JOIN staff s ON s.id = wpe.added_by WHERE wpe.workorder_id = ? ORDER BY wpe.performed_date DESC, wpe.created_at DESC');
$workPerformedEntries->execute([$id]);
$workPerformedEntries = $workPerformedEntries->fetchAll(PDO::FETCH_ASSOC);

$workPerformedEntriesByDate = [];
foreach ($workPerformedEntries as $entry) {
    $dateKey = $entry['performed_date'] ?? '';
    $workPerformedEntriesByDate[$dateKey][] = $entry;
}

// track which work-performed entry the customer has already seen, so "NEW" only shows once
$mostRecentEntryId = $workPerformedEntries[0]['id'] ?? null;
$showNewBadge = ($mostRecentEntryId !== null);
if ($currentRole === 'customer') {
    $conn->exec("CREATE TABLE IF NOT EXISTS workorder_view_state (
        id INT AUTO_INCREMENT PRIMARY KEY,
        customer_id INT NOT NULL,
        workorder_id INT NOT NULL,
        last_seen_entry_id INT DEFAULT NULL,
        viewed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY customer_workorder (customer_id, workorder_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

    $customerId = (int)($_SESSION['customer_id'] ?? 0);
    if ($customerId > 0) {
        $viewStmt = $conn->prepare('SELECT last_seen_entry_id FROM workorder_view_state WHERE customer_id = ? AND workorder_id = ? LIMIT 1');
        $viewStmt->execute([$customerId, $id]);
        $viewRow = $viewStmt->fetch(PDO::FETCH_ASSOC);
        $lastSeenEntryId = $viewRow ? (int)$viewRow['last_seen_entry_id'] : null;

        $showNewBadge = ($mostRecentEntryId !== null) && ($lastSeenEntryId === null || (int)$mostRecentEntryId > $lastSeenEntryId);

        if ($mostRecentEntryId !== null) {
            $upsert = $conn->prepare('INSERT INTO workorder_view_state (customer_id, workorder_id, last_seen_entry_id) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE last_seen_entry_id = VALUES(last_seen_entry_id), viewed_at = CURRENT_TIMESTAMP');
            $upsert->execute([$customerId, $id, $mostRecentEntryId]);
        }
    }
}

$totalWorkSeconds = 0;
foreach ($workPerformedEntries as $entry) {
    if (!empty($entry['performed_time'])) {
        $parts = explode(':', (string)$entry['performed_time']);
        if (isset($parts[0], $parts[1])) {
            $totalWorkSeconds += ((int)$parts[0] * 3600) + ((int)$parts[1] * 60) + ((int)($parts[2] ?? 0));
        }
    }
}
$totalWorkHours = intdiv($totalWorkSeconds, 3600);
$totalWorkMinutes = intdiv($totalWorkSeconds % 3600, 60);
$totalWorkText = format_duration_words($totalWorkHours, $totalWorkMinutes);

$title = 'View Work Order';
require_once '../includes/header.php';
?>

<style>
    :root { --sps-primary:#2563eb; --sps-primary-dark:#1d4ed8; }
    .wo-card { max-width: 1200px; margin: 48px auto 24px; padding: 22px 26px 32px; border-radius: 14px; background: #fff; box-shadow: 0 4px 24px rgba(15,23,42,0.08); font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; box-sizing: border-box; }
    .wo-header { display:flex; flex-wrap:wrap; justify-content:space-between; align-items:center; gap:10px; background:rgba(15,23,42,0.85); border:1px solid rgba(15,23,42,0.9); color:#fff; padding:10px 14px; border-radius:8px; margin-bottom:16px; }
    .wo-header h2 { margin:0; font-size:15px; font-weight:700; letter-spacing:.02em; color:#fff; }
    .wo-actions a { color:#1d4ed8; background:#fff; border:1px solid #cbd5e1; padding:6px 12px; border-radius:8px; text-decoration:none; font-weight:600; font-size:12px; margin-left:8px; transition: background .15s ease; }
    .wo-actions a:hover { background:#eff6ff; }
    .wo-actions a.edit-link { color:#fff; background:#2563eb; border-color:#2563eb; }
    .wo-actions a.edit-link:hover { background:#1d4ed8; }
    .wo-grid { display:grid; grid-template-columns: repeat(auto-fill, minmax(220px, 1fr)); gap:14px; margin-top:6px; }
    .wo-field { background:#f8fafc; border:1px solid #e2e8f0; border-radius:10px; padding:12px 14px; box-sizing:border-box; }
    .wo-field.full { grid-column: 1 / -1; }
    .wo-field .wo-label { font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.04em; color:#64748b; margin-bottom:6px; }
    .wo-field .wo-value { font-size:14px; color:#0f172a; line-height:1.5; word-break:break-word; }
    .edit-link { background-color: #2563eb; color: white; padding:8px 14px; border-radius:8px; text-decoration:none; font-weight:600; font-size:13px; }
    .view-link { background-color: #0891b2; color: white; padding:8px 14px; border-radius:8px; text-decoration:none; font-weight:600; font-size:13px; }
    .history-toggle-container { display:flex; justify-content:flex-end; width:100%; margin-top:6px; }
    .history-button-row { display:flex; justify-content:flex-end; align-items:center; width:100%; max-width:1200px; margin:12px auto 8px; position:relative; }
    .history-toggle-btn { width:100%; max-width:1200px; text-align:center; display:block; }
    .small-toggle-btn { background: transparent; color: #2563eb; border: 1px solid #2563eb; padding: 6px 10px; border-radius: 6px; font-size: 13px; cursor: pointer; transition: all .15s ease; }
    .small-toggle-btn:hover { background: #2563eb; color: #fff; }
    .history-table,
    .history-table th,
    .history-table td,
    #assignment-history-body table,
    #assignment-history-body table th,
    #assignment-history-body table td,
    #work-performed-history-body table,
    #work-performed-history-body table th,
    #work-performed-history-body table td,
    #tech-history-body table,
    #tech-history-body table th,
    #tech-history-body table td {
        width: 100%;
        max-width: 1200px;
        border-collapse: collapse;
        text-align: center;
        table-layout: fixed;
    }
    .history-table tbody tr:nth-child(odd),
    #assignment-history-body table tbody tr:nth-child(odd),
    #work-performed-history-body table tbody tr:nth-child(odd),
    #tech-history-body table tbody tr:nth-child(odd) {
        background-color: #f8fafc;
    }
    .history-table tbody tr:nth-child(even),
    #assignment-history-body table tbody tr:nth-child(even),
    #work-performed-history-body table tbody tr:nth-child(even),
    #tech-history-body table tbody tr:nth-child(even) {
        background-color: #eef3f8;
    }
    .history-table th:nth-child(1), .history-table td:nth-child(1),
    #assignment-history-body table th:nth-child(1), #assignment-history-body table td:nth-child(1),
    #work-performed-history-body table th:nth-child(1), #work-performed-history-body table td:nth-child(1),
    #tech-history-body table th:nth-child(1), #tech-history-body table td:nth-child(1) {
        width: 22%;
    }
    .history-table th:nth-child(2), .history-table td:nth-child(2),
    #assignment-history-body table th:nth-child(2), #assignment-history-body table td:nth-child(2),
    #work-performed-history-body table th:nth-child(2), #work-performed-history-body table td:nth-child(2),
    #tech-history-body table th:nth-child(2), #tech-history-body table td:nth-child(2) {
        width: 28%;
    }
    .history-table th:nth-child(3), .history-table td:nth-child(3),
    #assignment-history-body table th:nth-child(3), #assignment-history-body table td:nth-child(3),
    #work-performed-history-body table th:nth-child(3), #work-performed-history-body table td:nth-child(3),
    #tech-history-body table th:nth-child(3), #tech-history-body table td:nth-child(3) {
        width: 50%;
    }
</style>

<div class="wo-card">
<div class="wo-header">
    <h2>Work Order # <?php echo htmlspecialchars($displayOrderNumber, ENT_QUOTES, 'UTF-8'); ?></h2>
    <div class="wo-actions">
        <a href="/sps/pages/dashboard.php">← Back to Dashboard</a>
        <?php if (in_array($currentRole, ['admin','office','technician'])): ?>
            <a class="edit-link" href="/sps/pages/edit_workorder.php?id=<?php echo (int)$wo['id']; ?>">Edit</a>
        <?php endif; ?>
    </div>
</div>

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

    <div style="margin: 12px 0 18px; padding: 12px; border: 1px solid #dfe7f1; border-radius: 6px; background: #fffdf7;">
        <h3 style="margin:0 0 12px;">Add Work Performed</h3>
        <form method="post" style="display:flex; flex-direction:column; gap:10px; max-width:520px;">
            <input type="hidden" name="tech_action" value="add_work_performed">
            <div style="display:flex; gap:10px; flex-wrap:wrap;">
                <label style="flex:1; min-width:160px; font-weight:700;">Date<br><input type="date" name="performed_date" required style="width:100%; padding:8px; border:1px solid #cbd5e0; border-radius:4px;"></label>
                <label style="flex:1; min-width:160px; font-weight:700;">Time Spent<br>
                    <div style="display:flex; gap:6px; align-items:center;">
                        <input type="number" name="performed_hours" min="0" max="99" value="0" required style="width:70px; padding:8px; border:1px solid #cbd5e0; border-radius:4px;"> <span>hrs</span>
                        <input type="number" name="performed_minutes" min="0" max="59" step="5" value="0" required style="width:70px; padding:8px; border:1px solid #cbd5e0; border-radius:4px;"> <span>min</span>
                    </div>
                </label>
            </div>
            <label style="font-weight:700;">Description<br><textarea name="performed_description" rows="4" required style="width:100%; padding:8px; border:1px solid #cbd5e0; border-radius:4px; resize:vertical;"></textarea></label>
            <button type="submit" class="edit-link" style="border:none; cursor:pointer; width:max-content;">Save Work Performed</button>
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
<?php $fieldColors = ['#eff6ff', '#f0fdf4', '#fef9c3', '#fdf2f8', '#f5f3ff', '#ecfeff', '#fff7ed', '#f1f5f9']; $fieldIdx = 0; ?>
<div class="wo-grid">
    <div class="wo-field" style="background:<?php echo $fieldColors[$fieldIdx++ % count($fieldColors)]; ?>;"><div class="wo-label">Assigned To</div><div class="wo-value"><?php echo htmlspecialchars($assignedTechDisplay, ENT_QUOTES, 'UTF-8'); ?></div></div>
    <div class="wo-field" style="background:<?php echo $fieldColors[$fieldIdx++ % count($fieldColors)]; ?>;"><div class="wo-label">Client Name</div><div class="wo-value"><?php echo htmlspecialchars($wo['client_name'] ?? '', ENT_QUOTES, 'UTF-8'); ?></div></div>
    <div class="wo-field" style="background:<?php echo $fieldColors[$fieldIdx++ % count($fieldColors)]; ?>;"><div class="wo-label">Client Phone</div><div class="wo-value"><?php echo htmlspecialchars($wo['client_phone'] ?? '', ENT_QUOTES, 'UTF-8'); ?></div></div>
    <div class="wo-field" style="background:<?php echo $fieldColors[$fieldIdx++ % count($fieldColors)]; ?>;"><div class="wo-label">Location</div><div class="wo-value"><?php echo htmlspecialchars($wo['location'] ?? '', ENT_QUOTES, 'UTF-8'); ?></div></div>
    <div class="wo-field" style="background:<?php echo $fieldColors[$fieldIdx++ % count($fieldColors)]; ?>;"><div class="wo-label">Order Date</div><div class="wo-value"><?php echo htmlspecialchars($wo['order_date'] ?? '', ENT_QUOTES, 'UTF-8'); ?></div></div>
    <div class="wo-field" style="background:<?php echo $fieldColors[$fieldIdx++ % count($fieldColors)]; ?>;"><div class="wo-label">Status</div><div class="wo-value"><?php $rawStatus = isset($wo['status']) ? (string)$wo['status'] : 'Open'; $statusClass = strtolower(trim($rawStatus)); $statusStyle = 'display:inline-block;padding:4px 10px;border-radius:999px;font-weight:700;color:#fff;'; if ($statusClass === 'completed' || $statusClass === 'closed') { $statusStyle .= 'background:#198754;'; } elseif ($statusClass === 'waiting for parts') { $statusStyle .= 'background:#d97706;'; } elseif ($statusClass === 'on hold') { $statusStyle .= 'background:#7c3aed;'; } elseif ($statusClass === 'in progress') { $statusStyle .= 'background:#2563eb;'; } elseif ($statusClass === 'pending') { $statusStyle .= 'background:#6b7280;'; } elseif ($statusClass === 'open') { $statusStyle .= 'background:#0ea5e9;'; } else { $statusStyle .= 'background:#1d4ed8;'; } echo '<span style="' . htmlspecialchars($statusStyle, ENT_QUOTES, 'UTF-8') . '">' . htmlspecialchars($rawStatus, ENT_QUOTES, 'UTF-8') . '</span>'; ?> <button type="button" id="status-guide-toggle" aria-label="Open status guide" style="display:inline-flex; align-items:center; justify-content:center; width:20px; height:20px; border:1px solid #cbd5e1; border-radius:50%; background:#eff6ff; color:#1d4ed8; cursor:pointer; font-size:12px; line-height:1; font-weight:700; padding:0; vertical-align:middle;">ⓘ</button></div></div>
    <div class="wo-field" style="background:<?php echo $fieldColors[$fieldIdx++ % count($fieldColors)]; ?>;"><div class="wo-label">Priority</div><div class="wo-value"><?php $rawPriority = isset($wo['priority']) ? (string)$wo['priority'] : 'Normal'; $isHigh = (strtolower(trim($rawPriority)) === 'high'); ?><span style="<?php echo $isHigh ? 'color:#721c24;background:#f8d7da;padding:4px 8px;border-radius:4px;' : ''; ?>"><?php echo htmlspecialchars($rawPriority, ENT_QUOTES, 'UTF-8'); ?></span></div></div>
    <div class="wo-field" style="background:<?php echo $fieldColors[$fieldIdx++ % count($fieldColors)]; ?>;"><div class="wo-label">Expected Start</div><div class="wo-value"><?php echo htmlspecialchars(!empty($wo['expected_start_date']) ? $wo['expected_start_date'] : 'N/A', ENT_QUOTES, 'UTF-8'); ?></div></div>
    <div class="wo-field" style="background:<?php echo $fieldColors[$fieldIdx++ % count($fieldColors)]; ?>;"><div class="wo-label">Expected End</div><div class="wo-value"><?php echo htmlspecialchars(!empty($wo['expected_end_date']) ? $wo['expected_end_date'] : 'N/A', ENT_QUOTES, 'UTF-8'); ?></div></div>
    <div class="wo-field full" style="background:<?php echo $fieldColors[$fieldIdx++ % count($fieldColors)]; ?>;"><div class="wo-label">Requested Work</div><div class="wo-value"><?php echo nl2br(htmlspecialchars($wo['requested_work'] ?? '', ENT_QUOTES, 'UTF-8')); ?></div></div>
    <div class="wo-field full" style="background:<?php echo $fieldColors[$fieldIdx++ % count($fieldColors)]; ?>;"><div class="wo-label">Additional Comments</div><div class="wo-value"><?php echo nl2br(htmlspecialchars($wo['additional_comments'] ?? '', ENT_QUOTES, 'UTF-8')); ?></div></div>
    <div class="wo-field" style="background:<?php echo $fieldColors[$fieldIdx++ % count($fieldColors)]; ?>;"><div class="wo-label">Vessel VIN</div><div class="wo-value"><?php echo htmlspecialchars($wo['vessel_vin'] ?? '', ENT_QUOTES, 'UTF-8'); ?></div></div>
    <div class="wo-field" style="background:<?php echo $fieldColors[$fieldIdx++ % count($fieldColors)]; ?>;"><div class="wo-label">Vessel Hours</div><div class="wo-value"><?php echo htmlspecialchars($wo['vessel_hours'] ?? '', ENT_QUOTES, 'UTF-8'); ?></div></div>
    <div class="wo-field" style="background:<?php echo $fieldColors[$fieldIdx++ % count($fieldColors)]; ?>;"><div class="wo-label">Labor Time</div><div class="wo-value"><?php echo htmlspecialchars($wo['labor_time'] ?? '', ENT_QUOTES, 'UTF-8'); ?></div></div>
    <div class="wo-field" style="background:<?php echo $fieldColors[$fieldIdx++ % count($fieldColors)]; ?>;"><div class="wo-label">Parts/Materials Cost</div><div class="wo-value"><?php echo htmlspecialchars($wo['parts_cost'] ?? '', ENT_QUOTES, 'UTF-8'); ?></div></div>
    <div class="wo-field" style="background:<?php echo $fieldColors[$fieldIdx++ % count($fieldColors)]; ?>;"><div class="wo-label">Chargeable To</div><div class="wo-value"><?php echo htmlspecialchars($wo['chargeable_to'] ?? '', ENT_QUOTES, 'UTF-8'); ?></div></div>
    <div class="wo-field" style="background:<?php echo $fieldColors[$fieldIdx++ % count($fieldColors)]; ?>;"><div class="wo-label">Created By</div><div class="wo-value"><?php
        if (($wo['created_via'] ?? '') === 'service_request') {
            echo 'Automated (Customer Service Request)';
        } else {
            echo htmlspecialchars((($wo['received_first'] ?? '') ? ($wo['received_first'].' '.($wo['received_last'] ?? '')) : 'Unknown'), ENT_QUOTES, 'UTF-8');
        }
    ?></div></div>
    <?php if (!empty($workPerformedEntries)): ?>
    <div class="wo-field full" style="background:<?php echo $fieldColors[$fieldIdx++ % count($fieldColors)]; ?>;">
        <div class="wo-label">Work Performed <span style="background:#e8f5e9; color:#166534; font-weight:700; border-radius:999px; padding:4px 8px; font-size:11px; display:inline-block; margin-left:6px; text-transform:none; letter-spacing:normal;">Total: <?php echo htmlspecialchars($totalWorkText, ENT_QUOTES, 'UTF-8'); ?></span></div>
        <div class="wo-value">
            <?php $entryColors = ['#f8fbff', '#fffdf7']; $entryIndex = 0; ?>
            <?php foreach ($workPerformedEntriesByDate as $dayKey => $dayEntries): ?>
                <details open style="margin-bottom:12px;">
                    <summary style="cursor:pointer; text-align:left; font-weight:700; color:#0f172a; padding:4px 0; border-bottom:2px solid #dfe7f1; margin-bottom:6px;"><?php echo htmlspecialchars(format_entry_day($dayKey), ENT_QUOTES, 'UTF-8'); ?></summary>
                    <?php foreach ($dayEntries as $entry): ?>
                        <details style="padding:8px 10px; margin-bottom:6px; border-radius:6px; background:<?php echo $entryColors[$entryIndex++ % count($entryColors)]; ?>;">
                            <summary style="cursor:pointer; display:flex; flex-wrap:wrap; gap:8px; align-items:baseline; font-size:13px; color:#475569;">
                                <?php if ($showNewBadge && isset($entry['id']) && $entry['id'] == $mostRecentEntryId): ?>
                                    <span title="Most recent update" style="background:#dc2626; color:#fff; font-size:10px; font-weight:700; border-radius:999px; padding:2px 7px; letter-spacing:.03em;">NEW</span>
                                <?php endif; ?>
                                <span><?php echo htmlspecialchars(format_entry_logged_at($entry['created_at'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></span>
                                <span>&middot;</span>
                                <span style="font-weight:600;"><?php echo htmlspecialchars(format_work_duration($entry['performed_time'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></span>
                                <span>&middot;</span>
                                <span>Technician: <?php echo htmlspecialchars(trim((($entry['firstname'] ?? '') . ' ' . ($entry['lastname'] ?? ''))) ?: 'Unknown', ENT_QUOTES, 'UTF-8'); ?></span>
                            </summary>
                            <div style="margin-top:6px; white-space:pre-wrap;"><?php echo nl2br(htmlspecialchars($entry['description'] ?? '', ENT_QUOTES, 'UTF-8')); ?></div>
                        </details>
                    <?php endforeach; ?>
                </details>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>
    <div class="wo-field" style="background:<?php echo $fieldColors[$fieldIdx++ % count($fieldColors)]; ?>;"><div class="wo-label">Permission Anytime</div><div class="wo-value"><?php echo $wo['permission_anytime'] ? 'Yes' : 'No'; ?></div></div>
    <div class="wo-field" style="background:<?php echo $fieldColors[$fieldIdx++ % count($fieldColors)]; ?>;"><div class="wo-label">Permission Date/Time</div><div class="wo-value"><?php echo htmlspecialchars($wo['permission_date'] ?? '', ENT_QUOTES, 'UTF-8') . ' ' . htmlspecialchars($wo['permission_time'] ?? '', ENT_QUOTES, 'UTF-8'); ?></div></div>
    <div class="wo-field full" style="background:<?php echo $fieldColors[$fieldIdx++ % count($fieldColors)]; ?>;"><div class="wo-label">Entry Date / Time Entered / Departed</div><div class="wo-value"><?php echo htmlspecialchars($wo['entry_date'] ?? '', ENT_QUOTES, 'UTF-8') . ' / ' . htmlspecialchars($wo['time_entered'] ?? '', ENT_QUOTES, 'UTF-8') . ' / ' . htmlspecialchars($wo['time_departed'] ?? '', ENT_QUOTES, 'UTF-8'); ?></div></div>
    <div class="wo-field" style="background:<?php echo $fieldColors[$fieldIdx++ % count($fieldColors)]; ?>;"><div class="wo-label">Created At</div><div class="wo-value"><?php echo htmlspecialchars($wo['created_at'] ?? '', ENT_QUOTES, 'UTF-8'); ?></div></div>
    <div class="wo-field" style="background:<?php echo $fieldColors[$fieldIdx++ % count($fieldColors)]; ?>;"><div class="wo-label">Last Updated</div><div class="wo-value"><?php
        $lu_time = $lastEditedAt ?? ($wo['updated_at'] ?? '');
        $lu_person = $lastEditor ?? '';
        echo htmlspecialchars($lu_time, ENT_QUOTES, 'UTF-8');
        if ($lu_person) echo ' by ' . htmlspecialchars($lu_person, ENT_QUOTES, 'UTF-8');
    ?></div></div>
</div>
</div>

<div id="status-guide-panel" style="display:none; max-width:1200px; margin:0 auto 20px; padding:12px 16px; border:1px solid #dfe7f1; border-radius:8px; background:#f8fafc; box-sizing:border-box;">
    <div style="font-size:12px; font-weight:700; color:#0f172a; margin-bottom:8px;">Status guide</div>
    <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap:8px;">
        <div style="padding:8px 10px; border:1px solid #dbeafe; border-radius:8px; background:#eff6ff; color:#1d4ed8; font-size:12px; line-height:1.4;"><strong>Open:</strong> Your request has been received and is waiting for review.</div>
        <div style="padding:8px 10px; border:1px solid #dbeafe; border-radius:8px; background:#eff6ff; color:#1d4ed8; font-size:12px; line-height:1.4;"><strong>In Progress:</strong> A technician is actively working on your job.</div>
        <div style="padding:8px 10px; border:1px solid #fef3c7; border-radius:8px; background:#fffbeb; color:#92400e; font-size:12px; line-height:1.4;"><strong>Waiting for Parts:</strong> The job is paused until required materials arrive.</div>
        <div style="padding:8px 10px; border:1px solid #e9d5ff; border-radius:8px; background:#faf5ff; color:#6b21a8; font-size:12px; line-height:1.4;"><strong>On Hold:</strong> Work is paused because of scheduling, access, or a customer decision.</div>
        <div style="padding:8px 10px; border:1px solid #dcfce7; border-radius:8px; background:#f0fdf4; color:#166534; font-size:12px; line-height:1.4;"><strong>Completed:</strong> The service has been finished and is ready for review.</div>
        <div style="padding:8px 10px; border:1px solid #e5e7eb; border-radius:8px; background:#f3f4f6; color:#374151; font-size:12px; line-height:1.4;"><strong>Closed:</strong> The job has been fully closed and archived.</div>
    </div>
</div>

<?php
// show full history (creation + edits) - only for staff roles, hidden from customers
if (in_array($currentRole, ['admin', 'office'], true)) {
    try {
    // creator info
    $creatorName = ($wo['received_first'] ?? '') ? ($wo['received_first'] . ' ' . ($wo['received_last'] ?? '')) : '';
    $createdAt = $wo['created_at'] ?? '';
    $creatorDisplay = $creatorName ?: (!empty($wo['order_received_by']) ? ('User ' . $wo['order_received_by']) : 'System');
    $edStmt = $conn->prepare('SELECT e.*, s.firstname, s.lastname FROM workorder_edits e LEFT JOIN staff s ON e.edited_by = s.id WHERE e.workorder_id = ? ORDER BY e.edited_at DESC');
    $edStmt->execute([$id]);
    $edits = $edStmt->fetchAll(PDO::FETCH_ASSOC);
    if ($createdAt || $creatorName || $edits): ?>
        <h3>Complete History</h3>
        <p style="margin:4px 0 8px; color:#666; font-size:0.95em;">Edits: <?php echo is_array($edits) ? count($edits) : 0; ?></p>
        <div class="history-toggle-container">
            <button id="toggle-history-btn" class="small-toggle-btn history-toggle-btn" data-toggle-target="history-wrapper" aria-expanded="false" aria-controls="history-wrapper">Show History</button>
        </div>

        <div id="history-wrapper" style="display:none; margin-top:6px; padding-bottom:24px;">
        <table id="history-table" class="history-table" style="max-width:960px; margin:0 auto;">
            <thead><tr><th style="text-align:center; padding:6px; width:170px;">When</th><th style="text-align:center; padding:6px; width:200px;">Who</th><th style="text-align:center; padding:6px;">Event</th></tr></thead>
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
    <?php endif;
    } catch (Exception $ex) {
        // ignore if edit table missing
    }
}
?>

<?php
// Assignment and work-performed history - only for staff roles, hidden from customers
if (in_array($currentRole, ['admin', 'office', 'technician'], true)) {
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

        echo '<div style="max-width:960px; margin:0 auto 12px;">';
        echo '<div class="history-button-row">';
        echo '<button type="button" class="small-toggle-btn history-toggle-btn" data-toggle-target="tech-history-body" aria-expanded="false">Show Technician History</button>';
        echo '</div>';
        echo '<div id="tech-history-body" style="display:none;">';
        if (empty($techHistory)) {
            echo '<p style="max-width:900px;margin:6px auto;">No technician history recorded.</p>';
        } else {
            echo '<table class="history-table" style="max-width:960px; margin:6px auto 36px;">';
            echo '<thead><tr><th style="text-align:center; padding:6px; width:180px;">When</th><th style="text-align:center; padding:6px; width:200px;">Who</th><th style="text-align:center; padding:6px;">Event</th></tr></thead><tbody>';
            foreach ($techHistory as $h) {
                echo '<tr><td style="padding:6px;">'.htmlspecialchars($h['edited_at'] ?? '', ENT_QUOTES, 'UTF-8').'</td>';
                echo '<td style="padding:6px;">'.htmlspecialchars($h['who'] ?? '', ENT_QUOTES, 'UTF-8').'</td>';
                echo '<td style="padding:6px;">'.htmlspecialchars($h['event'] ?? '', ENT_QUOTES, 'UTF-8').'</td></tr>';
            }
            echo '</tbody></table>';
        }
        echo '</div>';
        echo '</div>';
    } else {
        // non-technician: show separate Assignment and Work Performed history as before
        if ((!empty($assigns) && count($assigns) > 0) || (!empty($workPerformedEdits) && count($workPerformedEdits) > 0)):
?>
    <div style="max-width:960px; margin:0 auto 12px;">
        <div class="history-button-row">
            <button type="button" class="small-toggle-btn history-toggle-btn" data-toggle-target="assignment-history-body" aria-expanded="false">Show Assignment History</button>
        </div>
        <div id="assignment-history-body" style="display:none; width:100%; max-width:960px; margin:0 auto;">
            <?php if (empty($assigns)): ?>
                <p style="max-width:900px;margin:6px auto;">No assignment events recorded.</p>
            <?php else: ?>
                <table class="history-table" style="max-width:960px; margin:6px auto 18px;">
                    <thead><tr><th style="text-align:center; padding:6px; width:180px;">When</th><th style="text-align:center; padding:6px; width:200px;">Assigned By</th><th style="text-align:center; padding:6px;">Assigned To</th></tr></thead>
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
        </div>
    </div>

    <div style="max-width:960px; margin:0 auto 12px;">
        <div class="history-button-row">
            <button type="button" class="small-toggle-btn history-toggle-btn" data-toggle-target="work-performed-history-body" aria-expanded="false">Show Work Performed History</button>
        </div>
        <div id="work-performed-history-body" style="display:none; width:100%; max-width:960px; margin:0 auto;">
            <?php if (empty($workPerformedEdits)): ?>
                <p style="max-width:900px;margin:6px auto;">No work-performed edits recorded.</p>
            <?php else: ?>
                <table class="history-table" style="max-width:960px; margin:6px auto 36px;">
                    <thead><tr><th style="text-align:center; padding:6px; width:180px;">When</th><th style="text-align:center; padding:6px; width:200px;">Who</th><th style="text-align:center; padding:6px;">Change</th></tr></thead>
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
        </div>
    </div>
<?php
        endif;
    }
} catch (Exception $ex) {
    // ignore if workorder_edits missing or other DB errors
}
}

require_once '../includes/footer.php';
?>

<script>
(function(){
    var statusGuideToggle = document.getElementById('status-guide-toggle');
    var statusGuidePanel = document.getElementById('status-guide-panel');
    if (statusGuideToggle && statusGuidePanel) {
        statusGuideToggle.addEventListener('click', function(){
            var isVisible = statusGuidePanel.style.display !== 'none';
            statusGuidePanel.style.display = isVisible ? 'none' : 'block';
        });
    }

    document.querySelectorAll('[data-toggle-target]').forEach(function(button){
        var targetId = button.getAttribute('data-toggle-target');
        var target = targetId ? document.getElementById(targetId) : null;
        if (!target) { return; }
        var defaultText = button.textContent.trim();
        var hideText = defaultText.replace(/^Show\s+/, 'Hide ');
        if (defaultText.indexOf('Assignment History') !== -1) {
            hideText = 'Hide Assignment History';
        }
        if (defaultText.indexOf('Work Performed History') !== -1) {
            hideText = 'Hide Work Performed History';
        }
        button.addEventListener('click', function(){
            var isVisible = target.style.display !== 'none';
            target.style.display = isVisible ? 'none' : 'block';
            button.textContent = isVisible ? defaultText : hideText;
            button.setAttribute('aria-expanded', String(!isVisible));
        });
    });
})();
</script>