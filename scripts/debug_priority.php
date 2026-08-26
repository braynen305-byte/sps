<?php
// Debug page: shows workorder.priority and recent priority edits
// Usage: http://localhost/sps/scripts/debug_priority.php?id=2
require_once __DIR__ . '/../includes/dbh.inc.php';
header('Content-Type: text/plain; charset=utf-8');
$id = isset($_GET['id']) ? (int)$_GET['id'] : 2;
if (!$id) {
    echo "Provide a numeric id, e.g. ?id=2\n";
    exit;
}
try {
    $stmt = $conn->prepare('SELECT * FROM workorders WHERE id = ? LIMIT 1');
    $stmt->execute([$id]);
    $wo = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$wo) {
        echo "Workorder $id not found.\n";
        exit;
    }
    echo "Workorder #$id\n";
    echo "priority (workorders table): " . (isset($wo['priority']) ? $wo['priority'] : '(no column)') . "\n";
    echo "updated_at: " . ($wo['updated_at'] ?? '(none)') . "\n";
    echo "\nRecent workorder_edits for 'priority':\n";
    $e = $conn->prepare("SELECT * FROM workorder_edits WHERE workorder_id = ? AND field_name = 'priority' ORDER BY edited_at DESC LIMIT 10");
    $e->execute([$id]);
    $edits = $e->fetchAll(PDO::FETCH_ASSOC);
    if (!$edits) {
        echo "(no edits recorded)\n";
    } else {
        foreach ($edits as $row) {
            echo "[" . ($row['edited_at'] ?? '') . "] by " . ($row['edited_by'] ?? '') . " -> old='" . ($row['old_value'] ?? '') . "' new='" . ($row['new_value'] ?? '') . "' (id=" . ($row['id'] ?? '') . ")\n";
        }
    }
    echo "\nAll columns in workorders (for reference):\n";
    $cols = $conn->query("SHOW COLUMNS FROM workorders")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($cols as $c) echo $c['Field'] . "\n";
} catch (PDOException $ex) {
    echo "DB error: " . $ex->getMessage() . "\n";
}
