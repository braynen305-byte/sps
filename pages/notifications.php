<?php
session_start();
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header('Location: /sps/login.php');
    exit;
}
require_once '../includes/dbh.inc.php';
require_once '../includes/portal_notifications.inc.php';
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$role = strtolower(trim((string)($_SESSION['role'] ?? '')));
$recipientType = $role === 'customer' ? 'customer' : 'staff';
$recipientId = $recipientType === 'customer' ? (int)($_SESSION['customer_id'] ?? 0) : (int)($_SESSION['user_id'] ?? 0);
if ($recipientId <= 0 || !in_array($role, ['customer', 'admin', 'office', 'staff', 'technician'], true)) {
    http_response_code(403);
    exit('You do not have access to these notifications.');
}
ensure_portal_notification_schema($conn);
if ($_SERVER['REQUEST_METHOD'] === 'POST' && hash_equals((string)($_SESSION['csrf_token'] ?? ''), (string)($_POST['csrf_token'] ?? ''))) {
    if (($_POST['action'] ?? '') === 'read_all') {
        $markAll = $conn->prepare('UPDATE portal_notifications SET read_at = NOW() WHERE recipient_type = ? AND recipient_id = ? AND read_at IS NULL');
        $markAll->execute([$recipientType, $recipientId]);
    } elseif (($_POST['action'] ?? '') === 'read') {
        mark_portal_notification_read($conn, $recipientType, $recipientId, (int)($_POST['notification_id'] ?? 0));
    }
    header('Location: /sps/pages/notifications.php');
    exit;
}
$notifications = fetch_portal_notifications($conn, $recipientType, $recipientId, 100, false);
$selectedNotification = null;
$selectedNotificationId = (int)($_GET['id'] ?? 0);
if ($selectedNotificationId > 0) {
    $selectedStmt = $conn->prepare('SELECT id, category, subject, message, link, created_at, read_at FROM portal_notifications WHERE id = ? AND recipient_type = ? AND recipient_id = ? LIMIT 1');
    $selectedStmt->execute([$selectedNotificationId, $recipientType, $recipientId]);
    $selectedNotification = $selectedStmt->fetch(PDO::FETCH_ASSOC) ?: null;
}
$historyNotifications = $selectedNotification
    ? array_values(array_filter($notifications, static function (array $notification) use ($selectedNotificationId): bool {
        return (int)($notification['id'] ?? 0) !== $selectedNotificationId;
    }))
    : $notifications;
$title = 'Notifications';
require_once '../includes/header.php';
?>
<style>
    .notifications-page { width:min(1000px,calc(100% - 36px)); margin:32px auto 48px; color:#0f172a; }
    .notifications-header { display:flex; justify-content:space-between; align-items:center; gap:12px; flex-wrap:wrap; margin-bottom:16px; }
    .notifications-header h2 { margin:0; font-size:clamp(22px,3vw,30px); }
    .notification-list { overflow:hidden; background:#fff; border:1px solid #e5e7eb; border-radius:12px; box-shadow:0 3px 14px rgba(15,23,42,.05); }
    .notification-item { display:flex; width:100%; margin:0; justify-content:space-between; align-items:center; gap:14px; padding:15px 17px; border-bottom:1px solid #eef2f7; }
    .notification-item:last-child { border-bottom:0; }
    .notification-item.is-unread { background:#eff6ff; box-shadow:inset 3px 0 #2563eb; }
    .notification-content { min-width:0; }
    .notification-item form { width:auto; flex:0 0 auto; margin:0 0 0 auto !important; align-self:center; }
    .notification-content a { color:#123d71; text-decoration:none; font-weight:800; }
    .notification-content a:hover { text-decoration:underline; }
    .notification-content p { margin:5px 0; color:#475569; font-size:13px; }
    .notification-content time { color:#64748b; font-size:11px; }
    .notification-detail { margin-bottom:16px; padding:18px; border:1px solid #dbeafe; border-radius:12px; background:#fff; box-shadow:0 3px 14px rgba(15,23,42,.05); }
    .notification-detail h3 { margin:0 0 8px; color:#123d71; font-size:18px; }
    .notification-detail p { color:#334155; line-height:1.55; white-space:pre-wrap; overflow-wrap:anywhere; }
    .notification-detail time { display:block; margin-top:12px; color:#64748b; font-size:11px; }
    .notification-detail-link { display:inline-block; margin-top:12px; color:#1d4ed8; font-size:13px; font-weight:800; text-decoration:none; }
    .notification-detail-link:hover { text-decoration:underline; }
    .notification-action { display:inline-flex; align-items:center; justify-content:center; flex:none; width:max-content; min-width:0; margin:0; padding:7px 10px; border:1px solid #cbd5e1; border-radius:7px; background:#fff; color:#1d4ed8; font-size:12px; font-weight:700; line-height:1.2; white-space:nowrap; cursor:pointer; }
    @media(max-width:600px) { .notifications-page { width:calc(100% - 24px); } .notification-item { padding:12px; } }
</style>
<main class="notifications-page">
    <div class="notifications-header">
        <div><h2>Notifications</h2><p style="margin:5px 0 0;color:#64748b;">New and previous updates for your account.</p></div>
        <form method="post" style="margin:0;">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8'); ?>">
            <input type="hidden" name="action" value="read_all">
            <button class="notification-action" type="submit">Mark all as read</button>
        </form>
    </div>
    <?php if ($selectedNotification): ?>
        <?php $selectedLink = (string)($selectedNotification['link'] ?? ''); if (strpos($selectedLink, '/sps/') !== 0) { $selectedLink = ''; } ?>
        <section class="notification-detail" aria-label="Notification details">
            <h3><?php echo htmlspecialchars($selectedNotification['subject'], ENT_QUOTES, 'UTF-8'); ?></h3>
            <p><?php echo htmlspecialchars($selectedNotification['message'], ENT_QUOTES, 'UTF-8'); ?></p>
            <time><?php echo htmlspecialchars(date('M j, Y g:i A', strtotime($selectedNotification['created_at'])), ENT_QUOTES, 'UTF-8'); ?></time>
            <?php if ($selectedLink !== ''): ?><a class="notification-detail-link" href="<?php echo htmlspecialchars($selectedLink, ENT_QUOTES, 'UTF-8'); ?>">Open related record</a><?php endif; ?>
        </section>
    <?php endif; ?>
    <?php if (!$selectedNotification || !empty($historyNotifications)): ?>
    <div class="notification-list">
        <?php if (empty($historyNotifications)): ?>
            <p style="padding:20px;color:#64748b;margin:0;">You have no notifications yet.</p>
        <?php else: ?>
            <?php foreach ($historyNotifications as $notification): ?>
                <?php $notificationLink = (string)($notification['link'] ?? ''); if (strpos($notificationLink, '/sps/') !== 0) { $notificationLink = ''; } ?>
                <article class="notification-item <?php echo empty($notification['read_at']) ? 'is-unread' : ''; ?>">
                    <div class="notification-content">
                        <a class="notification-link" data-notification-id="<?php echo (int)$notification['id']; ?>" data-unread="<?php echo empty($notification['read_at']) ? '1' : '0'; ?>" href="/sps/pages/notifications.php?id=<?php echo (int)$notification['id']; ?>"><?php echo htmlspecialchars($notification['subject'], ENT_QUOTES, 'UTF-8'); ?></a>
                        <p><?php echo nl2br(htmlspecialchars($notification['message'], ENT_QUOTES, 'UTF-8')); ?></p>
                        <time><?php echo htmlspecialchars(date('M j, Y g:i A', strtotime($notification['created_at'])), ENT_QUOTES, 'UTF-8'); ?></time>
                    </div>
                    <?php if (empty($notification['read_at'])): ?>
                        <form method="post" style="margin:0;">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8'); ?>">
                            <input type="hidden" name="action" value="read">
                            <input type="hidden" name="notification_id" value="<?php echo (int)$notification['id']; ?>">
                            <button class="notification-action" type="submit">Mark read</button>
                        </form>
                    <?php endif; ?>
                </article>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
    <?php endif; ?>
</main>
<script>
document.querySelectorAll('.notification-link[data-unread="1"]').forEach(function (link) {
    link.addEventListener('click', function (event) {
        event.preventDefault();
        var form = new URLSearchParams();
        form.set('action', 'read');
        form.set('notification_id', link.dataset.notificationId || '0');
        form.set('csrf_token', <?php echo json_encode($_SESSION['csrf_token']); ?>);
        fetch('/sps/pages/notifications_feed.php', {method:'POST', credentials:'same-origin', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body:form.toString()})
            .catch(function () {})
            .then(function () { window.location.href = link.href; });
    });
});
</script>
<?php require_once '../includes/footer.php'; ?>
