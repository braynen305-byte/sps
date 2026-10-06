<?php
require_once __DIR__ . '/portal_notifications.inc.php';
require_once __DIR__ . '/notification_transports.inc.php';

function table_has_column(PDO $conn, string $table, string $column): bool
{
    try {
        $result = $conn->query("SHOW COLUMNS FROM `" . str_replace('`', '``', $table) . "` LIKE '" . str_replace("'", "\\'", $column) . "'");
        return $result && $result->fetch(PDO::FETCH_ASSOC) !== false;
    } catch (Exception $e) {
        return false;
    }
}

function ensure_notification_tables(PDO $conn): void
{
    $conn->exec("CREATE TABLE IF NOT EXISTS notification_preferences (
        id INT AUTO_INCREMENT PRIMARY KEY,
        staff_id INT NOT NULL UNIQUE,
        email_enabled TINYINT(1) NOT NULL DEFAULT 1,
        whatsapp_enabled TINYINT(1) NOT NULL DEFAULT 0,
        sms_enabled TINYINT(1) NOT NULL DEFAULT 0,
        preferred_channel VARCHAR(20) NOT NULL DEFAULT 'email',
        phone_number VARCHAR(30) DEFAULT NULL,
        whatsapp_number VARCHAR(30) DEFAULT NULL,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX(staff_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

    foreach (['phone_number', 'whatsapp_number'] as $column) {
        if (!table_has_column($conn, 'notification_preferences', $column)) {
            try {
                $conn->exec("ALTER TABLE notification_preferences ADD COLUMN `" . str_replace('`', '``', $column) . "` VARCHAR(30) DEFAULT NULL");
            } catch (Exception $e) {
                // ignore legacy-schema migration issues
            }
        }
    }

    $conn->exec("CREATE TABLE IF NOT EXISTS notification_log (
        id INT AUTO_INCREMENT PRIMARY KEY,
        staff_id INT NOT NULL,
        category VARCHAR(80) NOT NULL,
        subject VARCHAR(255) NOT NULL,
        message LONGTEXT NOT NULL,
        link VARCHAR(255) DEFAULT NULL,
        channel VARCHAR(20) NOT NULL,
        sent_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        status VARCHAR(30) NOT NULL DEFAULT 'queued',
        INDEX(staff_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

    $conn->exec("CREATE TABLE IF NOT EXISTS customer_notification_preferences (
        id INT AUTO_INCREMENT PRIMARY KEY,
        customer_id INT NOT NULL UNIQUE,
        email_enabled TINYINT(1) NOT NULL DEFAULT 1,
        whatsapp_enabled TINYINT(1) NOT NULL DEFAULT 0,
        sms_enabled TINYINT(1) NOT NULL DEFAULT 0,
        preferred_channel VARCHAR(20) NOT NULL DEFAULT 'email',
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX(customer_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

    $conn->exec("CREATE TABLE IF NOT EXISTS customer_notification_log (
        id INT AUTO_INCREMENT PRIMARY KEY,
        customer_id INT NOT NULL,
        category VARCHAR(80) NOT NULL,
        subject VARCHAR(255) NOT NULL,
        channel VARCHAR(20) NOT NULL,
        status VARCHAR(30) NOT NULL,
        note VARCHAR(255) DEFAULT NULL,
        attempted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX(customer_id, attempted_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

    foreach (['phone_number', 'whatsapp_number'] as $column) {
        if (!table_has_column($conn, 'staff', $column)) {
            try {
                $conn->exec("ALTER TABLE staff ADD COLUMN `" . str_replace('`', '``', $column) . "` VARCHAR(30) DEFAULT NULL");
            } catch (Exception $e) {
                // ignore legacy-schema migration issues
            }
        }
    }
}

function get_staff_notification_preferences(PDO $conn, int $staffId): array
{
    ensure_notification_tables($conn);

    $stmt = $conn->prepare('SELECT * FROM notification_preferences WHERE staff_id = ? LIMIT 1');
    $stmt->execute([$staffId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        return [
            'email_enabled' => 1,
            'whatsapp_enabled' => 0,
            'sms_enabled' => 0,
            'preferred_channel' => 'email',
            'phone_number' => '',
            'whatsapp_number' => '',
        ];
    }

    return [
        'email_enabled' => (int)($row['email_enabled'] ?? 1),
        'whatsapp_enabled' => (int)($row['whatsapp_enabled'] ?? 0),
        'sms_enabled' => (int)($row['sms_enabled'] ?? 0),
        'preferred_channel' => strtolower((string)($row['preferred_channel'] ?? 'email')),
        'phone_number' => (string)($row['phone_number'] ?? ''),
        'whatsapp_number' => (string)($row['whatsapp_number'] ?? ''),
    ];
}

function save_staff_notification_preferences(PDO $conn, int $staffId, array $prefs): bool
{
    ensure_notification_tables($conn);

    $emailEnabled = isset($prefs['email_enabled']) ? (int)$prefs['email_enabled'] : 0;
    $whatsappEnabled = isset($prefs['whatsapp_enabled']) ? (int)$prefs['whatsapp_enabled'] : 0;
    $smsEnabled = isset($prefs['sms_enabled']) ? (int)$prefs['sms_enabled'] : 0;
    $preferredChannel = in_array(strtolower((string)($prefs['preferred_channel'] ?? 'email')), ['email', 'whatsapp', 'sms', 'all'], true)
        ? strtolower((string)$prefs['preferred_channel'])
        : 'email';
    $phoneNumber = trim((string)($prefs['phone_number'] ?? ''));
    $whatsappNumber = trim((string)($prefs['whatsapp_number'] ?? ''));

    $existing = $conn->prepare('SELECT id FROM notification_preferences WHERE staff_id = ? LIMIT 1');
    $existing->execute([$staffId]);

    $hasPhoneNumber = table_has_column($conn, 'notification_preferences', 'phone_number');
    $hasWhatsAppNumber = table_has_column($conn, 'notification_preferences', 'whatsapp_number');

    if ($existing->fetch()) {
        if ($hasPhoneNumber && $hasWhatsAppNumber) {
            $stmt = $conn->prepare('UPDATE notification_preferences SET email_enabled = ?, whatsapp_enabled = ?, sms_enabled = ?, preferred_channel = ?, phone_number = ?, whatsapp_number = ?, updated_at = NOW() WHERE staff_id = ?');
            return $stmt->execute([$emailEnabled, $whatsappEnabled, $smsEnabled, $preferredChannel, $phoneNumber, $whatsappNumber, $staffId]);
        }

        $stmt = $conn->prepare('UPDATE notification_preferences SET email_enabled = ?, whatsapp_enabled = ?, sms_enabled = ?, preferred_channel = ?, updated_at = NOW() WHERE staff_id = ?');
        return $stmt->execute([$emailEnabled, $whatsappEnabled, $smsEnabled, $preferredChannel, $staffId]);
    }

    if ($hasPhoneNumber && $hasWhatsAppNumber) {
        $stmt = $conn->prepare('INSERT INTO notification_preferences (staff_id, email_enabled, whatsapp_enabled, sms_enabled, preferred_channel, phone_number, whatsapp_number, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())');
        return $stmt->execute([$staffId, $emailEnabled, $whatsappEnabled, $smsEnabled, $preferredChannel, $phoneNumber, $whatsappNumber]);
    }

    $stmt = $conn->prepare('INSERT INTO notification_preferences (staff_id, email_enabled, whatsapp_enabled, sms_enabled, preferred_channel, updated_at) VALUES (?, ?, ?, ?, ?, NOW())');
    return $stmt->execute([$staffId, $emailEnabled, $whatsappEnabled, $smsEnabled, $preferredChannel]);
}

function get_enabled_notification_channels(array $prefs): array
{
    return get_notification_delivery_plan($prefs)['channels'];
}

function get_notification_delivery_plan(array $prefs): array
{
    $enabledChannels = [];
    foreach (['email', 'whatsapp', 'sms'] as $channel) {
        if (!empty($prefs[$channel . '_enabled'])) {
            $enabledChannels[] = $channel;
        }
    }
    if (empty($enabledChannels)) {
        return ['channels' => [], 'fallback' => [], 'send_all' => false];
    }

    $preferred = strtolower((string)($prefs['preferred_channel'] ?? 'email'));
    if ($preferred === 'all') {
        return ['channels' => $enabledChannels, 'fallback' => [], 'send_all' => true];
    }
    if (in_array($preferred, $enabledChannels, true)) {
        $fallback = in_array($preferred, ['sms', 'whatsapp'], true) && in_array('email', $enabledChannels, true)
            ? ['email']
            : [];
        return ['channels' => [$preferred], 'fallback' => $fallback, 'send_all' => false];
    }

    return ['channels' => $enabledChannels, 'fallback' => [], 'send_all' => true];
}

function deliver_notification_plan(array $plan, callable $sender): array
{
    $results = [];
    foreach ($plan['channels'] ?? [] as $channel) {
        $result = $sender((string)$channel);
        $results[] = $result;
        if (empty($plan['send_all'])) {
            $status = strtolower((string)($result['status'] ?? 'failed'));
            if (!in_array($status, ['accepted', 'sent', 'queued'], true)) {
                foreach ($plan['fallback'] ?? [] as $fallbackChannel) {
                    $results[] = $sender((string)$fallbackChannel);
                }
            }
            break;
        }
    }
    return $results;
}

function get_staff_contact_record(PDO $conn, int $staffId): array
{
    ensure_notification_tables($conn);

    $selectParts = ['id', 'email'];
    if (table_has_column($conn, 'staff', 'phone_number')) {
        $selectParts[] = 'phone_number';
    }
    if (table_has_column($conn, 'staff', 'whatsapp_number')) {
        $selectParts[] = 'whatsapp_number';
    }

    $stmt = $conn->prepare('SELECT ' . implode(', ', $selectParts) . ' FROM staff WHERE id = ? LIMIT 1');
    $stmt->execute([$staffId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    $preferenceStmt = $conn->prepare('SELECT phone_number, whatsapp_number FROM notification_preferences WHERE staff_id = ? LIMIT 1');
    $preferenceStmt->execute([$staffId]);
    $preferenceContact = $preferenceStmt->fetch(PDO::FETCH_ASSOC) ?: [];

    return [
        'email' => trim((string)($row['email'] ?? '')),
        'phone_number' => trim((string)($preferenceContact['phone_number'] ?? '')) ?: trim((string)($row['phone_number'] ?? '')),
        'whatsapp_number' => trim((string)($preferenceContact['whatsapp_number'] ?? '')) ?: trim((string)($row['whatsapp_number'] ?? ($row['phone_number'] ?? ''))),
    ];
}

function append_notification_log(PDO $conn, int $staffId, string $category, string $subject, string $message, string $link, string $channel, string $status = 'queued'): bool
{
    ensure_notification_tables($conn);
    $stmt = $conn->prepare('INSERT INTO notification_log (staff_id, category, subject, message, link, channel, status, sent_at) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())');
    return $stmt->execute([$staffId, $category, $subject, $message, $link, $channel, $status]);
}

function append_customer_notification_log(PDO $conn, int $customerId, string $category, string $subject, string $channel, string $status, string $note = ''): bool
{
    ensure_notification_tables($conn);
    $stmt = $conn->prepare('INSERT INTO customer_notification_log (customer_id, category, subject, channel, status, note, attempted_at) VALUES (?, ?, ?, ?, ?, ?, NOW())');
    return $stmt->execute([$customerId, $category, $subject, $channel, $status, $note !== '' ? $note : null]);
}

function send_notification_channel(PDO $conn, int $staffId, string $channel, string $category, string $subject, string $message, string $link = ''): array
{
    $contact = get_staff_contact_record($conn, $staffId);
    if ($channel === 'email' && $contact['email'] !== '') {
        $payload = send_email_notification($contact['email'], $subject, $message, $link);
    } elseif ($channel === 'whatsapp' && $contact['whatsapp_number'] !== '') {
        $payload = send_twilio_notification('whatsapp', $contact['whatsapp_number'], $subject, $message, $link);
    } elseif ($channel === 'sms' && $contact['phone_number'] !== '') {
        $payload = send_twilio_notification('sms', $contact['phone_number'], $subject, $message, $link);
    } else {
        $payload = ['channel' => $channel, 'status' => 'skipped', 'note' => $channel === 'email'
            ? 'No staff email address is available.'
            : 'No matching phone number is available for this channel.'];
    }

    append_notification_log($conn, $staffId, $category, $subject, $message, $link, $channel, (string)$payload['status']);

    return $payload;
}

function notify_staff_by_id(PDO $conn, int $staffId, string $category, string $subject, string $message, string $link = ''): array
{
    ensure_notification_tables($conn);
    create_portal_notification($conn, 'staff', $staffId, $category, $subject, $message, $link);

    $prefs = get_staff_notification_preferences($conn, $staffId);
    $plan = get_notification_delivery_plan($prefs);
    return deliver_notification_plan($plan, static function (string $channel) use ($conn, $staffId, $category, $subject, $message, $link): array {
        return send_notification_channel($conn, $staffId, $channel, $category, $subject, $message, $link);
    });
}

function notify_staff_roles(PDO $conn, array $roles, string $category, string $subject, string $message, string $link = ''): array
{
    $roles = array_filter(array_map('strtolower', $roles));
    if (empty($roles)) {
        return [];
    }

    $inClause = implode(',', array_fill(0, count($roles), '?'));
    $stmt = $conn->prepare('SELECT id FROM staff WHERE LOWER(role) IN (' . $inClause . ')');
    $stmt->execute($roles);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $results = [];
    foreach ($rows as $row) {
        $results[] = notify_staff_by_id($conn, (int)($row['id'] ?? 0), $category, $subject, $message, $link);
    }

    return $results;
}

function notify_assigned_technician(PDO $conn, int $workOrderId, int $techId, string $workOrderNumber = ''): array
{
    if ($techId <= 0) {
        return [];
    }

    $orderLabel = $workOrderNumber !== '' ? $workOrderNumber : '#'.$workOrderId;
    $link = '/sps/pages/view_workorder.php?id=' . (int)$workOrderId;
    $subject = 'New work order assigned: ' . $orderLabel;
    $message = 'You have been assigned to work order ' . $orderLabel . '. Please review the details and confirm the next steps.';

    return notify_staff_by_id($conn, $techId, 'work_order_assignment', $subject, $message, $link);
}

function get_customer_notification_preferences(PDO $conn, int $customerId): array
{
    ensure_notification_tables($conn);
    $stmt = $conn->prepare('SELECT * FROM customer_notification_preferences WHERE customer_id = ? LIMIT 1');
    $stmt->execute([$customerId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        return [
            'email_enabled' => 1,
            'whatsapp_enabled' => 0,
            'sms_enabled' => 0,
            'preferred_channel' => 'email',
        ];
    }

    return [
        'email_enabled' => (int)($row['email_enabled'] ?? 1),
        'whatsapp_enabled' => (int)($row['whatsapp_enabled'] ?? 0),
        'sms_enabled' => (int)($row['sms_enabled'] ?? 0),
        'preferred_channel' => strtolower((string)($row['preferred_channel'] ?? 'email')),
    ];
}

function save_customer_notification_preferences(PDO $conn, int $customerId, array $prefs): bool
{
    ensure_notification_tables($conn);

    $emailEnabled = isset($prefs['email_enabled']) ? (int)$prefs['email_enabled'] : 1;
    $whatsappEnabled = isset($prefs['whatsapp_enabled']) ? (int)$prefs['whatsapp_enabled'] : 1;
    $smsEnabled = isset($prefs['sms_enabled']) ? (int)$prefs['sms_enabled'] : 0;
    $preferredChannel = in_array(strtolower((string)($prefs['preferred_channel'] ?? 'email')), ['email', 'whatsapp', 'sms', 'all'], true)
        ? strtolower((string)$prefs['preferred_channel'])
        : 'email';

    $existing = $conn->prepare('SELECT id FROM customer_notification_preferences WHERE customer_id = ? LIMIT 1');
    $existing->execute([$customerId]);

    if ($existing->fetch()) {
        $stmt = $conn->prepare('UPDATE customer_notification_preferences SET email_enabled = ?, whatsapp_enabled = ?, sms_enabled = ?, preferred_channel = ?, updated_at = NOW() WHERE customer_id = ?');
        return $stmt->execute([$emailEnabled, $whatsappEnabled, $smsEnabled, $preferredChannel, $customerId]);
    }

    $stmt = $conn->prepare('INSERT INTO customer_notification_preferences (customer_id, email_enabled, whatsapp_enabled, sms_enabled, preferred_channel, updated_at) VALUES (?, ?, ?, ?, ?, NOW())');
    return $stmt->execute([$customerId, $emailEnabled, $whatsappEnabled, $smsEnabled, $preferredChannel]);
}

function notify_customer_event(PDO $conn, int $customerId, string $category, string $subject, string $message, string $link): array
{
    ensure_notification_tables($conn);
    $prefs = get_customer_notification_preferences($conn, $customerId);
    $customerStmt = $conn->prepare('SELECT id, name, email, phone FROM customers WHERE id = ? LIMIT 1');
    $customerStmt->execute([$customerId]);
    $customerRow = $customerStmt->fetch(PDO::FETCH_ASSOC);
    if (!$customerRow) {
        return [];
    }

    $plan = get_notification_delivery_plan($prefs);
    create_portal_notification($conn, 'customer', $customerId, $category, $subject, $message, $link);

    return deliver_notification_plan($plan, static function (string $channel) use ($conn, $customerId, $customerRow, $category, $subject, $message, $link): array {
        $result = ['channel' => $channel, 'status' => 'skipped', 'note' => 'No matching contact address was available.'];
        if ($channel === 'email' && !empty($customerRow['email'])) {
            $result = send_email_notification((string)$customerRow['email'], $subject, $message, $link);
        } elseif ($channel === 'whatsapp' && !empty($customerRow['phone'])) {
            $result = send_twilio_notification('whatsapp', (string)$customerRow['phone'], $subject, $message, $link);
        } elseif ($channel === 'sms' && !empty($customerRow['phone'])) {
            $result = send_twilio_notification('sms', (string)$customerRow['phone'], $subject, $message, $link);
        } else {
            $result['note'] = $channel === 'email' ? 'No customer email address is available.' : 'No customer phone number is available.';
        }
        append_customer_notification_log($conn, $customerId, $category, $subject, $channel, $result['status'], $result['note']);
        return $result;
    });
}

function customer_workorder_change_summary(array $changes): string
{
    $summaries = [];
    $seen = [];
    $fieldLabels = [
        'status' => 'status',
        'priority' => 'priority',
        'service_type' => 'service type',
        'expected_start_date' => 'scheduled start date',
        'expected_end_date' => 'scheduled end date',
        'requested_work' => 'requested work details',
        'work_description' => 'work description',
        'equipment_details' => 'asset details',
        'vessel_vin' => 'asset identification details',
        'vessel_hours' => 'asset hours',
        'permission_date' => 'property access schedule',
        'permission_time' => 'property access schedule',
        'permission_anytime' => 'property access schedule',
        'permission_time_relation' => 'property access schedule',
    ];

    foreach ($changes as $change) {
        $field = (string)($change['field'] ?? '');
        if (!isset($fieldLabels[$field])) {
            continue;
        }
        $label = $fieldLabels[$field];
        if (isset($seen[$label])) {
            continue;
        }
        $seen[$label] = true;
        if ($field === 'status' || $field === 'priority') {
            $newValue = trim(strip_tags((string)($change['new'] ?? '')));
            $summaries[] = $label . ' changed to ' . ($newValue !== '' ? $newValue : 'not set');
        } else {
            $summaries[] = ucfirst($label) . ' updated';
        }
    }

    return implode('; ', $summaries);
}

function notify_customer_workorder_update(PDO $conn, int $customerId, int $workOrderId, string $status, string $updateDetails = ''): array
{
    $subject = 'Update on your work order #' . $workOrderId;
    $updateDetails = trim(strip_tags($updateDetails));
    if ($updateDetails === '') {
        $status = trim(strip_tags($status));
        $updateDetails = $status !== '' && strtolower($status) !== 'updated'
            ? 'Status is currently ' . $status
            : 'Work-order details were updated';
    }
    $updateDetails = mb_substr($updateDetails, 0, 240, 'UTF-8');
    $message = 'Work order #' . $workOrderId . ': ' . rtrim($updateDetails, '. ') . '.';
    $link = '/sps/pages/view_workorder.php?id=' . $workOrderId;
    return notify_customer_event($conn, $customerId, 'workorder_update', $subject, $message, $link);
}
