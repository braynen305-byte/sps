<?php

function ensure_portal_notification_schema(PDO $conn): void
{
    $conn->exec("CREATE TABLE IF NOT EXISTS portal_notifications (
        id INT AUTO_INCREMENT PRIMARY KEY,
        recipient_type VARCHAR(20) NOT NULL,
        recipient_id INT NOT NULL,
        category VARCHAR(80) NOT NULL,
        subject VARCHAR(255) NOT NULL,
        message LONGTEXT NOT NULL,
        link VARCHAR(255) DEFAULT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        read_at DATETIME DEFAULT NULL,
        INDEX recipient_unread (recipient_type, recipient_id, read_at, id),
        INDEX recipient_recent (recipient_type, recipient_id, id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

function create_portal_notification(PDO $conn, string $recipientType, int $recipientId, string $category, string $subject, string $message, string $link = ''): bool
{
    if (!in_array($recipientType, ['staff', 'customer'], true) || $recipientId <= 0) {
        return false;
    }
    ensure_portal_notification_schema($conn);
    $stmt = $conn->prepare('INSERT INTO portal_notifications (recipient_type, recipient_id, category, subject, message, link) VALUES (?, ?, ?, ?, ?, ?)');
    return $stmt->execute([$recipientType, $recipientId, $category, $subject, $message, $link !== '' ? $link : null]);
}

function fetch_portal_notifications(PDO $conn, string $recipientType, int $recipientId, int $limit = 20, bool $unreadOnly = false): array
{
    ensure_portal_notification_schema($conn);
    $limit = max(1, min(100, $limit));
    $sql = 'SELECT id, category, subject, message, link, created_at, read_at FROM portal_notifications WHERE recipient_type = ? AND recipient_id = ?';
    if ($unreadOnly) {
        $sql .= ' AND read_at IS NULL';
    }
    $sql .= ' ORDER BY created_at DESC, id DESC LIMIT ' . $limit;
    $stmt = $conn->prepare($sql);
    $stmt->execute([$recipientType, $recipientId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function count_unread_portal_notifications(PDO $conn, string $recipientType, int $recipientId): int
{
    ensure_portal_notification_schema($conn);
    $stmt = $conn->prepare('SELECT COUNT(*) FROM portal_notifications WHERE recipient_type = ? AND recipient_id = ? AND read_at IS NULL');
    $stmt->execute([$recipientType, $recipientId]);
    return (int)$stmt->fetchColumn();
}

function mark_portal_notification_read(PDO $conn, string $recipientType, int $recipientId, int $notificationId): bool
{
    ensure_portal_notification_schema($conn);
    $stmt = $conn->prepare('UPDATE portal_notifications SET read_at = NOW() WHERE id = ? AND recipient_type = ? AND recipient_id = ? AND read_at IS NULL');
    return $stmt->execute([$notificationId, $recipientType, $recipientId]);
}
