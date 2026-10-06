<?php

function notification_env(string $name, string $default = ''): string
{
    $value = getenv($name);
    if ($value !== false && trim((string)$value) !== '') {
        return trim((string)$value);
    }

    static $localConfig = null;
    if ($localConfig === null) {
        $localConfig = [];
        $configPath = getenv('SPS_LOCAL_NOTIFICATION_CONFIG');
        if (($configPath === false || trim((string)$configPath) === '') && PHP_OS_FAMILY === 'Windows') {
            $configPath = 'C:/wamp64/private/sps-notifications.ini';
        }
        if (is_string($configPath) && $configPath !== '' && is_file($configPath) && is_readable($configPath)) {
            $parsed = parse_ini_file($configPath, false, INI_SCANNER_RAW);
            if (is_array($parsed)) {
                $localConfig = $parsed;
            }
        }
    }

    $localValue = $localConfig[$name] ?? null;
    return is_string($localValue) && trim($localValue) !== '' ? trim($localValue) : $default;
}

function notification_absolute_link(string $link): string
{
    if ($link === '' || preg_match('#^https?://#i', $link)) {
        return $link;
    }
    $baseUrl = rtrim(notification_env('SPS_PUBLIC_BASE_URL'), '/');
    if ($baseUrl === '') {
        return $link;
    }

    $baseParts = parse_url($baseUrl);
    $basePath = rtrim((string)($baseParts['path'] ?? ''), '/');
    if ($link !== '' && $link[0] === '/' && $basePath !== ''
        && ($link === $basePath || strpos($link, $basePath . '/') === 0)) {
        $origin = (string)($baseParts['scheme'] ?? 'https') . '://' . (string)($baseParts['host'] ?? '');
        if (isset($baseParts['port'])) {
            $origin .= ':' . (int)$baseParts['port'];
        }
        return $origin . $link;
    }

    return $baseUrl . '/' . ltrim($link, '/');
}

function send_email_notification(string $recipient, string $subject, string $message, string $link = ''): array
{
    $host = notification_env('SPS_SMTP_HOST');
    $fromAddress = notification_env('SPS_MAIL_FROM');
    $autoload = dirname(__DIR__) . '/vendor/autoload.php';
    if ($host === '' || $fromAddress === '' || !filter_var($fromAddress, FILTER_VALIDATE_EMAIL) || !filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
        return ['channel' => 'email', 'status' => 'not_configured', 'note' => 'Valid SMTP host, sender, and recipient email settings are required.'];
    }
    if (!is_file($autoload)) {
        return ['channel' => 'email', 'status' => 'not_configured', 'note' => 'PHPMailer is not installed. Run Composer install on this deployment.'];
    }

    require_once $autoload;
    try {
        $mail = new PHPMailer\PHPMailer\PHPMailer(true);
        $mail->isSMTP();
        $mail->Host = $host;
        $mail->Port = max(1, (int)notification_env('SPS_SMTP_PORT', '587'));
        $mail->SMTPAuth = notification_env('SPS_SMTP_USERNAME') !== '';
        if ($mail->SMTPAuth) {
            $mail->Username = notification_env('SPS_SMTP_USERNAME');
            $mail->Password = notification_env('SPS_SMTP_PASSWORD');
        }
        $encryption = strtolower(notification_env('SPS_SMTP_ENCRYPTION', 'tls'));
        if ($encryption === 'ssl') {
            $mail->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS;
        } elseif ($encryption === 'tls') {
            $mail->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
        } elseif ($encryption !== 'none') {
            return ['channel' => 'email', 'status' => 'not_configured', 'note' => 'SPS_SMTP_ENCRYPTION must be tls, ssl, or none.'];
        }
        $mail->CharSet = 'UTF-8';
        $mail->setFrom($fromAddress, notification_env('SPS_MAIL_FROM_NAME', 'Service Portal'));
        $mail->addAddress($recipient);
        $mail->Subject = $subject;
        $plainBody = trim($message . ($link !== '' ? "\n\nView in the portal: " . notification_absolute_link($link) : ''));
        $mail->isHTML(false);
        $mail->Body = $plainBody;
        $mail->send();
        return ['channel' => 'email', 'status' => 'accepted', 'note' => 'SMTP server accepted the message; final inbox delivery is not confirmed.'];
    } catch (Throwable $error) {
        error_log('Notification SMTP delivery failed: ' . $error->getMessage());
        return ['channel' => 'email', 'status' => 'failed', 'note' => 'SMTP handoff failed. Check the server mail configuration and application log.'];
    }
}

function normalize_notification_phone(string $phone): string
{
    $phone = trim(str_ireplace('whatsapp:', '', $phone));
    $phone = preg_replace('/[\s().-]+/', '', $phone) ?? '';
    return preg_match('/^\+[1-9][0-9]{7,14}$/', $phone) ? $phone : '';
}

function send_twilio_notification(string $channel, string $recipient, string $subject, string $message, string $link = ''): array
{
    $channel = strtolower($channel);
    $accountSid = notification_env('SPS_TWILIO_ACCOUNT_SID');
    $authToken = notification_env('SPS_TWILIO_AUTH_TOKEN');
    if (!in_array($channel, ['sms', 'whatsapp'], true)) {
        return ['channel' => $channel, 'status' => 'failed', 'note' => 'Unsupported messaging channel.'];
    }
    if ($accountSid === '' || $authToken === '') {
        return ['channel' => $channel, 'status' => 'not_configured', 'note' => 'Twilio account SID and auth token are not configured.'];
    }
    if (!function_exists('curl_init')) {
        return ['channel' => $channel, 'status' => 'not_configured', 'note' => 'The PHP cURL extension is required for Twilio delivery.'];
    }

    $to = normalize_notification_phone($recipient);
    if ($to === '') {
        return ['channel' => $channel, 'status' => 'skipped', 'note' => 'The recipient number must use international E.164 format, for example +14155552671.'];
    }
    $from = notification_env($channel === 'whatsapp' ? 'SPS_TWILIO_WHATSAPP_FROM' : 'SPS_TWILIO_SMS_FROM');
    $messagingServiceSid = $channel === 'sms' ? notification_env('SPS_TWILIO_SMS_MESSAGING_SERVICE_SID') : '';
    if ($channel === 'whatsapp') {
        $fromNumber = normalize_notification_phone($from);
        if ($fromNumber === '') {
            return ['channel' => $channel, 'status' => 'not_configured', 'note' => 'A valid SPS_TWILIO_WHATSAPP_FROM sender is required.'];
        }
        $from = 'whatsapp:' . $fromNumber;
        $to = 'whatsapp:' . $to;
    } elseif ($messagingServiceSid === '' && normalize_notification_phone($from) === '') {
        return ['channel' => $channel, 'status' => 'not_configured', 'note' => 'Configure an SMS messaging service SID or a valid Twilio SMS sender number.'];
    } elseif ($messagingServiceSid === '') {
        $from = normalize_notification_phone($from);
    }

    $body = trim($subject . "\n" . $message . ($link !== '' ? "\n\n" . notification_absolute_link($link) : ''));
    if ($channel === 'whatsapp' && notification_env('SPS_TWILIO_WHATSAPP_CONTENT_SID') !== '') {
        $variables = json_encode(['1' => $subject, '2' => $message, '3' => notification_absolute_link($link)], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $payload = [
            'To' => $to,
            'ContentSid' => notification_env('SPS_TWILIO_WHATSAPP_CONTENT_SID'),
            'ContentVariables' => $variables,
        ];
    } else {
        $payload = ['To' => $to, 'Body' => $body];
    }
    if ($messagingServiceSid !== '') {
        $payload['MessagingServiceSid'] = $messagingServiceSid;
    } else {
        $payload['From'] = $from;
    }

    $url = 'https://api.twilio.com/2010-04-01/Accounts/' . rawurlencode($accountSid) . '/Messages.json';
    $curl = curl_init($url);
    curl_setopt_array($curl, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query($payload),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_USERPWD => $accountSid . ':' . $authToken,
        CURLOPT_HTTPAUTH => CURLAUTH_BASIC,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_HTTPHEADER => ['Accept: application/json'],
    ]);
    $responseBody = curl_exec($curl);
    $curlError = curl_error($curl);
    $httpStatus = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE);
    curl_close($curl);

    $response = is_string($responseBody) ? json_decode($responseBody, true) : null;
    if ($responseBody === false || $httpStatus < 200 || $httpStatus >= 300 || !is_array($response)) {
        $providerMessage = is_array($response) ? (string)($response['message'] ?? '') : '';
        $safeReason = $providerMessage !== '' ? $providerMessage : ($curlError !== '' ? $curlError : 'Twilio API returned HTTP ' . $httpStatus . '.');
        error_log('Twilio ' . $channel . ' send failed (' . $httpStatus . '): ' . $safeReason);
        return ['channel' => $channel, 'status' => 'failed', 'note' => 'Twilio rejected the message or the API request failed. Check the application log.'];
    }

    return [
        'channel' => $channel,
        'status' => 'accepted',
        'provider_status' => (string)($response['status'] ?? 'queued'),
        'provider_message_id' => (string)($response['sid'] ?? ''),
        'note' => 'Twilio accepted the message; later carrier delivery is not confirmed.',
    ];
}