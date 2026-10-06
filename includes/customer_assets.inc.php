<?php

function ensure_customer_asset_schema(PDO $conn): void
{
    $conn->exec("CREATE TABLE IF NOT EXISTS customer_assets (
        id INT AUTO_INCREMENT PRIMARY KEY,
        customer_id INT NOT NULL,
        asset_name VARCHAR(120) DEFAULT NULL,
        asset_type VARCHAR(60) DEFAULT NULL,
        make VARCHAR(100) DEFAULT NULL,
        model VARCHAR(100) DEFAULT NULL,
        model_year SMALLINT DEFAULT NULL,
        serial_number VARCHAR(100) DEFAULT NULL,
        hours DECIMAL(10,2) DEFAULT NULL,
        boat_length DECIMAL(6,2) DEFAULT NULL,
        engine_count TINYINT UNSIGNED DEFAULT NULL,
        engine_details TEXT DEFAULT NULL,
        notes TEXT DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX customer_assets_owner (customer_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $assetColumns = $conn->query("SHOW COLUMNS FROM customer_assets")->fetchAll(PDO::FETCH_ASSOC);
    $existingAssetColumns = array_column($assetColumns, 'Field');
    foreach ($assetColumns as $column) {
        if (($column['Field'] ?? '') === 'asset_name' && strtoupper((string)($column['Null'] ?? '')) !== 'YES') {
            $conn->exec('ALTER TABLE customer_assets MODIFY asset_name VARCHAR(120) DEFAULT NULL');
            break;
        }
    }
    if (!in_array('engine_count', $existingAssetColumns, true)) {
        $conn->exec('ALTER TABLE customer_assets ADD COLUMN engine_count TINYINT UNSIGNED DEFAULT NULL');
    }
    if (!in_array('boat_length', $existingAssetColumns, true)) {
        $conn->exec('ALTER TABLE customer_assets ADD COLUMN boat_length DECIMAL(6,2) DEFAULT NULL');
    }
    if (!in_array('engine_details', $existingAssetColumns, true)) {
        $conn->exec('ALTER TABLE customer_assets ADD COLUMN engine_details TEXT DEFAULT NULL');
    }

    $conn->exec("UPDATE customer_assets SET serial_number = NULL WHERE serial_number IS NOT NULL AND TRIM(serial_number) = ''");
    $serialIndex = $conn->query("SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'customer_assets' AND INDEX_NAME = 'customer_assets_serial_unique'");
    if ((int)$serialIndex->fetchColumn() === 0) {
        $duplicateSerials = $conn->query("SELECT COUNT(*) FROM (
            SELECT UPPER(TRIM(serial_number)) AS normalized_serial
            FROM customer_assets
            WHERE serial_number IS NOT NULL AND TRIM(serial_number) <> ''
            GROUP BY UPPER(TRIM(serial_number))
            HAVING COUNT(*) > 1
        ) duplicates_found")->fetchColumn();
        if ((int)$duplicateSerials === 0) {
            $conn->exec('ALTER TABLE customer_assets ADD UNIQUE INDEX customer_assets_serial_unique (serial_number)');
        }
    }

    foreach (['customer_service_requests', 'workorders'] as $tableName) {
        $tableExists = $conn->prepare('SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
        $tableExists->execute([$tableName]);
        if ((int)$tableExists->fetchColumn() === 0) {
            continue;
        }
        $columns = $conn->query("SHOW COLUMNS FROM `$tableName`")->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('asset_id', $columns, true)) {
            $conn->exec("ALTER TABLE `$tableName` ADD COLUMN asset_id INT DEFAULT NULL");
        }

        $indexStmt = $conn->prepare('SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?');
        $indexStmt->execute([$tableName, $tableName . '_asset_id']);
        if ((int)$indexStmt->fetchColumn() === 0) {
            $conn->exec("ALTER TABLE `$tableName` ADD INDEX `{$tableName}_asset_id` (asset_id)");
        }
    }
}

function customer_asset_label(array $asset): string
{
    $name = trim((string)($asset['asset_name'] ?? ''));
    $details = [];
    foreach (['model_year', 'make', 'model'] as $field) {
        $value = trim((string)($asset[$field] ?? ''));
        if ($value !== '') {
            $details[] = $value;
        }
    }
    if ($name === '') {
        $type = trim((string)($asset['asset_type'] ?? ''));
        if ($type !== '') {
            $details[] = $type;
        }
        $name = $details ? implode(' ', $details) : 'Unnamed asset';
        $details = [];
    }

    return $name . ($details ? ' — ' . implode(' ', $details) : '');
}

function customer_asset_name_label(array $asset): string
{
    $name = trim((string)($asset['asset_name'] ?? ''));
    $details = [];
    foreach (['model_year', 'make', 'model'] as $field) {
        $value = trim((string)($asset[$field] ?? ''));
        if ($value !== '') {
            $details[] = $value;
        }
    }
    if ($name === '') {
        return $details ? implode(' ', $details) : 'Unnamed asset';
    }
    return $name . ($details ? ' — ' . implode(' ', $details) : '');
}

function customer_asset_selection_label(array $asset): string
{
    $label = customer_asset_label($asset);
    $serial = trim((string)($asset['serial_number'] ?? ''));
    return $serial !== '' ? $label . ' — VIN / HIN / Serial # ' . $serial : $label;
}

function customer_asset_snapshot(array $asset): string
{
    $parts = [customer_asset_label($asset)];
    $type = trim((string)($asset['asset_type'] ?? ''));
    $hours = trim((string)($asset['hours'] ?? ''));
    if ($type !== '') {
        $parts[] = 'Type: ' . $type;
    }
    if ($hours !== '') {
        $parts[] = 'Hours: ' . $hours;
    }
    if (strtolower(trim((string)($asset['asset_type'] ?? ''))) === 'boat') {
        $boatLength = trim((string)($asset['boat_length'] ?? ''));
        if ($boatLength !== '') {
            $parts[] = 'Length: ' . $boatLength . ' ft';
        }
        $engineCount = (int)($asset['engine_count'] ?? 0);
        if ($engineCount > 0) {
            $parts[] = 'Engines: ' . $engineCount;
        }
        $engineDetails = trim((string)($asset['engine_details'] ?? ''));
        if ($engineDetails !== '') {
            $parts[] = 'Engine information: ' . $engineDetails;
        }
    }
    $snapshot = implode(' | ', $parts);
    return function_exists('mb_substr') ? mb_substr($snapshot, 0, 255, 'UTF-8') : substr($snapshot, 0, 255);
}

function customer_asset_display_without_serial($snapshot): string
{
    $firstPart = trim((string)strtok((string)$snapshot, '|'));
    return preg_replace('/\s*\|.*$/', '', $firstPart) ?? $firstPart;
}