<?php
session_start();

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header('Location: /sps/login.php');
    exit;
}

require_once '../includes/dbh.inc.php';

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

$currentRole = strtolower($_SESSION['role'] ?? '');
$userId = (int)($_SESSION['user_id'] ?? 0);

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

$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // define allowed fields per role
    $allFields = [
        'status','priority',
        'client_name','client_phone','location','order_date','expected_start_date','expected_end_date',
        'requested_work','additional_comments','vessel_vin','vessel_hours','labor_time','parts_cost',
        'chargeable_to','order_received_by','work_performed_by','permission_anytime','permission_date','permission_time',
        'entry_date','time_entered','time_departed','work_description','order_number'
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
    } elseif ($currentRole === 'office') {
        $allowed = ['client_name','client_phone','location','order_date','expected_start_date','expected_end_date',
            'requested_work','additional_comments','parts_cost','chargeable_to','permission_anytime','permission_date','permission_time'];
        if ($hasPriority) { $allowed[] = 'priority'; }
        if ($hasOrderNumber) { $allowed[] = 'order_number'; }
    } elseif ($currentRole === 'technician') {
        $allowed = ['vessel_vin','vessel_hours','labor_time','work_performed_by','work_description','entry_date','time_entered','time_departed'];
        if ($hasStatus) { $allowed[] = 'status'; }
    } else {
        $message = 'You do not have permission to edit this work order.';
        $allowed = [];
    }

    $changes = [];
    $updateParts = [];
    $params = [];

    foreach ($allowed as $field) {
        $new = $_POST[$field] ?? null;
        if ($field === 'permission_anytime') {
            $new = isset($_POST['permission_anytime']) ? 1 : 0;
        }
        if ($field === 'order_number') {
            $raw = trim($new ?? '');
            if ($raw !== '') {
                $rawClean = preg_replace('/^WO/i','',$raw);
                $new = 'WO' . $rawClean;
            } else {
                $new = null;
            }
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
$staff = $conn->query('SELECT id, firstname, lastname FROM staff ORDER BY firstname, lastname')->fetchAll(PDO::FETCH_ASSOC);

$title = 'Edit Work Order';
require_once '../includes/header.php';
?>

<h2>Edit Work Order #<?php echo (int)$wo['id']; ?></h2>
<p><a href="/sps/pages/view_workorder.php?id=<?php echo (int)$wo['id']; ?>">← Back to Work Order</a></p>

<?php if ($message): ?><p style="color: red"><?php echo htmlspecialchars($message, ENT_QUOTES, 'UTF-8'); ?></p><?php endif; ?>
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

<form method="post" class="edit-form">
    <input type="hidden" name="id" value="<?php echo (int)$wo['id']; ?>">

    <fieldset style="padding:10px; margin-bottom:15px;">
        <legend>Client / Order</legend>
        <?php if (($currentRole === 'admin' || $currentRole === 'technician') && $hasStatus): ?>
        <label>Status<br>
            <select name="status">
                <?php $curStatus = $wo['status'] ?? 'Open'; $statuses = ['Open','In Progress','Completed','Closed','On Hold']; foreach ($statuses as $st): ?>
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

    <?php if ($currentRole === 'admin' || $currentRole === 'technician'): ?>
    <fieldset style="padding:10px; margin-bottom:15px;">
        <legend>Work Performed / Technician</legend>
        <label>Work Performed By<br>
            <select name="work_performed_by">
                <option value="">-- Select --</option>
                <?php foreach ($staff as $s): ?>
                    <option value="<?php echo (int)$s['id']; ?>" <?php echo ((int)$s['id'] === (int)($wo['work_performed_by'] ?? 0)) ? 'selected' : ''; ?>><?php echo htmlspecialchars($s['firstname'].' '.$s['lastname'], ENT_QUOTES, 'UTF-8'); ?></option>
                <?php endforeach; ?>
            </select>
        </label><br>
        <label>Vessel VIN<br><input type="text" name="vessel_vin" value="<?php echo htmlspecialchars($wo['vessel_vin'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"></label>
        <label>Vessel Hours<br><input type="number" step="0.5" name="vessel_hours" value="<?php echo htmlspecialchars($wo['vessel_hours'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"></label>
        <label>Labor Time<br><input type="text" name="labor_time" value="<?php echo htmlspecialchars($wo['labor_time'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"></label>
        <label>Work Description<br><textarea name="work_description"><?php echo htmlspecialchars($wo['work_description'] ?? '', ENT_QUOTES, 'UTF-8'); ?></textarea></label>
        <label>Entry Date<br><input type="date" name="entry_date" value="<?php echo htmlspecialchars($wo['entry_date'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"></label>
        <label>Time Entered<br><input type="time" name="time_entered" value="<?php echo htmlspecialchars($wo['time_entered'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"></label>
        <label>Time Departed<br><input type="time" name="time_departed" value="<?php echo htmlspecialchars($wo['time_departed'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"></label>
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

<?php require_once '../includes/footer.php'; ?>
