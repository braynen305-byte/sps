<?php
session_start();

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true || strtolower($_SESSION['role'] ?? '') !== 'admin') {
    header('Location: /sps/login.php');
    exit;
}

require_once '../includes/dbh.inc.php';

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
        foreach (['workorder_edits', 'work_performed_entries', 'workorder_view_state'] as $relatedTable) {
            try {
                $conn->prepare("DELETE FROM {$relatedTable} WHERE workorder_id = ?")->execute([$delId]);
            } catch (Exception $e) {
                // ignore if table doesn't exist yet
            }
        }
        $delStmt = $conn->prepare('DELETE FROM workorders WHERE id = ?');
        $delStmt->execute([$delId]);
    }
    header('Location: /sps/pages/workorder_dashboard.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bulk_delete_workorders'])) {
    $selectedIds = array_filter(array_map('intval', explode(',', (string)($_POST['selected_ids'] ?? ''))));
    foreach ($selectedIds as $delId) {
        foreach (['workorder_edits', 'work_performed_entries', 'workorder_view_state'] as $relatedTable) {
            try {
                $conn->prepare("DELETE FROM {$relatedTable} WHERE workorder_id = ?")->execute([$delId]);
            } catch (Exception $e) {
                // ignore if table doesn't exist yet
            }
        }
        $conn->prepare('DELETE FROM workorders WHERE id = ?')->execute([$delId]);
    }
    header('Location: /sps/pages/workorder_dashboard.php');
    exit;
}

$title = 'Work Orders Dashboard';
require_once '../includes/header.php';

$searchClient = trim($_GET['search_client'] ?? '');
$sortBy = $_GET['sort_by'] ?? 'recent';

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
?>

<style>
    .dashboard-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 30px;
        flex-wrap: wrap;
        gap: 15px;
    }
    .dashboard-header h2 {
        margin: 0;
        flex: 1;
    }
    .create-btn {
        background-color: #28a745;
        color: white;
        padding: 12px 25px;
        border: none;
        border-radius: 5px;
        text-decoration: none;
        font-weight: bold;
        display: inline-block;
        transition: background-color 0.3s;
    }
    .create-btn:hover {
        background-color: #218838;
    }
    .filters {
        background-color: rgb(234, 234, 234);
        padding: 15px;
        border-radius: 5px;
        margin-bottom: 20px;
        display: flex;
        gap: 15px;
        flex-wrap: wrap;
        align-items: center;
    }
    .filters input {
        padding: 8px;
        border: 1px solid #ccc;
        border-radius: 5px;
    }
    .filters select {
        padding: 8px;
        border: 1px solid #ccc;
        border-radius: 5px;
    }
    .filters button {
        background-color: #007BFF;
        color: white;
        padding: 8px 15px;
        border: none;
        border-radius: 5px;
        cursor: pointer;
    }
    .filters button:hover {
        background-color: #0056b3;
    }
    .workorder-table {
        width: 100%;
        border-collapse: collapse;
        background-color: white;
        border-radius: 5px;
        overflow: hidden;
        box-shadow: 0 0 10px rgba(0, 0, 0, 0.1);
    }
    .workorder-table thead {
        background-color: #007BFF;
        color: white;
    }
    .workorder-table th {
        padding: 6px 8px;
        text-align: left;
        font-weight: bold;
        font-size: 12px;
        line-height: 1.2;
    }
    .workorder-table td {
        padding: 4px 8px;
        border-bottom: 1px solid #ddd;
        font-size: 12px;
        line-height: 1.2;
    }
    .workorder-table tbody tr:hover {
        background-color: #f5f5f5;
    }
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
</style>

<div class="dashboard-header">
    <div style="display:flex; align-items:center; gap:12px;">
        <a href="/sps/pages/admin_dashboard.php" class="back-link">&larr; Admin Dashboard</a>
        <h2>Work Orders Dashboard</h2>
    </div>
    <a href="/sps/pages/create_workorder.php" class="create-btn">+ Create New Work Order</a>
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
    <form id="bulk-delete-form" method="post" action="/sps/pages/workorder_dashboard.php" onsubmit="return confirm('Delete the selected work orders? This cannot be undone.');">
        <input type="hidden" id="selected_ids" name="selected_ids" value="">
        <div class="bulk-actions-bar">
            <label style="display:inline-flex; align-items:center; gap:6px; font-size:12px; font-weight:700; color:#334155; margin:0;">
                <input type="checkbox" id="select-all-workorders" style="accent-color:#dc2626; width:14px; height:14px;">
                Select all
            </label>
            <button id="bulk-delete-button" type="submit" name="bulk_delete_workorders" value="1" class="bulk-delete-btn">Delete Selected</button>
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
                <tr>
                    <td><input type="checkbox" name="selected_workorders[]" value="<?php echo (int)$wo['id']; ?>" class="workorder-select-checkbox" style="accent-color:#dc2626; width:14px; height:14px;"></td>
                    <td><?php echo htmlspecialchars(!empty($wo['order_number']) ? $wo['order_number'] : 'WO' . str_pad((string)(int)$wo['id'], 4, '0', STR_PAD_LEFT), ENT_QUOTES, 'UTF-8'); ?></td>
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
                            $isHigh = (strtolower(trim($rawPriority)) === 'high');
                        ?>
                        <span style="<?php echo $isHigh ? 'color:#721c24;background:#f8d7da;padding:4px 8px;border-radius:4px;' : ''; ?>"><?php echo htmlspecialchars($rawPriority, ENT_QUOTES, 'UTF-8'); ?></span>
                    </td>
                    <td>
                        <div class="action-links">
                            <a href="/sps/pages/view_workorder.php?id=<?php echo (int)$wo['id']; ?>" class="view-link">View</a>
                            <a href="/sps/pages/edit_workorder.php?id=<?php echo (int)$wo['id']; ?>" class="edit-link">Edit</a>
                            <button type="button" class="delete-btn" onclick="submitSingleDelete(<?php echo (int)$wo['id']; ?>)">Delete</button>
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
    if (confirm('Delete this work order?')) {
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

<?php require_once '../includes/footer.php'; ?>