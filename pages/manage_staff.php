<?php
session_start();

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true || strtolower($_SESSION['role'] ?? '') !== 'admin') {
    header('Location: /sps/login.php');
    exit;
}

require_once '../includes/dbh.inc.php';

$title = 'Manage Staff';
require_once '../includes/header.php';

$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'create') {
        $firstname = trim($_POST['firstname'] ?? '');
        $middlename = trim($_POST['middlename'] ?? '');
        $lastname = trim($_POST['lastname'] ?? '');
        $email = strtolower(trim($_POST['email'] ?? ''));
        $role = trim($_POST['role'] ?? 'staff');
        $password = trim($_POST['password'] ?? '');

        if ($firstname === '' || $lastname === '' || $email === '' || $password === '') {
            $message = 'Please fill in the required fields.';
            $messageType = 'error';
        } else {
            $check = $conn->prepare('SELECT id FROM staff WHERE LOWER(email) = LOWER(?) LIMIT 1');
            $check->execute([$email]);
            if ($check->fetch()) {
                $message = 'A staff member with that email already exists.';
                $messageType = 'error';
            } else {
                $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
                $stmt = $conn->prepare('INSERT INTO staff (firstname, middlename, lastname, email, role, password, created_at) VALUES (?, ?, ?, ?, ?, ?, NOW())');
                if ($stmt->execute([$firstname, $middlename, $lastname, $email, $role, $hashedPassword])) {
                    $message = 'Staff member added successfully.';
                    $messageType = 'success';
                } else {
                    $message = 'Unable to add staff member.';
                    $messageType = 'error';
                }
            }
        }
    } elseif ($action === 'update_role') {
        $userId = (int)($_POST['user_id'] ?? 0);
        $role = trim($_POST['role'] ?? 'staff');
        if ($userId > 0) {
            $stmt = $conn->prepare('UPDATE staff SET role = ? WHERE id = ?');
            if ($stmt->execute([$role, $userId])) {
                $message = 'Role updated successfully.';
                $messageType = 'success';
            } else {
                $message = 'Unable to update role.';
                $messageType = 'error';
            }
        }
    } elseif ($action === 'delete') {
        $userId = (int)($_POST['user_id'] ?? 0);
        if ($userId > 0) {
            $stmt = $conn->prepare('DELETE FROM staff WHERE id = ?');
            if ($stmt->execute([$userId])) {
                $message = 'Staff member deleted successfully.';
                $messageType = 'success';
            } else {
                $message = 'Unable to delete staff member.';
                $messageType = 'error';
            }
        }
    }
}

$staffList = $conn->query('SELECT id, firstname, middlename, lastname, email, role FROM staff ORDER BY firstname, lastname')->fetchAll(PDO::FETCH_ASSOC);
?>

<h2>Manage Staff</h2>
<p><a href="/sps/pages/admin_dashboard.php">Back to dashboard</a></p>

<?php if ($message !== ''): ?>
    <p style="color: <?php echo $messageType === 'success' ? 'green' : 'red'; ?>;">
        <?php echo htmlspecialchars($message, ENT_QUOTES, 'UTF-8'); ?>
    </p>
<?php endif; ?>

<div class="staff-panel">
    <div class="panel-header"><h2 style="margin:0;">Staff Management</h2></div>
    <div class="panel-inner">
        <h3 class="section-heading"><span class="heading-title">Add Staff</span> <button id="toggle-add-staff" class="toggle-link" aria-expanded="false" style="margin-left:12px;padding:6px 0;background:transparent;border:0;color:#007BFF;cursor:pointer;">Show Add Staff ▾</button></h3>
        <style>
            .section-heading { text-align:center; margin:8px 0 6px; }
            .section-heading .heading-title { display:inline-block; text-transform:uppercase; font-weight:700; border-bottom:3px solid #007BFF; padding-bottom:6px; }
            .section-block { padding:12px; border-radius:8px; margin-bottom:18px; }
            .section-block.add { background:#ffffff; }
            .section-block.list { background:#fbfbff; }
            .staff-panel { max-width:1100px; margin:18px auto; }
            .staff-panel .panel-header { background:#007BFF; color:#fff; padding:12px 16px; border-radius:10px 10px 0 0; }
            .staff-panel .panel-inner { background:#fff; padding:16px; border-radius:0 0 10px 10px; box-shadow:0 4px 12px rgba(0,0,0,0.04); }
            .add-staff { max-width:920px; margin:10px auto 22px; }
            .add-staff.collapsed { display:none; }
    .add-staff .grid { display:grid; grid-template-columns: repeat(2, 1fr); gap:12px; }
    .add-staff label { display:block; font-size:13px; margin-bottom:4px; }
    .add-staff input[type="text"], .add-staff input[type="email"], .add-staff input[type="password"], .add-staff select { width:100%; padding:8px; box-sizing:border-box; border:1px solid #cfd8e3; border-radius:4px; }
    .add-staff .full { grid-column:1/-1; }
    .add-staff .actions { display:flex; justify-content:flex-end; }
    .add-staff .actions button { padding:8px 14px; border-radius:4px; background:#007bff; color:#fff; border:none; cursor:pointer; }
</style>
<div class="section-block add">
<div class="add-staff">
    <form method="post" class="add-staff-form">
        <input type="hidden" name="action" value="create">
        <div class="grid">
            <div>
                <label>First name</label>
                <input type="text" name="firstname" placeholder="First name" required>
            </div>
            <div>
                <label>Middle name</label>
                <input type="text" name="middlename" placeholder="Middle name">
            </div>
            <div>
                <label>Last name</label>
                <input type="text" name="lastname" placeholder="Last name" required>
            </div>
            <div>
                <label>Email</label>
                <input type="email" name="email" placeholder="Email" required>
            </div>
            <div>
                <label>Role</label>
                <select name="role">
                    <option value="staff">Staff</option>
                    <option value="technician">Technician</option>
                    <option value="admin">Admin</option>
                </select>
            </div>
            <div>
                <label>Temporary password</label>
                <input type="password" name="password" placeholder="Temporary password" required>
            </div>
            <div class="full actions">
                <button type="submit">Add Staff</button>
            </div>
        </div>
    </form>
    </div>
</div>
<script>
(function(){
    var btn = document.getElementById('toggle-add-staff');
    var panel = document.querySelector('.add-staff');
    if(!btn || !panel) return;
    // start collapsed
    panel.classList.add('collapsed');
    btn.setAttribute('aria-expanded','false');
    btn.addEventListener('click', function(){
        var collapsed = panel.classList.toggle('collapsed');
        btn.setAttribute('aria-expanded', String(!collapsed));
        btn.textContent = collapsed ? 'Show Add Staff ▾' : 'Hide Add Staff ▴';
    });
})();
</script>

    <h3 class="section-heading"><span class="heading-title">Current Staff</span></h3>
<style>
    .staff-table { width:100%; max-width:1100px; margin:12px auto; border-collapse:collapse; font-family: Arial, sans-serif; }
    .staff-table th, .staff-table td { padding:12px 14px; border:1px solid #e9ecef; vertical-align:middle; }
    .staff-table thead th { background:#f1f5f9; font-weight:700; }
    .staff-table tbody tr:nth-child(odd) { background:#ffffff; }
    .staff-table tbody tr:nth-child(even) { background:#fbfdff; }
    .staff-table tbody tr:hover { background:#f0f8ff; }
    .staff-table td.role-cell { min-width:300px; }
    .staff-table select { width:100%; max-width:320px; padding:8px; box-sizing:border-box; }
    .staff-table td.actions-cell { width:260px; text-align:center; }
    .staff-table .btn { padding:6px 10px; border-radius:4px; background:#007bff; color:#fff; border:none; cursor:pointer; }
    .staff-table form { margin:0; }
</style>
<?php if (empty($staffList)): ?>
    <p>No staff found.</p>
<?php else: ?>
    <div class="section-block list">
    <table class="staff-table">
        <thead>
            <tr>
                <th>Name</th>
                <th>Email</th>
                <th>Role</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($staffList as $staff): ?>
                <tr>
                    <td><?php echo htmlspecialchars(trim($staff['firstname'] . ' ' . $staff['middlename'] . ' ' . $staff['lastname']), ENT_QUOTES, 'UTF-8'); ?></td>
                    <td><?php echo htmlspecialchars($staff['email'], ENT_QUOTES, 'UTF-8'); ?></td>
                    <td class="role-cell">
                        <form id="role-form-<?php echo (int)$staff['id']; ?>" method="post" style="display:inline-block;">
                            <input type="hidden" name="action" value="update_role">
                            <input type="hidden" name="user_id" value="<?php echo (int)$staff['id']; ?>">
                            <select name="role">
                                <option value="staff" <?php echo strtolower($staff['role'] ?? '') === 'staff' ? 'selected' : ''; ?>>Staff</option>
                                <option value="technician" <?php echo strtolower($staff['role'] ?? '') === 'technician' ? 'selected' : ''; ?>>Technician</option>
                                <option value="admin" <?php echo strtolower($staff['role'] ?? '') === 'admin' ? 'selected' : ''; ?>>Admin</option>
                            </select>
                        </form>
                    </td>
                    <td class="actions-cell">
                        <div style="display:flex;justify-content:center;gap:12px;align-items:center;">
                            <button type="button" class="btn" onclick="document.getElementById('role-form-<?php echo (int)$staff['id']; ?>').submit();">Update</button>
                            <form method="post" style="display:inline;" onsubmit="return confirm('Delete this staff member?');">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="user_id" value="<?php echo (int)$staff['id']; ?>">
                                <button type="submit">Delete</button>
                            </form>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <?php endif; ?>
    </div>
</div>

<?php require_once '../includes/footer.php'; ?>