<?php
session_start();
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true || strtolower($_SESSION['role'] ?? '') !== 'customer') {
    header('Location: /sps/customer_login.php');
    exit;
}

require_once '../includes/dbh.inc.php';
require_once '../includes/notifications.php';

$customerId = (int)($_SESSION['customer_id'] ?? 0);
$message = '';
$messageType = 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['save_notifications'])) {
        $customerPrefs = [
            'email_enabled' => !empty($_POST['email_enabled']) ? 1 : 0,
            'whatsapp_enabled' => !empty($_POST['whatsapp_enabled']) ? 1 : 0,
            'sms_enabled' => !empty($_POST['sms_enabled']) ? 1 : 0,
            'preferred_channel' => strtolower((string)($_POST['preferred_channel'] ?? 'email')),
        ];

        if (save_customer_notification_preferences($conn, $customerId, $customerPrefs)) {
            $message = 'Notification preferences updated successfully.';
            $messageType = 'success';
        } else {
            $message = 'Unable to save notification preferences.';
            $messageType = 'error';
        }
    } else {
        $name = trim((string)($_POST['name'] ?? ''));
        $phone = trim((string)($_POST['phone'] ?? ''));
        $email = trim((string)($_POST['email'] ?? ''));
        $address = trim((string)($_POST['address'] ?? ''));
        $city = trim((string)($_POST['city'] ?? ''));
        $state = trim((string)($_POST['state'] ?? ''));
        $zip = trim((string)($_POST['zip'] ?? ''));

        if ($name === '' || $email === '') {
            $message = 'Name and email are required.';
            $messageType = 'error';
        } else {
            $stmt = $conn->prepare('UPDATE customers SET name = ?, phone = ?, email = ?, address = ?, city = ?, state = ?, zip = ?, updated_at = NOW() WHERE id = ?');
            if ($stmt->execute([$name, $phone, $email, $address, $city, $state, $zip, $customerId])) {
                $_SESSION['customer_name'] = $name;
                $_SESSION['email'] = $email;
                $_SESSION['full_name'] = $name;
                $message = 'Your profile was updated successfully.';
                $messageType = 'success';
            } else {
                $message = 'Unable to update your profile.';
                $messageType = 'error';
            }
        }
    }
}

$customer = $conn->prepare('SELECT * FROM customers WHERE id = ? LIMIT 1');
$customer->execute([$customerId]);
$customerRow = $customer->fetch(PDO::FETCH_ASSOC);
$customerPreferences = get_customer_notification_preferences($conn, $customerId);

$title = 'Customer Profile';
require_once '../includes/header.php';
?>

<div style="max-width: 780px; margin: 32px auto 48px; padding: 0 18px;">
    <h2>Profile</h2>
    <p>Keep your contact information current so we can reach you quickly about your work orders.</p>

    <?php if ($message !== ''): ?>
        <p style="color: <?php echo $messageType === 'success' ? '#166534' : '#b91c1c'; ?>; font-weight:600; margin-bottom: 16px;">
            <?php echo htmlspecialchars($message, ENT_QUOTES, 'UTF-8'); ?>
        </p>
    <?php endif; ?>

    <div style="background:#fff; border:1px solid #e5e7eb; border-radius:10px; padding:20px; box-shadow:0 1px 10px rgba(0,0,0,0.04);">
        <form method="post">
            <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 16px;">
                <div>
                    <label>Full Name</label>
                    <input type="text" name="name" value="<?php echo htmlspecialchars($customerRow['name'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" required>
                </div>
                <div>
                    <label>Email</label>
                    <input type="email" name="email" value="<?php echo htmlspecialchars($customerRow['email'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" required>
                </div>
                <div>
                    <label>Phone</label>
                    <input type="tel" name="phone" value="<?php echo htmlspecialchars($customerRow['phone'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                </div>
                <div>
                    <label>Address</label>
                    <input type="text" name="address" value="<?php echo htmlspecialchars($customerRow['address'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                </div>
                <div>
                    <label>City</label>
                    <input type="text" name="city" value="<?php echo htmlspecialchars($customerRow['city'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                </div>
                <div>
                    <label>State</label>
                    <input type="text" name="state" value="<?php echo htmlspecialchars($customerRow['state'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                </div>
                <div>
                    <label>ZIP</label>
                    <input type="text" name="zip" value="<?php echo htmlspecialchars($customerRow['zip'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                </div>
            </div>

            <div style="margin-top: 20px; display:flex; gap:12px; flex-wrap:wrap;">
                <button type="submit">Save Profile</button>
                <a href="/sps/pages/customer_dashboard.php" style="display:inline-block; background:#6b7280; color:#fff; text-decoration:none; padding:10px 16px; border-radius:6px; font-weight:700;">Back to Dashboard</a>
            </div>
        </form>

        <form method="post" style="margin-top:28px; border-top:1px solid #e5e7eb; padding-top:20px;">
            <input type="hidden" name="save_notifications" value="1">
            <h3 style="margin-top:0; margin-bottom:12px;">Work Order Update Notifications</h3>
            <p style="margin-top:0; color:#475569;">Choose how you want to be notified when a technician updates your work order.</p>

            <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap:14px; margin-bottom:18px;">
                <label style="display:block; font-weight:700; color:#334155;">
                    Email<br>
                    <input type="checkbox" name="email_enabled" value="1" <?php echo !empty($customerPreferences['email_enabled']) ? 'checked' : ''; ?>> Enabled
                </label>
                <label style="display:block; font-weight:700; color:#334155;">
                    WhatsApp<br>
                    <input type="checkbox" name="whatsapp_enabled" value="1" <?php echo !empty($customerPreferences['whatsapp_enabled']) ? 'checked' : ''; ?>> Enabled
                </label>
                <label style="display:block; font-weight:700; color:#334155;">
                    SMS<br>
                    <input type="checkbox" name="sms_enabled" value="1" <?php echo !empty($customerPreferences['sms_enabled']) ? 'checked' : ''; ?>> Enabled
                </label>
            </div>

            <div style="max-width:280px;">
                <label for="preferred_channel" style="display:block; font-weight:700; margin-bottom:6px; color:#334155;">Preferred channel</label>
                <select name="preferred_channel" id="preferred_channel" style="width:100%; padding:10px 12px; border:1px solid #cbd5e1; border-radius:8px; box-sizing:border-box;">
                    <option value="email" <?php echo (($customerPreferences['preferred_channel'] ?? 'email') === 'email') ? 'selected' : ''; ?>>Email</option>
                    <option value="whatsapp" <?php echo (($customerPreferences['preferred_channel'] ?? 'email') === 'whatsapp') ? 'selected' : ''; ?>>WhatsApp</option>
                    <option value="sms" <?php echo (($customerPreferences['preferred_channel'] ?? 'email') === 'sms') ? 'selected' : ''; ?>>SMS</option>
                    <option value="all" <?php echo (($customerPreferences['preferred_channel'] ?? 'email') === 'all') ? 'selected' : ''; ?>>All enabled channels</option>
                </select>
            </div>

            <div style="margin-top:20px;">
                <button type="submit">Save update alerts</button>
            </div>
        </form>
    </div>
</div>

<?php require_once '../includes/footer.php'; ?>
