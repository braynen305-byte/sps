<?php
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only.');
}

require_once __DIR__ . '/../includes/dbh.inc.php';
require_once __DIR__ . '/../includes/deleted_records.inc.php';

$purgedCount = purge_expired_deleted_records($conn);
fwrite(STDOUT, 'Permanently purged ' . $purgedCount . ' expired archived record(s).' . PHP_EOL);