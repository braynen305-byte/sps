<?php
session_start();
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true || strtolower($_SESSION['role'] ?? '') !== 'customer') {
    header('Location: /sps/customer_login.php');
    exit;
}

require_once '../includes/dbh.inc.php';
require_once '../includes/notifications.php';
require_once '../includes/customer_assets.inc.php';

$customerId = (int)($_SESSION['customer_id'] ?? 0);
ensure_customer_asset_schema($conn);
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$message = '';
$messageType = 'success';
if (($_GET['asset_saved'] ?? '') === 'added') {
    $message = 'Vessel or asset added to your profile.';
} elseif (($_GET['asset_saved'] ?? '') === 'updated') {
    $message = 'Vessel or asset updated.';
} elseif (($_GET['asset_saved'] ?? '') === 'deleted') {
    $message = 'Vessel or asset removed from your profile.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['csrf_token'], (string)($_POST['csrf_token'] ?? ''))) {
        $message = 'Your session could not be verified. Please reload the page and try again.';
        $messageType = 'error';
    } elseif (isset($_POST['asset_action'])) {
        $assetAction = (string)$_POST['asset_action'];
        $assetId = (int)($_POST['asset_id'] ?? 0);
        if ($assetAction === 'delete' && $assetId > 0) {
            $assetCheck = $conn->prepare('SELECT id FROM customer_assets WHERE id = ? AND customer_id = ? LIMIT 1');
            $assetCheck->execute([$assetId, $customerId]);
            if (!$assetCheck->fetchColumn()) {
                $message = 'That vessel or asset was not found in your profile.';
                $messageType = 'error';
            } else {
                $usageCheck = $conn->prepare('SELECT
                    (SELECT COUNT(*) FROM customer_service_requests WHERE asset_id = ?) +
                    (SELECT COUNT(*) FROM workorders WHERE asset_id = ?)');
                $usageCheck->execute([$assetId, $assetId]);
                if ((int)$usageCheck->fetchColumn() > 0) {
                    $message = 'This asset is linked to a service request or work order and cannot be deleted.';
                    $messageType = 'error';
                } else {
                    $deleteAsset = $conn->prepare('DELETE FROM customer_assets WHERE id = ? AND customer_id = ?');
                    $deleteAsset->execute([$assetId, $customerId]);
                    header('Location: /sps/pages/customer_profile.php?asset_saved=deleted');
                    exit;
                }
            }
        } elseif ($assetAction === 'save') {
            $assetName = trim((string)($_POST['asset_name'] ?? ''));
            $assetType = strtolower(trim((string)($_POST['asset_type'] ?? '')));
            if ($assetType !== 'boat') {
                $assetName = '';
            }
            $assetMake = trim((string)($_POST['make'] ?? ''));
            $assetModel = trim((string)($_POST['model'] ?? ''));
            $assetYear = trim((string)($_POST['model_year'] ?? ''));
            $assetSerial = strtoupper(trim((string)($_POST['serial_number'] ?? '')));
            $assetHours = trim((string)($_POST['hours'] ?? ''));
            $boatLengthInput = trim((string)($_POST['boat_length'] ?? ''));
            $engineCountInput = trim((string)($_POST['engine_count'] ?? ''));
            $engineDetails = trim((string)($_POST['engine_details'] ?? ''));
            $assetNotes = trim((string)($_POST['notes'] ?? ''));
            $validYear = $assetYear === '' || (ctype_digit($assetYear) && (int)$assetYear >= 1900 && (int)$assetYear <= ((int)date('Y') + 1));
            $validHours = $assetHours === '' || (is_numeric($assetHours) && (float)$assetHours >= 0);
            $allowedAssetTypes = ['boat', 'jetski', 'atv', 'motorcycle'];
            $validEngineCount = $assetType !== 'boat' || (ctype_digit($engineCountInput) && (int)$engineCountInput >= 1 && (int)$engineCountInput <= 20);
            $validBoatLength = $assetType !== 'boat' || (is_numeric($boatLengthInput) && (float)$boatLengthInput > 0 && (float)$boatLengthInput <= 300);

            $duplicateSerial = false;
            if ($assetSerial !== '') {
                $duplicateSerialStmt = $conn->prepare('SELECT id FROM customer_assets WHERE UPPER(TRIM(serial_number)) = ? AND (? = 0 OR id <> ?) LIMIT 1');
                $duplicateSerialStmt->execute([$assetSerial, $assetId, $assetId]);
                $duplicateSerial = (bool)$duplicateSerialStmt->fetchColumn();
            }

            if ($duplicateSerial) {
                $message = 'That VIN / HIN / serial number is already saved on another vessel or asset.';
                $messageType = 'error';
            } elseif (!in_array($assetType, $allowedAssetTypes, true)) {
                $message = 'Select whether this is a boat, asset, jet ski, ATV, or motorcycle.';
                $messageType = 'error';
            } elseif ($assetName === '' && $assetMake === '' && $assetModel === '' && $assetSerial === '' && !($assetType === 'boat' && $boatLengthInput !== '')) {
                $message = 'A vessel name is optional, but enter at least a make, model, or VIN/HIN/serial number.';
                $messageType = 'error';
            } elseif (!$validEngineCount) {
                $message = 'For a boat, enter the number of engines (1–20).';
                $messageType = 'error';
            } elseif (!$validBoatLength) {
                $message = 'For a boat, enter its length in feet (greater than 0 and no more than 300).';
                $messageType = 'error';
            } elseif (!$validYear || !$validHours) {
                $message = 'Enter a valid model year and non-negative hours value.';
                $messageType = 'error';
            } else {
                try {
                    if ($assetId > 0) {
                        $saveAsset = $conn->prepare('UPDATE customer_assets SET asset_name = ?, asset_type = ?, make = ?, model = ?, model_year = ?, serial_number = ?, hours = ?, boat_length = ?, engine_count = ?, engine_details = ?, notes = ?, updated_at = NOW() WHERE id = ? AND customer_id = ?');
                        $saveAsset->execute([$assetName ?: null, $assetType, $assetMake ?: null, $assetModel ?: null, $assetYear !== '' ? (int)$assetYear : null, $assetSerial ?: null, $assetHours !== '' ? (float)$assetHours : null, $assetType === 'boat' ? (float)$boatLengthInput : null, $assetType === 'boat' ? (int)$engineCountInput : null, $assetType === 'boat' && $engineDetails !== '' ? $engineDetails : null, $assetNotes ?: null, $assetId, $customerId]);
                        header('Location: /sps/pages/customer_profile.php?asset_saved=updated');
                        exit;
                    }

                    $saveAsset = $conn->prepare('INSERT INTO customer_assets (customer_id, asset_name, asset_type, make, model, model_year, serial_number, hours, boat_length, engine_count, engine_details, notes) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
                    $saveAsset->execute([$customerId, $assetName ?: null, $assetType, $assetMake ?: null, $assetModel ?: null, $assetYear !== '' ? (int)$assetYear : null, $assetSerial ?: null, $assetHours !== '' ? (float)$assetHours : null, $assetType === 'boat' ? (float)$boatLengthInput : null, $assetType === 'boat' ? (int)$engineCountInput : null, $assetType === 'boat' && $engineDetails !== '' ? $engineDetails : null, $assetNotes ?: null]);
                    header('Location: /sps/pages/customer_profile.php?asset_saved=added');
                    exit;
                } catch (PDOException $e) {
                    if ($e->getCode() === '23000') {
                        $message = 'That VIN / HIN / serial number is already saved on another vessel or asset.';
                        $messageType = 'error';
                    } else {
                        throw $e;
                    }
                }
            }
        }
    } elseif (isset($_POST['save_notifications'])) {
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
$assetsStmt = $conn->prepare('SELECT * FROM customer_assets WHERE customer_id = ? ORDER BY asset_name, id');
$assetsStmt->execute([$customerId]);
$customerAssets = $assetsStmt->fetchAll(PDO::FETCH_ASSOC);

$title = 'Customer Profile';
require_once '../includes/header.php';
?>

<style>
    .customer-profile-page {
        width: calc(100% - 36px);
        max-width: 1100px;
        margin: 32px auto 48px;
        font-family: Arial, sans-serif;
        color: #0f172a;
    }
    .customer-profile-page .profile-card {
        width: 100%;
        padding: 24px;
        box-sizing: border-box;
        background: #fff;
        border: 1px solid #e5e7eb;
        border-radius: 10px;
        box-shadow: 0 1px 10px rgba(0,0,0,0.04);
    }
    .customer-profile-page .profile-section {
        margin:0 0 18px;
        padding:18px;
        border:1px solid #dbe3ec;
        border-radius:10px;
        background:#f8fafc;
    }
    .customer-profile-page form.profile-section {
        display:block;
        width:100%;
        max-width:none;
        margin:0 0 18px;
        box-sizing:border-box;
    }
    .customer-profile-page .profile-section:last-child { margin-bottom:0; }
    .customer-profile-page .profile-section h3 {
        margin:0 0 8px;
        padding:0 0 10px;
        border-bottom:1px solid #e2e8f0;
        color:#1e3a5f;
        font-size:18px;
    }
    .customer-profile-page .profile-section-description { margin:0 0 16px; color:#475569; }
    .customer-profile-page .profile-section-actions { display:flex; gap:12px; flex-wrap:wrap; margin-top:14px; }
    .customer-profile-page .asset-list { display:grid; gap:10px; margin:0 0 16px; }
    .customer-profile-page .asset-card { display:flex; justify-content:space-between; align-items:center; gap:12px; flex-wrap:wrap; padding:12px; border:1px solid #e2e8f0; border-radius:8px; background:#fff; }
    .customer-profile-page .asset-edit-form,
    .customer-profile-page .asset-add-form { padding:14px; border:1px solid #dbe3ec; border-radius:8px; background:#fff; }
    .customer-profile-page .asset-edit-form { margin-top:10px; }
    .customer-profile-page .asset-fields { display:grid; grid-template-columns:repeat(auto-fit,minmax(170px,1fr)); gap:10px; }
    .customer-profile-page .boat-details-fields { grid-column:1/-1; grid-template-columns:repeat(auto-fit,minmax(170px,1fr)); gap:10px; }
    .customer-profile-page .boat-details-fields[style*="contents"] { display:grid !important; }
    .customer-profile-page .asset-section-add { margin-top:12px; padding-top:12px; border-top:1px solid #e2e8f0; }
    .customer-profile-page .notification-options { display:grid; grid-template-columns:repeat(auto-fit,minmax(160px,1fr)); gap:12px; margin-bottom:16px; }
    .customer-profile-page input:not([type="checkbox"]),
    .customer-profile-page select,
    .customer-profile-page textarea {
        width: 100%;
        max-width: 100%;
        min-height: 40px;
        margin: 5px 0 10px;
        padding: 10px 12px;
        box-sizing: border-box;
        border: 1px solid #cbd5e1;
        border-radius: 8px;
        background: #fff;
        color: #0f172a;
        font: 14px Arial, sans-serif;
    }
    .customer-profile-page textarea { min-height: 64px; resize: vertical; }
    .customer-profile-page label { display:block; font-weight:700; color:#334155; }
    .customer-profile-page input[type="checkbox"] { width:auto; min-height:0; margin:0 5px 0 0; }
    .customer-profile-page .profile-save-button { display:inline-block; width:auto; max-width:300px; flex:0 1 300px; margin:0; }
    @media (max-width: 600px) {
        .customer-profile-page { width:calc(100% - 24px); margin-top:20px; }
        .customer-profile-page .profile-card { padding:16px; }
        .customer-profile-page .profile-section { padding:14px; }
    }
</style>

<div class="customer-profile-page">
    <h2>Profile</h2>
    <p>Keep your contact information current so we can reach you quickly about your work orders.</p>

    <?php if ($message !== ''): ?>
        <p style="color: <?php echo $messageType === 'success' ? '#166534' : '#b91c1c'; ?>; font-weight:600; margin-bottom: 16px;">
            <?php echo htmlspecialchars($message, ENT_QUOTES, 'UTF-8'); ?>
        </p>
    <?php endif; ?>

    <div class="profile-card">
        <section class="profile-section">
        <h3>Contact Profile</h3>
        <p class="profile-section-description">Update the contact information we use for your service requests and work orders.</p>
        <form method="post">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8'); ?>">
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

            <div class="profile-section-actions">
                <button type="submit" class="profile-save-button">Save Profile</button>
                <a href="/sps/pages/customer_dashboard.php" style="display:inline-block; background:#6b7280; color:#fff; text-decoration:none; padding:10px 16px; border-radius:6px; font-weight:700;">Back to Dashboard</a>
            </div>
        </form>
        </section>

        <section class="profile-section">
            <h3>My Vessels &amp; Assets</h3>
            <p class="profile-section-description">Save your vessels or equipment here, then select one when submitting a service request.</p>
            <?php if (empty($customerAssets)): ?>
                <p style="color:#64748b;">You have not added any vessels or assets yet.</p>
            <?php else: ?>
                <div class="asset-list">
                    <?php foreach ($customerAssets as $asset): ?>
                        <div class="asset-card">
                            <div>
                                <strong><?php echo htmlspecialchars(customer_asset_label($asset), ENT_QUOTES, 'UTF-8'); ?></strong>
                                <?php if (!empty($asset['asset_type'])): ?><span style="color:#64748b;"> · <?php echo htmlspecialchars(ucfirst((string)$asset['asset_type']), ENT_QUOTES, 'UTF-8'); ?></span><?php endif; ?>
                                <?php if (!empty($asset['serial_number'])): ?><div style="display:inline-block; margin-top:6px; padding:4px 8px; border:1px solid #bfdbfe; border-radius:6px; background:#eff6ff; color:#1e3a8a; font-size:13px; font-weight:700;">VIN / HIN / Serial #: <?php echo htmlspecialchars($asset['serial_number'], ENT_QUOTES, 'UTF-8'); ?></div><?php endif; ?>
                                <?php if (($asset['asset_type'] ?? '') === 'boat'): ?>
                                    <?php if (!empty($asset['boat_length'])): ?><div style="font-size:13px; color:#475569; margin-top:3px;">Length: <?php echo htmlspecialchars((string)$asset['boat_length'], ENT_QUOTES, 'UTF-8'); ?> ft</div><?php endif; ?>
                                    <?php if (!empty($asset['engine_count'])): ?><div style="font-size:13px; color:#475569; margin-top:3px;">Engines: <?php echo (int)$asset['engine_count']; ?><?php echo !empty($asset['engine_details']) ? ' · ' . htmlspecialchars($asset['engine_details'], ENT_QUOTES, 'UTF-8') : ''; ?></div><?php endif; ?>
                                <?php endif; ?>
                                <?php if ($asset['hours'] !== null && $asset['hours'] !== ''): ?><div style="font-size:13px; color:#475569; margin-top:3px;">Hours: <?php echo htmlspecialchars((string)$asset['hours'], ENT_QUOTES, 'UTF-8'); ?></div><?php endif; ?>
                                <?php if (!empty($asset['notes'])): ?><div style="font-size:13px; color:#475569; margin-top:3px;"><?php echo htmlspecialchars($asset['notes'], ENT_QUOTES, 'UTF-8'); ?></div><?php endif; ?>
                            </div>
                            <div style="display:flex; gap:8px; align-items:center;">
                                <button type="button" class="asset-edit-toggle" data-asset-id="<?php echo (int)$asset['id']; ?>" style="width:auto; margin:0; padding:7px 10px;">Edit</button>
                                <form method="post" style="margin:0;" onsubmit="return confirm('Remove this vessel or asset from your profile?');">
                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8'); ?>">
                                    <input type="hidden" name="asset_action" value="delete">
                                    <input type="hidden" name="asset_id" value="<?php echo (int)$asset['id']; ?>">
                                    <button type="submit" style="width:auto; margin:0; padding:7px 10px; background:#b91c1c;">Delete</button>
                                </form>
                            </div>
                            <form method="post" id="asset-edit-<?php echo (int)$asset['id']; ?>" class="asset-edit-form" style="display:none; width:100%;">
                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8'); ?>">
                                <input type="hidden" name="asset_action" value="save">
                                <input type="hidden" name="asset_id" value="<?php echo (int)$asset['id']; ?>">
                                <div class="asset-fields">
                                    <label class="boat-name-field" style="display:<?php echo strtolower((string)($asset['asset_type'] ?? '')) === 'boat' ? 'block' : 'none'; ?>;">Vessel name (optional)<input type="text" name="asset_name" value="<?php echo htmlspecialchars($asset['asset_name'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" placeholder="For example: Miss Behavior"></label>
                                    <label>Asset Type
                                        <select name="asset_type" class="asset-type-select" required>
                                            <?php foreach (['boat' => 'Boat', 'jetski' => 'Jet ski', 'atv' => 'ATV', 'motorcycle' => 'Motorcycle'] as $typeValue => $typeLabel): ?>
                                                <option value="<?php echo $typeValue; ?>" <?php echo strtolower((string)($asset['asset_type'] ?? '')) === $typeValue ? 'selected' : ''; ?>><?php echo $typeLabel; ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </label>
                                    <label>Make<input type="text" name="make" value="<?php echo htmlspecialchars($asset['make'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"></label>
                                    <label>Model<input type="text" name="model" value="<?php echo htmlspecialchars($asset['model'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"></label>
                                    <label>Year<input type="number" name="model_year" min="1900" max="<?php echo (int)date('Y') + 1; ?>" value="<?php echo htmlspecialchars((string)($asset['model_year'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"></label>
                                    <label>VIN / HIN / Serial #<input type="text" name="serial_number" value="<?php echo htmlspecialchars($asset['serial_number'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"></label>
                                    <label>Hours<input type="number" name="hours" min="0" step="0.1" value="<?php echo htmlspecialchars((string)($asset['hours'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"></label>
                                    <div class="boat-details-fields" style="display:<?php echo strtolower((string)($asset['asset_type'] ?? '')) === 'boat' ? 'contents' : 'none'; ?>;">
                                        <label>Boat Length (ft)<input type="number" name="boat_length" min="0.1" max="300" step="0.1" value="<?php echo htmlspecialchars((string)($asset['boat_length'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"></label>
                                        <label>Number of Engines<input type="number" name="engine_count" min="1" max="20" value="<?php echo htmlspecialchars((string)($asset['engine_count'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"></label>
                                        <label style="grid-column:1/-1;">Engine Information (optional)<textarea name="engine_details" rows="2" style="width:100%; box-sizing:border-box;"><?php echo htmlspecialchars($asset['engine_details'] ?? '', ENT_QUOTES, 'UTF-8'); ?></textarea></label>
                                    </div>
                                    <label style="grid-column:1/-1;">Notes<textarea name="notes" rows="2" style="width:100%; box-sizing:border-box;"><?php echo htmlspecialchars($asset['notes'] ?? '', ENT_QUOTES, 'UTF-8'); ?></textarea></label>
                                </div>
                                <button type="submit" class="profile-save-button" style="margin:10px 0 0; padding:8px 12px;">Save Asset</button>
                            </form>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <details class="asset-section-add">
                <summary style="cursor:pointer; color:#1d4ed8; font-weight:700;">Add a vessel or asset</summary>
                <form method="post" class="asset-add-form">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8'); ?>">
                    <input type="hidden" name="asset_action" value="save">
                    <div class="asset-fields">
                        <label class="boat-name-field" style="display:none;">Vessel name (optional)<input type="text" name="asset_name" placeholder="For example: Miss Behaviour"></label>
                        <label>Asset Type
                            <select name="asset_type" class="asset-type-select" required>
                                <option value="" selected disabled>Select asset type</option>
                                <option value="boat">Boat</option>
                                <option value="jetski">Jet ski</option>
                                <option value="atv">ATV</option>
                                <option value="motorcycle">Motorcycle</option>
                            </select>
                        </label>
                        <label>Make<input type="text" name="make"></label>
                        <label>Model<input type="text" name="model"></label>
                        <label>Year<input type="number" name="model_year" min="1900" max="<?php echo (int)date('Y') + 1; ?>"></label>
                        <label>VIN / HIN / Serial #<input type="text" name="serial_number"></label>
                        <label>Hours<input type="number" name="hours" min="0" step="0.1"></label>
                        <div class="boat-details-fields" style="display:none;">
                            <label>Boat Length (ft)<input type="number" name="boat_length" min="0.1" max="300" step="0.1"></label>
                            <label>Number of Engines<input type="number" name="engine_count" min="1" max="20"></label>
                            <label style="grid-column:1/-1;">Engine Information (optional)<textarea name="engine_details" rows="2" style="width:100%; box-sizing:border-box;"></textarea></label>
                        </div>
                        <label style="grid-column:1/-1;">Notes<textarea name="notes" rows="2" style="width:100%; box-sizing:border-box;"></textarea></label>
                    </div>
                    <button type="submit" class="profile-save-button" style="margin:12px 0 0; padding:9px 14px;">Save Vessel / Asset</button>
                </form>
            </details>
        </section>

        </section>

        <form method="post" class="profile-section">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8'); ?>">
            <input type="hidden" name="save_notifications" value="1">
            <h3>Work Order Update Notifications</h3>
            <p class="profile-section-description">Choose how you want to be notified about work-order updates and service-request decisions.</p>
            <p class="profile-section-description" style="font-size:12px;">Email delivery depends on the portal mail-server configuration. SMS and WhatsApp are not connected yet, so selecting them will not send messages until a delivery provider is configured.</p>

            <div class="notification-options">
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

            <div style="max-width:360px;">
                <label for="preferred_channel" style="display:block; font-weight:700; margin-bottom:6px; color:#334155;">Preferred channel</label>
                <select name="preferred_channel" id="preferred_channel" style="width:100%; padding:10px 12px; border:1px solid #cbd5e1; border-radius:8px; box-sizing:border-box;">
                    <option value="email" <?php echo (($customerPreferences['preferred_channel'] ?? 'email') === 'email') ? 'selected' : ''; ?>>Email</option>
                    <option value="whatsapp" <?php echo (($customerPreferences['preferred_channel'] ?? 'email') === 'whatsapp') ? 'selected' : ''; ?>>WhatsApp</option>
                    <option value="sms" <?php echo (($customerPreferences['preferred_channel'] ?? 'email') === 'sms') ? 'selected' : ''; ?>>SMS</option>
                    <option value="all" <?php echo (($customerPreferences['preferred_channel'] ?? 'email') === 'all') ? 'selected' : ''; ?>>All enabled channels</option>
                </select>
            </div>

            <div class="profile-section-actions">
                <button type="submit" class="profile-save-button">Save update alerts</button>
            </div>
        </form>
    </div>
</div>
<script>
document.querySelectorAll('.asset-edit-toggle').forEach(function (button) {
    button.addEventListener('click', function () {
        const form = document.getElementById('asset-edit-' + button.dataset.assetId);
        if (!form) return;
        const isOpen = form.style.display !== 'none';
        form.style.display = isOpen ? 'none' : 'block';
        button.textContent = isOpen ? 'Edit' : 'Cancel';
    });
});
document.querySelectorAll('.asset-edit-form, details form').forEach(function (form) {
    const typeSelect = form.querySelector('.asset-type-select');
    const boatDetailsFields = form.querySelector('.boat-details-fields');
    const boatNameField = form.querySelector('.boat-name-field');
    const assetNameInput = form.querySelector('[name="asset_name"]');
    const engineCount = form.querySelector('[name="engine_count"]');
    const boatLength = form.querySelector('[name="boat_length"]');
    if (!typeSelect || !boatDetailsFields) return;
    function updateBoatFields() {
        const isBoat = typeSelect.value === 'boat';
        boatDetailsFields.style.display = isBoat ? 'contents' : 'none';
        if (boatNameField) boatNameField.style.display = isBoat ? 'block' : 'none';
        if (!isBoat && assetNameInput) assetNameInput.value = '';
        if (engineCount) {
            engineCount.required = isBoat;
            if (!isBoat) engineCount.value = '';
        }
        if (boatLength) {
            boatLength.required = isBoat;
            if (!isBoat) boatLength.value = '';
        }
        if (!isBoat) {
            const engineInfo = form.querySelector('[name="engine_details"]');
            if (engineInfo) engineInfo.value = '';
        }
    }
    typeSelect.addEventListener('change', updateBoatFields);
    updateBoatFields();
});
</script>

<?php require_once '../includes/footer.php'; ?>
