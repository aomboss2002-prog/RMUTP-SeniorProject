<?php
declare(strict_types=1);

function mailer_config(): array
{
    return function_exists('env_config') ? env_config() : [];
}

function mailer_transport(): string
{
    $config = mailer_config();
    $configured = strtolower(trim((string) ($config['MAIL_TRANSPORT'] ?? '')));
    if ($configured !== '') return $configured;
    return strtolower((string) ($config['APP_ENV'] ?? 'local')) === 'production' ? 'resend' : 'log';
}

/** @return array{name:string,email:string} */
function mailer_parse_sender(string $sender): array
{
    $sender = trim($sender);
    $name = '';
    $email = $sender;
    if (preg_match('/^(.+?)\s*<([^<>]+)>$/', $sender, $matches) === 1) {
        $name = trim($matches[1], " \t\n\r\0\x0B\"'");
        $email = trim($matches[2]);
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new RuntimeException('MAIL_FROM must contain a valid email address.');
    }
    return ['name' => str_replace(["\r", "\n"], '', $name), 'email' => $email];
}

/** @param resource $socket */
function smtp_read_response($socket, array $expectedCodes): string
{
    $response = '';
    while (($line = fgets($socket, 4096)) !== false) {
        $response .= $line;
        if (strlen($line) >= 4 && $line[3] === ' ') break;
    }
    if ($response === '') throw new RuntimeException('SMTP server closed the connection unexpectedly.');
    $code = (int) substr($response, 0, 3);
    if (!in_array($code, $expectedCodes, true)) {
        throw new RuntimeException("SMTP command failed (code {$code}).");
    }
    return $response;
}

/** @param resource $socket */
function smtp_command($socket, string $command, array $expectedCodes): string
{
    if (fwrite($socket, $command . "\r\n") === false) {
        throw new RuntimeException('Unable to write to the SMTP server.');
    }
    return smtp_read_response($socket, $expectedCodes);
}

/** @return array{id:string,transport:string} */
function send_email_via_smtp(
    array $config,
    string $recipient,
    string $subject,
    string $html,
    string $text
): array {
    if (!filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
        throw new RuntimeException('The recipient email address is invalid.');
    }
    if (!function_exists('stream_socket_client') || !function_exists('stream_socket_enable_crypto')) {
        throw new RuntimeException('The PHP OpenSSL stream extension is required for SMTP.');
    }

    $host = trim((string) ($config['SMTP_HOST'] ?? 'smtp.gmail.com'));
    $port = (int) ($config['SMTP_PORT'] ?? 465);
    $encryption = strtolower(trim((string) ($config['SMTP_ENCRYPTION'] ?? ($port === 465 ? 'ssl' : 'tls'))));
    $username = trim((string) ($config['SMTP_USERNAME'] ?? ''));
    // Google displays App Passwords in groups; spaces are not part of the secret.
    $password = preg_replace('/\s+/', '', (string) ($config['SMTP_PASSWORD'] ?? '')) ?? '';
    $sender = mailer_parse_sender((string) ($config['MAIL_FROM'] ?? ''));
    if ($host === '' || $port < 1 || $port > 65535 || $username === '' || $password === '') {
        throw new RuntimeException('SMTP_HOST, SMTP_PORT, SMTP_USERNAME, SMTP_PASSWORD and MAIL_FROM are required.');
    }
    if (!in_array($encryption, ['ssl', 'tls'], true)) {
        throw new RuntimeException('SMTP_ENCRYPTION must be ssl or tls.');
    }
    // Gmail deliverability is best when the visible From address matches the
    // authenticated mailbox. Reject accidental spoofing/misalignment early.
    if (strcasecmp($host, 'smtp.gmail.com') === 0 && strcasecmp($sender['email'], $username) !== 0) {
        throw new RuntimeException('MAIL_FROM must match SMTP_USERNAME when Gmail SMTP is used.');
    }

    $context = stream_context_create(['ssl' => [
        'verify_peer' => true,
        'verify_peer_name' => true,
        'peer_name' => $host,
        'SNI_enabled' => true,
    ]]);
    $remote = ($encryption === 'ssl' ? 'ssl://' : 'tcp://') . $host . ':' . $port;
    $socket = @stream_socket_client($remote, $errorNumber, $errorMessage, 15, STREAM_CLIENT_CONNECT, $context);
    if (!is_resource($socket)) {
        throw new RuntimeException('Unable to connect to the SMTP server' . ($errorMessage !== '' ? ": {$errorMessage}" : '.'));
    }
    stream_set_timeout($socket, 30);

    try {
        smtp_read_response($socket, [220]);
        $clientName = preg_replace('/[^a-z0-9.-]/i', '', (string) ($_SERVER['HTTP_HOST'] ?? 'localhost')) ?: 'localhost';
        smtp_command($socket, 'EHLO ' . $clientName, [250]);
        if ($encryption === 'tls') {
            smtp_command($socket, 'STARTTLS', [220]);
            if (!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                throw new RuntimeException('Unable to enable SMTP TLS encryption.');
            }
            smtp_command($socket, 'EHLO ' . $clientName, [250]);
        }
        smtp_command($socket, 'AUTH LOGIN', [334]);
        smtp_command($socket, base64_encode($username), [334]);
        smtp_command($socket, base64_encode($password), [235]);
        smtp_command($socket, 'MAIL FROM:<' . $sender['email'] . '>', [250]);
        smtp_command($socket, 'RCPT TO:<' . $recipient . '>', [250, 251]);
        smtp_command($socket, 'DATA', [354]);

        $boundary = 'rmutp-' . bin2hex(random_bytes(12));
        $deliveryId = 'smtp-' . bin2hex(random_bytes(16));
        $fromHeader = $sender['name'] !== ''
            ? '=?UTF-8?B?' . base64_encode($sender['name']) . '?= <' . $sender['email'] . '>'
            : $sender['email'];
        $headers = [
            'Date: ' . date(DATE_RFC2822),
            'From: ' . $fromHeader,
            'To: <' . $recipient . '>',
            'Reply-To: <' . $sender['email'] . '>',
            'Subject: =?UTF-8?B?' . base64_encode(str_replace(["\r", "\n"], '', $subject)) . '?=',
            'MIME-Version: 1.0',
            'Auto-Submitted: auto-generated',
            'X-Auto-Response-Suppress: All',
            'Content-Type: multipart/alternative; boundary="' . $boundary . '"',
        ];
        $body = '--' . $boundary . "\r\n"
            . "Content-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: 8bit\r\n\r\n"
            . str_replace(["\r\n", "\r"], "\n", $text) . "\r\n"
            . '--' . $boundary . "\r\n"
            . "Content-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: 8bit\r\n\r\n"
            . $html . "\r\n--" . $boundary . "--";
        $message = implode("\r\n", $headers) . "\r\n\r\n" . str_replace("\n", "\r\n", str_replace("\r\n", "\n", $body));
        $message = preg_replace('/(?m)^\./', '..', $message) ?? $message;
        if (fwrite($socket, $message . "\r\n.\r\n") === false) {
            throw new RuntimeException('Unable to send the SMTP message body.');
        }
        smtp_read_response($socket, [250]);
        smtp_command($socket, 'QUIT', [221]);
        return ['id' => $deliveryId, 'transport' => 'smtp'];
    } finally {
        fclose($socket);
    }
}

/** @return array{id:string,transport:string} */
function send_system_email(string $recipient, string $subject, string $html, string $text, string $idempotencyKey): array
{
    $config = mailer_config();
    $transport = mailer_transport();
    if (!filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
        throw new RuntimeException('The recipient email address is invalid.');
    }
    if ($transport === 'log') {
        $messageId = 'local-' . bin2hex(random_bytes(8));
        $line = sprintf("[%s] %s TO=%s SUBJECT=%s%s", date(DATE_ATOM), $messageId, $recipient, $subject, PHP_EOL);
        if (file_put_contents(sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'rmutp-system-mail.log', $line, FILE_APPEND | LOCK_EX) === false) {
            throw new RuntimeException('Unable to write the local mail log.');
        }
        return ['id' => $messageId, 'transport' => 'log'];
    }
    if ($transport === 'smtp') {
        return send_email_via_smtp($config, $recipient, $subject, $html, $text);
    }
    if ($transport !== 'resend') {
        throw new RuntimeException('Unsupported mail transport.');
    }
    $apiKey = trim((string) ($config['RESEND_API_KEY'] ?? ''));
    $from = trim((string) ($config['MAIL_FROM'] ?? ''));
    if ($apiKey === '' || $from === '' || !function_exists('curl_init')) {
        throw new RuntimeException('Email provider is not configured.');
    }
    $payload = json_encode(['from' => $from, 'to' => [$recipient], 'subject' => $subject, 'html' => $html, 'text' => $text], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    $handle = curl_init('https://api.resend.com/emails');
    if ($handle === false) throw new RuntimeException('Unable to initialize email delivery.');
    curl_setopt_array($handle, [
        CURLOPT_POST => true, CURLOPT_POSTFIELDS => $payload, CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_TIMEOUT => 30,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $apiKey, 'Content-Type: application/json', 'User-Agent: RMUTP-SeniorProject/1.0', 'Idempotency-Key: ' . substr(hash('sha256', $idempotencyKey), 0, 64)],
    ]);
    $response = curl_exec($handle);
    $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
    unset($handle);
    if ($response === false || $status < 200 || $status >= 300) throw new RuntimeException('Email provider rejected the test message.');
    $decoded = json_decode((string) $response, true);
    return ['id' => (string) (($decoded['id'] ?? '') ?: 'accepted'), 'transport' => 'resend'];
}

/** @return array{id:string,transport:string} */
function send_password_reset_email(string $recipient, string $name, string $resetUrl, int $expiresMinutes = 15): array
{
    $config = mailer_config();
    $transport = mailer_transport();
    $safeName = htmlspecialchars($name !== '' ? $name : 'ผู้ใช้งาน', ENT_QUOTES, 'UTF-8');
    $safeUrl = htmlspecialchars($resetUrl, ENT_QUOTES, 'UTF-8');
    $subject = 'ตั้งรหัสผ่านใหม่ - RMUTP Senior Project';
    $text = "สวัสดี {$name}\n\nเปิดลิงก์ต่อไปนี้เพื่อตั้งรหัสผ่านใหม่ภายใน {$expiresMinutes} นาที:\n{$resetUrl}\n\nหากคุณไม่ได้เป็นผู้ขอ กรุณาเพิกเฉยต่ออีเมลนี้";
    $html = '<div style="font-family:Arial,sans-serif;max-width:600px;margin:auto;color:#17233d">'
        . '<h2 style="color:#0b3c8c">ตั้งรหัสผ่านใหม่</h2>'
        . '<p>สวัสดี ' . $safeName . '</p>'
        . '<p>ระบบได้รับคำขอตั้งรหัสผ่านใหม่ ลิงก์นี้ใช้ได้ภายใน <strong>' . $expiresMinutes . ' นาที</strong> และใช้ได้ครั้งเดียว</p>'
        . '<p style="margin:28px 0"><a href="' . $safeUrl . '" style="background:#0b3c8c;color:#fff;padding:12px 20px;border-radius:8px;text-decoration:none;font-weight:700">ตั้งรหัสผ่านใหม่</a></p>'
        . '<p style="font-size:13px;color:#64748b">หากคุณไม่ได้เป็นผู้ขอ กรุณาเพิกเฉยต่ออีเมลนี้ และอย่าส่งต่อลิงก์ให้ผู้อื่น</p>'
        . '</div>';

    if ($transport === 'log') {
        $logPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'rmutp-password-reset-mail.log';
        $messageId = 'local-' . bin2hex(random_bytes(8));
        $line = sprintf("[%s] %s TO=%s URL=%s%s", date(DATE_ATOM), $messageId, $recipient, $resetUrl, PHP_EOL);
        if (file_put_contents($logPath, $line, FILE_APPEND | LOCK_EX) === false) {
            throw new RuntimeException('Unable to write the local mail log.');
        }
        return ['id' => $messageId, 'transport' => 'log'];
    }

    if ($transport === 'smtp') {
        return send_email_via_smtp($config, $recipient, $subject, $html, $text);
    }

    if ($transport !== 'resend') {
        throw new RuntimeException('Unsupported MAIL_TRANSPORT. Use smtp, resend or log.');
    }
    $apiKey = trim((string) ($config['RESEND_API_KEY'] ?? ''));
    $from = trim((string) ($config['MAIL_FROM'] ?? ''));
    if ($apiKey === '' || $from === '') {
        throw new RuntimeException('RESEND_API_KEY and MAIL_FROM are required for email delivery.');
    }
    if (!function_exists('curl_init')) {
        throw new RuntimeException('PHP cURL extension is required for Resend.');
    }

    $payload = json_encode([
        'from' => $from,
        'to' => [$recipient],
        'subject' => $subject,
        'html' => $html,
        'text' => $text,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    $handle = curl_init('https://api.resend.com/emails');
    if ($handle === false) throw new RuntimeException('Unable to initialize the email request.');
    curl_setopt_array($handle, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $apiKey,
            'Content-Type: application/json',
            'User-Agent: RMUTP-SeniorProject/1.0',
            'Idempotency-Key: password-reset-' . hash('sha256', $recipient . '|' . $resetUrl),
        ],
    ]);
    $response = curl_exec($handle);
    $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
    $error = curl_error($handle);
    // CurlHandle is released automatically. Calling curl_close() emits a
    // deprecation warning on PHP 8.5, which would corrupt JSON API responses.
    unset($handle);
    if ($response === false || $status < 200 || $status >= 300) {
        throw new RuntimeException('Email delivery failed' . ($error !== '' ? ': ' . $error : " (HTTP {$status})"));
    }
    $decoded = json_decode((string) $response, true);
    $messageId = is_array($decoded) ? (string) ($decoded['id'] ?? '') : '';
    if ($messageId === '') throw new RuntimeException('Email provider returned an invalid response.');
    return ['id' => $messageId, 'transport' => 'resend'];
}
