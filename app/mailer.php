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

    if ($transport !== 'resend') {
        throw new RuntimeException('Unsupported MAIL_TRANSPORT. Use resend or log.');
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
