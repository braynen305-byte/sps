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

<h3>Add Staff</h3>
<form method="post">
    <input type="hidden" name="action" value="create">
    <input type="text" name="firstname" placeholder="First name" required>
    <input type="text" name="middlename" placeholder="Middle name">
    <input type="text" name="lastname" placeholder="Last name" required>
    <input type="email" name="email" placeholder="Email" required>
    <select name="role">
        <option value="staff">Staff</option>
        <option value="technician">Technician</option>
        <option value="admin">Admin</option>
    </select>
    <input type="password" name="password" placeholder="Temporary password" required>
    <button type="submit">Add Staff</button>
</form>

<h3>Current Staff</h3>
<?php if (empty($staffList)): ?>
    <p>No staff found.</p>
<?php else: ?>
    <table>
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
                    <td>
                        <form method="post" style="display:inline;">
                            <input type="hidden" name="action" value="update_role">
                            <input type="hidden" name="user_id" value="<?php echo (int)$staff['id']; ?>">
                            <select name="role">
                                <option value="staff" <?php echo strtolower($staff['role'] ?? '') === 'staff' ? 'selected' : ''; ?>>Staff</option>
                                <option value="technician" <?php echo strtolower($staff['role'] ?? '') === 'technician' ? 'selected' : ''; ?>>Technician</option>
                                <option value="admin" <?php echo strtolower($staff['role'] ?? '') === 'admin' ? 'selected' : ''; ?>>Admin</option>
                            </select>
                            <button type="submit">Update</button>
                        </form>
                    </td>
                    <td>
                        <form method="post" style="display:inline;">
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="user_id" value="<?php echo (int)$staff['id']; ?>">
                            <button type="submit" onclick="return confirm('Delete this staff member?');">Delete</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>

<?php require_once '../includes/footer.php'; ?>