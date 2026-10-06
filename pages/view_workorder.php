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

function format_workorder_date_value($value) {
    $value = trim((string)$value);
    $timestamp = ($value !== '' && $value !== '0000-00-00') ? strtotime($value) : false;
    return $timestamp ? date('M j, Y', $timestamp) : 'Not set';
}

function format_workorder_time_value($value) {
    $value = trim((string)$value);
    $timestamp = $value !== '' ? strtotime($value) : false;
    return $timestamp ? date('g:i A', $timestamp) : 'Not set';
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

    if ($field === 'priority') {
        return 'Priority changed from ' . ($oldText !== '' ? $oldText : 'Not set') . ' to ' . ($newText !== '' ? $newText : 'Not set');
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
require_once '../includes/notifications.php';
require_once '../includes/customer_assets.inc.php';
require_once '../includes/property_entry_logs.inc.php';
require_once '../includes/workorder_technicians.inc.php';
require_once '../includes/customer_update_state.inc.php';
ensure_property_entry_log_schema($conn);
ensure_workorder_technicians_schema($conn);
ensure_customer_update_state_schema($conn);

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

 $viewWorkorderColumnsToEnsure = [
    'created_via' => 'VARCHAR(30) DEFAULT NULL',
    'service_type' => 'VARCHAR(100) DEFAULT NULL',
    'equipment_details' => 'VARCHAR(255) DEFAULT NULL',
    'work_location' => 'VARCHAR(30) DEFAULT NULL',
    'service_call_fee' => 'DECIMAL(10,2) NOT NULL DEFAULT 0',
    'special_instructions' => 'LONGTEXT DEFAULT NULL',
    'permission_anytime' => 'TINYINT(1) NOT NULL DEFAULT 0',
    'permission_anytime_with_time' => 'TINYINT(1) NOT NULL DEFAULT 0',
    'permission_date' => 'DATE DEFAULT NULL',
    'permission_time' => 'TIME DEFAULT NULL',
    'entry_date' => 'DATE DEFAULT NULL',
    'time_entered' => 'TIME DEFAULT NULL',
    'permission_time_relation' => 'VARCHAR(10) DEFAULT NULL',
    'time_departed' => 'TIME DEFAULT NULL'
];
$viewWorkorderColumnRows = $conn->query('SHOW COLUMNS FROM workorders')->fetchAll(PDO::FETCH_ASSOC);
$viewWorkorderColumnNames = array_column($viewWorkorderColumnRows, 'Field');
foreach ($viewWorkorderColumnsToEnsure as $columnName => $columnDefinition) {
    if (!in_array($columnName, $viewWorkorderColumnNames, true)) {
        $conn->exec("ALTER TABLE workorders ADD COLUMN `$columnName` $columnDefinition");
        $viewWorkorderColumnNames[] = $columnName;
    }
}
if (in_array('entry_time_relation', $viewWorkorderColumnNames, true)) {
    $conn->exec("UPDATE workorders SET permission_time_relation = entry_time_relation WHERE permission_time_relation IS NULL AND entry_time_relation IN ('before', 'after')");
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

$conn->exec("INSERT INTO workorder_property_entry_logs (workorder_id, entry_date, time_entered, time_departed, logged_by)
    SELECT w.id, w.entry_date, NULLIF(w.time_entered, '00:00:00'), NULLIF(w.time_departed, '00:00:00'), w.order_received_by
    FROM workorders w
    WHERE w.entry_date IS NOT NULL AND w.entry_date <> '0000-00-00'
      AND NOT EXISTS (SELECT 1 FROM workorder_property_entry_logs l WHERE l.workorder_id = w.id)");

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

$currentRole = strtolower(trim((string)($_SESSION['role'] ?? '')));
if ($currentRole === 'customer') {
    $customerId = (int)($_SESSION['customer_id'] ?? 0);
    if ($customerId <= 0 || (int)($wo['customer_id'] ?? 0) !== $customerId) {
        http_response_code(403);
        exit('You do not have permission to view this work order.');
    }
    mark_customer_update_seen($conn, $customerId, 'workorder', $id);
} elseif ($currentRole === 'admin') {
    mark_staff_update_seen($conn, (int)($_SESSION['user_id'] ?? 0), 'workorder', $id);
} elseif (!in_array($currentRole, ['admin', 'office', 'technician', 'staff'], true)) {
    http_response_code(403);
    exit('You do not have permission to view this work order.');
}
sync_primary_workorder_technician($conn, $id, $wo['work_performed_by'] ?? 0);
$isAssignedTechnician = $currentRole === 'technician'
    && is_workorder_technician_assigned($conn, $id, (int)($_SESSION['user_id'] ?? 0), $wo['work_performed_by'] ?? 0);
if ($currentRole === 'technician' && !$isAssignedTechnician) {
    http_response_code(403);
    exit('This work order must be assigned to you before you can work on it.');
} elseif ($currentRole === 'technician') {
    mark_staff_update_seen($conn, (int)($_SESSION['user_id'] ?? 0), 'workorder', $id);
}

// Find the Customer Service Request linked to this Work Order
$serviceRequest = null;

try {
    $serviceRequestStmt = $conn->prepare('
    SELECT id, request_number, service_type, equipment_details, problem_summary, description, special_instructions
    FROM customer_service_requests
    WHERE approved_workorder_id = ?
    LIMIT 1
');

    $serviceRequestStmt->execute([$id]);
    $serviceRequest = $serviceRequestStmt->fetch(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $serviceRequest = null;
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
        $latestAssignment = $conn->prepare("SELECT new_value FROM workorder_edits WHERE workorder_id = ? AND field_name = 'work_performed_by' ORDER BY edited_at DESC, id DESC LIMIT 1");
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
$assignedTeamIds = get_workorder_technician_ids($conn, $id, $wo['work_performed_by'] ?? 0);
$assignedTeamNames = [];
if (!empty($assignedTeamIds)) {
    $teamPlaceholders = implode(',', array_fill(0, count($assignedTeamIds), '?'));
    $teamNamesStmt = $conn->prepare("SELECT firstname, lastname FROM staff WHERE id IN ($teamPlaceholders) ORDER BY firstname, lastname");
    $teamNamesStmt->execute($assignedTeamIds);
    foreach ($teamNamesStmt->fetchAll(PDO::FETCH_ASSOC) as $teamNameRow) {
        $assignedTeamNames[] = trim((string)($teamNameRow['firstname'] ?? '') . ' ' . (string)($teamNameRow['lastname'] ?? ''));
    }
}

if (empty($wo['order_received_by']) || empty($wo['received_first'])) {
    $creatorLookup = $conn->prepare('SELECT e.new_value, e.edited_by, s.firstname, s.lastname FROM workorder_edits e LEFT JOIN staff s ON s.id = e.edited_by WHERE e.workorder_id = ? AND e.field_name = ? ORDER BY e.edited_at DESC, e.id DESC LIMIT 1');
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

$userId = (int)($_SESSION['user_id'] ?? 0);
$techActionMessage = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !hash_equals($_SESSION['csrf_token'], (string)($_POST['csrf_token'] ?? ''))) {
    $techActionMessage = 'Your session could not be verified. Please reload the page and try again.';
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($currentRole, ['admin', 'technician'], true)) {
    $assignedToMe = is_workorder_technician_assigned($conn, $id, $userId, $wo['work_performed_by'] ?? 0);
    $canUsePropertyVisitButtons = $currentRole === 'admin' || $assignedToMe;
    $requestedTechAction = (string)($_POST['tech_action'] ?? '');
    $isPropertyVisitAction = in_array($requestedTechAction, ['property_check_in', 'property_check_out'], true);
    $isPrimaryAssignedTechnician = $currentRole === 'technician' && (int)($wo['work_performed_by'] ?? 0) === $userId;
    $canUseOtherTechAction = $currentRole === 'technician' && $assignedToMe
        && ($requestedTechAction === 'add_work_performed' || ($isPrimaryAssignedTechnician && in_array($requestedTechAction, ['transfer', 'opt_out'], true)));
    if (isset($_POST['tech_action']) && (($isPropertyVisitAction && $canUsePropertyVisitButtons) || $canUseOtherTechAction)) {
        $techAction = $_POST['tech_action'];
        $targetTech = isset($_POST['new_tech']) ? (int)$_POST['new_tech'] : 0;
        $targetTechIsValid = false;
        if ($targetTech > 0) {
            $targetTechCheck = $conn->prepare("SELECT id FROM staff WHERE id = ? AND LOWER(TRIM(COALESCE(role, ''))) IN ('admin', 'technician', 'staff', '') LIMIT 1");
            $targetTechCheck->execute([$targetTech]);
            $targetTechIsValid = (bool)$targetTechCheck->fetchColumn();
        }

        if ($techAction === 'property_check_in') {
            $activeVisitStmt = $conn->prepare('SELECT id FROM workorder_property_entry_logs WHERE workorder_id = ? AND COALESCE(technician_id, logged_by) = ? AND time_entered IS NOT NULL AND time_departed IS NULL ORDER BY id DESC LIMIT 1');
            $activeVisitStmt->execute([$id, $userId]);
            if ($activeVisitStmt->fetchColumn()) {
                $techActionMessage = 'You are already checked in at this property.';
            } else {
                $buttonSource = $currentRole === 'admin' ? 'admin_button' : 'technician_button';
                $checkInStmt = $conn->prepare('INSERT INTO workorder_property_entry_logs (workorder_id, technician_id, entry_date, time_entered, logged_by, entry_source) VALUES (?, ?, CURDATE(), CURTIME(), ?, ?)');
                $checkInStmt->execute([$id, $userId, $userId, $buttonSource]);
                $techActionMessage = 'Check-in recorded.';
                if ($currentRole === 'technician') {
                    $orderLabel = trim((string)($wo['order_number'] ?? '')) ?: ('WO' . str_pad((string)$id, 4, '0', STR_PAD_LEFT));
                    $activityLink = '/sps/pages/view_workorder.php?id=' . $id;
                    try {
                        notify_staff_roles($conn, ['admin', 'office'], 'technician_check_in', 'Technician checked in: ' . $orderLabel, 'A technician checked in at the property for work order ' . $orderLabel . '.', $activityLink);
                        if ((int)($wo['customer_id'] ?? 0) > 0) {
                            notify_customer_workorder_update($conn, (int)$wo['customer_id'], $id, (string)($wo['status'] ?? 'Updated'), 'The technician checked in at the job site.');
                        }
                    } catch (Throwable $notificationError) {
                        error_log('Technician check-in notification failed: ' . $notificationError->getMessage());
                    }
                }
            }
        } elseif ($techAction === 'property_check_out') {
            $activeVisitStmt = $conn->prepare('SELECT id FROM workorder_property_entry_logs WHERE workorder_id = ? AND COALESCE(technician_id, logged_by) = ? AND time_entered IS NOT NULL AND time_departed IS NULL ORDER BY id DESC LIMIT 1');
            $activeVisitStmt->execute([$id, $userId]);
            $activeVisitId = (int)$activeVisitStmt->fetchColumn();
            if ($activeVisitId <= 0) {
                $techActionMessage = 'There is no active check-in to close.';
            } else {
                $buttonSource = $currentRole === 'admin' ? 'admin_button' : 'technician_button';
                $checkOutStmt = $conn->prepare('UPDATE workorder_property_entry_logs SET departure_date = CURDATE(), time_departed = CURTIME(), departed_by = ?, departure_source = ? WHERE id = ? AND workorder_id = ? AND time_departed IS NULL');
                $checkOutStmt->execute([$userId, $buttonSource, $activeVisitId, $id]);
                $billableSeconds = 0;
                foreach (property_visit_billable_seconds_by_technician($conn, $id) as $technicianBillableTotal) {
                    $billableSeconds += (int)($technicianBillableTotal['total_seconds'] ?? 0);
                }
                $billableLaborTime = intdiv($billableSeconds, 3600) . 'h ' . sprintf('%02d', intdiv($billableSeconds % 3600, 60)) . 'm';
                $updateBillableLabor = $conn->prepare('UPDATE workorders SET labor_time = ? WHERE id = ?');
                $updateBillableLabor->execute([$billableLaborTime, $id]);
                $techActionMessage = 'Check-out recorded.';
                if ($currentRole === 'technician') {
                    $orderLabel = trim((string)($wo['order_number'] ?? '')) ?: ('WO' . str_pad((string)$id, 4, '0', STR_PAD_LEFT));
                    $activityLink = '/sps/pages/view_workorder.php?id=' . $id;
                    try {
                        notify_staff_roles($conn, ['admin', 'office'], 'technician_check_out', 'Technician checked out: ' . $orderLabel, 'A technician checked out from the property for work order ' . $orderLabel . '.', $activityLink);
                        if ((int)($wo['customer_id'] ?? 0) > 0) {
                            notify_customer_workorder_update($conn, (int)$wo['customer_id'], $id, (string)($wo['status'] ?? 'Updated'), 'The technician checked out from the job site and visit time was recorded.');
                        }
                    } catch (Throwable $notificationError) {
                        error_log('Technician check-out notification failed: ' . $notificationError->getMessage());
                    }
                }
            }
        } elseif ($techAction === 'add_work_performed') {
            $performedDate = trim((string)($_POST['performed_date'] ?? ''));
            $performedHours = max(0, min(99, (int)($_POST['performed_hours'] ?? 0)));
            $performedMinutes = max(0, min(59, (int)($_POST['performed_minutes'] ?? 0)));
            $performedTime = sprintf('%02d:%02d:00', $performedHours, $performedMinutes);
            $description = trim((string)($_POST['performed_description'] ?? ''));

            if ($performedDate !== '' && ($performedHours > 0 || $performedMinutes > 0) && $description !== '') {
                $insertEntry = $conn->prepare('INSERT INTO work_performed_entries (workorder_id, performed_date, performed_time, description, added_by) VALUES (?, ?, ?, ?, ?)');
                $insertEntry->execute([$id, $performedDate, $performedTime, $description, $userId]);
                $techActionMessage = 'Work performed entry added successfully.';
                if ($currentRole === 'technician') {
                    $orderLabel = trim((string)($wo['order_number'] ?? '')) ?: ('WO' . str_pad((string)$id, 4, '0', STR_PAD_LEFT));
                    $activityLink = '/sps/pages/view_workorder.php?id=' . $id;
                    try {
                        notify_staff_roles($conn, ['admin', 'office'], 'technician_work_entry', 'Technician work logged: ' . $orderLabel, 'A technician added a work-performed update to work order ' . $orderLabel . '.', $activityLink);
                        if ((int)($wo['customer_id'] ?? 0) > 0) {
                            $entryHoursText = $performedHours . 'h ' . sprintf('%02d', $performedMinutes) . 'm';
                            $entryNotice = 'A technician logged work performed on ' . date('M j, Y', strtotime($performedDate)) . ' (' . $entryHoursText . ').';
                            notify_customer_workorder_update($conn, (int)$wo['customer_id'], $id, (string)($wo['status'] ?? 'Updated'), $entryNotice);
                        }
                    } catch (Throwable $notificationError) {
                        error_log('Technician work-entry notification failed: ' . $notificationError->getMessage());
                    }
                }
            } else {
                $techActionMessage = 'Please enter a date, time spent, and description for this work entry.';
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
        } elseif ($techAction === 'transfer' && $targetTechIsValid && $targetTech !== $userId) {
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

$techs = $conn->query("SELECT id, firstname, lastname FROM staff WHERE LOWER(TRIM(COALESCE(role, ''))) IN ('admin', 'technician', 'staff', '') ORDER BY firstname, lastname")->fetchAll(PDO::FETCH_ASSOC);
$workPerformedEntries = $conn->prepare('SELECT wpe.*, s.firstname, s.lastname FROM work_performed_entries wpe LEFT JOIN staff s ON s.id = wpe.added_by WHERE wpe.workorder_id = ? ORDER BY wpe.performed_date DESC, wpe.created_at DESC');
$workPerformedEntries->execute([$id]);
$workPerformedEntries = $workPerformedEntries->fetchAll(PDO::FETCH_ASSOC);
$propertyEntryLogsStmt = $conn->prepare('SELECT l.*, t.firstname AS technician_first, t.lastname AS technician_last FROM workorder_property_entry_logs l LEFT JOIN staff t ON t.id = l.technician_id WHERE l.workorder_id = ? ORDER BY l.entry_date DESC, l.time_entered DESC, l.id DESC');
$propertyEntryLogsStmt->execute([$id]);
$propertyEntryLogs = $propertyEntryLogsStmt->fetchAll(PDO::FETCH_ASSOC);
$activePropertyVisit = null;
$myActivePropertyVisit = null;
$activePropertyVisits = [];
foreach ($propertyEntryLogs as $propertyEntryLog) {
    if (!empty($propertyEntryLog['time_entered']) && empty($propertyEntryLog['time_departed'])) {
        $activePropertyVisits[] = $propertyEntryLog;
        if ($activePropertyVisit === null) {
            $activePropertyVisit = $propertyEntryLog;
        }
        if ((int)($propertyEntryLog['technician_id'] ?? $propertyEntryLog['logged_by'] ?? 0) === $userId) {
            $myActivePropertyVisit = $propertyEntryLog;
        }
    }
}

$workPerformedEntriesByDate = [];
foreach ($workPerformedEntries as $entry) {
    $dateKey = $entry['performed_date'] ?? '';
    $workPerformedEntriesByDate[$dateKey][] = $entry;
}

// track which work-performed entry the customer has already seen, so "NEW" only shows once
$mostRecentEntryId = $workPerformedEntries[0]['id'] ?? null;
$showNewBadge = false;
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

$technicianBillableTotals = property_visit_billable_seconds_by_technician($conn, $id, true);
$technicianBillableTotals = array_values(array_filter($technicianBillableTotals, static function ($row) {
    return (int)($row['total_seconds'] ?? 0) > 0;
}));
$totalWorkSeconds = 0;
foreach ($technicianBillableTotals as $technicianBillableTotal) {
    $totalWorkSeconds += (int)($technicianBillableTotal['total_seconds'] ?? 0);
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
        .wo-watermark { display:none; }
    .wo-header { display:flex; flex-wrap:wrap; justify-content:space-between; align-items:center; gap:10px; background:rgba(15,23,42,0.85); border:1px solid rgba(15,23,42,0.9); color:#fff; padding:10px 14px; border-radius:8px; margin-bottom:16px; }
    .wo-header h2 { margin:0; font-size:15px; font-weight:700; letter-spacing:.02em; color:#fff; }
    .wo-print-timestamp { display:none; }
    .wo-actions a { color:#1d4ed8; background:#fff; border:1px solid #cbd5e1; padding:6px 12px; border-radius:8px; text-decoration:none; font-weight:600; font-size:12px; margin-left:8px; transition: background .15s ease; }
    .wo-actions a:hover { background:#eff6ff; }
    .wo-actions a.edit-link { color:#fff; background:#2563eb; border-color:#2563eb; }
    .wo-actions a.edit-link:hover { background:#1d4ed8; }
    .wo-actions .wo-print-button { display:inline-flex; align-items:center; justify-content:center; gap:6px; width:auto; margin:0 0 0 8px; padding:6px 12px; border:1px solid #cbd5e1; border-radius:8px; background:#fff; color:#1d4ed8; font:600 12px Arial,sans-serif; cursor:pointer; }
    .wo-print-button svg { width:15px; height:15px; }
    .wo-grid { display:grid; grid-template-columns: repeat(auto-fill, minmax(220px, 1fr)); gap:14px; margin-top:6px; }
    .wo-field { background:#f8fafc; border:1px solid #e2e8f0; border-radius:10px; padding:12px 14px; box-sizing:border-box; }
    .wo-field.full { grid-column: 1 / -1; }
    .wo-field .wo-label { font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.04em; color:#64748b; margin-bottom:6px; }
    .wo-field .wo-value { font-size:14px; color:#0f172a; line-height:1.5; word-break:break-word; }
    .edit-link { background-color: #2563eb; color: white; padding:8px 14px; border-radius:8px; text-decoration:none; font-weight:600; font-size:13px; }
    .view-link { background-color: #0891b2; color: white; padding:8px 14px; border-radius:8px; text-decoration:none; font-weight:600; font-size:13px; }
    .technician-transfer-controls { display:flex; flex-wrap:wrap; justify-content:space-between; align-items:center; gap:16px; min-width:0; }
    .technician-transfer-form { display:flex; flex:1 1 280px; min-width:0; flex-direction:column; align-items:flex-start; gap:8px; margin:0; }
    .technician-opt-out-form { display:flex; flex:0 1 300px; max-width:100%; min-width:0; align-items:center; justify-content:flex-end; margin:0 0 0 auto; }
    .technician-opt-out-button { box-sizing:border-box; width:100%; max-width:300px; min-height:54px; margin:0; padding:12px 18px; font-size:15px; white-space:normal; }
        .property-visit-panel { flex-direction:column !important; align-items:flex-start !important; justify-content:flex-start !important; }
        .property-visit-form { display:flex; justify-content:flex-start; width:100%; }
        .property-visit-button { max-width:min(100%, 350px); margin:0; }
    @media (max-width:640px) {
        .technician-transfer-controls { align-items:flex-start; flex-direction:column; }
        .technician-opt-out-form { justify-content:flex-start; margin-left:0; }
    }
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
    @media print {
        @page { size:letter portrait; margin:0.18in; }
        html, body { display:block !important; width:auto !important; min-height:0 !important; margin:0 !important; padding:0 !important; background:#fff !important; color:#111827 !important; -webkit-print-color-adjust:exact !important; print-color-adjust:exact !important; }
        nav, .hero, footer { display:none !important; }
        .page-body { display:block !important; width:auto !important; min-width:0 !important; padding:0 !important; margin:0 !important; }
        .page-body > :not(.wo-card) { display:none !important; }
        .wo-card { position:relative; isolation:isolate; display:block !important; width:100% !important; min-height:80vh !important; max-width:none !important; margin:0 !important; padding:0 !important; border:0 !important; border-radius:0 !important; box-shadow:none !important; background:#fff !important; color:#111827 !important; }
        .wo-card > .wo-watermark { display:block !important; position:fixed; z-index:2; left:50vw; top:50vh; width:96vw; height:92vh; transform:translate(-50%,-50%) rotate(-18deg); object-fit:contain; opacity:.12; pointer-events:none; }
        .wo-card > * { position:relative; z-index:1; }
        .wo-card > .wo-watermark { position:fixed; z-index:2; }
        .wo-header { display:block !important; margin:0 0 6px !important; padding:0 0 4px !important; border:0 !important; border-bottom:1px solid #94a3b8 !important; border-radius:0 !important; background:transparent !important; color:#111827 !important; }
        .wo-header h2 { color:#111827 !important; font-size:15px !important; }
        .wo-print-timestamp { display:block !important; margin-top:3px; color:#475569; font-size:9px; text-align:right; }
        .wo-actions, .wo-interactive-panel, .technician-transfer-controls, .technician-transfer-form, .technician-opt-out-form, .wo-card form, .wo-card button, .history-toggle-container, .history-button-row, .small-toggle-btn, #status-guide-panel { display:none !important; }
        .wo-card > p { display:none !important; }
        .wo-grid { display:grid !important; grid-template-columns:repeat(3,minmax(0,1fr)) !important; gap:6px !important; margin-top:0 !important; }
        .wo-field { position:relative; z-index:auto; break-inside:avoid; padding:8px 9px !important; border:1px solid #cbd5e1 !important; border-radius:4px !important; box-shadow:none !important; -webkit-print-color-adjust:exact !important; print-color-adjust:exact !important; }
        .wo-field > * { position:relative; z-index:3; }
        .wo-field.full { grid-column:1 / -1 !important; }
        .wo-field .wo-label { margin-bottom:3px !important; color:#475569 !important; font-size:9px !important; }
        .wo-field .wo-value { color:#111827 !important; font-size:11px !important; font-weight:600 !important; line-height:1.35 !important; }
        .wo-field small { color:#475569 !important; font-size:9px !important; }
        .wo-field details, .wo-field details[open] { display:block !important; break-inside:avoid; }
        .wo-field details > summary { display:block !important; color:#111827 !important; }
        .technician-time-breakdown:not([open]) > .wo-value { display:block !important; }
        .wo-field table { display:table !important; width:100% !important; min-width:0 !important; border-collapse:collapse !important; font-size:10px !important; }
        .wo-field th, .wo-field td { padding:5px !important; border:1px solid #cbd5e1 !important; color:#111827 !important; }
        a { color:#111827 !important; text-decoration:none !important; }
    }
</style>

<div class="wo-card">
<img class="wo-watermark" src="/sps/nsmlogo_new.png" alt="" aria-hidden="true">
<div class="wo-header">
    <h2>Work Order # <?php echo htmlspecialchars($displayOrderNumber, ENT_QUOTES, 'UTF-8'); ?></h2>
    <div class="wo-print-timestamp" id="wo-print-timestamp"></div>
    <div class="wo-actions">
        <a href="/sps/pages/dashboard.php">← Back to Dashboard</a>
        <?php if (in_array($currentRole, ['admin','office','technician'])): ?>
            <a class="edit-link" href="/sps/pages/edit_workorder.php?id=<?php echo (int)$wo['id']; ?>">Edit</a>
        <?php endif; ?>
        <button class="wo-print-button" type="button" onclick="window.print()" aria-label="Print work order">
            <svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M7 8V3h10v5M7 17H5a2 2 0 0 0-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5h-2"></path><path d="M7 14h10v7H7zM18 11h.01"></path></svg>
            <span>Print</span>
        </button>
    </div>
</div>
<script>
    window.addEventListener('beforeprint', function () {
        var timestamp = document.getElementById('wo-print-timestamp');
        if (timestamp) {
            timestamp.textContent = 'Printed: ' + new Date().toLocaleString();
        }
    });
</script>

<?php if ($techActionMessage !== ''): ?>
    <p style="color:#0b5a2c; font-weight:600; margin:10px 0;"><?php echo htmlspecialchars($techActionMessage, ENT_QUOTES, 'UTF-8'); ?></p>
<?php endif; ?>

<?php if ($currentRole === 'admin' || $isAssignedTechnician): ?>
    <div class="wo-interactive-panel property-visit-panel" style="margin:12px 0; padding:14px 16px; border:1px solid <?php echo $myActivePropertyVisit ? '#86efac' : '#bfdbfe'; ?>; border-radius:10px; background:<?php echo $myActivePropertyVisit ? '#f0fdf4' : '#eff6ff'; ?>; display:flex; flex-wrap:wrap; align-items:center; justify-content:space-between; gap:12px;">
        <div>
            <strong style="font-size:16px; color:#0f172a;">Property visit time</strong><br>
            <span style="color:#475569;"><?php echo $currentRole === 'admin' ? 'Use the button to record arrival or departure at the property.' : 'Reminder: check in as soon as you arrive at the property, then check out before you leave.'; ?></span>
            <?php if ($myActivePropertyVisit): ?>
                <div style="margin-top:5px; color:#166534; font-weight:700;">You are checked in. Remember to check out before leaving.</div>
            <?php endif; ?>
        </div>
        <form method="post" class="property-visit-form" style="margin:0;">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8'); ?>">
            <input type="hidden" name="tech_action" value="<?php echo $myActivePropertyVisit ? 'property_check_out' : 'property_check_in'; ?>">
            <button type="submit" class="property-visit-button" style="border:0; border-radius:8px; padding:12px 18px; color:#fff; background:<?php echo $myActivePropertyVisit ? '#b91c1c' : '#15803d'; ?>; font-size:15px; font-weight:700; cursor:pointer;">
                <?php echo $myActivePropertyVisit ? 'Check Out Now' : 'Check In Now'; ?>
            </button>
        </form>
    </div>
    <?php if ($currentRole === 'technician' && (int)($wo['work_performed_by'] ?? 0) === $userId): ?>
        <details class="wo-interactive-panel" style="margin:12px 0 10px; padding:10px 12px; border:1px solid #dfe7f1; border-radius:6px; background:#f8fbff;">
        <summary style="cursor:pointer; font-weight:700; color:#0f172a;">Transfer / Opt Out</summary>
        <div class="technician-transfer-controls" style="padding-top:10px;">
        <form method="post" class="technician-transfer-form">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8'); ?>">
            <input type="hidden" name="tech_action" value="transfer">
            <label for="new_tech" style="font-weight:700; margin:0;">Transfer to:</label>
            <select id="new_tech" name="new_tech" style="width:260px; max-width:100%; padding:8px; border:1px solid #cbd5e0; border-radius:4px;">
                <option value="">Select technician</option>
                <?php foreach ($techs as $tech): ?>
                    <?php if ((int)$tech['id'] !== $userId): ?>
                        <option value="<?php echo (int)$tech['id']; ?>"><?php echo htmlspecialchars($tech['firstname'] . ' ' . $tech['lastname'], ENT_QUOTES, 'UTF-8'); ?></option>
                    <?php endif; ?>
                <?php endforeach; ?>
            </select>
            <button type="submit" class="edit-link" style="display:inline-block; align-self:flex-start; width:auto; max-width:400px; margin:0; border:none; cursor:pointer;">Transfer Technician</button>
        </form>

        <form method="post" class="technician-opt-out-form">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8'); ?>">
            <input type="hidden" name="tech_action" value="opt_out">
            <button type="submit" class="view-link technician-opt-out-button" style="border:none; cursor:pointer; background:#6c757d;">Opt Out of This Work Order</button>
        </form>
        </div>
    </details>

    <?php endif; ?>
    <?php if ($currentRole === 'technician' && $isAssignedTechnician): ?>
    <details class="wo-interactive-panel" style="margin:10px 0 18px; padding:10px 12px; border:1px solid #dfe7f1; border-radius:6px; background:#fffdf7;">
        <summary style="cursor:pointer; font-weight:700; color:#0f172a;">Add Work Performed</summary>
        <div style="padding-top:10px;">
        <form method="post" style="display:flex; flex-direction:column; gap:10px; max-width:520px;">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8'); ?>">
            <input type="hidden" name="tech_action" value="add_work_performed">
            <div style="display:flex; gap:10px; flex-wrap:wrap;">
                <label style="flex:1; min-width:160px; font-weight:700;">Date<br><input type="date" name="performed_date" required style="width:100%; padding:8px; border:1px solid #cbd5e0; border-radius:4px;"></label>
                <label style="flex:1; min-width:200px; font-weight:700;">Hours and minutes spent on this entry<br>
                    <div style="display:flex; gap:6px; align-items:center;">
                        <input type="number" name="performed_hours" min="0" max="99" value="0" required style="width:70px; padding:8px; border:1px solid #cbd5e0; border-radius:4px;"> <span>hrs</span>
                        <input type="number" name="performed_minutes" min="0" max="59" step="5" value="0" required style="width:70px; padding:8px; border:1px solid #cbd5e0; border-radius:4px;"> <span>min</span>
                    </div>
                    <small style="display:block;color:#64748b;">Entry duration only; on-site total is calculated from check-in/out.</small>
                </label>
            </div>
            <label style="font-weight:700;">Description<br><textarea name="performed_description" rows="4" required style="width:100%; padding:8px; border:1px solid #cbd5e0; border-radius:4px; resize:vertical;"></textarea></label>
            <button type="submit" class="edit-link" style="border:none; cursor:pointer; width:max-content;">Save Work Performed</button>
        </form>
        </div>
    </details>
    <?php endif; ?>
<?php endif; ?>

<?php
// determine last updated by: latest edit or creator
$lastEditor = null;
$lastEditedAt = null;
try {
    $le = $conn->prepare('SELECT e.*, s.firstname, s.lastname FROM workorder_edits e LEFT JOIN staff s ON e.edited_by = s.id WHERE e.workorder_id = ? ORDER BY e.edited_at DESC, e.id DESC LIMIT 1');
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
    <?php if (count($assignedTeamNames) > 1): ?>
    <div class="wo-field" style="background:<?php echo $fieldColors[$fieldIdx++ % count($fieldColors)]; ?>;"><div class="wo-label">Assigned Team</div><div class="wo-value"><?php echo htmlspecialchars(implode(', ', $assignedTeamNames), ENT_QUOTES, 'UTF-8'); ?></div></div>
    <?php endif; ?>
    <div class="wo-field" style="background:<?php echo $fieldColors[$fieldIdx++ % count($fieldColors)]; ?>;"><div class="wo-label">Client Name</div><div class="wo-value"><?php echo htmlspecialchars($wo['client_name'] ?? '', ENT_QUOTES, 'UTF-8'); ?></div></div>
    <div class="wo-field" style="background:<?php echo $fieldColors[$fieldIdx++ % count($fieldColors)]; ?>;"><div class="wo-label">Client Phone</div><div class="wo-value"><?php echo htmlspecialchars($wo['client_phone'] ?? '', ENT_QUOTES, 'UTF-8'); ?></div></div>
    <div class="wo-field" style="background:<?php echo $fieldColors[$fieldIdx++ % count($fieldColors)]; ?>;"><div class="wo-label">Location</div><div class="wo-value"><?php echo htmlspecialchars($wo['location'] ?? '', ENT_QUOTES, 'UTF-8'); ?></div></div>
    <div class="wo-field" style="background:<?php echo $fieldColors[$fieldIdx++ % count($fieldColors)]; ?>;"><div class="wo-label">Order Date</div><div class="wo-value"><?php echo htmlspecialchars($wo['order_date'] ?? '', ENT_QUOTES, 'UTF-8'); ?></div></div>
    <div class="wo-field" style="background:<?php echo $fieldColors[$fieldIdx++ % count($fieldColors)]; ?>;"><div class="wo-label">Service Type</div><div class="wo-value"><?php $serviceTypeDisplay = trim((string)($wo['service_type'] ?? ''));
        if ($serviceTypeDisplay === '') {
            $serviceTypeDisplay = trim((string)($serviceRequest['service_type'] ?? ''));
        }
        echo htmlspecialchars($serviceTypeDisplay !== '' ? $serviceTypeDisplay : 'N/A', ENT_QUOTES, 'UTF-8');
        ?>
    </div>
</div>
<?php
$workLocationValue = trim((string)($wo['work_location'] ?? ''));
$workLocationDisplay = $workLocationValue === 'shop' ? 'Our Shop' : ($workLocationValue === 'customer_property' ? 'Customer Property' : 'Not specified');
?>
    <div class="wo-field" style="background:<?php echo $fieldColors[$fieldIdx++ % count($fieldColors)]; ?>;"><div class="wo-label">Work Performed At</div><div class="wo-value"><?php echo htmlspecialchars($workLocationDisplay, ENT_QUOTES, 'UTF-8'); ?></div></div>
    <div class="wo-field" style="background:<?php echo $fieldColors[$fieldIdx++ % count($fieldColors)]; ?>;"><div class="wo-label">Service Call Fee</div><div class="wo-value">$<?php echo number_format((float)($wo['service_call_fee'] ?? 0), 2); ?></div></div>
    <div class="wo-field" style="background:<?php echo $fieldColors[$fieldIdx++ % count($fieldColors)]; ?>;"><div class="wo-label">Status</div><div class="wo-value"><?php $rawStatus = isset($wo['status']) ? (string)$wo['status'] : 'Open'; $statusClass = strtolower(trim($rawStatus)); $statusStyle = 'display:inline-block;padding:4px 10px;border-radius:999px;font-weight:700;color:#fff;'; if ($statusClass === 'completed' || $statusClass === 'closed') { $statusStyle .= 'background:#198754;'; } elseif ($statusClass === 'waiting for parts') { $statusStyle .= 'background:#d97706;'; } elseif ($statusClass === 'on hold') { $statusStyle .= 'background:#7c3aed;'; } elseif ($statusClass === 'in progress') { $statusStyle .= 'background:#2563eb;'; } elseif ($statusClass === 'pending') { $statusStyle .= 'background:#6b7280;'; } elseif ($statusClass === 'open') { $statusStyle .= 'background:#0ea5e9;'; } else { $statusStyle .= 'background:#1d4ed8;'; } echo '<span style="' . htmlspecialchars($statusStyle, ENT_QUOTES, 'UTF-8') . '">' . htmlspecialchars($rawStatus, ENT_QUOTES, 'UTF-8') . '</span>'; ?> <button type="button" id="status-guide-toggle" aria-label="Open status guide" aria-expanded="false" title="Click or hover for status meanings" style="display:inline-flex; align-items:center; justify-content:center; width:20px; height:20px; border:1px solid #cbd5e1; border-radius:50%; background:#eff6ff; color:#1d4ed8; cursor:pointer; font-size:12px; line-height:1; font-weight:700; padding:0; vertical-align:middle;">ⓘ</button></div></div>
    <div class="wo-field" style="background:<?php echo $fieldColors[$fieldIdx++ % count($fieldColors)]; ?>;"><div class="wo-label">Priority</div><div class="wo-value"><?php $rawPriority = isset($wo['priority']) ? (string)$wo['priority'] : 'Normal'; $isHigh = in_array(strtolower(trim($rawPriority)), ['high', 'urgent', 'emergency'], true); ?><span style="<?php echo $isHigh ? 'color:#721c24;background:#f8d7da;padding:4px 8px;border-radius:4px;' : ''; ?>"><?php echo htmlspecialchars($rawPriority, ENT_QUOTES, 'UTF-8'); ?></span></div></div>
    <div class="wo-field" style="background:<?php echo $fieldColors[$fieldIdx++ % count($fieldColors)]; ?>;"><div class="wo-label">Expected Start</div><div class="wo-value"><?php echo htmlspecialchars(!empty($wo['expected_start_date']) ? $wo['expected_start_date'] : 'N/A', ENT_QUOTES, 'UTF-8'); ?></div></div>
    <div class="wo-field" style="background:<?php echo $fieldColors[$fieldIdx++ % count($fieldColors)]; ?>;"><div class="wo-label">Expected End</div><div class="wo-value"><?php echo htmlspecialchars(!empty($wo['expected_end_date']) ? $wo['expected_end_date'] : 'N/A', ENT_QUOTES, 'UTF-8'); ?></div></div>
<?php
$equipmentDisplay = trim((string)($wo['equipment_details'] ?? ''));
if ($equipmentDisplay === '') {
    $equipmentDisplay = trim((string)($serviceRequest['equipment_details'] ?? ''));
}
$vesselVinDisplay = trim((string)($wo['vessel_vin'] ?? ''));
$linkedAssetId = (int)($wo['asset_id'] ?? $serviceRequest['asset_id'] ?? 0);
$linkedCustomerId = (int)($wo['customer_id'] ?? $serviceRequest['customer_id'] ?? 0);
if ($linkedAssetId > 0 && $linkedCustomerId > 0) {
    $assetDetailsStmt = $conn->prepare('SELECT asset_name, model_year, make, model, serial_number FROM customer_assets WHERE id = ? AND customer_id = ? LIMIT 1');
    $assetDetailsStmt->execute([$linkedAssetId, $linkedCustomerId]);
    $linkedAssetDetails = $assetDetailsStmt->fetch(PDO::FETCH_ASSOC);
    if ($linkedAssetDetails) {
        $equipmentDisplay = customer_asset_name_label($linkedAssetDetails);
        if ($vesselVinDisplay === '') {
            $vesselVinDisplay = trim((string)($linkedAssetDetails['serial_number'] ?? ''));
        }
    }
} else {
    $equipmentDisplay = customer_asset_display_without_serial($equipmentDisplay);
}
$permissionDateRaw = trim((string)($wo['permission_date'] ?? ''));
$permissionTimeRaw = trim((string)($wo['permission_time'] ?? ''));
$hasPermissionDate = $permissionDateRaw !== '' && $permissionDateRaw !== '0000-00-00';
$hasPermissionTime = $permissionTimeRaw !== '';
$permissionDateDisplay = format_workorder_date_value($permissionDateRaw);
$permissionTimeDisplay = format_workorder_time_value($permissionTimeRaw);
$permissionTimeRelation = strtolower(trim((string)($wo['permission_time_relation'] ?? '')));
$permissionAnytime = !empty($wo['permission_anytime']);
$showPermissionTimeWithAnytime = $permissionAnytime && !empty($wo['permission_anytime_with_time']) && $hasPermissionTime;
$permissionDateValueDisplay = $permissionAnytime
    ? 'Anytime'
    : ($hasPermissionDate ? $permissionDateDisplay : 'Not set');
$permissionTimeValueDisplay = ($permissionAnytime && !$showPermissionTimeWithAnytime)
    ? 'Anytime'
    : ($hasPermissionTime
        ? (in_array($permissionTimeRelation, ['before', 'after'], true)
            ? ucfirst($permissionTimeRelation) . ' ' . $permissionTimeDisplay
            : $permissionTimeDisplay)
        : ($permissionAnytime ? 'Anytime' : 'Not set'));
?>

<?php if ($equipmentDisplay !== ''): ?>
<div class="wo-field" style="background:<?php echo $fieldColors[$fieldIdx++ % count($fieldColors)]; ?>;">
    <div class="wo-label">Vessel / Asset</div>
    <div class="wo-value">
        <?php echo htmlspecialchars($equipmentDisplay, ENT_QUOTES, 'UTF-8'); ?>
    </div>
</div>
<?php endif; ?>
    <div class="wo-field" style="background:<?php echo $fieldColors[$fieldIdx++ % count($fieldColors)]; ?>;">
    <div class="wo-label">Service Request #</div>
    <div class="wo-value">
        <?php
        $serviceRequestNumber = trim((string)($serviceRequest['request_number'] ?? ''));

        if ($serviceRequestNumber === '' && !empty($serviceRequest['id'])) {
            $serviceRequestNumber = 'SR-' . str_pad((string)(int)$serviceRequest['id'], 5, '0', STR_PAD_LEFT);
        }

        if ($serviceRequest && !empty($serviceRequest['id'])) {
            echo '<a href="/sps/pages/view_service_request.php?id=' . (int)$serviceRequest['id'] . '" style="color:#1d4ed8;font-weight:700;text-decoration:underline;">' . htmlspecialchars($serviceRequestNumber, ENT_QUOTES, 'UTF-8') . '</a>';
        } else {
            echo htmlspecialchars($serviceRequestNumber !== '' ? $serviceRequestNumber : 'N/A', ENT_QUOTES, 'UTF-8');
        }
        ?>
    </div>
</div>

<div class="wo-field full" style="background:<?php echo $fieldColors[$fieldIdx++ % count($fieldColors)]; ?>;">
    <div class="wo-label">Requested Work</div>
    <div class="wo-value">
        <?php if ($serviceRequest && (!empty($serviceRequest['problem_summary']) || !empty($serviceRequest['description']))): ?>

            <?php if (!empty($serviceRequest['problem_summary'])): ?>
                <strong>
                    <?php echo htmlspecialchars($serviceRequest['problem_summary'], ENT_QUOTES, 'UTF-8'); ?>
                </strong>
            <?php endif; ?>

            <?php if (!empty($serviceRequest['description'])): ?>
                <?php if (!empty($serviceRequest['problem_summary'])): ?>
                    <br>
                <?php endif; ?>

                <?php echo nl2br(htmlspecialchars($serviceRequest['description'], ENT_QUOTES, 'UTF-8')); ?>
            <?php endif; ?>

        <?php else: ?>

            <?php echo nl2br(htmlspecialchars($wo['requested_work'] ?? '', ENT_QUOTES, 'UTF-8')); ?>

        <?php endif; ?>
    </div></div>
<?php
$displayAdditionalComments = trim((string)($wo['additional_comments'] ?? ''));

// Remove the old automatic message that was added when a Service Request
// was converted into a Work Order.
if (preg_match('/^Created from customer service request #\d+$/i', $displayAdditionalComments)) {
    $displayAdditionalComments = '';
}
?>

<?php if ($displayAdditionalComments !== ''): ?>
    <div class="wo-field full" style="background:<?php echo $fieldColors[$fieldIdx++ % count($fieldColors)]; ?>;">
        <div class="wo-label">Additional Comments</div>
        <div class="wo-value">
            <?php echo nl2br(htmlspecialchars($displayAdditionalComments, ENT_QUOTES, 'UTF-8')); ?>
        </div>
    </div>
<?php endif; ?>
    <?php
$specialInstructionsDisplay = trim((string)($wo['special_instructions'] ?? ''));

if ($specialInstructionsDisplay === '') {
    $specialInstructionsDisplay = trim((string)($serviceRequest['special_instructions'] ?? ''));
}
?>

<?php if ($specialInstructionsDisplay !== ''): ?>
<div class="wo-field full" style="background:<?php echo $fieldColors[$fieldIdx++ % count($fieldColors)]; ?>;">
    <div class="wo-label">Special Instructions</div>
    <div class="wo-value">
        <?php echo nl2br(htmlspecialchars($specialInstructionsDisplay, ENT_QUOTES, 'UTF-8')); ?>
    </div>
</div>
<?php endif; ?>    
    <div class="wo-field" style="background:<?php echo $fieldColors[$fieldIdx++ % count($fieldColors)]; ?>;"><div class="wo-label">Vessel VIN</div><div class="wo-value"><?php echo htmlspecialchars($vesselVinDisplay, ENT_QUOTES, 'UTF-8'); ?></div></div>
    <div class="wo-field" style="background:<?php echo $fieldColors[$fieldIdx++ % count($fieldColors)]; ?>;"><div class="wo-label">Vessel Hours</div><div class="wo-value"><?php echo htmlspecialchars($wo['vessel_hours'] ?? '', ENT_QUOTES, 'UTF-8'); ?></div></div>
    <?php if ($currentRole !== 'technician'): ?>
    <div class="wo-field full" style="background:<?php echo $fieldColors[$fieldIdx++ % count($fieldColors)]; ?>;"><div class="wo-label">Total Technician Time</div><div class="wo-value"><?php echo htmlspecialchars($totalWorkText, ENT_QUOTES, 'UTF-8'); ?><small style="display:inline;margin-left:8px;color:#64748b;font-weight:400;">Sum of each technician’s property arrival-to-departure time. Overlapping time is counted for each technician.</small></div></div>
        <?php if (!empty($technicianBillableTotals)): ?>
        <details class="wo-field full technician-time-breakdown" style="background:<?php echo $fieldColors[$fieldIdx++ % count($fieldColors)]; ?>;">
            <summary class="wo-label">Technician Time Breakdown</summary>
            <div class="wo-value">
                    <?php foreach ($technicianBillableTotals as $technicianBillableTotal): ?>
                        <?php
                            $technicianBillableId = (int)($technicianBillableTotal['technician_id'] ?? 0);
                            $technicianBillableSeconds = (int)($technicianBillableTotal['total_seconds'] ?? 0);
                            $technicianBillableName = 'Unknown technician';
                            if ($technicianBillableId > 0) {
                                $billableNameStmt = $conn->prepare('SELECT firstname, lastname FROM staff WHERE id = ? LIMIT 1');
                                $billableNameStmt->execute([$technicianBillableId]);
                                $billableNameRow = $billableNameStmt->fetch(PDO::FETCH_ASSOC);
                                if ($billableNameRow) {
                                    $technicianBillableName = trim((string)($billableNameRow['firstname'] ?? '') . ' ' . (string)($billableNameRow['lastname'] ?? ''));
                                }
                            }
                            $technicianHours = intdiv($technicianBillableSeconds, 3600);
                            $technicianMinutes = intdiv($technicianBillableSeconds % 3600, 60);
                            $technicianTimeText = format_duration_words($technicianHours, $technicianMinutes);
                        ?>
                        <div style="padding:6px 0; border-bottom:1px solid #e2e8f0;"><?php echo htmlspecialchars($technicianBillableName . ': ' . $technicianTimeText, ENT_QUOTES, 'UTF-8'); ?></div>
                    <?php endforeach; ?>
            </div>
        </details>
        <?php endif; ?>
    <?php endif; ?>
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
        <div class="wo-label">Work Performed</div>
        <div class="wo-value">
            <div style="margin-bottom:8px;color:#64748b;font-size:12px;">Each entry shows its time spent and the technician who recorded it.</div>
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
                                <span>Submitted: <?php echo htmlspecialchars(format_entry_logged_at($entry['created_at'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></span>
                                <span>&middot;</span>
                                <span style="font-weight:600;"><?php echo htmlspecialchars(($entry['performed_time'] ?? '') === '00:00:00' ? 'Time not recorded' : format_work_duration($entry['performed_time'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></span>
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
    <div class="wo-field full" style="background:<?php echo $fieldColors[$fieldIdx++ % count($fieldColors)]; ?>;">
        <div class="wo-label">Property Entry Permissions / Logs</div>
        <div class="wo-value">
            <strong>Permission</strong><br>
            <strong>Date:</strong> <?php echo htmlspecialchars($permissionDateValueDisplay, ENT_QUOTES, 'UTF-8'); ?><br>
            <strong>Time:</strong> <?php echo htmlspecialchars($permissionTimeValueDisplay, ENT_QUOTES, 'UTF-8'); ?>
            <hr style="border:0; border-top:1px solid #dbe3ec; margin:10px 0;">
            <details style="margin-top:10px;">
                <summary style="cursor:pointer; font-weight:700; color:#0f172a;">
                    Property Entry Log (<?php echo count($propertyEntryLogs); ?> visits)
                </summary>
            <?php if (!empty($activePropertyVisits)): ?>
                <div style="margin-top:8px; padding:10px 12px; border:1px solid #86efac; border-radius:7px; background:#f0fdf4; color:#166534; font-weight:700;">
                    <?php foreach ($activePropertyVisits as $activeVisitIndex => $openVisit): ?>
                        <?php if ($activeVisitIndex > 0) echo '<br>'; ?>
                        <?php
                            $openVisitTechnician = trim((string)($openVisit['technician_first'] ?? '') . ' ' . (string)($openVisit['technician_last'] ?? ''));
                            if ($openVisitTechnician === '') {
                                $openVisitTechnician = 'Technician';
                            }
                        ?>
                        <?php echo htmlspecialchars($openVisitTechnician . ' is checked in since ' . date('g:i A', strtotime($openVisit['time_entered'])) . ' on ' . format_entry_day($openVisit['entry_date'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
            <?php if (empty($propertyEntryLogs)): ?>
                <div style="margin-top:6px;">No property entry visits have been logged.</div>
            <?php else: ?>
                <div style="overflow-x:auto; margin-top:8px;">
                    <table style="width:100%; border-collapse:collapse; background:rgba(255,255,255,0.75);">
                        <thead>
                            <tr style="text-align:left; border-bottom:1px solid #cbd5e1;">
                                <th style="padding:8px;">Visit Date</th>
                                <th style="padding:8px;">Technician</th>
                                <th style="padding:8px;">Arrival</th>
                                <th style="padding:8px;">Departure</th>
                                <th style="padding:8px;">Record</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($propertyEntryLogs as $propertyLog): ?>
                                <tr style="border-bottom:1px solid #e2e8f0;">
                                    <td style="padding:8px;"><?php echo htmlspecialchars(format_entry_day($propertyLog['entry_date'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td style="padding:8px;"><?php echo htmlspecialchars(trim((string)($propertyLog['technician_first'] ?? '') . ' ' . (string)($propertyLog['technician_last'] ?? '')) ?: 'Not recorded', ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td style="padding:8px;"><?php echo htmlspecialchars(!empty($propertyLog['time_entered']) ? date('g:i A', strtotime($propertyLog['time_entered'])) : 'Not recorded', ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td style="padding:8px;"><?php
                                        if (!empty($propertyLog['time_departed'])) {
                                            $departureDisplay = date('g:i A', strtotime($propertyLog['time_departed']));
                                            if (!empty($propertyLog['departure_date']) && $propertyLog['departure_date'] !== ($propertyLog['entry_date'] ?? '')) {
                                                $departureDisplay = format_entry_day($propertyLog['departure_date']) . ' ' . $departureDisplay;
                                            }
                                        } else {
                                            $departureDisplay = 'Not recorded';
                                        }
                                        echo htmlspecialchars($departureDisplay, ENT_QUOTES, 'UTF-8');
                                    ?></td>
                                    <td style="padding:8px;"><?php
                                        $entryWasOfficeRecorded = ($propertyLog['entry_source'] ?? '') === 'office_manual';
                                        $departureWasOfficeRecorded = ($propertyLog['departure_source'] ?? '') === 'office_manual';
                                        $entryWasAdminButton = ($propertyLog['entry_source'] ?? '') === 'admin_button';
                                        $departureWasAdminButton = ($propertyLog['departure_source'] ?? '') === 'admin_button';
                                        if ($entryWasOfficeRecorded && $departureWasOfficeRecorded) {
                                            $visitRecordNote = 'Office-entered times';
                                        } elseif ($entryWasAdminButton && $departureWasAdminButton) {
                                            $visitRecordNote = 'Admin check-in and check-out';
                                        } elseif ($entryWasOfficeRecorded) {
                                            $visitRecordNote = 'Office-entered arrival';
                                        } elseif ($departureWasOfficeRecorded) {
                                            $visitRecordNote = 'Office-entered departure';
                                        } elseif ($entryWasAdminButton) {
                                            $visitRecordNote = 'Admin check-in';
                                            if ($departureWasAdminButton) {
                                                $visitRecordNote .= ' and check-out';
                                            } elseif (($propertyLog['departure_source'] ?? '') === 'technician_button') {
                                                $visitRecordNote .= ' and technician check-out';
                                            }
                                        } elseif (($propertyLog['entry_source'] ?? '') === 'technician_button') {
                                            $visitRecordNote = 'Technician check-in';
                                            if (($propertyLog['departure_source'] ?? '') === 'technician_button') {
                                                $visitRecordNote .= ' and check-out';
                                            } elseif ($departureWasAdminButton) {
                                                $visitRecordNote .= ' and admin check-out';
                                            }
                                        } elseif (($propertyLog['departure_source'] ?? '') === 'technician_button') {
                                            $visitRecordNote = 'Technician check-out';
                                        } else {
                                            $visitRecordNote = 'Earlier record (source unknown)';
                                        }
                                        echo htmlspecialchars($visitRecordNote, ENT_QUOTES, 'UTF-8');
                                    ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
            </details>
        </div>
    </div>
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

<?php if (($_GET['print'] ?? '') === '1'): ?>
<script>window.addEventListener('load', function () { window.setTimeout(function () { window.print(); }, 300); });</script>
<?php endif; ?>
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
    $completeHistoryEvents = [];
    foreach ($edits as $edit) {
        $completeHistoryEvents[] = [
            'when' => (string)($edit['edited_at'] ?? ''),
            'sort_id' => (int)($edit['id'] ?? 0),
            'who' => (($edit['firstname'] ?? '') ? ($edit['firstname'] . ' ' . ($edit['lastname'] ?? '')) : ('User ' . ($edit['edited_by'] ?? ''))),
            'event' => formatHistoryEventText($edit['field_name'] ?? '', $edit['old_value'] ?? '', $edit['new_value'] ?? '')
        ];
    }
    if ($createdAt) {
        $completeHistoryEvents[] = [
            'when' => (string)$createdAt,
            'sort_id' => 0,
            'who' => $creatorDisplay,
            'event' => 'Created work order'
        ];
    }
    usort($completeHistoryEvents, static function (array $a, array $b): int {
        $timeOrder = strcmp((string)$b['when'], (string)$a['when']);
        return $timeOrder !== 0 ? $timeOrder : ((int)$b['sort_id'] <=> (int)$a['sort_id']);
    });
    if ($createdAt || $creatorName || $edits): ?>
        <h3>Complete History</h3>
        <p style="margin:4px 0 8px; color:#666; font-size:0.95em;">Events: <?php echo count($completeHistoryEvents); ?></p>
        <div class="history-toggle-container">
            <button id="toggle-history-btn" class="small-toggle-btn history-toggle-btn" data-toggle-target="history-wrapper" aria-expanded="false" aria-controls="history-wrapper">Show History</button>
        </div>

        <div id="history-wrapper" style="display:none; margin-top:6px; padding-bottom:24px;">
        <table id="history-table" class="history-table" style="max-width:960px; margin:0 auto;">
            <thead><tr><th style="text-align:center; padding:6px; width:170px;">When</th><th style="text-align:center; padding:6px; width:200px;">Who</th><th style="text-align:center; padding:6px;">Event</th></tr></thead>
            <tbody>
            <?php foreach ($completeHistoryEvents as $historyEvent): ?>
                <tr>
                    <td style="padding:6px;"><?php echo htmlspecialchars($historyEvent['when'], ENT_QUOTES, 'UTF-8'); ?></td>
                    <td style="padding:6px;"><?php echo htmlspecialchars($historyEvent['who'], ENT_QUOTES, 'UTF-8'); ?></td>
                    <td style="padding:6px;"><?php echo htmlspecialchars($historyEvent['event'], ENT_QUOTES, 'UTF-8'); ?></td>
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
    $assignStmt = $conn->prepare("SELECT e.*, s.firstname, s.lastname FROM workorder_edits e LEFT JOIN staff s ON e.edited_by = s.id WHERE e.workorder_id = ? AND e.field_name = 'work_performed_by' ORDER BY e.edited_at DESC, e.id DESC");
    $assignStmt->execute([$id]);
    $assigns = $assignStmt->fetchAll(PDO::FETCH_ASSOC);

    $wpStmt = $conn->prepare("SELECT e.*, s.firstname, s.lastname FROM workorder_edits e LEFT JOIN staff s ON e.edited_by = s.id WHERE e.workorder_id = ? AND e.field_name IN ('work_description','time_entered','time_departed','vessel_hours','labor_time','parts_cost') ORDER BY e.edited_at DESC, e.id DESC");
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
        var statusGuideHideTimer = null;
        var statusGuidePinned = false;
        function positionStatusGuide() {
            var anchor = statusGuideToggle.getBoundingClientRect();
            var viewportWidth = document.documentElement.clientWidth || window.innerWidth;
            var viewportHeight = window.innerHeight;
            var panelWidth = Math.min(360, Math.max(220, viewportWidth - 24));

            statusGuidePanel.style.position = 'fixed';
            statusGuidePanel.style.zIndex = '1300';
            statusGuidePanel.style.width = panelWidth + 'px';
            statusGuidePanel.style.maxWidth = 'calc(100vw - 24px)';
            statusGuidePanel.style.maxHeight = Math.round(viewportHeight * 0.7) + 'px';
            statusGuidePanel.style.overflowY = 'auto';
            statusGuidePanel.style.margin = '0';
            statusGuidePanel.style.boxShadow = '0 12px 30px rgba(15,23,42,.24)';

            var panelHeight = statusGuidePanel.getBoundingClientRect().height;
            var left = Math.max(12, Math.min(anchor.left, viewportWidth - panelWidth - 12));
            var top = anchor.bottom + 8;
            if (top + panelHeight > viewportHeight - 12) {
                top = anchor.top - panelHeight - 8;
            }
            top = Math.max(12, Math.min(top, viewportHeight - panelHeight - 12));
            statusGuidePanel.style.left = left + 'px';
            statusGuidePanel.style.top = top + 'px';
        }
        function showStatusGuide() {
            if (statusGuideHideTimer) window.clearTimeout(statusGuideHideTimer);
            statusGuidePanel.style.display = 'block';
            positionStatusGuide();
            statusGuideToggle.setAttribute('aria-expanded', 'true');
        }
        function scheduleStatusGuideHide() {
            if (statusGuideHideTimer) window.clearTimeout(statusGuideHideTimer);
            statusGuideHideTimer = window.setTimeout(function () {
                if (!statusGuidePinned && !statusGuideToggle.matches(':hover') && !statusGuidePanel.matches(':hover') && !statusGuideToggle.matches(':focus')) {
                    statusGuidePanel.style.display = 'none';
                    statusGuideToggle.setAttribute('aria-expanded', 'false');
                }
            }, 300);
        }
        statusGuideToggle.addEventListener('click', function(){
            statusGuidePinned = !statusGuidePinned;
            if (statusGuidePinned) showStatusGuide();
            else scheduleStatusGuideHide();
        });
        statusGuideToggle.addEventListener('mouseenter', showStatusGuide);
        statusGuideToggle.addEventListener('mouseleave', scheduleStatusGuideHide);
        statusGuideToggle.addEventListener('focus', showStatusGuide);
        statusGuideToggle.addEventListener('blur', scheduleStatusGuideHide);
        statusGuidePanel.addEventListener('mouseenter', showStatusGuide);
        statusGuidePanel.addEventListener('mouseleave', scheduleStatusGuideHide);
        window.addEventListener('resize', function () {
            if (statusGuidePanel.style.display !== 'none') positionStatusGuide();
        });
        window.addEventListener('scroll', function () {
            if (statusGuidePanel.style.display !== 'none') positionStatusGuide();
        }, true);
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