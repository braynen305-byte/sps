<?php
$mysqli = new mysqli('localhost', 'root', '', 'sps');
if ($mysqli->connect_error) {
    echo 'DB_ERROR:' . $mysqli->connect_error;
    exit(1);
}
$res = $mysqli->query('SHOW COLUMNS FROM staff');
if (!$res) {
    echo 'SHOW_ERROR';
    exit(1);
}
while ($row = $res->fetch_assoc()) {
    echo $row['Field'] . PHP_EOL;
}
