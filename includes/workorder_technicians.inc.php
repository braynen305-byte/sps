<?php

function ensure_workorder_technicians_schema(PDO $conn): void
{
    $conn->exec("CREATE TABLE IF NOT EXISTS workorder_technicians (
        id INT AUTO_INCREMENT PRIMARY KEY,
        workorder_id INT NOT NULL,
        technician_id INT NOT NULL,
        assigned_by INT DEFAULT NULL,
        assigned_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY workorder_technician (workorder_id, technician_id),
        INDEX (technician_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

function sync_primary_workorder_technician(PDO $conn, int $workOrderId, $primaryTechnicianId): void
{
    $technicianId = (int)$primaryTechnicianId;
    if ($workOrderId <= 0 || $technicianId <= 0) {
        return;
    }

    $stmt = $conn->prepare('INSERT IGNORE INTO workorder_technicians (workorder_id, technician_id) VALUES (?, ?)');
    $stmt->execute([$workOrderId, $technicianId]);
}

function is_workorder_technician_assigned(PDO $conn, int $workOrderId, int $technicianId, $primaryTechnicianId = 0): bool
{
    if ($workOrderId <= 0 || $technicianId <= 0) {
        return false;
    }
    if ((int)$primaryTechnicianId === $technicianId) {
        return true;
    }

    $stmt = $conn->prepare('SELECT 1 FROM workorder_technicians WHERE workorder_id = ? AND technician_id = ? LIMIT 1');
    $stmt->execute([$workOrderId, $technicianId]);
    return (bool)$stmt->fetchColumn();
}

function get_workorder_technician_ids(PDO $conn, int $workOrderId, $primaryTechnicianId = 0): array
{
    $ids = [];
    if ((int)$primaryTechnicianId > 0) {
        $ids[] = (int)$primaryTechnicianId;
    }

    $stmt = $conn->prepare('SELECT technician_id FROM workorder_technicians WHERE workorder_id = ? ORDER BY assigned_at, technician_id');
    $stmt->execute([$workOrderId]);
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $technicianId) {
        $technicianId = (int)$technicianId;
        if ($technicianId > 0 && !in_array($technicianId, $ids, true)) {
            $ids[] = $technicianId;
        }
    }
    return $ids;
}
