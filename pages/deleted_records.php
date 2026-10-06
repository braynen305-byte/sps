    <a href="<?php echo $currentRole === 'admin' ? '/sps/pages/workorder_dashboard.php' : '/sps/pages/manage_service_requests.php'; ?>" style="color:#1d4ed8; font-weight:700; text-decoration:none;">Back</a>
<?php
session_start();
$currentRole = strtolower(trim((string)($_SESSION['role'] ?? '')));
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true || !in_array($currentRole, ['admin', 'office'], true)) {
    header('Location: /sps/login.php');
    exit;
}

require_once '../includes/dbh.inc.php';
require_once '../includes/deleted_records.inc.php';
ensure_deleted_records_schema($conn);
purge_expired_deleted_records($conn);

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$message = '';
$messageType = 'success';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['csrf_token'], (string)($_POST['csrf_token'] ?? ''))) {
        $message = 'Your session could not be verified. Please reload the page and try again.';
        $messageType = 'error';
    } elseif (($_POST['action'] ?? '') === 'restore') {
        try {
            restore_deleted_record($conn, (int)($_POST['archive_id'] ?? 0));
            $message = 'The archived record was restored.';
        } catch (Throwable $restoreError) {
            $message = 'The record could not be restored. It may already exist or its archived data may be invalid.';
            $messageType = 'error';
            error_log('Deleted record restore failed: ' . $restoreError->getMessage());
        }
    }
}

$deletedRecords = $conn->query('SELECT id, record_type, original_id, display_label, deleted_by_role, deleted_by_id, deleted_at, payload FROM deleted_records ORDER BY deleted_at DESC')->fetchAll(PDO::FETCH_ASSOC);
$title = 'Trash / Restore';
require_once '../includes/header.php';
?>

<main style="max-width:1400px; margin:32px auto 48px; padding:0 18px;">
    <header style="display:flex; flex-wrap:wrap; align-items:center; justify-content:space-between; gap:12px; margin-bottom:18px;">
        <h1 style="margin:0; font-size:24px;">Trash / Restore</h1>
        <a href="/sps/pages/workorder_dashboard.php" style="color:#1d4ed8; font-weight:700; text-decoration:none;">Back to Work Orders</a>
    </header>
    <p style="margin:0 0 16px; color:#475569;">Archived work orders and service requests remain recoverable for at least 7 months. Expired records are permanently removed by the scheduled cleanup.</p>

    <?php if ($message !== ''): ?>
        <div role="status" style="margin:0 0 16px; padding:12px 14px; border:1px solid <?php echo $messageType === 'error' ? '#fecaca' : '#bbf7d0'; ?>; border-radius:6px; background:<?php echo $messageType === 'error' ? '#fef2f2' : '#f0fdf4'; ?>; color:<?php echo $messageType === 'error' ? '#991b1b' : '#166534'; ?>; font-weight:700;">
            <?php echo htmlspecialchars($message, ENT_QUOTES, 'UTF-8'); ?>
        </div>
    <?php endif; ?>

    <?php if (empty($deletedRecords)): ?>
        <p style="padding:18px 0; color:#64748b;">Trash is empty.</p>
    <?php else: ?>
        <div style="overflow-x:auto; border:1px solid #e2e8f0; border-radius:6px;">
            <table style="width:100%; border-collapse:collapse; background:#fff;">
                <thead>
                    <tr style="background:#f8fafc; text-align:left;">
                        <th style="padding:10px;">Record</th>
                        <th style="padding:10px;">Type</th>
                        <th style="padding:10px;">Deleted</th>
                        <th style="padding:10px;">Purge eligible after</th>
                        <th style="padding:10px;">Snapshot</th>
                        <th style="padding:10px;">Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($deletedRecords as $record): ?>
                        <?php
                            $payload = json_decode((string)$record['payload'], true);
                            $purgeDate = (new DateTimeImmutable((string)$record['deleted_at']))->modify('+7 months');
                            $recordTypeLabel = $record['record_type'] === 'workorder_bundle'
                                ? 'Work Order' . (!empty($payload['service_requests']) ? ' + Service Request' : '')
                                : 'Service Request';
                            $snapshot = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
                        ?>
                        <tr style="border-top:1px solid #e2e8f0; vertical-align:top;">
                            <td style="padding:10px; font-weight:700;"><?php echo htmlspecialchars((string)$record['display_label'], ENT_QUOTES, 'UTF-8'); ?></td>
                            <td style="padding:10px;"><?php echo htmlspecialchars($recordTypeLabel, ENT_QUOTES, 'UTF-8'); ?></td>
                            <td style="padding:10px; white-space:nowrap;"><?php echo htmlspecialchars((string)$record['deleted_at'], ENT_QUOTES, 'UTF-8'); ?><br><small><?php echo htmlspecialchars((string)$record['deleted_by_role'], ENT_QUOTES, 'UTF-8'); ?> #<?php echo (int)$record['deleted_by_id']; ?></small></td>
                            <td style="padding:10px; white-space:nowrap;"><?php echo htmlspecialchars($purgeDate->format('Y-m-d H:i'), ENT_QUOTES, 'UTF-8'); ?></td>
                            <td style="padding:10px; min-width:220px;">
                                <details>
                                    <summary>View archived details</summary>
                                    <pre style="max-width:560px; max-height:320px; overflow:auto; white-space:pre-wrap; overflow-wrap:anywhere; font-size:11px;"><?php echo htmlspecialchars($snapshot ?: '{}', ENT_QUOTES, 'UTF-8'); ?></pre>
                                </details>
                            </td>
                            <td style="padding:10px;">
                                <form method="post" onsubmit="return confirm('Restore this record and its linked data?');">
                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8'); ?>">
                                    <input type="hidden" name="action" value="restore">
                                    <input type="hidden" name="archive_id" value="<?php echo (int)$record['id']; ?>">
                                    <button type="submit" style="padding:7px 10px; border:1px solid #16a34a; border-radius:5px; background:#f0fdf4; color:#166534; font-weight:700; cursor:pointer;">Restore</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</main>

<?php require_once '../includes/footer.php'; ?>