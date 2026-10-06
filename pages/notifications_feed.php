<?php
session_start();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    http_response_code(401);
    echo json_encode(['error' => 'Authentication required.']);
    exit;
}
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

require_once '../includes/dbh.inc.php';
require_once '../includes/portal_notifications.inc.php';
$role = strtolower(trim((string)($_SESSION['role'] ?? '')));
$recipientType = $role === 'customer' ? 'customer' : 'staff';
$recipientId = $recipientType === 'customer'
    ? (int)($_SESSION['customer_id'] ?? 0)
    : (int)($_SESSION['user_id'] ?? 0);
if ($recipientId <= 0 || !in_array($role, ['customer', 'admin', 'office', 'staff', 'technician'], true)) {
    http_response_code(403);
    echo json_encode(['error' => 'Notification access denied.']);
    exit;
}
ensure_portal_notification_schema($conn);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ((string)($_SESSION['csrf_token'] ?? '') === '' || !hash_equals((string)$_SESSION['csrf_token'], (string)($_POST['csrf_token'] ?? ''))) {
        http_response_code(403);
        echo json_encode(['error' => 'Request verification failed.']);
        exit;
    }
    $action = (string)($_POST['action'] ?? 'read');
    if ($action === 'read_all') {
        $stmt = $conn->prepare('UPDATE portal_notifications SET read_at = NOW() WHERE recipient_type = ? AND recipient_id = ? AND read_at IS NULL');
        $stmt->execute([$recipientType, $recipientId]);
    } else {
        $notificationId = (int)($_POST['notification_id'] ?? 0);
        if ($notificationId <= 0) {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid notification.']);
            exit;
        }
        mark_portal_notification_read($conn, $recipientType, $recipientId, $notificationId);
    }
    echo json_encode(['ok' => true, 'unread_count' => count_unread_portal_notifications($conn, $recipientType, $recipientId)]);
    exit;
}

$notifications = fetch_portal_notifications($conn, $recipientType, $recipientId, 8, false);
echo json_encode([
    'unread_count' => count_unread_portal_notifications($conn, $recipientType, $recipientId),
    'notifications' => $notifications
], JSON_UNESCAPED_SLASHES);
