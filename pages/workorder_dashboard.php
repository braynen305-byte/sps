<?php
session_start();

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true || strtolower($_SESSION['role'] ?? '') !== 'admin') {
    header('Location: /sps/login.php');
    exit;
}

require_once '../includes/dbh.inc.php';

$title = 'Work Orders Dashboard';
require_once '../includes/header.php';

$searchClient = trim($_GET['search_client'] ?? '');
$sortBy = $_GET['sort_by'] ?? 'recent';

$query = 'SELECT * FROM workorders WHERE 1=1';
$params = [];

if ($searchClient !== '') {
    $query .= ' AND client_name LIKE ?';
    $params[] = '%' . $searchClient . '%';
}

if ($sortBy === 'recent') {
    $query .= ' ORDER BY created_at DESC';
} elseif ($sortBy === 'client') {
    $query .= ' ORDER BY client_name ASC';
} elseif ($sortBy === 'date') {
    $query .= ' ORDER BY order_date DESC';
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
        padding: 12px;
        text-align: left;
        font-weight: bold;
    }
    .workorder-table td {
        padding: 12px;
        border-bottom: 1px solid #ddd;
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
</style>

<div class="dashboard-header">
    <h2>Work Orders Dashboard</h2>
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
    <table class="workorder-table">
        <thead>
            <tr>
                <th>Order ID</th>
                <th>Client Name</th>
                <th>Location</th>
                <th>Order Date</th>
                <th>Status</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($workorders as $wo): ?>
                <tr>
                    <td><?php echo (int)$wo['id']; ?></td>
                    <td><?php echo htmlspecialchars($wo['client_name'], ENT_QUOTES, 'UTF-8'); ?></td>
                    <td><?php echo htmlspecialchars($wo['location'], ENT_QUOTES, 'UTF-8'); ?></td>
                    <td><?php echo htmlspecialchars($wo['order_date'], ENT_QUOTES, 'UTF-8'); ?></td>
                    <td>
                        <?php 
                            $status = 'Open';
                            if ($wo['time_departed']) {
                                $status = 'Completed';
                            } elseif ($wo['time_entered']) {
                                $status = 'In Progress';
                            }
                            echo htmlspecialchars($status, ENT_QUOTES, 'UTF-8');
                        ?>
                    </td>
                    <td>
                        <div class="action-links">
                            <a href="/sps/pages/view_workorder.php?id=<?php echo (int)$wo['id']; ?>" class="view-link">View</a>
                            <a href="/sps/pages/edit_workorder.php?id=<?php echo (int)$wo['id']; ?>" class="edit-link">Edit</a>
                            <form method="post" style="display:inline;">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="workorder_id" value="<?php echo (int)$wo['id']; ?>">
                                <button type="submit" class="delete-btn" onclick="return confirm('Delete this work order?');">Delete</button>
                            </form>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>

<?php require_once '../includes/footer.php'; ?>