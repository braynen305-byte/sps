<?php
require_once __DIR__ . '/../includes/dbh.inc.php';
$stmt = $conn->query("SELECT * FROM work_performed_entries WHERE workorder_id=3 ORDER BY id ASC");
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) { echo implode(' | ', $r) . "\n"; }
