<?php

function ensure_property_entry_log_schema(PDO $conn): void
{
    $conn->exec("CREATE TABLE IF NOT EXISTS workorder_property_entry_logs (
        id INT AUTO_INCREMENT PRIMARY KEY,
        workorder_id INT NOT NULL,
        technician_id INT DEFAULT NULL,
        entry_date DATE NOT NULL,
        departure_date DATE DEFAULT NULL,
        time_entered TIME DEFAULT NULL,
        time_departed TIME DEFAULT NULL,
        logged_by INT DEFAULT NULL,
        entry_source VARCHAR(30) NOT NULL DEFAULT 'manual',
        departed_by INT DEFAULT NULL,
        departure_source VARCHAR(30) DEFAULT NULL,
        entry_reporter_name VARCHAR(150) DEFAULT NULL,
        entry_reported_at DATETIME DEFAULT NULL,
        departure_reporter_name VARCHAR(150) DEFAULT NULL,
        departure_reported_at DATETIME DEFAULT NULL,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX(workorder_id, entry_date)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
    $columns = [
        'technician_id' => 'INT DEFAULT NULL',
        'departure_date' => 'DATE DEFAULT NULL',
        'entry_source' => "VARCHAR(30) NOT NULL DEFAULT 'manual'",
        'departed_by' => 'INT DEFAULT NULL',
        'departure_source' => 'VARCHAR(30) DEFAULT NULL',
        'entry_reporter_name' => 'VARCHAR(150) DEFAULT NULL',
        'entry_reported_at' => 'DATETIME DEFAULT NULL',
        'departure_reporter_name' => 'VARCHAR(150) DEFAULT NULL',
        'departure_reported_at' => 'DATETIME DEFAULT NULL',
        'updated_at' => 'TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP'
    ];

    $existingColumnRows = $conn->query('SHOW COLUMNS FROM workorder_property_entry_logs')->fetchAll(PDO::FETCH_ASSOC);
    $existingColumnNames = array_column($existingColumnRows, 'Field');
    foreach ($columns as $columnName => $columnDefinition) {
        if (!in_array($columnName, $existingColumnNames, true)) {
            $conn->exec("ALTER TABLE workorder_property_entry_logs ADD COLUMN `$columnName` $columnDefinition");
            $existingColumnNames[] = $columnName;
        }
    }

    $conn->exec('UPDATE workorder_property_entry_logs SET departure_date = entry_date WHERE departure_date IS NULL AND time_departed IS NOT NULL');
    $conn->exec("UPDATE workorder_property_entry_logs SET technician_id = logged_by WHERE technician_id IS NULL AND entry_source IN ('technician_button', 'admin_button') AND logged_by IS NOT NULL");
}

function property_visit_billable_seconds(PDO $conn, int $workOrderId, bool $includeActiveVisit = false): int
{
    $stmt = $conn->prepare("SELECT COALESCE(SUM(
        CASE
            WHEN time_departed IS NOT NULL THEN GREATEST(0, TIMESTAMPDIFF(SECOND, TIMESTAMP(entry_date, time_entered), TIMESTAMP(COALESCE(departure_date, entry_date), time_departed)))
            WHEN ? = 1 THEN GREATEST(0, TIMESTAMPDIFF(SECOND, TIMESTAMP(entry_date, time_entered), NOW()))
            ELSE 0
        END
    ), 0)
    FROM workorder_property_entry_logs
    WHERE workorder_id = ? AND time_entered IS NOT NULL");
    $stmt->execute([$includeActiveVisit ? 1 : 0, $workOrderId]);
    return max(0, (int)$stmt->fetchColumn());
}

function property_visit_billable_seconds_by_technician(PDO $conn, int $workOrderId, bool $includeActiveVisit = false): array
{
    $stmt = $conn->prepare("SELECT technician_id, COALESCE(SUM(
        CASE
            WHEN time_departed IS NOT NULL THEN GREATEST(0, TIMESTAMPDIFF(SECOND, TIMESTAMP(entry_date, time_entered), TIMESTAMP(COALESCE(departure_date, entry_date), time_departed)))
            WHEN ? = 1 THEN GREATEST(0, TIMESTAMPDIFF(SECOND, TIMESTAMP(entry_date, time_entered), NOW()))
            ELSE 0
        END
    ), 0) AS total_seconds
    FROM workorder_property_entry_logs
    WHERE workorder_id = ? AND time_entered IS NOT NULL
    GROUP BY technician_id
    ORDER BY technician_id");
    $stmt->execute([$includeActiveVisit ? 1 : 0, $workOrderId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}
