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

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header('Location: /sps/login.php');
    exit;
}

require_once '../includes/dbh.inc.php';
require_once '../includes/notifications.php';

 $id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
if (!$id) {
    header('Location: /sps/pages/dashboard.php');
    exit;
}

// load workorder
$stmt = $conn->prepare('SELECT * FROM workorders WHERE id = ?');
$stmt->execute([$id]);
$wo = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$wo) {
    echo 'Work order not found.';
    exit;
}

$currentRole = strtolower(trim((string)($_SESSION['role'] ?? '')));
$userId = (int)($_SESSION['user_id'] ?? 0);

if ($currentRole === '' && $userId > 0) {
    $roleStmt = $conn->prepare('SELECT role FROM staff WHERE id = ? LIMIT 1');
    $roleStmt->execute([$userId]);
    $roleRow = $roleStmt->fetch(PDO::FETCH_ASSOC);
    if ($roleRow) {
        $currentRole = strtolower(trim((string)($roleRow['role'] ?? '')));
        $_SESSION['role'] = $currentRole;
    }
}

$canManageStatus = in_array($currentRole, ['admin', 'office', 'technician', 'staff'], true);

// create edits table if missing
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

// ensure status column exists (Open/In Progress/Completed/Closed/etc.)
try {
    $conn->exec("ALTER TABLE workorders ADD COLUMN IF NOT EXISTS `status` VARCHAR(50) DEFAULT 'Open'");
} catch (Exception $ex) {
    // ignore if ALTER not supported; DB may already have the column
}

// ensure priority column exists (Low/Normal/High)
try {
    $conn->exec("ALTER TABLE workorders ADD COLUMN IF NOT EXISTS `priority` VARCHAR(20) DEFAULT 'Normal'");
} catch (Exception $ex) {
    // ignore
}
// ensure order_number column exists
try {
    $conn->exec("ALTER TABLE workorders ADD COLUMN IF NOT EXISTS `order_number` VARCHAR(100) DEFAULT NULL");
} catch (Exception $ex) {
    // ignore
}

// detect whether workorders has a `status` column
$hasStatus = false;
try {
    $col = $conn->query("SHOW COLUMNS FROM workorders LIKE 'status'")->fetch(PDO::FETCH_ASSOC);
    $hasStatus = !empty($col);
} catch (Exception $e) {
    $hasStatus = false;
}
if (!$hasStatus) {
    try {
        $conn->exec("ALTER TABLE workorders ADD COLUMN status VARCHAR(50) NOT NULL DEFAULT 'Open'");
        $hasStatus = true;
    } catch (Exception $e) {
        $hasStatus = false;
    }
}
$canEditStatus = in_array($currentRole, ['admin', 'office', 'technician'], true);

// detect priority column
$hasPriority = false;
try {
    $colp = $conn->query("SHOW COLUMNS FROM workorders LIKE 'priority'")->fetch(PDO::FETCH_ASSOC);
    $hasPriority = !empty($colp);
} catch (Exception $e) {
    $hasPriority = false;
}

// detect order_number column
$hasOrderNumber = false;
try {
    $coln = $conn->query("SHOW COLUMNS FROM workorders LIKE 'order_number'")->fetch(PDO::FETCH_ASSOC);
    $hasOrderNumber = !empty($coln);
} catch (Exception $e) {
    $hasOrderNumber = false;
}

function normalizeWorkOrderNumber($value) {
    $raw = trim((string)($value ?? ''));
    if ($raw === '') {
        return null;
    }

    $withoutPrefix = preg_replace('/^WO/i', '', $raw);
    $digitsOnly = preg_replace('/\D+/', '', $withoutPrefix ?? '');
    if ($digitsOnly === '') {
        return null;
    }

    $numeric = (int)$digitsOnly;
    return 'WO' . str_pad((string)$numeric, 4, '0', STR_PAD_LEFT);
}

$message = '';
$techEntryMessage = '';
$isAssignedTechnician = ($currentRole === 'technician' && (int)($wo['work_performed_by'] ?? 0) === $userId);
$techAssignmentWarning = ($currentRole === 'technician' && !$isAssignedTechnician) ? 'This work order must be assigned to you to edit.' : '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $canAddWorkPerformed = ($currentRole === 'admin') || ($currentRole === 'technician' && $isAssignedTechnician);
    if (in_array($currentRole, ['admin', 'technician'], true) && isset($_POST['tech_action']) && $_POST['tech_action'] === 'add_work_performed') {
        if (!$canAddWorkPerformed) {
            $techEntryMessage = 'You can only add work performed on work orders assigned to you.';
        } else {
            $performedDate = trim((string)($_POST['performed_date'] ?? ''));
            $performedHours = max(0, min(99, (int)($_POST['performed_hours'] ?? 0)));
            $performedMinutes = max(0, min(59, (int)($_POST['performed_minutes'] ?? 0)));
            $performedTime = sprintf('%02d:%02d:00', $performedHours, $performedMinutes);
            $performedDescription = trim((string)($_POST['performed_description'] ?? ''));

            if ($performedDate !== '' && ($performedHours > 0 || $performedMinutes > 0) && $performedDescription !== '') {
                $insertEntry = $conn->prepare('INSERT INTO work_performed_entries (workorder_id, performed_date, performed_time, description, added_by) VALUES (?, ?, ?, ?, ?)');
                $insertEntry->execute([$id, $performedDate, $performedTime, $performedDescription, $userId]);

                $customerIdForEntry = (int)($wo['customer_id'] ?? 0);
                if ($customerIdForEntry > 0) {
                    $entryStatus = trim((string)($wo['status'] ?? 'Updated'));
                    notify_customer_workorder_update($conn, $customerIdForEntry, $id, $entryStatus);
                }

                $techEntryMessage = 'Work performed entry added successfully.';
                header('Location: /sps/pages/edit_workorder.php?id=' . $id . '&entry_saved=1');
                exit;
            }

            $techEntryMessage = 'Please enter a date, time spent, and description for the work performed entry.';
        }
    }

    if ($currentRole === 'technician' && !$isAssignedTechnician && empty($_POST['tech_action'])) {
        $message = $techAssignmentWarning !== '' ? $techAssignmentWarning : 'You can only edit work orders assigned to you.';
    }

    // define allowed fields per role
    $allFields = [
        'status','priority',
        'client_name','client_phone','location','order_date','expected_start_date','expected_end_date',
        'requested_work','additional_comments','vessel_vin','vessel_hours','labor_time','parts_cost',
        'chargeable_to','order_received_by','work_performed_by','permission_anytime','permission_date','permission_time',
        'entry_date','time_entered','time_departed','order_number'
    ];

    if (!$hasStatus) {
        $allFields = array_values(array_filter($allFields, function($f){ return $f !== 'status'; }));
    }
    if (!$hasPriority) {
        $allFields = array_values(array_filter($allFields, function($f){ return $f !== 'priority'; }));
    }
    if (!$hasOrderNumber) {
        $allFields = array_values(array_filter($allFields, function($f){ return $f !== 'order_number'; }));
    }

    if ($currentRole === 'admin') {
        $allowed = $allFields;
    } elseif (in_array($currentRole, ['office', 'staff'], true)) {
        $allowed = ['status','client_name','client_phone','location','order_date','expected_start_date','expected_end_date',
            'requested_work','additional_comments','parts_cost','chargeable_to','permission_anytime','permission_date','permission_time',
            'work_performed_by'];
        if ($hasPriority) { $allowed[] = 'priority'; }
        if ($hasOrderNumber) { $allowed[] = 'order_number'; }
    } elseif ($currentRole === 'technician') {
        if (!$isAssignedTechnician) {
            $allowed = [];
            $message = $techAssignmentWarning !== '' ? $techAssignmentWarning : 'You can only edit work orders assigned to you.';
        } else {
            $allowed = ['vessel_vin','vessel_hours','labor_time','work_performed_by'];
            if ($hasStatus) { $allowed[] = 'status'; }
        }
    } else {
        $message = 'You do not have permission to edit this work order.';
        $allowed = [];
    }

    $changes = [];
    $updateParts = [];
    $params = [];

    $oldAssignedTech = (int)($wo['work_performed_by'] ?? 0);
    $newAssignedTech = isset($_POST['work_performed_by']) ? (int)($_POST['work_performed_by'] ?? 0) : $oldAssignedTech;

    foreach ($allowed as $field) {
        $new = $_POST[$field] ?? null;
        if ($field === 'permission_anytime') {
            $new = isset($_POST['permission_anytime']) ? 1 : 0;
        }
        if ($field === 'order_number') {
            $raw = trim((string)($new ?? ''));
            $new = normalizeWorkOrderNumber($raw);
        }
        // normalize empty strings to null for DB consistency
        $newNorm = ($new === '' ? null : $new);
        $old = $wo[$field] ?? null;
        // compare as strings
        if ((string)$old !== (string)$newNorm) {
            $changes[] = ['field' => $field, 'old' => $old, 'new' => $newNorm];
            $updateParts[] = "$field = ?";
            $params[] = $newNorm;
        }
    }

    if (!empty($updateParts)) {
        $params[] = $id;
        $sql = 'UPDATE workorders SET ' . implode(', ', $updateParts) . ' WHERE id = ?';
        $upd = $conn->prepare($sql);
        $upd->execute($params);

        $customerIdForNotice = (int)($wo['customer_id'] ?? 0);
        $statusNotice = trim((string)($_POST['status'] ?? ($wo['status'] ?? 'Updated')));
        if ($customerIdForNotice > 0 && $statusNotice !== '') {
            notify_customer_workorder_update($conn, $customerIdForNotice, $id, $statusNotice);
        }

        // insert edits
        $ins = $conn->prepare('INSERT INTO workorder_edits (workorder_id, field_name, old_value, new_value, edited_by) VALUES (?, ?, ?, ?, ?)');
        foreach ($changes as $c) {
            $ins->execute([$id, $c['field'], $c['old'], $c['new'], $userId]);
        }

        // reload workorder
        $stmt = $conn->prepare('SELECT * FROM workorders WHERE id = ?');
        $stmt->execute([$id]);
        $wo = $stmt->fetch(PDO::FETCH_ASSOC);

        header('Location: /sps/pages/view_workorder.php?id=' . $id);
        exit;
    } else {
        $message = 'No changes detected.';
    }
}

// fetch staff for selects
$staff = $conn->query('SELECT id, firstname, lastname, role FROM staff ORDER BY firstname, lastname')->fetchAll(PDO::FETCH_ASSOC);

$techWorkEntries = [];
if (in_array($currentRole, ['admin', 'technician'], true)) {
    $techWorkEntriesStmt = $conn->prepare('SELECT wpe.*, s.firstname, s.lastname FROM work_performed_entries wpe LEFT JOIN staff s ON s.id = wpe.added_by WHERE wpe.workorder_id = ? ORDER BY wpe.performed_date DESC, wpe.created_at DESC');
    $techWorkEntriesStmt->execute([$id]);
    $techWorkEntries = $techWorkEntriesStmt->fetchAll(PDO::FETCH_ASSOC);
}

$techWorkEntriesByDate = [];
foreach ($techWorkEntries as $entry) {
    $dateKey = $entry['performed_date'] ?? '';
    $techWorkEntriesByDate[$dateKey][] = $entry;
}

$title = 'Edit Work Order';
require_once '../includes/header.php';
?>

<h2>Edit Work Order #<?php echo (int)$wo['id']; ?></h2>
<p><a href="/sps/pages/view_workorder.php?id=<?php echo (int)$wo['id']; ?>">← Back to Work Order</a></p>

<?php if ($message): ?><p style="color: #b45309; font-weight: 700; background: #fff7ed; border: 1px solid #fdba74; padding: 10px 12px; border-radius: 6px; max-width: 920px; margin: 12px auto 0;"><?php echo htmlspecialchars($message, ENT_QUOTES, 'UTF-8'); ?></p><?php endif; ?>
<?php $isTechBlocked = ($currentRole === 'technician' && !$isAssignedTechnician); ?>
<?php if ($isTechBlocked): ?>
    <div style="max-width: 920px; margin: 20px auto 0; padding: 14px 16px; border: 1px solid #fbbf24; border-radius: 8px; background: #fff7ed; color: #92400e; font-weight: 700;">
        <?php echo htmlspecialchars($techAssignmentWarning, ENT_QUOTES, 'UTF-8'); ?>
    </div>
    <p style="max-width: 920px; margin: 16px auto 0;"><a href="/sps/pages/view_workorder.php?id=<?php echo (int)$wo['id']; ?>">← Back to Work Order</a></p>
<?php else: ?>
<style>
    form.edit-form { max-width: 920px; margin: 18px auto 60px; font-family: Arial, sans-serif; padding-bottom: 120px; box-sizing: border-box; }
    form.edit-form fieldset { padding: 14px; margin-bottom: 14px; border-radius: 6px; border: 1px solid #d0d7de; }
    form.edit-form fieldset:nth-of-type(odd) { background: #ffffff; }
    form.edit-form fieldset:nth-of-type(even) { background: #f7fbff; }
    form.edit-form legend { font-weight: bold; padding: 0 6px; }
    form.edit-form label { display:block; margin:8px 0; }
    form.edit-form input[type="text"], form.edit-form input[type="date"], form.edit-form input[type="time"], form.edit-form input[type="number"], form.edit-form select, form.edit-form textarea { width:100%; box-sizing: border-box; padding:8px; border:1px solid #cbd5e0; border-radius:4px; }
    form.edit-form textarea { min-height:80px; }
    form.edit-form .actions { margin-top:12px; }
    form.edit-form .actions button { padding:10px 16px; background:#007BFF; color:#fff; border:none; border-radius:4px; cursor:pointer; }
    form.edit-form .actions a { margin-left:10px; color:#333; text-decoration:none; }
</style>

<form id="add-work-performed-form" method="post"></form>
<form method="post" class="edit-form">
    <input type="hidden" name="id" value="<?php echo (int)$wo['id']; ?>">

    <fieldset style="padding:10px; margin-bottom:15px;">
        <legend>Client / Order</legend>
        <?php if ($canEditStatus): ?>
        <label>Status<br>
            <select name="status">
                <?php $curStatus = $wo['status'] ?? 'Open'; $statuses = ['Pending','Open','In Progress','Waiting for Parts','Completed','Closed','On Hold']; foreach ($statuses as $st): ?>
                    <option value="<?php echo htmlspecialchars($st, ENT_QUOTES, 'UTF-8'); ?>" <?php echo ($curStatus === $st) ? 'selected' : ''; ?>><?php echo htmlspecialchars($st, ENT_QUOTES, 'UTF-8'); ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <?php endif; ?>
        <?php if (($currentRole === 'admin' || $currentRole === 'office') && $hasPriority): ?>
        <label>Priority<br>
            <?php $curPriority = $wo['priority'] ?? 'Normal'; $priorities = ['Low','Normal','High']; ?>
            <select name="priority">
                <?php foreach ($priorities as $p): ?>
                    <option value="<?php echo htmlspecialchars($p, ENT_QUOTES, 'UTF-8'); ?>" <?php echo ($curPriority === $p) ? 'selected' : ''; ?>><?php echo htmlspecialchars($p, ENT_QUOTES, 'UTF-8'); ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <?php endif; ?>
        <?php if (($currentRole === 'admin' || $currentRole === 'office') && $hasOrderNumber): ?>
        <label>Work Order Number (optional)<br>
            <input type="text" name="order_number" value="<?php echo htmlspecialchars($wo['order_number'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" placeholder="WO123 or 123">
        </label>
        <?php endif; ?>
        <label>Client Name<br><input type="text" name="client_name" value="<?php echo htmlspecialchars($wo['client_name'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" <?php echo ($currentRole==='office' || $currentRole==='admin') ? '' : 'readonly'; ?>></label>
        <label>Client Phone<br><input type="text" name="client_phone" value="<?php echo htmlspecialchars($wo['client_phone'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" <?php echo ($currentRole==='office' || $currentRole==='admin') ? '' : 'readonly'; ?>></label>
        <label>Location<br><input type="text" name="location" value="<?php echo htmlspecialchars($wo['location'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" <?php echo ($currentRole==='office' || $currentRole==='admin') ? '' : 'readonly'; ?>></label>
        <label>Order Date<br><input type="date" name="order_date" value="<?php echo htmlspecialchars($wo['order_date'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" <?php echo ($currentRole==='office' || $currentRole==='admin') ? '' : 'readonly'; ?>></label>
    </fieldset>

    <fieldset style="padding:10px; margin-bottom:15px;">
        <legend>Work / Comments</legend>
        <label>Requested Work<br><textarea name="requested_work" <?php echo ($currentRole==='office' || $currentRole==='admin') ? '' : 'readonly'; ?>><?php echo htmlspecialchars($wo['requested_work'] ?? '', ENT_QUOTES, 'UTF-8'); ?></textarea></label>
        <label>Additional Comments<br><textarea name="additional_comments"><?php echo htmlspecialchars($wo['additional_comments'] ?? '', ENT_QUOTES, 'UTF-8'); ?></textarea></label>
    </fieldset>

    <?php if ($currentRole === 'admin' || $currentRole === 'office' || $currentRole === 'technician'): ?>
    <fieldset style="padding:10px; margin-bottom:15px;">
        <legend>Assignment / Technician</legend>
        <label>Assigned Technician<br>
            <select name="work_performed_by">
                <option value="">-- Not Assigned --</option>
                <?php foreach ($staff as $s): ?>
                    <?php
                        $roleValue = strtolower(trim((string)($s['role'] ?? '')));
                        $showInTechList = ($roleValue === 'admin' || $roleValue === 'technician' || $roleValue === 'staff' || $roleValue === '');
                        if ($showInTechList):
                    ?>
                        <option value="<?php echo (int)$s['id']; ?>" <?php echo ((int)$s['id'] === (int)($wo['work_performed_by'] ?? 0)) ? 'selected' : ''; ?>><?php echo htmlspecialchars($s['firstname'].' '.$s['lastname'], ENT_QUOTES, 'UTF-8'); ?></option>
                    <?php endif; ?>
                <?php endforeach; ?>
            </select>
        </label><br>
        <?php if ($currentRole === 'admin' || $currentRole === 'technician'): ?>
        <label>Vessel VIN<br><input type="text" name="vessel_vin" value="<?php echo htmlspecialchars($wo['vessel_vin'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"></label>
        <label>Vessel Hours<br><input type="number" step="0.5" name="vessel_hours" value="<?php echo htmlspecialchars($wo['vessel_hours'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"></label>
        <label>Labor Time<br><input type="text" name="labor_time" value="<?php echo htmlspecialchars($wo['labor_time'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"></label>
        <?php if ($currentRole === 'admin' || ($currentRole === 'technician' && $isAssignedTechnician)): ?>
            <div style="margin-top:16px; padding:14px; border:1px solid #dfe7f1; border-radius:6px; background:#f8fbff;">
                <h3 style="margin:0 0 10px;">Add Work Performed</h3>
                <?php if ($techEntryMessage !== ''): ?>
                    <p style="margin:0 0 10px; color:#0b5a2c; font-weight:600;"><?php echo htmlspecialchars($techEntryMessage, ENT_QUOTES, 'UTF-8'); ?></p>
                <?php endif; ?>
                <div style="display:flex; flex-direction:column; gap:10px; margin:0;">
                    <div style="display:flex; gap:10px; flex-wrap:wrap;">
                        <label style="flex:1; min-width:160px; font-weight:700; margin:0;">Date<br><input type="date" name="performed_date" form="add-work-performed-form" required style="width:100%; padding:8px; border:1px solid #cbd5e0; border-radius:4px;"></label>
                        <label style="flex:1; min-width:160px; font-weight:700; margin:0;">Time Spent<br>
                            <div style="display:flex; gap:6px; align-items:center;">
                                <input type="number" name="performed_hours" form="add-work-performed-form" min="0" max="99" value="0" required style="width:70px; padding:8px; border:1px solid #cbd5e0; border-radius:4px;"> <span>hrs</span>
                                <input type="number" name="performed_minutes" form="add-work-performed-form" min="0" max="59" step="5" value="0" required style="width:70px; padding:8px; border:1px solid #cbd5e0; border-radius:4px;"> <span>min</span>
                            </div>
                        </label>
                    </div>
                    <label style="font-weight:700; margin:0;">Description<br><textarea name="performed_description" form="add-work-performed-form" rows="4" required style="width:100%; padding:8px; border:1px solid #cbd5e0; border-radius:4px; resize:vertical;"></textarea></label>
                    <button type="submit" form="add-work-performed-form" name="tech_action" value="add_work_performed" style="padding:10px 16px; background:#007BFF; color:#fff; border:none; border-radius:4px; cursor:pointer; width:max-content;">Save Work Performed</button>
                </div>
            </div>
            <?php if (!empty($techWorkEntries)): ?>
                <div style="margin-top:16px;">
                    <h4 style="margin:0 0 8px;">Work Performed</h4>
                    <?php $entryColors = ['#f8fbff', '#fffdf7']; $entryIndex = 0; ?>
                    <?php foreach ($techWorkEntriesByDate as $dayKey => $dayEntries): ?>
                        <details open style="margin-bottom:14px;">
                            <summary style="cursor:pointer; text-align:left; font-weight:700; color:#0f172a; padding:6px 0; border-bottom:2px solid #dfe7f1; margin-bottom:6px;"><?php echo htmlspecialchars(format_entry_day($dayKey), ENT_QUOTES, 'UTF-8'); ?></summary>
                            <?php foreach ($dayEntries as $entry): ?>
                                <details style="padding:8px 10px; margin-bottom:6px; border-radius:6px; background:<?php echo $entryColors[$entryIndex++ % count($entryColors)]; ?>;">
                                    <summary style="cursor:pointer; display:flex; flex-wrap:wrap; gap:8px; align-items:baseline; font-size:13px; color:#475569;">
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
            <?php endif; ?>
        <?php elseif ($currentRole === 'admin'): ?>
            <label>Entry Date<br><input type="date" name="entry_date" value="<?php echo htmlspecialchars($wo['entry_date'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"></label>
            <label>Time Entered<br><input type="time" name="time_entered" value="<?php echo htmlspecialchars($wo['time_entered'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"></label>
            <label>Time Departed<br><input type="time" name="time_departed" value="<?php echo htmlspecialchars($wo['time_departed'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"></label>
        <?php endif; ?>
        <?php endif; ?>
    </fieldset>
    <?php endif; ?>

    <?php if ($currentRole === 'admin' || $currentRole === 'office' || $currentRole === 'technician'): ?>
    <fieldset style="padding:10px; margin-bottom:15px;">
        <legend>Costs & Permissions</legend>
        <label>Parts/Material Cost ($)<br><input type="number" step="0.01" name="parts_cost" value="<?php echo htmlspecialchars($wo['parts_cost'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"></label>
        <label>Chargeable To<br><input type="text" name="chargeable_to" value="<?php echo htmlspecialchars($wo['chargeable_to'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"></label>
        <label><input type="checkbox" name="permission_anytime" value="1" <?php echo (!empty($wo['permission_anytime'])) ? 'checked' : ''; ?>> Permission Anytime</label>
        <label>Permission Date<br><input type="date" name="permission_date" value="<?php echo htmlspecialchars($wo['permission_date'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"></label>
        <label>Permission Time<br><input type="time" name="permission_time" value="<?php echo htmlspecialchars($wo['permission_time'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"></label>
    </fieldset>
    <?php endif; ?>

    <div class="actions">
        <button type="submit">Save Changes</button>
        <a href="/sps/pages/view_workorder.php?id=<?php echo (int)$wo['id']; ?>">Cancel</a>
    </div>
</form>
<?php endif; ?>

<?php require_once '../includes/footer.php'; ?>
