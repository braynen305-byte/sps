<?php
session_start();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    http_response_code(401);
    echo json_encode(['error' => 'Authentication required.']);
    exit;
}

require_once '../includes/dbh.inc.php';
require_once '../includes/property_entry_logs.inc.php';
require_once '../includes/workorder_technicians.inc.php';
$liveDbName = (string)$conn->query('SELECT DATABASE()')->fetchColumn();
$liveTableExists = $conn->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?');
foreach (['workorder_property_entry_logs', 'workorder_technicians'] as $requiredLiveTable) {
    $liveTableExists->execute([$liveDbName, $requiredLiveTable]);
    if ((int)$liveTableExists->fetchColumn() === 0) {
        if ($requiredLiveTable === 'workorder_property_entry_logs') {
            ensure_property_entry_log_schema($conn);
        } else {
            ensure_workorder_technicians_schema($conn);
        }
    }
}
$role = strtolower(trim((string)($_SESSION['role'] ?? '')));
$userId = (int)($_SESSION['user_id'] ?? 0);
$customerId = (int)($_SESSION['customer_id'] ?? 0);
$markers = [];

try {
    $readRows = static function (string $tableName, array $visibleColumns, string $whereSql = '1=1', array $whereParams = []) use ($conn): array {
        try {
            $columnRows = $conn->query("SHOW COLUMNS FROM `$tableName`")->fetchAll(PDO::FETCH_ASSOC);
            $availableColumns = array_column($columnRows, 'Field');
            $selectedColumns = array_values(array_filter($visibleColumns, static function ($columnName) use ($availableColumns) {
                return in_array($columnName, $availableColumns, true);
            }));
            if (!in_array('id', $selectedColumns, true)) {
                return [];
            }
            $columnSql = implode(', ', array_map(static function ($columnName) {
                return '`' . str_replace('`', '``', $columnName) . '`';
            }, $selectedColumns));
            $rowsStmt = $conn->prepare("SELECT $columnSql FROM `$tableName` WHERE $whereSql ORDER BY id");
            $rowsStmt->execute($whereParams);
            return $rowsStmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $error) {
            return [];
        }
    };

    $visibleWorkOrderColumns = [
        'id', 'customer_id', 'order_number', 'status', 'priority', 'client_name', 'location', 'order_date',
        'service_type', 'equipment_details', 'special_instructions', 'expected_start_date', 'expected_end_date',
        'requested_work', 'additional_comments', 'work_description', 'work_performed_by', 'vessel_vin', 'vessel_hours',
        'labor_time', 'parts_cost', 'chargeable_to', 'permission_anytime', 'permission_anytime_with_time',
        'permission_date', 'permission_time'
    ];
    $visibleRequestColumns = [
        'id', 'customer_id', 'request_number', 'service_type', 'location', 'preferred_date', 'preferred_end_date',
        'urgency', 'equipment_details', 'problem_summary', 'description', 'special_instructions', 'status', 'approved_workorder_id'
    ];
    $visibleVisitColumns = [
        'id', 'workorder_id', 'technician_id', 'entry_date', 'departure_date', 'time_entered', 'time_departed',
        'entry_source', 'departure_source'
    ];
    $visibleWorkEntryColumns = ['id', 'workorder_id', 'performed_date', 'performed_time', 'description', 'added_by'];

    if ($role === 'customer') {
        if ($customerId <= 0) {
            http_response_code(403);
            echo json_encode(['error' => 'Customer account could not be verified.']);
            exit;
        }
        // Deliberately exclude generic updated_at values: unrelated admin/dashboard maintenance
        // can touch those timestamps without changing anything the customer can see.
        $customerWorkOrders = $readRows('workorders', $visibleWorkOrderColumns, 'customer_id = ?', [$customerId]);
        $customerRequests = $readRows('customer_service_requests', $visibleRequestColumns, 'customer_id = ?', [$customerId]);
        $customerWorkOrderIds = array_map(static function ($row) {
            return (int)$row['id'];
        }, $customerWorkOrders);
        $customerVisits = [];
        $customerWorkPerformed = [];
        if (!empty($customerWorkOrderIds)) {
            $workOrderIdPlaceholders = implode(',', array_fill(0, count($customerWorkOrderIds), '?'));
            $customerVisits = $readRows('workorder_property_entry_logs', $visibleVisitColumns, 'workorder_id IN (' . $workOrderIdPlaceholders . ')', $customerWorkOrderIds);
            $customerWorkPerformed = $readRows('work_performed_entries', $visibleWorkEntryColumns, 'workorder_id IN (' . $workOrderIdPlaceholders . ')', $customerWorkOrderIds);
        }
        $markers = [
            'workorders' => $customerWorkOrders,
            'service_requests' => $customerRequests,
            'property_visits' => $customerVisits,
            'work_performed' => $customerWorkPerformed
        ];
    } elseif ($role === 'technician') {
        if ($userId <= 0) {
            http_response_code(403);
            echo json_encode(['error' => 'Technician account could not be verified.']);
            exit;
        }
        $assignedWorkOrders = $readRows('workorders', $visibleWorkOrderColumns,
            'work_performed_by = ? OR EXISTS (SELECT 1 FROM workorder_technicians wt WHERE wt.workorder_id = workorders.id AND wt.technician_id = ?)',
            [$userId, $userId]);
        $assignedWorkOrderIds = array_map(static function ($row) { return (int)$row['id']; }, $assignedWorkOrders);
        $markers = ['workorders' => $assignedWorkOrders];
        if (!empty($assignedWorkOrderIds)) {
            $placeholders = implode(',', array_fill(0, count($assignedWorkOrderIds), '?'));
            $markers['team'] = $readRows('workorder_technicians', ['id', 'workorder_id', 'technician_id'],
                'workorder_id IN (' . $placeholders . ')', $assignedWorkOrderIds);
            $markers['visits'] = $readRows('workorder_property_entry_logs', $visibleVisitColumns,
                'workorder_id IN (' . $placeholders . ')', $assignedWorkOrderIds);
            $markers['work_performed'] = $readRows('work_performed_entries', $visibleWorkEntryColumns,
                'workorder_id IN (' . $placeholders . ')', $assignedWorkOrderIds);
            $markers['service_requests'] = $readRows('customer_service_requests', $visibleRequestColumns,
                'approved_workorder_id IN (' . $placeholders . ')', $assignedWorkOrderIds);
        }
    } elseif (in_array($role, ['admin', 'office', 'staff'], true)) {
        $markers = [
            'workorders' => $readRows('workorders', $visibleWorkOrderColumns),
            'service_requests' => $readRows('customer_service_requests', $visibleRequestColumns),
            'workorder_edits' => $readRows('workorder_edits', ['id', 'workorder_id', 'field_name', 'old_value', 'new_value', 'edited_by']),
            'team' => $readRows('workorder_technicians', ['id', 'workorder_id', 'technician_id']),
            'visits' => $readRows('workorder_property_entry_logs', $visibleVisitColumns),
            'work_performed' => $readRows('work_performed_entries', $visibleWorkEntryColumns)
        ];
    } else {
        http_response_code(403);
        echo json_encode(['error' => 'This account does not have dashboard notifications.']);
        exit;
    }
} catch (Throwable $error) {
    http_response_code(503);
    echo json_encode(['error' => 'Live updates are temporarily unavailable.']);
    exit;
}

$encodedMarkers = json_encode($markers, JSON_UNESCAPED_SLASHES);
echo json_encode([
    'version' => hash('sha256', (string)$encodedMarkers),
    'activity' => $markers
]);
