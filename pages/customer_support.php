<?php
session_start();
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true || strtolower($_SESSION['role'] ?? '') !== 'customer') {
    header('Location: /sps/customer_login.php');
    exit;
}

require_once '../includes/dbh.inc.php';
$customerId = (int)($_SESSION['customer_id'] ?? 0);
$message = '';

$conn->exec("CREATE TABLE IF NOT EXISTS customer_support_messages (
    id INT AUTO_INCREMENT PRIMARY KEY,
    customer_id INT NOT NULL,
    message LONGTEXT NOT NULL,
    status VARCHAR(30) NOT NULL DEFAULT 'Open',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX(customer_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $ticketMessage = trim((string)($_POST['message'] ?? ''));
    if ($ticketMessage !== '') {
        $insert = $conn->prepare('INSERT INTO customer_support_messages (customer_id, message, status) VALUES (?, ?, "Open")');
        $insert->execute([$customerId, $ticketMessage]);
        $message = 'Your support request was sent successfully.';
    } else {
        $message = 'Please enter a message before sending your support request.';
    }
}

$ticketStmt = $conn->prepare('SELECT * FROM customer_support_messages WHERE customer_id = ? ORDER BY created_at DESC');
$ticketStmt->execute([$customerId]);
$tickets = $ticketStmt->fetchAll(PDO::FETCH_ASSOC);

$title = 'Customer Support';
require_once '../includes/header.php';
?>

<div style="max-width: 980px; margin: 32px auto 48px; padding: 0 18px;">
    <h2>Live Support</h2>
    <p>Use this area to send a message to the support team about your service request, timeline, or equipment issue.</p>

    <?php if ($message !== ''): ?>
        <p style="color:#166534; font-weight:600; margin-bottom: 18px;"><?php echo htmlspecialchars($message, ENT_QUOTES, 'UTF-8'); ?></p>
    <?php endif; ?>

    <div style="display:grid; grid-template-columns: 1.1fr 0.9fr; gap: 20px; align-items:start;">
        <div style="background:#fff; border:1px solid #e5e7eb; border-radius:10px; box-shadow:0 1px 10px rgba(0,0,0,0.04); padding:20px;">
            <h3 style="margin-top:0;">Send a Message</h3>
            <form method="post">
                <label for="message">Describe what you need help with</label>
                <textarea id="message" name="message" rows="8" required style="width:100%; resize:vertical; padding:10px; border:1px solid #cbd5e1; border-radius:6px;"></textarea>
                <div style="margin-top: 16px;">
                    <button type="submit">Send Support Request</button>
                    <a href="/sps/pages/customer_dashboard.php" style="display:inline-block; background:#6b7280; color:#fff; text-decoration:none; padding:10px 16px; border-radius:6px; font-weight:700; margin-left:10px;">Back to Dashboard</a>
                </div>
            </form>
        </div>

        <div style="background:#f8fafc; border:1px solid #e5e7eb; border-radius:10px; padding:20px;">
            <h3 style="margin-top:0;">Support Queue</h3>
            <?php if (empty($tickets)): ?>
                <p>No support requests yet.</p>
            <?php else: ?>
                <div style="display:flex; flex-direction:column; gap:12px;">
                    <?php foreach ($tickets as $ticket): ?>
                        <div style="background:#fff; border:1px solid #dbeafe; border-radius:8px; padding:12px;">
                            <div style="display:flex; justify-content:space-between; gap:10px; align-items:center; margin-bottom:8px;">
                                <strong><?php echo htmlspecialchars($ticket['status'] ?? 'Open', ENT_QUOTES, 'UTF-8'); ?></strong>
                                <span style="font-size:12px; color:#475569;"><?php echo htmlspecialchars($ticket['created_at'] ?? '', ENT_QUOTES, 'UTF-8'); ?></span>
                            </div>
                            <div style="white-space:pre-wrap; color:#1f2937; line-height:1.5;">
                                <?php echo nl2br(htmlspecialchars($ticket['message'] ?? '', ENT_QUOTES, 'UTF-8')); ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php require_once '../includes/footer.php'; ?>
