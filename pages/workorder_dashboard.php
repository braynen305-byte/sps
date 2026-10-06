<?php
session_start();

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true || strtolower($_SESSION['role'] ?? '') !== 'admin') {
    header('Location: /sps/login.php');
    exit;
}

require_once '../includes/dbh.inc.php';
require_once '../includes/customer_update_state.inc.php';
require_once '../includes/deleted_records.inc.php';
ensure_customer_update_state_schema($conn);

// Ensure `priority` column exists and backfill from `workorder_edits` if present
$migration_error = '';
try {
        $colStmt = $conn->query("SHOW COLUMNS FROM workorders LIKE 'priority'");
        $hasPriority = ($colStmt && $colStmt->rowCount() > 0);
        if (!$hasPriority) {
                $conn->exec("ALTER TABLE workorders ADD COLUMN `priority` VARCHAR(20) NOT NULL DEFAULT 'Normal'");
                $hasPriority = true;
        }

        $tblStmt = $conn->query("SHOW TABLES LIKE 'workorder_edits'");
        $hasEdits = ($tblStmt && $tblStmt->rowCount() > 0);
        if ($hasPriority && $hasEdits) {
                $updateSql = "UPDATE workorders w
JOIN (
    SELECT we1.workorder_id, we1.new_value
    FROM workorder_edits we1
    JOIN (
        SELECT workorder_id, MAX(edited_at) AS maxt
        FROM workorder_edits
        WHERE field_name = 'priority'
        GROUP BY workorder_id
    ) we2 ON we1.workorder_id = we2.workorder_id AND we1.edited_at = we2.maxt
    WHERE we1.field_name = 'priority'
) latest ON w.id = latest.workorder_id
SET w.priority = latest.new_value";
                $conn->exec($updateSql);
        }
} catch (PDOException $e) {
        $migration_error = $e->getMessage();
}

// Handle delete requests (admin only - page already restricted)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    $delId = (int)($_POST['workorder_id'] ?? 0);
    if ($delId) {
        archive_workorder($conn, $delId, 'admin', (int)($_SESSION['user_id'] ?? 0));
    }
    header('Location: /sps/pages/workorder_dashboard.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bulk_delete_workorders'])) {
    $selectedIds = array_filter(array_map('intval', explode(',', (string)($_POST['selected_ids'] ?? ''))));
    foreach ($selectedIds as $delId) {
        archive_workorder($conn, $delId, 'admin', (int)($_SESSION['user_id'] ?? 0));
    }
    header('Location: /sps/pages/workorder_dashboard.php');
    exit;
}

$title = 'Work Orders Dashboard';
require_once '../includes/header.php';

$searchClient = trim($_GET['search_client'] ?? '');
$sortBy = $_GET['sort_by'] ?? 'recent';
$staffUserId = (int)($_SESSION['user_id'] ?? 0);
ensure_customer_update_state_schema($conn);

$query = 'SELECT w.*, s.firstname AS assignee_firstname, s.lastname AS assignee_lastname, s.role AS assignee_role, c.firstname AS creator_firstname, c.lastname AS creator_lastname FROM workorders w LEFT JOIN staff s ON s.id = w.work_performed_by LEFT JOIN staff c ON c.id = w.order_received_by WHERE 1=1';
$params = [];

if ($searchClient !== '') {
    $query .= ' AND w.client_name LIKE ?';
    $params[] = '%' . $searchClient . '%';
}

if ($sortBy === 'recent') {
    $query .= ' ORDER BY w.created_at DESC';
} elseif ($sortBy === 'client') {
    $query .= ' ORDER BY w.client_name ASC';
} elseif ($sortBy === 'date') {
    $query .= ' ORDER BY w.order_date DESC';
}

$stmt = $conn->prepare($query);
$stmt->execute($params);
$workorders = $stmt->fetchAll(PDO::FETCH_ASSOC);
$workorderLastActivity = [];
$activityWorkorderIds = array_map(static function ($workorder) { return (int)$workorder['id']; }, $workorders);
if (!empty($activityWorkorderIds)) {
    $idPlaceholders = implode(',', array_fill(0, count($activityWorkorderIds), '?'));
    $activityStmt = $conn->prepare("SELECT w.id, GREATEST(
        COALESCE(w.created_at, '1000-01-01 00:00:00'),
        COALESCE((SELECT MAX(e.edited_at) FROM workorder_edits e WHERE e.workorder_id = w.id), '1000-01-01 00:00:00'),
        COALESCE((SELECT MAX(p.created_at) FROM work_performed_entries p WHERE p.workorder_id = w.id), '1000-01-01 00:00:00'),
        COALESCE((SELECT MAX(l.updated_at) FROM workorder_property_entry_logs l WHERE l.workorder_id = w.id), '1000-01-01 00:00:00')
    ) AS latest_activity FROM workorders w WHERE w.id IN ($idPlaceholders)");
    $activityStmt->execute($activityWorkorderIds);
    foreach ($activityStmt->fetchAll(PDO::FETCH_ASSOC) as $activityRow) {
        $workorderLastActivity[(int)$activityRow['id']] = (string)$activityRow['latest_activity'];
    }
}
$unreadAdminWorkorders = [];
foreach ($workorders as $workorder) {
    $workorderId = (int)$workorder['id'];
    $lastSeen = staff_update_seen_at($conn, $staffUserId, 'workorder', $workorderId);
    $baseline = $lastSeen ?? (string)($workorder['created_at'] ?? '');
    $latestActivity = $workorderLastActivity[$workorderId] ?? $baseline;
    if ($latestActivity !== '' && $baseline !== '' && $latestActivity > $baseline) {
        $unreadAdminWorkorders[$workorderId] = true;
    }
}
?>

<style>
    .admin-workorders-page { width:min(1500px,calc(100% - 24px)); margin:30px auto 48px; padding:0 12px; color:#0f172a; box-sizing:border-box; }
    .admin-workorders-page #bulk-delete-form { width:min(1500px,calc(100vw - 24px)); margin-left:50%; transform:translateX(-50%); }
    .dashboard-header {
        display: flex;
        justify-content: space-between;
        align-items: flex-start;
        margin-bottom: 18px;
        flex-wrap: wrap;
        gap: 15px;
    }
    .dashboard-title-group { display:flex; flex-direction:column; align-items:flex-start; gap:8px; }
    .dashboard-header-actions { display:flex; align-items:center; justify-content:flex-end; gap:10px; flex-wrap:wrap; }
    .dashboard-header .back-link { display:inline-flex; align-items:center; min-height:40px; padding:0 13px; border:1px solid #cbd5e1; border-radius:8px; background:#fff; color:#123d71; font-size:13px; font-weight:700; text-decoration:none; }
    .dashboard-header .back-link:hover { background:#f8fafc; border-color:#94a3b8; }
    .dashboard-header h2 {
        margin: 0;
        flex: 1;
        color:#0f172a;
        font-size:clamp(22px,3vw,30px);
    }
    .create-btn {
        background:linear-gradient(135deg,#0f766e,#115e59);
        color: white;
        padding: 10px 16px;
        border: none;
        border-radius: 8px;
        text-decoration: none;
        font-weight: bold;
        display: inline-block;
        transition: background-color 0.3s;
    }
    .create-btn:hover {
        background:#115e59;
    }
    .filters {
        background:#fff;
        padding: 12px 14px;
        border:1px solid #e5e7eb;
        border-radius: 12px;
        box-shadow:0 3px 14px rgba(15,23,42,.05);
        margin-bottom: 16px;
        display: flex;
        gap: 15px;
        flex-wrap: wrap;
        align-items: center;
    }
    .filters input {
        padding: 9px 11px;
        border: 1px solid #cbd5e1;
        border-radius: 8px;
    }
    .filters select {
        padding: 9px 11px;
        border: 1px solid #cbd5e1;
        border-radius: 8px;
    }
    .filters button {
        background:linear-gradient(135deg,#2563eb,#1d4ed8);
        color: white;
        padding: 9px 14px;
        border: none;
        border-radius: 8px;
        cursor: pointer;
    }
    .filters button:hover {
        background:#1d4ed8;
    }
    .workorder-table {
        width: 100%;
        border-collapse: collapse;
        background-color: white;
        border-radius: 12px;
        overflow: hidden;
        box-shadow: 0 3px 14px rgba(15,23,42,.06);
        border:1px solid #e5e7eb;
    }
    .workorder-table thead {
        background:linear-gradient(135deg,#123d71,#1d4ed8);
        color: white;
    }
    .workorder-table th {
        padding: 12px 10px;
        text-align: left;
        font-weight: bold;
        font-size: 12px;
        line-height: 1.3;
        text-transform:uppercase;
        letter-spacing:.035em;
    }
    .workorder-table td {
        padding: 8px 9px;
        border-bottom: 1px solid #eef2f7;
        font-size: 12px;
        line-height: 1.2;
    }
    .workorder-table tbody tr:hover {
        background-color: #f8fbff;
    }
    .workorder-table tbody tr.admin-unread-workorder td { background:#eff6ff; }
    .workorder-table tbody tr.admin-unread-workorder td:first-child { box-shadow:inset 3px 0 #2563eb; }
    .admin-new-tag { display:inline-block; margin-left:5px; color:#1d4ed8; font-size:10px; font-weight:800; letter-spacing:.03em; }
    .action-links {
        display: flex;
        gap: 8px;
        align-items: center;
    }
    .action-links form {
        display: inline;
        margin: 0;
    }
    .action-links a, .action-links button {
        padding: 6px 12px;
        border-radius: 4px;
        text-decoration: none;
        font-size: 12px;
        border: none;
        cursor: pointer;
        transition: background-color 0.3s;
        display: inline-block;
        white-space: nowrap;
    }
    .edit-link {
        background-color: #007BFF;
        color: white;
    }
    .edit-link:hover {
        background-color: #0056b3;
    }
    .view-link {
        background-color: #17a2b8;
        color: white;
    }
    .view-link:hover {
        background-color: #138496;
    }
    .delete-btn {
        background-color: #dc3545;
        color: white;
        padding: 6px 10px;
        font-size: 11px;
    }
    .delete-btn:hover {
        background-color: #c82333;
    }
    .no-results {
        text-align: center;
        padding: 40px;
        color: #666;
        font-size: 16px;
    }
    .bulk-actions-bar {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 10px;
        padding: 8px 10px;
        background: #f8fafc;
        border: 1px solid #e5e7eb;
        border-bottom: none;
        border-radius: 5px 5px 0 0;
    }
    .bulk-delete-btn {
        display: none;
        align-items: center;
        justify-content: center;
        padding: 6px 14px;
        background: linear-gradient(135deg, #f87171, #dc2626);
        color: #fff;
        border: none;
        border-radius: 5px;
        cursor: pointer;
        font-weight: 700;
        font-size: 11px;
        box-shadow: 0 2px 8px rgba(220,38,38,0.15);
    }
    .bulk-actions-bar { border-radius:12px 12px 0 0; }
    @media(max-width:720px) { .admin-workorders-page { width:100%; padding:0 10px; margin-top:22px; } .admin-workorders-page #bulk-delete-form { width:calc(100vw - 20px); } .workorder-table { min-width:950px; } }
</style>

<main class="admin-workorders-page">
<div class="dashboard-header">
    <div class="dashboard-title-group">
        <h2>Work Orders Dashboard</h2>
    </div>
    <div class="dashboard-header-actions">
        <a href="/sps/pages/admin_dashboard.php" class="back-link">&larr; Admin Dashboard</a>
        <a href="/sps/pages/deleted_records.php" class="back-link">Trash / Restore</a>
        <a href="/sps/pages/create_workorder.php" class="create-btn">+ Create New Work Order</a>
    </div>
</div>

<div class="filters">
    <form method="get" style="display: flex; gap: 15px; flex-wrap: wrap; align-items: center; width: 100%;">
        <input type="text" name="search_client" placeholder="Search by client name..." value="<?php echo htmlspecialchars($searchClient, ENT_QUOTES, 'UTF-8'); ?>">
        <select name="sort_by">
            <option value="recent" <?php echo $sortBy === 'recent' ? 'selected' : ''; ?>>Sort by: Most Recent</option>
            <option value="client" <?php echo $sortBy === 'client' ? 'selected' : ''; ?>>Sort by: Client Name</option>
            <option value="date" <?php echo $sortBy === 'date' ? 'selected' : ''; ?>>Sort by: Order Date</option>
        </select>
        <button type="submit">Filter</button>
        <a href="/sps/pages/manage_workorders.php" style="color: #007BFF; text-decoration: none;">Clear</a>
    </form>
</div>

<?php if (empty($workorders)): ?>
    <div class="no-results">
        <p>No work orders found.</p>
        <a href="/sps/pages/create_workorder.php" class="create-btn">Create your first work order</a>
    </div>
<?php else: ?>
    <form id="bulk-delete-form" method="post" action="/sps/pages/workorder_dashboard.php" onsubmit="return confirm('Move the selected work orders to Trash? They can be restored for 7 months.');">
        <input type="hidden" id="selected_ids" name="selected_ids" value="">
        <div class="bulk-actions-bar">
            <label style="display:inline-flex; align-items:center; gap:6px; font-size:12px; font-weight:700; color:#334155; margin:0;">
                <input type="checkbox" id="select-all-workorders" style="accent-color:#dc2626; width:14px; height:14px;">
                Select all
            </label>
            <button id="bulk-delete-button" type="submit" name="bulk_delete_workorders" value="1" class="bulk-delete-btn">Archive Selected</button>
        </div>
    <table class="workorder-table">
        <thead>
            <tr>
                <th style="width:30px;">Sel</th>
                <th>WO#</th>
                <th>Client Name</th>
                <th>Location</th>
                <th>Created By</th>
                <th>Assigned To</th>
                <th>Order Date</th>
                <th>Status</th>
                <th>Priority</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($workorders as $wo): ?>
                <?php $hasUnreadUpdate = !empty($unreadAdminWorkorders[(int)$wo['id']]); ?>
                <tr class="<?php echo $hasUnreadUpdate ? 'admin-unread-workorder' : ''; ?>">
                    <td><input type="checkbox" name="selected_workorders[]" value="<?php echo (int)$wo['id']; ?>" class="workorder-select-checkbox" style="accent-color:#dc2626; width:14px; height:14px;"></td>
                    <td>
                        <?php echo htmlspecialchars(!empty($wo['order_number']) ? $wo['order_number'] : 'WO' . str_pad((string)(int)$wo['id'], 4, '0', STR_PAD_LEFT), ENT_QUOTES, 'UTF-8'); ?>
                        <?php if ($hasUnreadUpdate): ?><span class="admin-new-tag">NEW</span><?php endif; ?>
                    </td>
                    <td><?php echo htmlspecialchars($wo['client_name'], ENT_QUOTES, 'UTF-8'); ?></td>
                    <td><?php echo htmlspecialchars($wo['location'], ENT_QUOTES, 'UTF-8'); ?></td>
                    <td>
                        <?php
                            $createdName = trim((($wo['creator_firstname'] ?? '') . ' ' . ($wo['creator_lastname'] ?? '')));
                            echo htmlspecialchars($createdName !== '' ? $createdName : 'Unknown', ENT_QUOTES, 'UTF-8');
                        ?>
                    </td>
                    <td>
                        <?php
                            $assignedName = trim((($wo['assignee_firstname'] ?? '') . ' ' . ($wo['assignee_lastname'] ?? '')));
                            $assignedRole = strtolower(trim((string)($wo['assignee_role'] ?? '')));
                            if ($assignedName === '' || !($assignedRole === 'admin' || in_array($assignedRole, ['technician', 'staff', ''], true))) {
                                $assignedName = 'Not Assigned';
                            }
                            echo htmlspecialchars($assignedName, ENT_QUOTES, 'UTF-8');
                        ?>
                    </td>
                    <td><?php echo htmlspecialchars($wo['order_date'], ENT_QUOTES, 'UTF-8'); ?></td>
                    <td>
                        <?php 
                            $status = 'Open';
                            if (!empty($wo['time_departed'])) {
                                $status = 'Completed';
                            } elseif (!empty($wo['time_entered'])) {
                                $status = 'In Progress';
                            }
                            echo htmlspecialchars($status, ENT_QUOTES, 'UTF-8');
                        ?>
                    </td>
                    <td>
                        <?php
                            $rawPriority = isset($wo['priority']) ? (string)$wo['priority'] : 'Normal';
                            $isHigh = in_array(strtolower(trim($rawPriority)), ['high', 'urgent', 'emergency'], true);
                        ?>
                        <span style="<?php echo $isHigh ? 'color:#721c24;background:#f8d7da;padding:4px 8px;border-radius:4px;' : ''; ?>"><?php echo htmlspecialchars($rawPriority, ENT_QUOTES, 'UTF-8'); ?></span>
                    </td>
                    <td>
                        <div class="action-links">
                            <a href="/sps/pages/view_workorder.php?id=<?php echo (int)$wo['id']; ?>" class="view-link">View</a>
                            <a href="/sps/pages/edit_workorder.php?id=<?php echo (int)$wo['id']; ?>" class="edit-link">Edit</a>
                            <button type="button" class="delete-btn" onclick="submitSingleDelete(<?php echo (int)$wo['id']; ?>)">Archive</button>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    </form>
<?php endif; ?>

<form id="single-delete-form" method="post" action="/sps/pages/workorder_dashboard.php" style="display:none;">
    <input type="hidden" name="action" value="delete">
    <input type="hidden" id="single-delete-id" name="workorder_id" value="">
</form>

<script>
function submitSingleDelete(id) {
    if (confirm('Move this work order to Trash? It can be restored for 7 months.')) {
        document.getElementById('single-delete-id').value = id;
        document.getElementById('single-delete-form').submit();
    }
}
(function () {
    var selectAll = document.getElementById('select-all-workorders');
    var bulkDeleteButton = document.getElementById('bulk-delete-button');
    var bulkDeleteForm = document.getElementById('bulk-delete-form');
    var selectedIdsInput = document.getElementById('selected_ids');

    function updateBulkDeleteButton() {
        var checkboxes = document.querySelectorAll('.workorder-select-checkbox');
        var selectedIds = [];
        checkboxes.forEach(function (checkbox) {
            if (checkbox.checked) { selectedIds.push(checkbox.value); }
        });
        if (selectedIdsInput) { selectedIdsInput.value = selectedIds.join(','); }
        if (bulkDeleteButton) { bulkDeleteButton.style.display = selectedIds.length ? 'inline-flex' : 'none'; }
    }

    if (bulkDeleteForm) {
        bulkDeleteForm.addEventListener('submit', function (event) {
            var selectedIds = [];
            document.querySelectorAll('.workorder-select-checkbox').forEach(function (checkbox) {
                if (checkbox.checked) { selectedIds.push(checkbox.value); }
            });
            if (!selectedIds.length) { event.preventDefault(); return false; }
            if (selectedIdsInput) { selectedIdsInput.value = selectedIds.join(','); }
        });
    }

    if (selectAll) {
        selectAll.addEventListener('change', function () {
            document.querySelectorAll('.workorder-select-checkbox').forEach(function (checkbox) {
                checkbox.checked = selectAll.checked;
            });
            updateBulkDeleteButton();
        });
    }

    document.querySelectorAll('.workorder-select-checkbox').forEach(function (checkbox) {
        checkbox.addEventListener('change', updateBulkDeleteButton);
    });

    updateBulkDeleteButton();
})();
</script>
</main>

<?php require_once '../includes/footer.php'; ?>