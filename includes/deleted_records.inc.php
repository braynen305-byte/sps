<?php

function ensure_deleted_records_schema(PDO $conn): void
{
    $conn->exec("CREATE TABLE IF NOT EXISTS deleted_records (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        record_type VARCHAR(40) NOT NULL,
        original_id INT NOT NULL,
        display_label VARCHAR(255) NOT NULL,
        deleted_by_role VARCHAR(30) NOT NULL,
        deleted_by_id INT NOT NULL DEFAULT 0,
        deleted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        payload LONGTEXT NOT NULL,
        INDEX (deleted_at),
        INDEX (record_type, deleted_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

function deleted_record_table_exists(PDO $conn, string $table): bool
{
    $stmt = $conn->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?');
    $stmt->execute([$table]);
    return (int)$stmt->fetchColumn() > 0;
}

function deleted_record_insert_row(PDO $conn, string $table, array $row): void
{
    $allowedTables = [
        'workorders', 'customer_service_requests', 'workorder_edits',
        'work_performed_entries', 'workorder_view_state', 'workorder_technicians',
        'workorder_property_entry_logs', 'customer_update_reads', 'staff_update_reads'
    ];
    if (!in_array($table, $allowedTables, true) || empty($row)) {
        throw new InvalidArgumentException('Invalid archived row.');
    }

    $columns = array_keys($row);
    $quotedColumns = array_map(static function ($column) {
        return '`' . str_replace('`', '``', (string)$column) . '`';
    }, $columns);
    $placeholders = implode(', ', array_fill(0, count($columns), '?'));
    $sql = 'INSERT INTO `' . $table . '` (' . implode(', ', $quotedColumns) . ') VALUES (' . $placeholders . ')';
    $conn->prepare($sql)->execute(array_values($row));
}

function archive_service_request(PDO $conn, int $requestId, string $deletedByRole, int $deletedById, ?int $customerId = null): bool
{
    ensure_deleted_records_schema($conn);
    $conn->beginTransaction();
    try {
        $sql = 'SELECT * FROM customer_service_requests WHERE id = ?';
        $params = [$requestId];
        if ($customerId !== null) {
            $sql .= ' AND customer_id = ?';
            $params[] = $customerId;
        }
        $requestStmt = $conn->prepare($sql . ' LIMIT 1 FOR UPDATE');
        $requestStmt->execute($params);
        $request = $requestStmt->fetch(PDO::FETCH_ASSOC);
        if (!$request) {
            $conn->rollBack();
            return false;
        }
        if (!empty($request['approved_workorder_id'])) {
            $workorderStmt = $conn->prepare('SELECT id FROM workorders WHERE id = ? LIMIT 1');
            $workorderStmt->execute([(int)$request['approved_workorder_id']]);
            if ($workorderStmt->fetchColumn() !== false) {
                $conn->rollBack();
                return false;
            }
            $request['approved_workorder_id'] = null;
        }

        $requestLabel = trim((string)($request['request_number'] ?? ''));
        if ($requestLabel === '') {
            $requestLabel = 'SR-' . str_pad((string)$requestId, 5, '0', STR_PAD_LEFT);
        }
        $payload = json_encode(['service_request' => $request], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $archiveStmt = $conn->prepare('INSERT INTO deleted_records (record_type, original_id, display_label, deleted_by_role, deleted_by_id, payload) VALUES (?, ?, ?, ?, ?, ?)');
        $archiveStmt->execute(['service_request', $requestId, $requestLabel, $deletedByRole, $deletedById, $payload]);
        $conn->prepare('DELETE FROM customer_service_requests WHERE id = ?')->execute([$requestId]);
        $conn->commit();
        return true;
    } catch (Throwable $error) {
        if ($conn->inTransaction()) {
            $conn->rollBack();
        }
        throw $error;
    }
}

function archive_workorder(PDO $conn, int $workorderId, string $deletedByRole, int $deletedById): bool
{
    ensure_deleted_records_schema($conn);
    $conn->beginTransaction();
    try {
        $workorderStmt = $conn->prepare('SELECT * FROM workorders WHERE id = ? LIMIT 1 FOR UPDATE');
        $workorderStmt->execute([$workorderId]);
        $workorder = $workorderStmt->fetch(PDO::FETCH_ASSOC);
        if (!$workorder) {
            $conn->rollBack();
            return false;
        }

        $requestStmt = $conn->prepare('SELECT * FROM customer_service_requests WHERE approved_workorder_id = ? FOR UPDATE');
        $requestStmt->execute([$workorderId]);
        $serviceRequests = $requestStmt->fetchAll(PDO::FETCH_ASSOC);
        $relations = [
            'workorder_edits' => ['workorder_id = ?', [$workorderId]],
            'work_performed_entries' => ['workorder_id = ?', [$workorderId]],
            'workorder_view_state' => ['workorder_id = ?', [$workorderId]],
            'workorder_technicians' => ['workorder_id = ?', [$workorderId]],
            'workorder_property_entry_logs' => ['workorder_id = ?', [$workorderId]],
            'customer_update_reads' => ['entity_type = ? AND entity_id = ?', ['workorder', $workorderId]],
            'staff_update_reads' => ['entity_type = ? AND entity_id = ?', ['workorder', $workorderId]]
        ];
        $relatedRows = [];
        foreach ($relations as $table => [$where, $whereParams]) {
            if (!deleted_record_table_exists($conn, $table)) {
                continue;
            }
            $relatedStmt = $conn->prepare('SELECT * FROM `' . $table . '` WHERE ' . $where);
            $relatedStmt->execute($whereParams);
            $relatedRows[$table] = $relatedStmt->fetchAll(PDO::FETCH_ASSOC);
        }

        $orderLabel = trim((string)($workorder['order_number'] ?? ''));
        if ($orderLabel === '') {
            $orderLabel = 'WO-' . str_pad((string)$workorderId, 4, '0', STR_PAD_LEFT);
        }
        $payload = json_encode([
            'workorder' => $workorder,
            'service_requests' => $serviceRequests,
            'related_rows' => $relatedRows
        ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $archiveStmt = $conn->prepare('INSERT INTO deleted_records (record_type, original_id, display_label, deleted_by_role, deleted_by_id, payload) VALUES (?, ?, ?, ?, ?, ?)');
        $archiveStmt->execute(['workorder_bundle', $workorderId, $orderLabel, $deletedByRole, $deletedById, $payload]);

        foreach ($relations as $table => [$where, $whereParams]) {
            if (isset($relatedRows[$table])) {
                $conn->prepare('DELETE FROM `' . $table . '` WHERE ' . $where)->execute($whereParams);
            }
        }
        $conn->prepare('DELETE FROM customer_service_requests WHERE approved_workorder_id = ?')->execute([$workorderId]);
        $conn->prepare('DELETE FROM workorders WHERE id = ?')->execute([$workorderId]);
        $conn->commit();
        return true;
    } catch (Throwable $error) {
        if ($conn->inTransaction()) {
            $conn->rollBack();
        }
        throw $error;
    }
}

function restore_deleted_record(PDO $conn, int $archiveId): void
{
    ensure_deleted_records_schema($conn);
    $conn->beginTransaction();
    try {
        $archiveStmt = $conn->prepare('SELECT * FROM deleted_records WHERE id = ? LIMIT 1 FOR UPDATE');
        $archiveStmt->execute([$archiveId]);
        $archive = $archiveStmt->fetch(PDO::FETCH_ASSOC);
        if (!$archive) {
            throw new RuntimeException('The archived record was not found.');
        }
        $payload = json_decode((string)$archive['payload'], true, 512, JSON_THROW_ON_ERROR);
        if ($archive['record_type'] === 'service_request') {
            deleted_record_insert_row($conn, 'customer_service_requests', $payload['service_request'] ?? []);
        } elseif ($archive['record_type'] === 'workorder_bundle') {
            deleted_record_insert_row($conn, 'workorders', $payload['workorder'] ?? []);
            foreach (($payload['service_requests'] ?? []) as $serviceRequest) {
                deleted_record_insert_row($conn, 'customer_service_requests', $serviceRequest);
            }
            foreach (($payload['related_rows'] ?? []) as $table => $rows) {
                foreach ($rows as $row) {
                    deleted_record_insert_row($conn, (string)$table, $row);
                }
            }
        } else {
            throw new RuntimeException('This archive entry cannot be restored.');
        }
        $conn->prepare('DELETE FROM deleted_records WHERE id = ?')->execute([$archiveId]);
        $conn->commit();
    } catch (Throwable $error) {
        if ($conn->inTransaction()) {
            $conn->rollBack();
        }
        throw $error;
    }
}

function purge_expired_deleted_records(PDO $conn): int
{
    ensure_deleted_records_schema($conn);
    $expiredStmt = $conn->prepare('SELECT id, record_type, original_id, payload FROM deleted_records WHERE deleted_at < DATE_SUB(NOW(), INTERVAL 7 MONTH)');
    $expiredStmt->execute();
    $expiredRecords = $expiredStmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($expiredRecords as $record) {
        $payload = json_decode((string)$record['payload'], true);
        if ($record['record_type'] === 'workorder_bundle') {
            if (deleted_record_table_exists($conn, 'customer_hidden_workorders')) {
                $conn->prepare('DELETE FROM customer_hidden_workorders WHERE workorder_id = ?')->execute([(int)$record['original_id']]);
            }
            if (deleted_record_table_exists($conn, 'customer_hidden_service_requests')) {
                foreach (($payload['service_requests'] ?? []) as $serviceRequest) {
                    $conn->prepare('DELETE FROM customer_hidden_service_requests WHERE service_request_id = ?')->execute([(int)($serviceRequest['id'] ?? 0)]);
                }
            }
        } elseif ($record['record_type'] === 'service_request' && deleted_record_table_exists($conn, 'customer_hidden_service_requests')) {
            $conn->prepare('DELETE FROM customer_hidden_service_requests WHERE service_request_id = ?')->execute([(int)$record['original_id']]);
        }
        $conn->prepare('DELETE FROM deleted_records WHERE id = ?')->execute([(int)$record['id']]);
    }
    return count($expiredRecords);
}