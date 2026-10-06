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

function format_labor_total_seconds($seconds) {
    $seconds = (int)$seconds;
    return intdiv($seconds, 3600) . 'h ' . sprintf('%02d', intdiv($seconds % 3600, 60)) . 'm';
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
ensure_customer_asset_schema($conn);
ensure_property_entry_log_schema($conn);
ensure_workorder_technicians_schema($conn);

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

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
sync_primary_workorder_technician($conn, $id, $wo['work_performed_by'] ?? 0);

if ($currentRole === '' && $userId > 0) {
    $roleStmt = $conn->prepare('SELECT role FROM staff WHERE id = ? LIMIT 1');
    $roleStmt->execute([$userId]);
    $roleRow = $roleStmt->fetch(PDO::FETCH_ASSOC);
    if ($roleRow) {
        $currentRole = strtolower(trim((string)($roleRow['role'] ?? '')));
        $_SESSION['role'] = $currentRole;
    }
}

if ($currentRole === 'customer') {
    http_response_code(403);
    exit('Customers cannot edit work orders.');
}
if (!in_array($currentRole, ['admin', 'office', 'technician', 'staff'], true)) {
    http_response_code(403);
    exit('You do not have permission to edit this work order.');
}
if ($currentRole === 'technician' && !is_workorder_technician_assigned($conn, $id, $userId, $wo['work_performed_by'] ?? 0)) {
    http_response_code(403);
    exit('This work order must be assigned to you before you can edit it.');
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
// Add columns individually so one unsupported/already-existing column cannot skip later migrations.
$workorderColumnsToEnsure = [
    'order_number' => 'VARCHAR(100) DEFAULT NULL',
    'service_type' => 'VARCHAR(100) DEFAULT NULL',
    'equipment_details' => 'VARCHAR(255) DEFAULT NULL',
    'work_location' => 'VARCHAR(30) DEFAULT NULL',
    'service_call_fee' => 'DECIMAL(10,2) NOT NULL DEFAULT 0',
    'special_instructions' => 'LONGTEXT DEFAULT NULL',
    'permission_time_relation' => 'VARCHAR(10) DEFAULT NULL',
    'permission_anytime_with_time' => 'TINYINT(1) NOT NULL DEFAULT 0',
    'entry_date' => 'DATE DEFAULT NULL',
    'time_entered' => 'TIME DEFAULT NULL',
    'time_departed' => 'TIME DEFAULT NULL'
];
$existingWorkorderColumnRows = $conn->query('SHOW COLUMNS FROM workorders')->fetchAll(PDO::FETCH_ASSOC);
$existingWorkorderColumnNames = array_column($existingWorkorderColumnRows, 'Field');
foreach ($workorderColumnsToEnsure as $columnName => $columnDefinition) {
    if (!in_array($columnName, $existingWorkorderColumnNames, true)) {
        $conn->exec("ALTER TABLE workorders ADD COLUMN `$columnName` $columnDefinition");
        $existingWorkorderColumnNames[] = $columnName;
    }
}

$conn->exec("INSERT INTO workorder_property_entry_logs (workorder_id, entry_date, time_entered, time_departed, logged_by)
    SELECT w.id, w.entry_date, NULLIF(w.time_entered, '00:00:00'), NULLIF(w.time_departed, '00:00:00'), w.order_received_by
    FROM workorders w
    WHERE w.entry_date IS NOT NULL AND w.entry_date <> '0000-00-00'
      AND NOT EXISTS (SELECT 1 FROM workorder_property_entry_logs l WHERE l.workorder_id = w.id)");

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
if (!$hasPriority) {
    try {
        $conn->exec("ALTER TABLE workorders ADD COLUMN priority VARCHAR(20) NOT NULL DEFAULT 'Normal'");
        $hasPriority = true;
    } catch (Exception $e) {
        $hasPriority = false;
    }
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
$propertyLogMessage = !empty($_GET['property_log_saved']) ? 'Visit times and caller details saved.' : '';
$propertyLogDateValue = '';
$propertyLogDepartureDateValue = '';
$propertyLogTechnicianValue = '';
$propertyLogEnteredValue = '';
$propertyLogDepartedValue = '';
$propertyLogCallerNameValue = '';
$propertyLogCallReceivedValue = '';
$isAssignedTechnician = ($currentRole === 'technician' && is_workorder_technician_assigned($conn, $id, $userId, $wo['work_performed_by'] ?? 0));
$techAssignmentWarning = ($currentRole === 'technician' && !$isAssignedTechnician) ? 'This work order must be assigned to you to edit.' : '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !hash_equals($_SESSION['csrf_token'], (string)($_POST['csrf_token'] ?? ''))) {
    $message = 'Your session could not be verified. Please reload the form and try again.';
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['property_log_action'] ?? '') === 'add') {
    $propertyLogDateValue = trim((string)($_POST['property_entry_date'] ?? ''));
    $propertyLogDepartureDateValue = trim((string)($_POST['property_departure_date'] ?? ''));
    $propertyLogTechnicianValue = trim((string)($_POST['property_log_technician_id'] ?? ''));
    $propertyLogEnteredValue = trim((string)($_POST['property_time_entered'] ?? ''));
    $propertyLogDepartedValue = trim((string)($_POST['property_time_departed'] ?? ''));
    $propertyLogCallerNameValue = trim((string)($_POST['property_call_reporter'] ?? ''));
    $propertyLogCallReceivedValue = trim((string)($_POST['property_call_received_at'] ?? ''));

    $dateValue = DateTime::createFromFormat('!Y-m-d', $propertyLogDateValue);
    $validDate = $dateValue && $dateValue->format('Y-m-d') === $propertyLogDateValue;
    $resolvedDepartureDate = $propertyLogDepartureDateValue !== '' ? $propertyLogDepartureDateValue : $propertyLogDateValue;
    $departureDateValue = DateTime::createFromFormat('!Y-m-d', $resolvedDepartureDate);
    $validDepartureDate = $propertyLogDepartedValue === '' || ($departureDateValue && $departureDateValue->format('Y-m-d') === $resolvedDepartureDate && $resolvedDepartureDate >= $propertyLogDateValue);
    $validEnteredTime = $propertyLogEnteredValue === '' || preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $propertyLogEnteredValue);
    $validDepartedTime = $propertyLogDepartedValue === '' || preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $propertyLogDepartedValue);
    $callReceivedAt = DateTime::createFromFormat('!Y-m-d\TH:i', $propertyLogCallReceivedValue);
    $validCallReceivedAt = $callReceivedAt && $callReceivedAt->format('Y-m-d\TH:i') === $propertyLogCallReceivedValue;
    $manualTechnicianId = (int)$propertyLogTechnicianValue;
    $manualTechnicianCheck = $conn->prepare("SELECT id FROM staff WHERE id = ? AND LOWER(TRIM(COALESCE(role, ''))) IN ('admin', 'technician', 'staff', '') LIMIT 1");
    $manualTechnicianCheck->execute([$manualTechnicianId]);
    $validVisitTechnician = $manualTechnicianId > 0 && (bool)$manualTechnicianCheck->fetchColumn();

    if ($currentRole !== 'admin') {
        http_response_code(403);
        $propertyLogMessage = 'Only an admin can manually enter property visit times.';
    } elseif (!$validDate || !$validDepartureDate || !$validEnteredTime || !$validDepartedTime || ($propertyLogEnteredValue === '' && $propertyLogDepartedValue === '') || !$validVisitTechnician || $propertyLogCallerNameValue === '' || !$validCallReceivedAt) {
        $propertyLogMessage = 'Choose the assigned technician, enter valid visit dates/times, the caller name, and when the call was received.';
    } else {
        if ($propertyLogEnteredValue === '' && $propertyLogDepartedValue !== '') {
            $openVisitStmt = $conn->prepare('SELECT id FROM workorder_property_entry_logs WHERE workorder_id = ? AND COALESCE(technician_id, logged_by) = ? AND time_entered IS NOT NULL AND time_departed IS NULL ORDER BY id DESC LIMIT 1');
            $openVisitStmt->execute([$id, $manualTechnicianId]);
            $openVisitId = (int)$openVisitStmt->fetchColumn();
        } else {
            $openVisitId = 0;
        }
        if ($openVisitId > 0) {
            $closeVisitStmt = $conn->prepare("UPDATE workorder_property_entry_logs SET departure_date = ?, time_departed = ?, departed_by = ?, departure_source = 'office_manual', departure_reporter_name = ?, departure_reported_at = ? WHERE id = ? AND workorder_id = ? AND time_departed IS NULL");
            $closeVisitStmt->execute([$resolvedDepartureDate, $propertyLogDepartedValue, $userId ?: null, $propertyLogCallerNameValue, $callReceivedAt->format('Y-m-d H:i:s'), $openVisitId, $id]);
        } else {
            $addPropertyLog = $conn->prepare('INSERT INTO workorder_property_entry_logs (workorder_id, technician_id, entry_date, departure_date, time_entered, time_departed, logged_by, entry_source, departed_by, departure_source, entry_reporter_name, entry_reported_at, departure_reporter_name, departure_reported_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
            $addPropertyLog->execute([
                $id,
                $manualTechnicianId,
                $propertyLogDateValue,
                $propertyLogDepartedValue !== '' ? $resolvedDepartureDate : null,
                $propertyLogEnteredValue !== '' ? $propertyLogEnteredValue : null,
                $propertyLogDepartedValue !== '' ? $propertyLogDepartedValue : null,
                $userId ?: null,
                $propertyLogEnteredValue !== '' ? 'office_manual' : 'manual',
                $propertyLogDepartedValue !== '' ? ($userId ?: null) : null,
                $propertyLogDepartedValue !== '' ? 'office_manual' : null,
                $propertyLogEnteredValue !== '' ? $propertyLogCallerNameValue : null,
                $propertyLogEnteredValue !== '' ? $callReceivedAt->format('Y-m-d H:i:s') : null,
                $propertyLogDepartedValue !== '' ? $propertyLogCallerNameValue : null,
                $propertyLogDepartedValue !== '' ? $callReceivedAt->format('Y-m-d H:i:s') : null
            ]);
        }
        $billableSeconds = 0;
        foreach (property_visit_billable_seconds_by_technician($conn, $id) as $technicianBillableTotal) {
            $billableSeconds += (int)($technicianBillableTotal['total_seconds'] ?? 0);
        }
        $billableLaborTime = intdiv($billableSeconds, 3600) . 'h ' . sprintf('%02d', intdiv($billableSeconds % 3600, 60)) . 'm';
        $updateBillableLabor = $conn->prepare('UPDATE workorders SET labor_time = ? WHERE id = ?');
        $updateBillableLabor->execute([$billableLaborTime, $id]);
        header('Location: /sps/pages/edit_workorder.php?id=' . $id . '&property_log_saved=1');
        exit;
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
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
            $assetHoursInput = trim((string)($_POST['asset_hours'] ?? ''));
            $validAssetHours = $assetHoursInput === '' || (is_numeric($assetHoursInput) && (float)$assetHoursInput >= 0);

            if ($performedDate !== '' && ($performedHours > 0 || $performedMinutes > 0) && $performedDescription !== '' && $validAssetHours) {
                $insertEntry = $conn->prepare('INSERT INTO work_performed_entries (workorder_id, performed_date, performed_time, description, added_by) VALUES (?, ?, ?, ?, ?)');
                $insertEntry->execute([$id, $performedDate, $performedTime, $performedDescription, $userId]);
                if ($assetHoursInput !== '') {
                    $updateWorkorderHours = $conn->prepare('UPDATE workorders SET vessel_hours = ? WHERE id = ?');
                    $updateWorkorderHours->execute([(float)$assetHoursInput, $id]);
                    if (!empty($wo['asset_id']) && !empty($wo['customer_id'])) {
                        $updateCustomerAssetHours = $conn->prepare('UPDATE customer_assets SET hours = ?, updated_at = NOW() WHERE id = ? AND customer_id = ?');
                        $updateCustomerAssetHours->execute([(float)$assetHoursInput, (int)$wo['asset_id'], (int)$wo['customer_id']]);
                    }
                    $wo['vessel_hours'] = (float)$assetHoursInput;
                }
                $customerIdForEntry = (int)($wo['customer_id'] ?? 0);
                if ($customerIdForEntry > 0) {
                    $entryStatus = trim((string)($wo['status'] ?? 'Updated'));
                    $entryHoursText = $performedHours . 'h ' . sprintf('%02d', $performedMinutes) . 'm';
                    $entryNotice = 'A technician logged work performed on ' . date('M j, Y', strtotime($performedDate)) . ' (' . $entryHoursText . ').';
                    try {
                        notify_customer_workorder_update($conn, $customerIdForEntry, $id, $entryStatus, $entryNotice);
                    } catch (Throwable $notificationError) {
                        error_log('Customer work-entry notification failed: ' . $notificationError->getMessage());
                    }
                }
                if ($currentRole === 'technician') {
                    $orderLabel = trim((string)($wo['order_number'] ?? '')) ?: ('WO' . str_pad((string)$id, 4, '0', STR_PAD_LEFT));
                    try {
                        notify_staff_roles($conn, ['admin', 'office'], 'technician_work_entry', 'Technician work logged: ' . $orderLabel, 'A technician added a work-performed update to work order ' . $orderLabel . '.', '/sps/pages/view_workorder.php?id=' . $id);
                    } catch (Throwable $notificationError) {
                        error_log('Technician work-entry notification failed: ' . $notificationError->getMessage());
                    }
                }

                $techEntryMessage = 'Work performed entry added successfully.';
                header('Location: /sps/pages/edit_workorder.php?id=' . $id . '&entry_saved=1');
                exit;
            }

            $techEntryMessage = $validAssetHours
                ? 'Please enter a date, time spent, and description for this work entry.'
                : 'Enter a valid non-negative asset-hours value.';
        }
    }

    if ($currentRole === 'technician' && !$isAssignedTechnician && empty($_POST['tech_action'])) {
        $message = $techAssignmentWarning !== '' ? $techAssignmentWarning : 'You can only edit work orders assigned to you.';
    }

    // define allowed fields per role
    $allFields = [
        'status','priority',
        'client_name','client_phone','location','order_date','service_type','equipment_details','work_location','service_call_fee','special_instructions', 'expected_start_date','expected_end_date',
        'requested_work','additional_comments','vessel_vin','vessel_hours','parts_cost',
        'chargeable_to','order_received_by','work_performed_by','permission_anytime','permission_anytime_with_time','permission_date','permission_time',
        'permission_time_relation','order_number'
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
        $allowed = ['status','client_name','client_phone','location','order_date','service_type','equipment_details','work_location','service_call_fee','special_instructions', 'expected_start_date','expected_end_date',
            'requested_work','additional_comments','parts_cost','chargeable_to','permission_anytime','permission_anytime_with_time','permission_date','permission_time','permission_time_relation',
            'work_performed_by'];
        if ($hasPriority) { $allowed[] = 'priority'; }
        if ($hasOrderNumber) { $allowed[] = 'order_number'; }
    } elseif ($currentRole === 'staff') {
        $allowed = [];
        $message = 'You do not have permission to edit this work order.';
    } elseif ($currentRole === 'technician') {
        if (!$isAssignedTechnician) {
            $allowed = [];
            $message = $techAssignmentWarning !== '' ? $techAssignmentWarning : 'You can only edit work orders assigned to you.';
        } else {
            $allowed = ['vessel_vin','vessel_hours','work_performed_by'];
            if ($hasStatus) { $allowed[] = 'status'; }
        }
    } else {
        $message = 'You do not have permission to edit this work order.';
        $allowed = [];
    }

    if (in_array('work_location', $allowed, true) && array_key_exists('work_location', $_POST)) {
        $postedWorkLocation = trim((string)$_POST['work_location']);
        if (!in_array($postedWorkLocation, ['', 'shop', 'customer_property'], true)) {
            $message = 'Select a valid work location.';
            $allowed = [];
        } else {
            $_POST['work_location'] = $postedWorkLocation;
            $postedServiceType = trim((string)($_POST['service_type'] ?? $wo['service_type'] ?? ''));
            $_POST['service_call_fee'] = ($postedServiceType === 'Service Call' && $postedWorkLocation === 'customer_property') ? '75.00' : '0.00';
        }
    }

    $changes = [];
    $updateParts = [];
    $params = [];

    $permissionTimeChoiceRaw = (string)($_POST['permission_time_choice'] ?? '');
    $permissionTimeChoice = $permissionTimeChoiceRaw === 'yes';
    $permissionTimeChoiceInvalid = in_array($currentRole, ['admin', 'office'], true) && empty($_POST['tech_action']) && (
        !in_array($permissionTimeChoiceRaw, ['yes', 'no'], true)
        || ($permissionTimeChoice && trim((string)($_POST['permission_time'] ?? '')) === '')
    );
    if ($permissionTimeChoiceInvalid) {
        $message = 'Choose whether to set a permission time, and enter it if you choose Yes.';
        $allowed = [];
    }
    if (!$permissionTimeChoice) {
        $_POST['permission_time'] = '';
        $_POST['permission_time_relation'] = '';
    }
    if (in_array('vessel_hours', $allowed, true) && array_key_exists('vessel_hours', $_POST)) {
        $submittedVesselHours = trim((string)$_POST['vessel_hours']);
        if ($submittedVesselHours !== '' && (!is_numeric($submittedVesselHours) || (float)$submittedVesselHours < 0)) {
            $message = 'Enter a valid non-negative vessel-hours value.';
            $allowed = [];
        }
    }
    if (in_array('priority', $allowed, true) && array_key_exists('priority', $_POST)
        && !in_array((string)$_POST['priority'], ['Low', 'Normal', 'High', 'Urgent', 'Emergency'], true)) {
        $message = 'Select a valid work-order priority.';
        $allowed = [];
    }
    if (!empty($_POST['permission_anytime'])) {
        $_POST['permission_date'] = '';
    }

    $oldAssignedTech = (int)($wo['work_performed_by'] ?? 0);
    $newAssignedTech = isset($_POST['work_performed_by']) ? (int)($_POST['work_performed_by'] ?? 0) : $oldAssignedTech;
    $saveTechnicianTeam = in_array($currentRole, ['admin', 'office'], true) && array_key_exists('additional_technicians', $_POST) && !empty($allowed);
    $newAdditionalTechnicianIds = [];
    if ($saveTechnicianTeam) {
        foreach ((array)$_POST['additional_technicians'] as $candidateId) {
            $candidateId = (int)$candidateId;
            if ($candidateId > 0 && $candidateId !== $newAssignedTech && !in_array($candidateId, $newAdditionalTechnicianIds, true)) {
                $teamMemberCheck = $conn->prepare("SELECT id FROM staff WHERE id = ? AND LOWER(TRIM(COALESCE(role, ''))) IN ('admin', 'technician', 'staff', '') LIMIT 1");
                $teamMemberCheck->execute([$candidateId]);
                if ($teamMemberCheck->fetchColumn()) {
                    $newAdditionalTechnicianIds[] = $candidateId;
                } else {
                    $message = 'One or more selected team members are not valid technicians.';
                    $allowed = [];
                    $saveTechnicianTeam = false;
                    break;
                }
            }
        }
    }
    $currentAdditionalTechnicianIds = array_values(array_diff(
        get_workorder_technician_ids($conn, $id, $oldAssignedTech),
        $oldAssignedTech > 0 ? [$oldAssignedTech] : []
    ));
    sort($currentAdditionalTechnicianIds);
    $comparisonAdditionalTechnicianIds = $newAdditionalTechnicianIds;
    sort($comparisonAdditionalTechnicianIds);
    $technicianTeamChanged = $saveTechnicianTeam && (
        $oldAssignedTech !== $newAssignedTech || $currentAdditionalTechnicianIds !== $comparisonAdditionalTechnicianIds
    );

    foreach ($allowed as $field) {
        if (!in_array($field, ['permission_anytime', 'permission_anytime_with_time'], true) && !array_key_exists($field, $_POST)) {
            continue;
        }
        $new = $_POST[$field] ?? null;
        if (in_array($field, ['permission_anytime', 'permission_anytime_with_time'], true)) {
            $new = isset($_POST['permission_anytime']) ? 1 : 0;
            if ($field === 'permission_anytime_with_time') {
                $new = isset($_POST['permission_anytime'])
                    && $permissionTimeChoice
                    && trim((string)($_POST['permission_time'] ?? '')) !== ''
                    ? 1
                    : 0;
            }
        }
        if ($field === 'permission_time_relation') {
            $new = strtolower(trim((string)($new ?? '')));
            $permissionTimeValue = trim((string)($_POST['permission_time'] ?? $wo['permission_time'] ?? ''));
            if (!in_array($new, ['at', 'before', 'after'], true) || $permissionTimeValue === '') {
                $new = null;
            }
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

    if (!empty($updateParts) || $technicianTeamChanged) {
        if (!empty($updateParts)) {
            $params[] = $id;
            $sql = 'UPDATE workorders SET ' . implode(', ', $updateParts) . ' WHERE id = ?';
            $upd = $conn->prepare($sql);
            $upd->execute($params);
        }

        if ($saveTechnicianTeam) {
            $conn->prepare('DELETE FROM workorder_technicians WHERE workorder_id = ?')->execute([$id]);
            $insertTeamMember = $conn->prepare('INSERT INTO workorder_technicians (workorder_id, technician_id, assigned_by) VALUES (?, ?, ?)');
            foreach ($newAdditionalTechnicianIds as $teamTechnicianId) {
                $insertTeamMember->execute([$id, $teamTechnicianId, $userId ?: null]);
            }
            sync_primary_workorder_technician($conn, $id, $newAssignedTech);

            $teamAudit = $conn->prepare('INSERT INTO workorder_edits (workorder_id, field_name, old_value, new_value, edited_by) VALUES (?, ?, ?, ?, ?)');
            foreach (array_diff($currentAdditionalTechnicianIds, $newAdditionalTechnicianIds) as $removedTechnicianId) {
                $teamAudit->execute([$id, 'additional_technician', (string)$removedTechnicianId, null, $userId ?: null]);
            }
            foreach (array_diff($newAdditionalTechnicianIds, $currentAdditionalTechnicianIds) as $addedTechnicianId) {
                $teamAudit->execute([$id, 'additional_technician', null, (string)$addedTechnicianId, $userId ?: null]);
            }
            $newlyAssignedTechnicianIds = array_values(array_unique(array_filter(array_merge(
                array_diff([$newAssignedTech], [$oldAssignedTech]),
                array_diff($newAdditionalTechnicianIds, $currentAdditionalTechnicianIds)
            ))));
            $workOrderLabel = trim((string)($wo['order_number'] ?? '')) ?: ('WO' . str_pad((string)$id, 4, '0', STR_PAD_LEFT));
            foreach ($newlyAssignedTechnicianIds as $newTechnicianId) {
                try {
                    notify_assigned_technician($conn, (int)$id, (int)$newTechnicianId, $workOrderLabel);
                } catch (Throwable $notificationError) {
                    error_log('Work-order assignment notification failed: ' . $notificationError->getMessage());
                }
            }
        }

        foreach ($changes as $change) {
            if ($change['field'] === 'vessel_hours' && $change['new'] !== null && !empty($wo['asset_id']) && !empty($wo['customer_id'])) {
                $syncAssetHours = $conn->prepare('UPDATE customer_assets SET hours = ?, updated_at = NOW() WHERE id = ? AND customer_id = ?');
                $syncAssetHours->execute([(float)$change['new'], (int)$wo['asset_id'], (int)$wo['customer_id']]);
                break;
            }
        }

        $customerIdForNotice = (int)($wo['customer_id'] ?? 0);
        $statusNotice = trim((string)($_POST['status'] ?? ($wo['status'] ?? 'Updated')));
        $customerChangeSummary = customer_workorder_change_summary($changes);
        if ($customerIdForNotice > 0 && $customerChangeSummary !== '') {
            try {
            notify_customer_workorder_update($conn, $customerIdForNotice, $id, $statusNotice, $customerChangeSummary);
            } catch (Throwable $notificationError) {
                error_log('Customer work-order notification failed: ' . $notificationError->getMessage());
            }
        }
        if ($currentRole === 'technician' && !empty($changes)) {
            $orderLabel = trim((string)($wo['order_number'] ?? '')) ?: ('WO' . str_pad((string)$id, 4, '0', STR_PAD_LEFT));
            $changedFields = implode(', ', array_map(static function ($change) { return (string)$change['field']; }, $changes));
            try {
                notify_staff_roles($conn, ['admin', 'office'], 'technician_workorder_update', 'Technician updated ' . $orderLabel, 'A technician updated ' . $changedFields . ' on work order ' . $orderLabel . '.', '/sps/pages/view_workorder.php?id=' . $id);
            } catch (Throwable $notificationError) {
                error_log('Technician work-order notification failed: ' . $notificationError->getMessage());
            }
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
        if ($message === '') {
            $message = 'No changes detected.';
        }
    }
}

// fetch staff for selects
$staff = $conn->query('SELECT id, firstname, lastname, role FROM staff ORDER BY firstname, lastname')->fetchAll(PDO::FETCH_ASSOC);
$currentWorkorderTechnicianIds = get_workorder_technician_ids($conn, $id, $wo['work_performed_by'] ?? 0);

$techWorkEntries = [];
if (in_array($currentRole, ['admin', 'technician'], true)) {
    $techWorkEntriesStmt = $conn->prepare('SELECT wpe.*, s.firstname, s.lastname FROM work_performed_entries wpe LEFT JOIN staff s ON s.id = wpe.added_by WHERE wpe.workorder_id = ? ORDER BY wpe.performed_date DESC, wpe.created_at DESC');
    $techWorkEntriesStmt->execute([$id]);
    $techWorkEntries = $techWorkEntriesStmt->fetchAll(PDO::FETCH_ASSOC);
}
$laborTimeSeconds = 0;
foreach (property_visit_billable_seconds_by_technician($conn, $id, true) as $technicianBillableTotal) {
    $laborTimeSeconds += (int)($technicianBillableTotal['total_seconds'] ?? 0);
}
$laborTimeSummary = format_labor_total_seconds($laborTimeSeconds);
$linkedCustomerAssetHours = null;
$linkedCustomerAssetSerial = '';
if (!empty($wo['asset_id']) && !empty($wo['customer_id'])) {
    $linkedAssetHoursStmt = $conn->prepare('SELECT hours, serial_number FROM customer_assets WHERE id = ? AND customer_id = ? LIMIT 1');
    $linkedAssetHoursStmt->execute([(int)$wo['asset_id'], (int)$wo['customer_id']]);
    $linkedAssetDetails = $linkedAssetHoursStmt->fetch(PDO::FETCH_ASSOC) ?: [];
    $linkedCustomerAssetHours = $linkedAssetDetails['hours'] ?? null;
    $linkedCustomerAssetSerial = trim((string)($linkedAssetDetails['serial_number'] ?? ''));
}
$assetHoursCurrentValue = ($linkedCustomerAssetHours !== false && $linkedCustomerAssetHours !== null)
    ? (string)$linkedCustomerAssetHours
    : (string)($wo['vessel_hours'] ?? '');
$equipmentNameValue = customer_asset_display_without_serial($wo['equipment_details'] ?? '');
$vesselVinValue = trim((string)($wo['vessel_vin'] ?? '')) ?: $linkedCustomerAssetSerial;

$propertyLogsStmt = $conn->prepare('SELECT l.*, t.firstname AS technician_first, t.lastname AS technician_last, s.firstname AS recorder_first, s.lastname AS recorder_last, d.firstname AS departed_first, d.lastname AS departed_last FROM workorder_property_entry_logs l LEFT JOIN staff t ON t.id = l.technician_id LEFT JOIN staff s ON s.id = l.logged_by LEFT JOIN staff d ON d.id = l.departed_by WHERE l.workorder_id = ? ORDER BY l.entry_date DESC, l.time_entered DESC, l.id DESC');
$propertyLogsStmt->execute([$id]);
$propertyEntryLogs = $propertyLogsStmt->fetchAll(PDO::FETCH_ASSOC);

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

<?php if ($message): ?><p style="color: #b45309; font-weight: 700; background: #fff7ed; border: 1px solid #fdba74; padding: 10px 12px; border-radius: 6px; max-width: 1040px; width:calc(100% - 32px); box-sizing:border-box; margin: 12px auto 0;"><?php echo htmlspecialchars($message, ENT_QUOTES, 'UTF-8'); ?></p><?php endif; ?>
<?php $isTechBlocked = ($currentRole === 'technician' && !$isAssignedTechnician); ?>
<?php if ($isTechBlocked): ?>
    <div style="max-width: 1040px; width:calc(100% - 32px); box-sizing:border-box; margin: 20px auto 0; padding: 14px 16px; border: 1px solid #fbbf24; border-radius: 8px; background: #fff7ed; color: #92400e; font-weight: 700;">
        <?php echo htmlspecialchars($techAssignmentWarning, ENT_QUOTES, 'UTF-8'); ?>
    </div>
    <p style="max-width: 1040px; margin: 16px auto 0;"><a href="/sps/pages/view_workorder.php?id=<?php echo (int)$wo['id']; ?>">← Back to Work Order</a></p>
<?php else: ?>
<style>
    form.edit-form { max-width: 1040px; width:calc(100% - 32px); margin: 12px auto 40px; font-family: Arial, sans-serif; padding-bottom: 48px; box-sizing: border-box; }
    form.edit-form fieldset { padding: 9px 11px; margin-bottom: 9px; border-radius: 6px; border: 1px solid #d0d7de; }
    form.edit-form fieldset:nth-of-type(odd) { background: #ffffff; }
    form.edit-form fieldset:nth-of-type(even) { background: #f7fbff; }
    form.edit-form legend { font-weight: bold; padding: 0 6px; }
    form.edit-form label { display:block; margin:4px 0; }
    form.edit-form input[type="text"], form.edit-form input[type="date"], form.edit-form input[type="time"], form.edit-form input[type="number"], form.edit-form select, form.edit-form textarea { width:100%; box-sizing:border-box; min-height:34px; padding:5px 7px; border:1px solid #cbd5e0; border-radius:4px; background:#fff; color:#0f172a; font:13px Arial, sans-serif; line-height:20px; }
    form.edit-form input[type="date"], form.edit-form input[type="time"] { height:34px; }
    form.edit-form input[type="date"]::-webkit-datetime-edit,
    form.edit-form input[type="time"]::-webkit-datetime-edit { padding:0; }
    form.edit-form input[type="date"]::-webkit-calendar-picker-indicator,
    form.edit-form input[type="time"]::-webkit-calendar-picker-indicator { margin:0; padding:0; cursor:pointer; }
    form.edit-form .client-order-grid { display:grid; grid-template-columns:repeat(4, minmax(0, 1fr)); column-gap:10px; row-gap:0; align-items:start; }
    form.edit-form .client-order-grid .labor-time-field { max-width:220px; }
    form.edit-form .work-comments-grid,
    form.edit-form .assignment-grid,
    form.edit-form .property-permission-grid,
    form.edit-form .costs-grid { display:grid; grid-template-columns:repeat(3, minmax(0, 1fr)); column-gap:10px; row-gap:0; align-items:start; }
    form.edit-form .work-performed-section { display:block; width:100%; clear:both; }
    form.edit-form .status-priority-row { display:grid; grid-template-columns:minmax(0, 2fr) minmax(100px, 1fr); gap:8px; grid-column:span 2; width:100%; max-width:560px; }
    form.edit-form .status-priority-row label { margin:2px 0 4px; }
    form.edit-form .status-priority-row select { max-width:100%; padding:5px 7px; }
    form.edit-form .field-span-2 { grid-column:span 2; }
    form.edit-form .field-span-3,
    form.edit-form .field-span-all { grid-column:1 / -1; }
    form.edit-form .client-order-grid input[name="client_phone"],
    form.edit-form .property-permission-grid input[type="time"],
    form.edit-form .costs-grid input[name="parts_cost"] { max-width:240px; }
    form.edit-form .assignment-grid select[name="work_performed_by"] { max-width:260px; }
    form.edit-form .assignment-grid input[name="vessel_vin"] { max-width:220px; }
    form.edit-form .assignment-grid input[name="vessel_hours"] { max-width:150px; }
    form.edit-form .client-order-grid input[name="client_phone"] { max-width:190px; }
    form.edit-form .client-order-grid .work-order-number-field { width:100%; max-width:190px; }
    form.edit-form .client-order-grid input[name="order_number"] { width:100%; max-width:170px; }
    form.edit-form .work-comments-grid textarea,
    form.edit-form .work-performed-section .work-performed-panel,
    form.edit-form .work-performed-section .work-performed-history,
    form.edit-form .property-permission-grid .property-entry-history,
    form.edit-form .property-permission-grid .property-entry-add { grid-column:1 / -1; }
    form.edit-form .work-performed-inputs { display:grid; grid-template-columns:repeat(3, minmax(0, 1fr)) auto; gap:10px; align-items:end; }
    form.edit-form .work-comments-grid label { grid-column:1 / -1; }
    form.edit-form .property-permission-grid > p { grid-column:1 / -1; }
    form.edit-form .property-permission-grid .permission-time-fields { display:grid; grid-template-columns:repeat(2, minmax(0, 1fr)); gap:12px; grid-column:1 / -1; max-width:560px; }
    form.edit-form .property-permission-grid .permission-time-fields label { margin:4px 0 8px; }
    form.edit-form .property-permission-grid .permission-time-choice { max-width:360px; }
    form.edit-form .property-permission-grid .property-entry-add { display:grid; grid-template-columns:repeat(3, minmax(0, 1fr)) auto; gap:12px; align-items:end; }
    form.edit-form .costs-grid input[name="chargeable_to"] { max-width:420px; }
    form.edit-form textarea { min-height:58px; }
    form.edit-form .actions { margin-top:12px; }
    form.edit-form .actions button { padding:10px 16px; background:#007BFF; color:#fff; border:none; border-radius:4px; cursor:pointer; }
    form.edit-form .actions a { margin-left:10px; color:#333; text-decoration:none; }
    @media (max-width: 520px) {
        form.edit-form .client-order-grid,
        form.edit-form .work-comments-grid,
        form.edit-form .assignment-grid,
        form.edit-form .property-permission-grid,
        form.edit-form .costs-grid { grid-template-columns:1fr; }
        form.edit-form .field-span-2,
        form.edit-form .field-span-3,
        form.edit-form .field-span-all,
        form.edit-form .status-priority-row,
        form.edit-form .work-comments-grid textarea,
        form.edit-form .work-performed-section .work-performed-panel,
        form.edit-form .work-performed-section .work-performed-history,
        form.edit-form .property-permission-grid .property-entry-history,
        form.edit-form .property-permission-grid .property-entry-add,
        form.edit-form .property-permission-grid .permission-time-fields { grid-column:1; }
        form.edit-form .status-priority-row,
        form.edit-form .property-permission-grid .permission-time-fields,
        form.edit-form .property-permission-grid .property-entry-add,
        form.edit-form .work-performed-inputs { grid-template-columns:1fr; gap:6px; }
    }
    @media (min-width: 521px) and (max-width: 760px) {
        form.edit-form .client-order-grid { grid-template-columns:repeat(2, minmax(0, 1fr)); }
        form.edit-form .field-span-3 { grid-column:1 / -1; }
    }
</style>

<form id="add-work-performed-form" method="post"><input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8'); ?>"></form>
<form method="post" class="edit-form">
    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8'); ?>">
    <input type="hidden" name="id" value="<?php echo (int)$wo['id']; ?>">

    <fieldset class="client-order-grid" style="padding:10px; margin-bottom:15px;">
        <legend>Client / Order</legend>
        <?php if ($currentRole !== 'technician'): ?>
        <label class="labor-time-field">Total Technician Time<br><input type="text" value="<?php echo htmlspecialchars($laborTimeSummary, ENT_QUOTES, 'UTF-8'); ?>" readonly><small style="display:block;color:#64748b;">Sum of each technician’s property arrival-to-departure time.</small></label>
        <?php endif; ?>
        <div class="status-priority-row">
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
            <?php $curPriority = $wo['priority'] ?? 'Normal'; $priorities = ['Low','Normal','High','Urgent','Emergency']; if ($curPriority !== '' && !in_array($curPriority, $priorities, true)) { $priorities[] = $curPriority; } ?>
            <select name="priority">
                <?php foreach ($priorities as $p): ?>
                    <option value="<?php echo htmlspecialchars($p, ENT_QUOTES, 'UTF-8'); ?>" <?php echo ($curPriority === $p) ? 'selected' : ''; ?>><?php echo htmlspecialchars($p, ENT_QUOTES, 'UTF-8'); ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <?php elseif ($hasPriority): ?>
        <label>Priority<br><input type="text" value="<?php echo htmlspecialchars((string)($wo['priority'] ?? 'Normal'), ENT_QUOTES, 'UTF-8'); ?>" readonly aria-readonly="true"></label>
        <?php endif; ?>
        </div>
        <?php if (($currentRole === 'admin' || $currentRole === 'office') && $hasOrderNumber): ?>
        <label class="work-order-number-field">Work Order Number (optional)<br>
            <input type="text" name="order_number" value="<?php echo htmlspecialchars($wo['order_number'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" placeholder="WO123 or 123">
        </label>
        <?php endif; ?>
        <label class="field-span-2">Client Name<br><input type="text" name="client_name" value="<?php echo htmlspecialchars($wo['client_name'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" <?php echo ($currentRole==='office' || $currentRole==='admin') ? '' : 'readonly'; ?>></label>
        <label>Client Phone<br><input type="text" name="client_phone" value="<?php echo htmlspecialchars($wo['client_phone'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" <?php echo ($currentRole==='office' || $currentRole==='admin') ? '' : 'readonly'; ?>></label>
        <label class="field-span-2">Location<br><input type="text" name="location" value="<?php echo htmlspecialchars($wo['location'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" <?php echo ($currentRole==='office' || $currentRole==='admin') ? '' : 'readonly'; ?>></label>
        <label>Order Date<br><input type="date" name="order_date" value="<?php echo htmlspecialchars($wo['order_date'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" <?php echo ($currentRole==='office' || $currentRole==='admin') ? '' : 'readonly'; ?>></label>
        <label>Service Type<br>
    <input type="text"
           name="service_type"
           value="<?php echo htmlspecialchars($wo['service_type'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"
           <?php echo ($currentRole==='office' || $currentRole==='admin') ? '' : 'readonly'; ?>>
</label>

<?php $workLocationValue = (string)($wo['work_location'] ?? ''); ?>
<label>Work Performed At<br>
    <?php if ($currentRole === 'office' || $currentRole === 'admin'): ?>
        <select name="work_location">
            <option value="" <?php echo $workLocationValue === '' ? 'selected' : ''; ?>>Not recorded</option>
            <option value="customer_property" <?php echo $workLocationValue === 'customer_property' ? 'selected' : ''; ?>>Customer Property</option>
            <option value="shop" <?php echo $workLocationValue === 'shop' ? 'selected' : ''; ?>>Our Shop</option>
        </select>
    <?php else: ?>
        <input type="text" value="<?php echo $workLocationValue === 'customer_property' ? 'Customer Property' : ($workLocationValue === 'shop' ? 'Our Shop' : 'Not recorded'); ?>" readonly aria-readonly="true">
    <?php endif; ?>
</label>

<label class="field-span-2">Equipment / Asset<br>
    <input type="text"
           name="equipment_details"
           value="<?php echo htmlspecialchars($equipmentNameValue, ENT_QUOTES, 'UTF-8'); ?>"
           <?php echo ($currentRole==='office' || $currentRole==='admin') ? '' : 'readonly'; ?>>
</label>

<label>Expected Start Date<br>
    <input type="date"
           name="expected_start_date"
           value="<?php echo htmlspecialchars($wo['expected_start_date'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"
           <?php echo ($currentRole==='office' || $currentRole==='admin') ? '' : 'readonly'; ?>>
</label>

<label>Expected End Date<br>
    <input type="date"
           name="expected_end_date"
           value="<?php echo htmlspecialchars($wo['expected_end_date'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"
           <?php echo ($currentRole==='office' || $currentRole==='admin') ? '' : 'readonly'; ?>>
</label>
    </fieldset>

    <fieldset class="work-comments-grid" style="padding:10px; margin-bottom:15px;">
        <legend>Work / Comments</legend>
        <label>Requested Work<br><textarea name="requested_work" <?php echo ($currentRole==='office' || $currentRole==='admin') ? '' : 'readonly'; ?>><?php echo htmlspecialchars($wo['requested_work'] ?? '', ENT_QUOTES, 'UTF-8'); ?></textarea></label>
        <label>Additional Comments<br><textarea name="additional_comments"><?php echo htmlspecialchars($wo['additional_comments'] ?? '', ENT_QUOTES, 'UTF-8'); ?></textarea></label>
        <label>Special Instructions<br>
    <textarea name="special_instructions"><?php
        echo htmlspecialchars($wo['special_instructions'] ?? '', ENT_QUOTES, 'UTF-8');
    ?></textarea>
</label>
    </fieldset>

    <?php if ($currentRole === 'admin' || $currentRole === 'office' || $currentRole === 'technician'): ?>
    <fieldset class="assignment-grid" style="padding:10px; margin-bottom:15px;">
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
        </label>
        <?php if ($currentRole === 'admin' || $currentRole === 'office'): ?>
        <div style="grid-column:1/-1; padding:10px 12px; border:1px solid #dbe3ec; border-radius:8px; background:#f8fafc;">
            <strong style="display:block; margin-bottom:6px;">Additional Technicians</strong>
            <small style="display:block; margin-bottom:8px; color:#64748b;">Select any other technicians working on this job. Each person tracks their own arrival and departure.</small>
            <input type="hidden" name="additional_technicians[]" value="">
            <div style="display:flex; flex-wrap:wrap; gap:8px 16px;">
                <?php foreach ($staff as $teamStaff): ?>
                    <?php
                        $teamRole = strtolower(trim((string)($teamStaff['role'] ?? '')));
                        $isEligibleTeamMember = in_array($teamRole, ['admin', 'technician', 'staff', ''], true);
                        $teamStaffId = (int)$teamStaff['id'];
                        $isPrimaryTechnician = $teamStaffId === (int)($wo['work_performed_by'] ?? 0);
                        if ($isEligibleTeamMember && !$isPrimaryTechnician):
                    ?>
                        <label style="display:inline-flex; align-items:center; gap:6px; margin:0;">
                            <input type="checkbox" name="additional_technicians[]" value="<?php echo $teamStaffId; ?>" <?php echo in_array($teamStaffId, $currentWorkorderTechnicianIds, true) ? 'checked' : ''; ?>>
                            <?php echo htmlspecialchars(trim(($teamStaff['firstname'] ?? '') . ' ' . ($teamStaff['lastname'] ?? '')), ENT_QUOTES, 'UTF-8'); ?>
                        </label>
                    <?php endif; ?>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>
        <?php if ($currentRole === 'admin' || $currentRole === 'technician'): ?>
        <label>Vessel VIN<br><input type="text" name="vessel_vin" value="<?php echo htmlspecialchars($vesselVinValue, ENT_QUOTES, 'UTF-8'); ?>"></label>
        <label>Vessel Hours<br><input type="number" step="0.5" name="vessel_hours" value="<?php echo htmlspecialchars($wo['vessel_hours'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"></label>
        <?php endif; ?>
    </fieldset>
    <?php endif; ?>

    <?php if ($currentRole === 'admin' || ($currentRole === 'technician' && $isAssignedTechnician)): ?>
    <fieldset class="work-performed-section" style="padding:10px; margin-bottom:15px;">
        <legend>Add Work Performed</legend>
        <div class="work-performed-panel">
            <?php if ($techEntryMessage !== ''): ?>
                <p style="margin:0 0 8px; color:#0b5a2c; font-weight:600;"><?php echo htmlspecialchars($techEntryMessage, ENT_QUOTES, 'UTF-8'); ?></p>
            <?php endif; ?>
            <div class="work-performed-inputs">
                <label style="margin:0;">Date<br><input type="date" name="performed_date" form="add-work-performed-form" required></label>
                <label style="margin:0;">Hours spent on this entry<br><input type="number" name="performed_hours" form="add-work-performed-form" min="0" max="99" value="0" required></label>
                <label style="margin:0;">Minutes<br><input type="number" name="performed_minutes" form="add-work-performed-form" min="0" max="59" step="5" value="0" required></label>
                <?php if (!empty($wo['asset_id'])): ?>
                <label style="margin:0;">Update Asset Hours (optional)<br><input type="number" name="asset_hours" form="add-work-performed-form" min="0" step="0.1" placeholder="Leave blank to keep current<?php echo $assetHoursCurrentValue !== '' ? ': ' . htmlspecialchars($assetHoursCurrentValue, ENT_QUOTES, 'UTF-8') : ''; ?>"></label>
                <?php endif; ?>
                <label class="field-span-all" style="margin:0;">Description<br><textarea name="performed_description" form="add-work-performed-form" rows="2" required></textarea></label>
                <button type="submit" form="add-work-performed-form" name="tech_action" value="add_work_performed" style="grid-column:1 / -1; justify-self:start; width:auto; max-width:300px; margin:0; padding:8px 12px; background:#007BFF; color:#fff; border:none; border-radius:4px; cursor:pointer;">Add Work Entry</button>
            </div>
        </div>
        <?php if (!empty($techWorkEntries)): ?>
            <div class="work-performed-history" style="margin-top:12px;">
                <h3 style="margin:0 0 8px; font-size:15px;">Work Performed History</h3>
                <?php $entryColors = ['#f8fbff', '#fffdf7']; $entryIndex = 0; ?>
                <?php foreach ($techWorkEntriesByDate as $dayKey => $dayEntries): ?>
                    <details open style="margin-bottom:10px;">
                        <summary style="cursor:pointer; text-align:left; font-weight:700; color:#0f172a; padding:5px 0; border-bottom:1px solid #dfe7f1; margin-bottom:6px;"><?php echo htmlspecialchars(format_entry_day($dayKey), ENT_QUOTES, 'UTF-8'); ?></summary>
                        <?php foreach ($dayEntries as $entry): ?>
                            <details style="padding:7px 9px; margin-bottom:5px; border-radius:6px; background:<?php echo $entryColors[$entryIndex++ % count($entryColors)]; ?>;">
                                <summary style="cursor:pointer; display:flex; flex-wrap:wrap; gap:8px; align-items:baseline; font-size:13px; color:#475569;">
                                    <span>Submitted: <?php echo htmlspecialchars(format_entry_logged_at($entry['created_at'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></span>
                                    <span>&middot;</span>
                                    <span style="font-weight:600;"><?php echo htmlspecialchars(($entry['performed_time'] ?? '') === '00:00:00' ? 'Time not recorded' : format_work_duration($entry['performed_time'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></span>
                                    <span>&middot;</span>
                                    <span>Technician: <?php echo htmlspecialchars(trim((($entry['firstname'] ?? '') . ' ' . ($entry['lastname'] ?? ''))) ?: 'Unknown', ENT_QUOTES, 'UTF-8'); ?></span>
                                </summary>
                                <div style="margin-top:5px; white-space:pre-wrap;"><?php echo nl2br(htmlspecialchars($entry['description'] ?? '', ENT_QUOTES, 'UTF-8')); ?></div>
                            </details>
                        <?php endforeach; ?>
                    </details>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </fieldset>
    <?php endif; ?>

    <?php if (in_array($currentRole, ['admin', 'office', 'technician'], true)): ?>
    <fieldset class="property-permission-grid" style="padding:10px; margin-bottom:15px;">
        <legend>Property Entry Permissions / Logs</legend>
        <p style="margin:0 0 10px; color:#64748b;">Set when access is permitted. Assigned technicians can record their actual arrival and departure.</p>
        <?php $permissionAnytimeChecked = !empty($wo['permission_anytime']); $permissionHasSetTime = $permissionAnytimeChecked ? !empty($wo['permission_anytime_with_time']) : !empty($wo['permission_time']); ?>
        <?php if ($currentRole === 'admin' || $currentRole === 'office'): ?>
        <label><input type="checkbox" name="permission_anytime" id="permission_anytime" value="1" <?php echo $permissionAnytimeChecked ? 'checked' : ''; ?>> Permission Anytime (any date)</label>
        <label class="property-date-field" id="permission-date-group">Permission Date<br><input type="date" name="permission_date" id="permission_date" value="<?php echo htmlspecialchars($wo['permission_date'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"></label>
        <label class="permission-time-choice" for="permission_time_choice" id="permission-time-question">Would you like to specify a permission time?</label>
        <select class="permission-time-choice" name="permission_time_choice" id="permission_time_choice" required>
            <option value="no" <?php echo !$permissionHasSetTime ? 'selected' : ''; ?>>No specific time</option>
            <option value="yes" <?php echo $permissionHasSetTime ? 'selected' : ''; ?>>Yes, specify a time</option>
        </select>
        <div class="permission-time-fields" id="permission-time-details" style="display:<?php echo $permissionHasSetTime ? 'grid' : 'none'; ?>;">
            <label>Permission Time<br><input type="time" name="permission_time" id="permission_time" value="<?php echo htmlspecialchars($wo['permission_time'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"></label>
            <label>Timing
                <select name="permission_time_relation" id="permission_time_relation">
                    <option value="at" <?php echo empty($wo['permission_time_relation']) || $wo['permission_time_relation'] === 'at' ? 'selected' : ''; ?>>At the set time</option>
                    <option value="before" <?php echo ($wo['permission_time_relation'] ?? '') === 'before' ? 'selected' : ''; ?>>Before the set time</option>
                    <option value="after" <?php echo ($wo['permission_time_relation'] ?? '') === 'after' ? 'selected' : ''; ?>>After the set time</option>
                </select>
            </label>
        </div>
        <?php else: ?>
            <div class="field-span-all" style="margin:0 0 10px;">
                <strong>Permission Date:</strong> <?php echo htmlspecialchars($permissionAnytimeChecked ? 'Anytime' : (!empty($wo['permission_date']) ? date('M j, Y', strtotime($wo['permission_date'])) : 'Not set'), ENT_QUOTES, 'UTF-8'); ?><br>
                <strong>Permission Time:</strong> <?php
                    $displayPermissionTime = !empty($wo['permission_anytime']) && empty($wo['permission_anytime_with_time'])
                        ? 'Anytime'
                        : (!empty($wo['permission_time']) ? ((($wo['permission_time_relation'] ?? 'at') === 'before' ? 'Before ' : (($wo['permission_time_relation'] ?? 'at') === 'after' ? 'After ' : '')) . date('g:i A', strtotime($wo['permission_time']))) : 'Not set');
                    echo htmlspecialchars($displayPermissionTime, ENT_QUOTES, 'UTF-8');
                ?>
            </div>
        <?php endif; ?>
        <?php if (in_array($currentRole, ['admin', 'office', 'technician'], true)): ?>
            <p style="margin:16px 0 10px; color:#64748b;">Actual entry details (complete after staff access the property):</p>
            <?php if ($propertyLogMessage !== '' && ($currentRole === 'admin' || ($currentRole === 'technician' && $isAssignedTechnician))): ?>
                <p style="margin:0 0 10px; color:<?php echo isset($_GET['property_log_saved']) ? '#166534' : '#991b1b'; ?>; font-weight:600;"><?php echo htmlspecialchars($propertyLogMessage, ENT_QUOTES, 'UTF-8'); ?></p>
            <?php endif; ?>
            <?php if (empty($propertyEntryLogs)): ?>
                <div class="property-entry-history">
                <p style="margin:0 0 10px; color:#64748b;">No property entry visits have been logged yet.</p>
                </div>
            <?php else: ?>
                <div class="property-entry-history" style="overflow-x:auto; margin-bottom:14px;">
                    <table style="width:100%; border-collapse:collapse; background:#fff;">
                        <thead>
                            <tr style="background:#f1f5f9; text-align:left;">
                                <th style="padding:8px; border-bottom:1px solid #cbd5e1;">Visit Date</th>
                                <th style="padding:8px; border-bottom:1px solid #cbd5e1;">Technician</th>
                                <th style="padding:8px; border-bottom:1px solid #cbd5e1;">Time Entered</th>
                                <th style="padding:8px; border-bottom:1px solid #cbd5e1;">Time Departed</th>
                                <th style="padding:8px; border-bottom:1px solid #cbd5e1;">Check-in recorded by</th>
                                <th style="padding:8px; border-bottom:1px solid #cbd5e1;">Check-out recorded by</th>
                                <th style="padding:8px; border-bottom:1px solid #cbd5e1;">Office call note</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($propertyEntryLogs as $propertyLog): ?>
                                <tr>
                                    <td style="padding:8px; border-bottom:1px solid #e2e8f0;"><?php echo htmlspecialchars(format_entry_day($propertyLog['entry_date'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td style="padding:8px; border-bottom:1px solid #e2e8f0;"><?php echo htmlspecialchars(trim((string)($propertyLog['technician_first'] ?? '') . ' ' . (string)($propertyLog['technician_last'] ?? '')) ?: 'Not recorded', ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td style="padding:8px; border-bottom:1px solid #e2e8f0;"><?php echo htmlspecialchars(!empty($propertyLog['time_entered']) ? date('g:i A', strtotime($propertyLog['time_entered'])) : 'Not recorded', ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td style="padding:8px; border-bottom:1px solid #e2e8f0;"><?php
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
                                    <td style="padding:8px; border-bottom:1px solid #e2e8f0;"><?php
                                        $entryRecorder = trim((string)($propertyLog['recorder_first'] ?? '') . ' ' . (string)($propertyLog['recorder_last'] ?? ''));
                                        $entrySource = (string)($propertyLog['entry_source'] ?? '');
                                        echo htmlspecialchars($entrySource === 'technician_button' ? ($entryRecorder ?: 'Technician') : ($entrySource === 'office_manual' ? 'Office entered' . ($entryRecorder !== '' ? ' (' . $entryRecorder . ')' : '') : 'Earlier record (source not tracked)'), ENT_QUOTES, 'UTF-8');
                                    ?></td>
                                    <td style="padding:8px; border-bottom:1px solid #e2e8f0;"><?php
                                        $exitRecorder = trim((string)($propertyLog['departed_first'] ?? '') . ' ' . (string)($propertyLog['departed_last'] ?? ''));
                                        $exitSource = (string)($propertyLog['departure_source'] ?? '');
                                        echo htmlspecialchars($exitSource === 'technician_button' ? ($exitRecorder ?: 'Technician') : ($exitSource === 'office_manual' ? 'Office entered' . ($exitRecorder !== '' ? ' (' . $exitRecorder . ')' : '') : (!empty($propertyLog['time_departed']) ? 'Earlier record (source not tracked)' : '—')), ENT_QUOTES, 'UTF-8');
                                    ?></td>
                                    <td style="padding:8px; border-bottom:1px solid #e2e8f0;"><?php
                                        $callNotes = [];
                                        if (!empty($propertyLog['entry_reporter_name']) || !empty($propertyLog['entry_reported_at'])) {
                                            $callNotes[] = 'Arrival: ' . trim((string)($propertyLog['entry_reporter_name'] ?? 'Caller not recorded')) . ' at ' . (!empty($propertyLog['entry_reported_at']) ? date('M j, Y g:i A', strtotime($propertyLog['entry_reported_at'])) : 'time not recorded');
                                        }
                                        if (!empty($propertyLog['departure_reporter_name']) || !empty($propertyLog['departure_reported_at'])) {
                                            $callNotes[] = 'Departure: ' . trim((string)($propertyLog['departure_reporter_name'] ?? 'Caller not recorded')) . ' at ' . (!empty($propertyLog['departure_reported_at']) ? date('M j, Y g:i A', strtotime($propertyLog['departure_reported_at'])) : 'time not recorded');
                                        }
                                        echo htmlspecialchars($callNotes ? implode('; ', $callNotes) : '—', ENT_QUOTES, 'UTF-8');
                                    ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
            <?php if ($currentRole === 'admin'): ?>
            <div class="property-entry-add">
                <p class="field-span-all" style="margin:0;color:#64748b;">Offline/manual entry — entered by an admin at the technician’s request.</p>
                <label style="flex:1; min-width:220px;">Technician this visit belongs to<br>
                    <select name="property_log_technician_id">
                        <option value="">Select assigned technician</option>
                        <?php foreach ($staff as $visitTechnician): ?>
                            <?php if (in_array(strtolower(trim((string)($visitTechnician['role'] ?? ''))), ['admin', 'technician', 'staff', ''], true)): ?>
                                <option value="<?php echo (int)$visitTechnician['id']; ?>" <?php echo (int)$propertyLogTechnicianValue === (int)$visitTechnician['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars(trim(($visitTechnician['firstname'] ?? '') . ' ' . ($visitTechnician['lastname'] ?? '')), ENT_QUOTES, 'UTF-8'); ?></option>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label style="flex:1; min-width:160px;">Arrival Date<br><input type="date" name="property_entry_date" value="<?php echo htmlspecialchars($propertyLogDateValue, ENT_QUOTES, 'UTF-8'); ?>"></label>
                <label style="flex:1; min-width:160px;">Arrival Time (optional)<br><input type="time" name="property_time_entered" value="<?php echo htmlspecialchars($propertyLogEnteredValue, ENT_QUOTES, 'UTF-8'); ?>"></label>
                <label style="flex:1; min-width:180px;">Departure Date (if different)<br><input type="date" name="property_departure_date" value="<?php echo htmlspecialchars($propertyLogDepartureDateValue, ENT_QUOTES, 'UTF-8'); ?>"></label>
                <label style="flex:1; min-width:160px;">Departure Time (optional)<br><input type="time" name="property_time_departed" value="<?php echo htmlspecialchars($propertyLogDepartedValue, ENT_QUOTES, 'UTF-8'); ?>"></label>
                <label style="flex:1; min-width:220px;">Name of person who called<br><input type="text" name="property_call_reporter" maxlength="150" value="<?php echo htmlspecialchars($propertyLogCallerNameValue, ENT_QUOTES, 'UTF-8'); ?>"></label>
                <label style="flex:1; min-width:220px;">When the call was received<br><input type="datetime-local" name="property_call_received_at" value="<?php echo htmlspecialchars($propertyLogCallReceivedValue, ENT_QUOTES, 'UTF-8'); ?>"></label>
                <button type="submit" name="property_log_action" value="add" style="grid-column:1 / -1; justify-self:start; width:auto; max-width:300px; margin:0; padding:8px 12px; background:#15803d; color:#fff; border:0; border-radius:6px; cursor:pointer;">Add Admin-Recorded Visit</button>
            </div>
            <?php endif; ?>
        <?php endif; ?>
    </fieldset>
    <?php endif; ?>

    <?php if ($currentRole === 'admin' || $currentRole === 'office' || $currentRole === 'technician'): ?>
    <fieldset class="costs-grid" style="padding:10px; margin-bottom:15px;">
        <legend>Costs</legend>
        <label>Parts/Material Cost ($)<br><input type="number" step="0.01" name="parts_cost" value="<?php echo htmlspecialchars($wo['parts_cost'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"></label>
        <label>Chargeable To<br><input type="text" name="chargeable_to" value="<?php echo htmlspecialchars($wo['chargeable_to'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"></label>
    </fieldset>
    <?php endif; ?>

    <div class="actions">
        <button type="submit">Save Changes</button>
        <a href="/sps/pages/view_workorder.php?id=<?php echo (int)$wo['id']; ?>">Cancel</a>
    </div>
</form>
<script>
document.addEventListener('DOMContentLoaded', function () {
    const anytime = document.getElementById('permission_anytime');
    const dateGroup = document.getElementById('permission-date-group');
    const dateField = document.getElementById('permission_date');
    const timeChoice = document.getElementById('permission_time_choice');
    const timeQuestion = document.getElementById('permission-time-question');
    const timeDetails = document.getElementById('permission-time-details');
    const permissionTime = document.getElementById('permission_time');
    const timeRelation = document.getElementById('permission_time_relation');

    function updatePermissionFields() {
        const isAnytime = anytime && anytime.checked;
        const wantsTime = timeChoice && timeChoice.value === 'yes';
        if (dateGroup) {
            dateGroup.style.display = isAnytime ? 'none' : '';
            if (isAnytime && dateField) dateField.value = '';
        }
        if (timeQuestion) {
            timeQuestion.textContent = isAnytime
                ? 'Would you like to include a specific time with Anytime permission?'
                : 'Would you like to specify a permission time?';
        }
        if (timeDetails) timeDetails.style.display = wantsTime ? '' : 'none';
        if (permissionTime) permissionTime.required = wantsTime;
        if (!wantsTime) {
            if (permissionTime) permissionTime.value = '';
            if (timeRelation) timeRelation.value = 'at';
        }
    }

    if (anytime) anytime.addEventListener('change', updatePermissionFields);
    if (timeChoice) timeChoice.addEventListener('change', updatePermissionFields);
    updatePermissionFields();
});
</script>
<?php endif; ?>

<?php require_once '../includes/footer.php'; ?>
