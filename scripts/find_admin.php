<?php
require_once __DIR__ . '/../includes/dbh.inc.php';
$stmt = $conn->query("SELECT id, firstname, lastname, role FROM staff WHERE LOWER(role)='admin' LIMIT 1");
var_dump($stmt->fetch(PDO::FETCH_ASSOC));
