<?php
require_once 'includes/dbh.inc.php';

echo "<h2>Database Status</h2>";

// Check if customers table exists
try {
    $result = $conn->query("SELECT COUNT(*) FROM customers");
    echo "✓ Customers table exists<br>";
} catch (Exception $e) {
    echo "✗ Customers table NOT found<br>";
}

// Check if workorders table exists
try {
    $result = $conn->query("SELECT COUNT(*) FROM workorders");
    echo "✓ Workorders table exists<br>";
} catch (Exception $e) {
    echo "✗ Workorders table NOT found<br>";
}

// List all tables in database
echo "<h3>All tables in 'sps' database:</h3>";
$tables = $conn->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
echo "<ul>";
foreach ($tables as $table) {
    echo "<li>$table</li>";
}
echo "</ul>";

echo "<p><a href='/sps/setup_database.php'>Run Setup Again</a></p>";
?>