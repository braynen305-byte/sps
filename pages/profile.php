<?php
session_start();
if (empty($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true || !in_array(strtolower(trim((string)($_SESSION['role'] ?? ''))), ['admin', 'office', 'staff', 'technician'], true)) {
	header('Location: /sps/login.php');
	exit;
}

require_once '../includes/dbh.inc.php';
require_once '../includes/notifications.php';
ensure_notification_tables($conn);

$staffId = (int)($_SESSION['user_id'] ?? 0);
if ($staffId <= 0) {
	http_response_code(403);
	exit('Your staff profile could not be identified. Please sign in again.');
}
if (empty($_SESSION['csrf_token'])) {
	$_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$staffStmt = $conn->prepare('SELECT id, firstname, middlename, lastname, email, role, password, phone_number, whatsapp_number FROM staff WHERE id = ? LIMIT 1');
$staffStmt->execute([$staffId]);
$staff = $staffStmt->fetch(PDO::FETCH_ASSOC);
if (!$staff) {
	http_response_code(404);
	exit('Staff profile not found.');
}

$message = '';
$messageType = '';
$allowedPreferences = ['email', 'sms', 'whatsapp', 'all'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
	if (!hash_equals((string)$_SESSION['csrf_token'], (string)($_POST['csrf_token'] ?? ''))) {
		$message = 'Your session could not be verified. Reload the page and try again.';
		$messageType = 'error';
	} else {
		$action = (string)($_POST['action'] ?? '');
		try {
			if ($action === 'save_profile') {
				$firstName = trim((string)($_POST['firstname'] ?? ''));
				$middleName = trim((string)($_POST['middlename'] ?? ''));
				$lastName = trim((string)($_POST['lastname'] ?? ''));
				$email = strtolower(trim((string)($_POST['email'] ?? '')));
				if ($firstName === '' || $lastName === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
					throw new InvalidArgumentException('Enter your first name, last name, and a valid email address.');
				}
				$emailCheck = $conn->prepare('SELECT id FROM staff WHERE LOWER(email) = LOWER(?) AND id <> ? LIMIT 1');
				$emailCheck->execute([$email, $staffId]);
				if ($emailCheck->fetchColumn()) {
					throw new InvalidArgumentException('That email address is already used by another staff account.');
				}
				$update = $conn->prepare('UPDATE staff SET firstname = ?, middlename = ?, lastname = ?, email = ? WHERE id = ?');
				$update->execute([$firstName, $middleName !== '' ? $middleName : null, $lastName, $email, $staffId]);
				$staff['firstname'] = $firstName;
				$staff['middlename'] = $middleName;
				$staff['lastname'] = $lastName;
				$staff['email'] = $email;
				$_SESSION['email'] = $email;
				$_SESSION['full_name'] = trim($firstName . ' ' . $lastName);
				$message = 'Your profile details were saved.';
				$messageType = 'success';
			} elseif ($action === 'save_notifications') {
				$phone = trim((string)($_POST['phone_number'] ?? ''));
				$whatsappPhone = trim((string)($_POST['whatsapp_number'] ?? ''));
				if ($phone !== '' && normalize_notification_phone($phone) === '') {
					throw new InvalidArgumentException('Enter your SMS phone number in international format, for example +14155552671.');
				}
				if ($whatsappPhone !== '' && normalize_notification_phone($whatsappPhone) === '') {
					throw new InvalidArgumentException('Enter your WhatsApp number in international format, for example +14155552671.');
				}
				$preferred = strtolower(trim((string)($_POST['preferred_channel'] ?? 'email')));
				if (!in_array($preferred, $allowedPreferences, true)) {
					$preferred = 'email';
				}
				$saved = save_staff_notification_preferences($conn, $staffId, [
					'email_enabled' => isset($_POST['email_enabled']) ? 1 : 0,
					'sms_enabled' => isset($_POST['sms_enabled']) ? 1 : 0,
					'whatsapp_enabled' => isset($_POST['whatsapp_enabled']) ? 1 : 0,
					'preferred_channel' => $preferred,
					'phone_number' => $phone,
					'whatsapp_number' => $whatsappPhone,
				]);
				if (!$saved) {
					throw new RuntimeException('Your notification preferences could not be saved.');
				}
				$message = 'Your notification preferences were saved.';
				$messageType = 'success';
			} elseif ($action === 'change_password') {
				$currentPassword = (string)($_POST['current_password'] ?? '');
				$newPassword = (string)($_POST['new_password'] ?? '');
				$confirmPassword = (string)($_POST['confirm_password'] ?? '');
				if (!is_string($staff['password'] ?? null) || !password_verify($currentPassword, (string)$staff['password'])) {
					throw new InvalidArgumentException('Your current password is incorrect.');
				}
				if (strlen($newPassword) < 12) {
					throw new InvalidArgumentException('Use a new password with at least 12 characters.');
				}
				if ($newPassword !== $confirmPassword) {
					throw new InvalidArgumentException('The new password and confirmation do not match.');
				}
				$passwordUpdate = $conn->prepare('UPDATE staff SET password = ? WHERE id = ?');
				$passwordUpdate->execute([password_hash($newPassword, PASSWORD_DEFAULT), $staffId]);
				$staff['password'] = null;
				$message = 'Your password was changed.';
				$messageType = 'success';
			} else {
				throw new InvalidArgumentException('Unknown profile action.');
			}
		} catch (InvalidArgumentException $error) {
			$message = $error->getMessage();
			$messageType = 'error';
		} catch (Throwable $error) {
			error_log('Staff profile update failed: ' . $error->getMessage());
			$message = 'The profile update could not be completed. Please try again.';
			$messageType = 'error';
		}
	}
}

$prefs = get_staff_notification_preferences($conn, $staffId);
$contact = get_staff_contact_record($conn, $staffId);
$prefs['phone_number'] = $prefs['phone_number'] !== '' ? $prefs['phone_number'] : $contact['phone_number'];
$prefs['whatsapp_number'] = $prefs['whatsapp_number'] !== '' ? $prefs['whatsapp_number'] : $contact['whatsapp_number'];
$roleLabel = ucfirst(strtolower(trim((string)($staff['role'] ?? 'staff'))));
$title = 'Staff Profile';
require_once '../includes/header.php';
?>
<style>
	.staff-profile-page { width:min(920px,calc(100% - 36px)); margin:28px auto 48px; color:#0f172a; }
	.staff-profile-title { margin:0 0 6px; font-size:clamp(23px,3vw,30px); }
	.staff-profile-subtitle { margin:0 0 20px; color:#64748b; }
	.staff-profile-notice { margin:0 0 16px; padding:11px 14px; border-radius:8px; font-weight:700; }
	.staff-profile-notice.success { color:#166534; background:#f0fdf4; border:1px solid #bbf7d0; }
	.staff-profile-notice.error { color:#991b1b; background:#fef2f2; border:1px solid #fecaca; }
	.staff-profile-section { margin:0 0 16px; padding:18px; border:1px solid #e2e8f0; border-radius:12px; background:#fff; box-shadow:0 3px 14px rgba(15,23,42,.05); }
	.staff-profile-section h2 { margin:0 0 6px; color:#123d71; font-size:17px; }
	.staff-profile-section > p { margin:0 0 15px; color:#64748b; font-size:13px; line-height:1.45; }
	.staff-profile-grid { display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); gap:12px; }
	.staff-profile-field { min-width:0; }
	.staff-profile-field.full { grid-column:1/-1; }
	.staff-profile-field label { display:block; margin:0 0 5px; color:#334155; font-size:12px; font-weight:700; }
	.staff-profile-field input,.staff-profile-field select { width:100%; min-height:40px; margin:0; padding:9px 11px; border:1px solid #cbd5e1; border-radius:7px; background:#fff; color:#0f172a; font:14px Arial,sans-serif; box-sizing:border-box; }
	.staff-profile-checks { display:flex; flex-wrap:wrap; gap:12px 20px; margin:4px 0 16px; }
	.staff-profile-checks label { display:inline-flex; align-items:center; gap:7px; color:#334155; font-size:13px; font-weight:700; }
	.staff-profile-checks input { width:17px; height:17px; margin:0; accent-color:#1d4ed8; }
	.staff-profile-actions { display:flex; justify-content:flex-end; margin-top:15px; }
	.staff-profile-save { width:auto; min-width:0; margin:0; padding:9px 14px; border:0; border-radius:7px; background:#1d4ed8; color:#fff; font-size:13px; font-weight:700; cursor:pointer; }
	.staff-profile-help { margin-top:10px !important; font-size:12px !important; }
	@media(max-width:600px) { .staff-profile-page { width:100%; margin:18px 0 32px; padding:0 12px; box-sizing:border-box; } .staff-profile-section { padding:14px; } .staff-profile-grid { grid-template-columns:1fr; } .staff-profile-field.full { grid-column:auto; } .staff-profile-actions .staff-profile-save { width:100%; min-height:42px; } }
</style>
<main class="staff-profile-page">
	<h1 class="staff-profile-title">My Profile</h1>
	<p class="staff-profile-subtitle"><?php echo htmlspecialchars($roleLabel, ENT_QUOTES, 'UTF-8'); ?> account settings and notification delivery options.</p>
	<?php if ($message !== ''): ?><div class="staff-profile-notice <?php echo $messageType === 'success' ? 'success' : 'error'; ?>" role="status"><?php echo htmlspecialchars($message, ENT_QUOTES, 'UTF-8'); ?></div><?php endif; ?>

	<form method="post" class="staff-profile-section">
		<input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8'); ?>">
		<input type="hidden" name="action" value="save_profile">
		<h2>Account details</h2>
		<p>Update your name and email address. Your role is managed by the system administrator.</p>
		<div class="staff-profile-grid">
			<div class="staff-profile-field"><label for="firstname">First name</label><input id="firstname" name="firstname" type="text" maxlength="30" required value="<?php echo htmlspecialchars((string)($staff['firstname'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"></div>
			<div class="staff-profile-field"><label for="middlename">Middle name</label><input id="middlename" name="middlename" type="text" maxlength="30" value="<?php echo htmlspecialchars((string)($staff['middlename'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"></div>
			<div class="staff-profile-field"><label for="lastname">Last name</label><input id="lastname" name="lastname" type="text" maxlength="30" required value="<?php echo htmlspecialchars((string)($staff['lastname'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"></div>
			<div class="staff-profile-field"><label for="email">Email</label><input id="email" name="email" type="email" maxlength="255" required value="<?php echo htmlspecialchars((string)($staff['email'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"></div>
			<div class="staff-profile-field"><label>Role</label><input type="text" readonly value="<?php echo htmlspecialchars($roleLabel, ENT_QUOTES, 'UTF-8'); ?>"></div>
		</div>
		<div class="staff-profile-actions"><button class="staff-profile-save" type="submit">Save account details</button></div>
	</form>

	<form method="post" class="staff-profile-section">
		<input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8'); ?>">
		<input type="hidden" name="action" value="save_notifications">
		<h2>Notification preferences</h2>
		<p>Select the external channels you want to receive. If SMS or WhatsApp is selected as preferred and cannot be sent, enabled email is used as a fallback. The in-portal notification bell remains available independently.</p>
		<div class="staff-profile-checks">
			<label><input type="checkbox" name="email_enabled" value="1" <?php echo !empty($prefs['email_enabled']) ? 'checked' : ''; ?>> Email</label>
			<label><input type="checkbox" name="sms_enabled" value="1" <?php echo !empty($prefs['sms_enabled']) ? 'checked' : ''; ?>> SMS</label>
			<label><input type="checkbox" name="whatsapp_enabled" value="1" <?php echo !empty($prefs['whatsapp_enabled']) ? 'checked' : ''; ?>> WhatsApp</label>
		</div>
		<div class="staff-profile-grid">
			<div class="staff-profile-field"><label for="phone_number">SMS number (international format)</label><input id="phone_number" name="phone_number" type="tel" autocomplete="tel" placeholder="+14155552671" value="<?php echo htmlspecialchars((string)$prefs['phone_number'], ENT_QUOTES, 'UTF-8'); ?>"></div>
			<div class="staff-profile-field"><label for="whatsapp_number">WhatsApp number (international format)</label><input id="whatsapp_number" name="whatsapp_number" type="tel" autocomplete="tel" placeholder="+14155552671" value="<?php echo htmlspecialchars((string)$prefs['whatsapp_number'], ENT_QUOTES, 'UTF-8'); ?>"></div>
			<div class="staff-profile-field"><label for="preferred_channel">Preferred channel</label><select id="preferred_channel" name="preferred_channel">
				<option value="email" <?php echo $prefs['preferred_channel'] === 'email' ? 'selected' : ''; ?>>Email (default)</option>
				<option value="sms" <?php echo $prefs['preferred_channel'] === 'sms' ? 'selected' : ''; ?>>SMS, then email fallback</option>
				<option value="whatsapp" <?php echo $prefs['preferred_channel'] === 'whatsapp' ? 'selected' : ''; ?>>WhatsApp, then email fallback</option>
				<option value="all" <?php echo $prefs['preferred_channel'] === 'all' ? 'selected' : ''; ?>>All enabled channels</option>
			</select></div>
		</div>
		<p class="staff-profile-help">Phone numbers must use E.164 format, e.g. +14155552671. SMTP and Twilio providers must be configured by the portal administrator before those channels can deliver.</p>
		<div class="staff-profile-actions"><button class="staff-profile-save" type="submit">Save notification preferences</button></div>
	</form>

	<form method="post" class="staff-profile-section">
		<input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8'); ?>">
		<input type="hidden" name="action" value="change_password">
		<h2>Change password</h2>
		<p>Choose a new password with at least 12 characters. Your current password is required.</p>
		<div class="staff-profile-grid">
			<div class="staff-profile-field full"><label for="current_password">Current password</label><input id="current_password" name="current_password" type="password" autocomplete="current-password" required></div>
			<div class="staff-profile-field"><label for="new_password">New password</label><input id="new_password" name="new_password" type="password" autocomplete="new-password" minlength="12" required></div>
			<div class="staff-profile-field"><label for="confirm_password">Confirm new password</label><input id="confirm_password" name="confirm_password" type="password" autocomplete="new-password" minlength="12" required></div>
		</div>
		<div class="staff-profile-actions"><button class="staff-profile-save" type="submit">Change password</button></div>
	</form>
</main>
<?php require_once '../includes/footer.php'; ?>
