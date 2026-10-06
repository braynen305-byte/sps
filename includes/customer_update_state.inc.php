<?php

function ensure_customer_update_state_schema(PDO $conn): void
{
    $conn->exec("CREATE TABLE IF NOT EXISTS customer_update_reads (
        customer_id INT NOT NULL,
        entity_type VARCHAR(30) NOT NULL,
        entity_id INT NOT NULL,
        last_seen_at DATETIME NOT NULL,
        PRIMARY KEY (customer_id, entity_type, entity_id),
        INDEX (customer_id, last_seen_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

function ensure_staff_update_state_schema(PDO $conn): void
{
    $conn->exec("CREATE TABLE IF NOT EXISTS staff_update_reads (
        staff_id INT NOT NULL,
        entity_type VARCHAR(30) NOT NULL,
        entity_id INT NOT NULL,
        last_seen_at DATETIME NOT NULL,
        PRIMARY KEY (staff_id, entity_type, entity_id),
        INDEX (staff_id, last_seen_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

function mark_staff_update_seen(PDO $conn, int $staffId, string $entityType, int $entityId): void
{
    if ($staffId <= 0 || $entityId <= 0 || !in_array($entityType, ['workorder', 'service_request'], true)) {
        return;
    }
    ensure_staff_update_state_schema($conn);
    $stmt = $conn->prepare('INSERT INTO staff_update_reads (staff_id, entity_type, entity_id, last_seen_at) VALUES (?, ?, ?, NOW()) ON DUPLICATE KEY UPDATE last_seen_at = NOW()');
    $stmt->execute([$staffId, $entityType, $entityId]);
}

function staff_update_seen_at(PDO $conn, int $staffId, string $entityType, int $entityId): ?string
{
    if ($staffId <= 0 || $entityId <= 0) {
        return null;
    }
    ensure_staff_update_state_schema($conn);
    $stmt = $conn->prepare('SELECT last_seen_at FROM staff_update_reads WHERE staff_id = ? AND entity_type = ? AND entity_id = ? LIMIT 1');
    $stmt->execute([$staffId, $entityType, $entityId]);
    $value = $stmt->fetchColumn();
    return $value !== false ? (string)$value : null;
}

function mark_customer_update_seen(PDO $conn, int $customerId, string $entityType, int $entityId): void
{
    if ($customerId <= 0 || $entityId <= 0 || !in_array($entityType, ['workorder', 'service_request'], true)) {
        return;
    }

    ensure_customer_update_state_schema($conn);
    $stmt = $conn->prepare('INSERT INTO customer_update_reads (customer_id, entity_type, entity_id, last_seen_at) VALUES (?, ?, ?, NOW()) ON DUPLICATE KEY UPDATE last_seen_at = NOW()');
    $stmt->execute([$customerId, $entityType, $entityId]);
}

function customer_update_seen_at(PDO $conn, int $customerId, string $entityType, int $entityId): ?string
{
    if ($customerId <= 0 || $entityId <= 0) {
        return null;
    }
    ensure_customer_update_state_schema($conn);
    $stmt = $conn->prepare('SELECT last_seen_at FROM customer_update_reads WHERE customer_id = ? AND entity_type = ? AND entity_id = ? LIMIT 1');
    $stmt->execute([$customerId, $entityType, $entityId]);
    $value = $stmt->fetchColumn();
    return $value !== false ? (string)$value : null;
}

function customer_workorder_latest_activity(PDO $conn, int $workOrderId, string $createdAt): string
{
    $timestamps = [$createdAt];
    $queries = [
        ['workorder_edits', 'SELECT MAX(edited_at) FROM workorder_edits WHERE workorder_id = ?'],
        ['work_performed_entries', 'SELECT MAX(created_at) FROM work_performed_entries WHERE workorder_id = ?'],
        ['workorder_property_entry_logs', 'SELECT MAX(updated_at) FROM workorder_property_entry_logs WHERE workorder_id = ?']
    ];
    foreach ($queries as [$table, $sql]) {
        try {
            $stmt = $conn->prepare($sql);
            $stmt->execute([$workOrderId]);
            $value = $stmt->fetchColumn();
            if (is_string($value) && $value !== '') {
                $timestamps[] = $value;
            }
        } catch (Throwable $error) {
            // Optional legacy tables/columns don't prevent the dashboard from rendering.
        }
    }
    rsort($timestamps, SORT_STRING);
    return (string)($timestamps[0] ?? $createdAt);
}
