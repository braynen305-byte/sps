<?php
session_start();
$currentRole = strtolower($_SESSION['role'] ?? '');
$isStaffView = in_array($currentRole, ['admin', 'office'], true);
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true || (!$isStaffView && $currentRole !== 'customer')) {
    header('Location: /sps/login.php');
    exit;
}

require_once '../includes/dbh.inc.php';

$customerId = (int)($_SESSION['customer_id'] ?? 0);
$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
if (!$id) {
    header('Location: /sps/pages/customer_dashboard.php');
    exit;
}

if ($isStaffView) {
    $stmt = $conn->prepare('SELECT r.*, w.id AS linked_workorder_id, w.order_number AS linked_workorder_number, c.name AS customer_name, c.email AS customer_email FROM customer_service_requests r LEFT JOIN workorders w ON w.id = r.approved_workorder_id LEFT JOIN customers c ON c.id = r.customer_id WHERE r.id = ? LIMIT 1');
    $stmt->execute([$id]);
} else {
    $stmt = $conn->prepare('SELECT r.*, w.id AS linked_workorder_id, w.order_number AS linked_workorder_number FROM customer_service_requests r LEFT JOIN workorders w ON w.id = r.approved_workorder_id WHERE r.id = ? AND r.customer_id = ? LIMIT 1');
    $stmt->execute([$id, $customerId]);
}
$request = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$request) {
    echo 'Service request not found.';
    require_once '../includes/footer.php';
    exit;
}

$status = strtolower(trim((string)($request['status'] ?? 'Pending')));
$isEditable = ($status === 'pending') && !$isStaffView;
$message = '';
$messageType = 'error';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $isEditable) {
    // re-check status to avoid a race with staff approving/rejecting mid-edit
    $recheck = $conn->prepare('SELECT status FROM customer_service_requests WHERE id = ? AND customer_id = ? LIMIT 1');
    $recheck->execute([$id, $customerId]);
    $recheckRow = $recheck->fetch(PDO::FETCH_ASSOC);

    if (!$recheckRow || strtolower(trim((string)($recheckRow['status'] ?? ''))) !== 'pending') {
        $message = 'This request has already been reviewed and can no longer be edited.';
        $isEditable = false;
    } else {
        $serviceType = trim((string)($_POST['service_type'] ?? ''));
        $location = trim((string)($_POST['location'] ?? ''));
        $preferredDate = trim((string)($_POST['preferred_date'] ?? ''));
        $customerPhone = trim((string)($_POST['customer_phone'] ?? ''));
        $urgency = trim((string)($_POST['urgency'] ?? 'Normal'));
        $equipmentDetails = trim((string)($_POST['equipment_details'] ?? ''));
        $problemSummary = trim((string)($_POST['problem_summary'] ?? ''));
        $description = trim((string)($_POST['description'] ?? ''));
        $specialInstructions = trim((string)($_POST['special_instructions'] ?? ''));

        if ($serviceType === '' || $location === '' || $problemSummary === '' || $description === '') {
            $message = 'Please provide the service type, service location, brief problem summary, and detailed description of the issue.';
        } else {
            $update = $conn->prepare('UPDATE customer_service_requests SET
                service_type = ?, location = ?, preferred_date = ?, customer_phone = ?, urgency = ?,
                equipment_details = ?, problem_summary = ?, description = ?, special_instructions = ?, updated_at = NOW()
                WHERE id = ? AND customer_id = ? AND status = ?');
            $success = $update->execute([
                $serviceType,
                $location,
                $preferredDate !== '' ? $preferredDate : null,
                $customerPhone !== '' ? $customerPhone : null,
                $urgency !== '' ? $urgency : 'Normal',
                $equipmentDetails !== '' ? $equipmentDetails : null,
                $problemSummary,
                $description,
                $specialInstructions !== '' ? $specialInstructions : null,
                $id,
                $customerId,
                'Pending',
            ]);

            if ($success) {
                header('Location: /sps/pages/view_service_request.php?id=' . $id . '&updated=1');
                exit;
            }

            $message = 'Unable to save your changes right now. Please try again.';
        }
    }

    // reload latest data after a failed/blocked update attempt
    $stmt->execute([$id, $customerId]);
    $request = $stmt->fetch(PDO::FETCH_ASSOC);
    $status = strtolower(trim((string)($request['status'] ?? 'Pending')));
}

$requestNumber = trim((string)($request['request_number'] ?? ''));
if ($requestNumber === '') {
    $requestNumber = 'SR-' . str_pad((string)(int)$request['id'], 5, '0', STR_PAD_LEFT);
}

$statusColor = '#1d4ed8';
if ($status === 'accepted') { $statusColor = '#166534'; }
elseif ($status === 'rejected') { $statusColor = '#991b1b'; }
elseif ($status === 'pending') { $statusColor = '#7c3aed'; }

$title = 'Service Request ' . $requestNumber;
require_once '../includes/header.php';
?>

<div style="max-width: 760px; margin: 40px auto 48px; padding: 0 18px;">
    <p><a href="<?php echo $isStaffView ? '/sps/pages/manage_service_requests.php' : '/sps/pages/customer_dashboard.php'; ?>" style="color:#007BFF; text-decoration:none;">← Back to <?php echo $isStaffView ? 'Service Requests' : 'Dashboard'; ?></a></p>

    <div style="background:#fff; border:1px solid #e5e7eb; border-radius:12px; box-shadow:0 1px 12px rgba(0,0,0,0.05); padding:24px;">
        <div style="display:flex; justify-content:space-between; align-items:center; gap:10px; flex-wrap:wrap; margin-bottom:8px;">
            <h2 style="margin:0;">Service Request <?php echo htmlspecialchars($requestNumber, ENT_QUOTES, 'UTF-8'); ?></h2>
            <span style="display:inline-block; padding:4px 10px; border-radius:999px; background:rgba(59,130,246,0.12); color:<?php echo $statusColor; ?>; font-weight:700; font-size:12px;">
                <?php echo htmlspecialchars($request['status'] ?? 'Pending', ENT_QUOTES, 'UTF-8'); ?>
            </span>
        </div>

        <?php if ($isStaffView): ?>
            <p style="margin:0 0 16px; color:#475569;">Customer: <strong><?php echo htmlspecialchars($request['customer_name'] ?? 'Unknown', ENT_QUOTES, 'UTF-8'); ?></strong> &middot; <?php echo htmlspecialchars($request['customer_email'] ?? '', ENT_QUOTES, 'UTF-8'); ?></p>
        <?php endif; ?>

        <?php if (!empty($request['linked_workorder_id'])): ?>
            <p style="margin:0 0 16px;"><a href="/sps/pages/view_workorder.php?id=<?php echo (int)$request['linked_workorder_id']; ?>" style="color:#007BFF; text-decoration:none; font-weight:700;">View resulting work order #<?php echo htmlspecialchars(!empty($request['linked_workorder_number']) ? $request['linked_workorder_number'] : 'WO' . str_pad((string)(int)$request['linked_workorder_id'], 4, '0', STR_PAD_LEFT), ENT_QUOTES, 'UTF-8'); ?> →</a></p>
        <?php elseif ($status === 'rejected'): ?>
            <p style="margin:0 0 16px; color:#991b1b; font-weight:600;">This request was reviewed and rejected. It can no longer be edited.</p>
        <?php elseif ($isEditable): ?>
            <p style="margin:0 0 16px; color:#475569;">This request is still pending review, so you can update the details below before our office team reviews it.</p>
        <?php endif; ?>

        <?php if (isset($_GET['updated'])): ?>
            <div style="background:#ecfdf5; color:#166534; border:1px solid #bbf7d0; border-radius:8px; padding:12px 14px; margin-bottom:18px; font-weight:700;">
                Your changes were saved.
            </div>
        <?php endif; ?>

        <?php if ($message !== ''): ?>
            <div style="background:#fee2e2; color:#991b1b; border:1px solid #fecaca; border-radius:8px; padding:12px 14px; margin-bottom:18px; font-weight:700;">
                <?php echo htmlspecialchars($message, ENT_QUOTES, 'UTF-8'); ?>
            </div>
        <?php endif; ?>

        <?php if ($isEditable): ?>
        <form method="post" action="/sps/pages/view_service_request.php?id=<?php echo (int)$id; ?>">
            <input type="hidden" name="id" value="<?php echo (int)$id; ?>">
            <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap:16px;">
                <div>
                    <label for="service_type" style="display:block; font-weight:700; margin-bottom:6px; color:#334155;">Service Type</label>
                    <select name="service_type" id="service_type" required style="width:100%; padding:10px 12px; border:1px solid #cbd5e1; border-radius:8px; box-sizing:border-box;">
                        <?php foreach (['Repair','Inspection','Maintenance','Installation','Service Call','Other'] as $opt): ?>
                            <option value="<?php echo htmlspecialchars($opt, ENT_QUOTES, 'UTF-8'); ?>" <?php echo ($request['service_type'] ?? '') === $opt ? 'selected' : ''; ?>><?php echo htmlspecialchars($opt, ENT_QUOTES, 'UTF-8'); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label for="urgency" style="display:block; font-weight:700; margin-bottom:6px; color:#334155;">Urgency</label>
                    <select name="urgency" id="urgency" style="width:100%; padding:10px 12px; border:1px solid #cbd5e1; border-radius:8px; box-sizing:border-box;">
                        <?php foreach (['Normal','Urgent','Emergency'] as $opt): ?>
                            <option value="<?php echo htmlspecialchars($opt, ENT_QUOTES, 'UTF-8'); ?>" <?php echo ($request['urgency'] ?? 'Normal') === $opt ? 'selected' : ''; ?>><?php echo htmlspecialchars($opt, ENT_QUOTES, 'UTF-8'); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label for="preferred_date" style="display:block; font-weight:700; margin-bottom:6px; color:#334155;">Preferred Date</label>
                    <input type="date" name="preferred_date" id="preferred_date" value="<?php echo htmlspecialchars($request['preferred_date'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" style="width:100%; padding:10px 12px; border:1px solid #cbd5e1; border-radius:8px; box-sizing:border-box;">
                </div>
            </div>

            <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap:16px; margin-top:16px;">
                <div>
                    <label for="customer_phone" style="display:block; font-weight:700; margin-bottom:6px; color:#334155;">Best Contact Phone</label>
                    <input type="tel" name="customer_phone" id="customer_phone" value="<?php echo htmlspecialchars($request['customer_phone'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" style="width:100%; padding:10px 12px; border:1px solid #cbd5e1; border-radius:8px; box-sizing:border-box;">
                </div>
                <div>
                    <label for="equipment_details" style="display:block; font-weight:700; margin-bottom:6px; color:#334155;">Equipment / Asset Details</label>
                    <input type="text" name="equipment_details" id="equipment_details" value="<?php echo htmlspecialchars($request['equipment_details'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" style="width:100%; padding:10px 12px; border:1px solid #cbd5e1; border-radius:8px; box-sizing:border-box;">
                </div>
            </div>

            <div style="margin-top:16px;">
                <label for="location" style="display:block; font-weight:700; margin-bottom:6px; color:#334155;">Service Location</label>
                <input type="text" name="location" id="location" value="<?php echo htmlspecialchars($request['location'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" required style="width:100%; padding:10px 12px; border:1px solid #cbd5e1; border-radius:8px; box-sizing:border-box;">
            </div>

            <div style="margin-top:16px;">
                <label for="problem_summary" style="display:block; font-weight:700; margin-bottom:6px; color:#334155;">Problem Summary</label>
                <input type="text" name="problem_summary" id="problem_summary" value="<?php echo htmlspecialchars($request['problem_summary'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" required style="width:100%; padding:10px 12px; border:1px solid #cbd5e1; border-radius:8px; box-sizing:border-box;">
            </div>

            <div style="margin-top:16px;">
                <label for="description" style="display:block; font-weight:700; margin-bottom:6px; color:#334155;">Detailed Description</label>
                <textarea name="description" id="description" rows="5" required style="width:100%; padding:10px 12px; border:1px solid #cbd5e1; border-radius:8px; box-sizing:border-box; resize:vertical;"><?php echo htmlspecialchars($request['description'] ?? '', ENT_QUOTES, 'UTF-8'); ?></textarea>
            </div>

            <div style="margin-top:16px;">
                <label for="special_instructions" style="display:block; font-weight:700; margin-bottom:6px; color:#334155;">Special Instructions</label>
                <textarea name="special_instructions" id="special_instructions" rows="3" style="width:100%; padding:10px 12px; border:1px solid #cbd5e1; border-radius:8px; box-sizing:border-box; resize:vertical;"><?php echo htmlspecialchars($request['special_instructions'] ?? '', ENT_QUOTES, 'UTF-8'); ?></textarea>
            </div>

            <div style="margin-top:20px;">
                <button type="submit" style="background:#007BFF; color:#fff; border:none; border-radius:8px; padding:10px 18px; font-weight:700; cursor:pointer;">Save Changes</button>
            </div>
        </form>
        <?php else: ?>
            <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap:14px;">
                <div><div style="font-size:11px; font-weight:700; text-transform:uppercase; color:#64748b; margin-bottom:4px;">Service Type</div><div><?php echo htmlspecialchars($request['service_type'] ?? '', ENT_QUOTES, 'UTF-8'); ?></div></div>
                <div><div style="font-size:11px; font-weight:700; text-transform:uppercase; color:#64748b; margin-bottom:4px;">Urgency</div><div><?php echo htmlspecialchars($request['urgency'] ?? 'Normal', ENT_QUOTES, 'UTF-8'); ?></div></div>
                <div><div style="font-size:11px; font-weight:700; text-transform:uppercase; color:#64748b; margin-bottom:4px;">Preferred Date</div><div><?php echo htmlspecialchars($request['preferred_date'] ?? 'Not set', ENT_QUOTES, 'UTF-8'); ?></div></div>
                <div><div style="font-size:11px; font-weight:700; text-transform:uppercase; color:#64748b; margin-bottom:4px;">Best Contact Phone</div><div><?php echo htmlspecialchars($request['customer_phone'] ?? '', ENT_QUOTES, 'UTF-8'); ?></div></div>
                <div><div style="font-size:11px; font-weight:700; text-transform:uppercase; color:#64748b; margin-bottom:4px;">Equipment / Asset</div><div><?php echo htmlspecialchars($request['equipment_details'] ?? '', ENT_QUOTES, 'UTF-8'); ?></div></div>
                <div style="grid-column: 1 / -1;"><div style="font-size:11px; font-weight:700; text-transform:uppercase; color:#64748b; margin-bottom:4px;">Service Location</div><div><?php echo htmlspecialchars($request['location'] ?? '', ENT_QUOTES, 'UTF-8'); ?></div></div>
                <div style="grid-column: 1 / -1;"><div style="font-size:11px; font-weight:700; text-transform:uppercase; color:#64748b; margin-bottom:4px;">Problem Summary</div><div><?php echo htmlspecialchars($request['problem_summary'] ?? '', ENT_QUOTES, 'UTF-8'); ?></div></div>
                <div style="grid-column: 1 / -1;"><div style="font-size:11px; font-weight:700; text-transform:uppercase; color:#64748b; margin-bottom:4px;">Detailed Description</div><div style="white-space:pre-wrap;"><?php echo nl2br(htmlspecialchars($request['description'] ?? '', ENT_QUOTES, 'UTF-8')); ?></div></div>
                <?php if (!empty($request['special_instructions'])): ?>
                <div style="grid-column: 1 / -1;"><div style="font-size:11px; font-weight:700; text-transform:uppercase; color:#64748b; margin-bottom:4px;">Special Instructions</div><div style="white-space:pre-wrap;"><?php echo nl2br(htmlspecialchars($request['special_instructions'] ?? '', ENT_QUOTES, 'UTF-8')); ?></div></div>
                <?php endif; ?>
                <div><div style="font-size:11px; font-weight:700; text-transform:uppercase; color:#64748b; margin-bottom:4px;">Submitted</div><div><?php echo htmlspecialchars($request['created_at'] ?? '', ENT_QUOTES, 'UTF-8'); ?></div></div>
                <div><div style="font-size:11px; font-weight:700; text-transform:uppercase; color:#64748b; margin-bottom:4px;">Last Updated</div><div><?php echo htmlspecialchars($request['updated_at'] ?? '', ENT_QUOTES, 'UTF-8'); ?></div></div>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php require_once '../includes/footer.php'; ?>
