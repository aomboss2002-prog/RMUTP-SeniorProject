<?php
declare(strict_types=1);
require_once __DIR__ . '/mailer.php';

/** Safe diagnostics: return only fixed messages/key names, never configuration values. */
function mail_configuration_issue(array $config, string $transport): ?array
{
    if ($transport === 'log') return null;
    if (!in_array($transport, ['smtp', 'resend'], true)) return ['code' => 'MAIL_TRANSPORT_INVALID', 'message' => 'กำหนด MAIL_TRANSPORT เป็น smtp, resend หรือ log'];
    $required = $transport === 'smtp' ? ['SMTP_USERNAME', 'SMTP_PASSWORD', 'MAIL_FROM'] : ['RESEND_API_KEY', 'MAIL_FROM'];
    $missing = array_values(array_filter($required, static fn($key) => trim((string) ($config[$key] ?? '')) === ''));
    if ($missing) return ['code' => 'MAIL_CONFIG_MISSING', 'message' => 'ยังไม่ได้กำหนด: ' . implode(', ', $missing) . ' ใน Environment ของระบบที่ใช้งาน'];
    try { $sender = mailer_parse_sender((string) $config['MAIL_FROM']); }
    catch (Throwable) { return ['code' => 'MAIL_FROM_INVALID', 'message' => 'MAIL_FROM ต้องเป็นอีเมล หรือ ชื่อผู้ส่ง <อีเมล> ที่ถูกต้อง']; }
    if ($transport === 'smtp') {
        $host = trim((string) ($config['SMTP_HOST'] ?? 'smtp.gmail.com'));
        $port = (int) ($config['SMTP_PORT'] ?? 465);
        $encryption = strtolower(trim((string) ($config['SMTP_ENCRYPTION'] ?? ($port === 465 ? 'ssl' : 'tls'))));
        if ($host === '' || $port < 1 || $port > 65535 || !in_array($encryption, ['ssl', 'tls'], true)) {
            return ['code' => 'SMTP_CONFIG_INVALID', 'message' => 'ตรวจ SMTP_HOST, SMTP_PORT และ SMTP_ENCRYPTION (ssl หรือ tls)'];
        }
        if (strcasecmp($host, 'smtp.gmail.com') === 0) {
            if (strcasecmp($sender['email'], trim((string) $config['SMTP_USERNAME'])) !== 0) return ['code' => 'SMTP_SENDER_MISMATCH', 'message' => 'เมื่อใช้ Gmail อีเมลใน MAIL_FROM ต้องตรงกับ SMTP_USERNAME'];
            if (!(($port === 465 && $encryption === 'ssl') || ($port === 587 && $encryption === 'tls'))) return ['code' => 'SMTP_TLS_CONFIG', 'message' => 'Gmail SMTP ต้องจับคู่พอร์ต 465 กับ ssl หรือพอร์ต 587 กับ tls'];
        }
    }
    return null;
}

function mail_delivery_issue(Throwable $error): array
{
    $message = $error->getMessage();
    if (preg_match('/^SMTP command failed \(code (\d{3})\)\.$/', $message, $match)) {
        $code = (int) $match[1];
        if (in_array($code, [534, 535], true)) return ['code' => 'SMTP_AUTH_FAILED', 'message' => 'SMTP ยืนยันตัวตนไม่ผ่าน ตรวจ SMTP_USERNAME และ SMTP_PASSWORD หากใช้ Gmail ต้องใช้ App Password ของบัญชีผู้ส่ง ไม่ใช่รหัสผ่านปกติ'];
        if (in_array($code, [550, 551, 553], true)) return ['code' => 'SMTP_ADDRESS_REJECTED', 'message' => 'SMTP ปฏิเสธผู้ส่งหรือผู้รับ ตรวจ MAIL_FROM และ ADMIN_RECOVERY_EMAIL รวมถึงนโยบายของผู้ให้บริการ'];
        if ($code >= 400 && $code < 500) return ['code' => 'SMTP_TEMPORARY_FAILURE', 'message' => 'ผู้ให้บริการ SMTP ไม่พร้อมหรือจำกัดการส่งชั่วคราว กรุณารอแล้วลองใหม่'];
        return ['code' => 'SMTP_REJECTED', 'message' => 'SMTP ปฏิเสธคำสั่ง (รหัส ' . $code . ') กรุณาตรวจนโยบายและการตั้งค่าผู้ให้บริการ'];
    }
    if (str_starts_with($message, 'Unable to connect to the SMTP server') || $message === 'SMTP server closed the connection unexpectedly.') return ['code' => 'SMTP_CONNECTION_FAILED', 'message' => 'เชื่อมต่อ SMTP ไม่สำเร็จหรือการเชื่อมต่อถูกปิด ตรวจ Host, พอร์ต, TLS และการเชื่อมต่อจากเซิร์ฟเวอร์'];
    if ($message === 'Unable to enable SMTP TLS encryption.') return ['code' => 'SMTP_TLS_FAILED', 'message' => 'เชื่อมต่อ TLS ไม่สำเร็จ ตรวจใบรับรองและคู่พอร์ต/การเข้ารหัส ห้ามปิดการตรวจใบรับรอง'];
    if ($message === 'The PHP OpenSSL stream extension is required for SMTP.') return ['code' => 'SMTP_EXTENSION_MISSING', 'message' => 'PHP ของเซิร์ฟเวอร์ต้องรองรับ OpenSSL streams สำหรับ SMTP'];
    if ($message === 'Email provider rejected the test message.') {
        $status = (int) $error->getCode();
        if (in_array($status, [401, 403], true)) return ['code' => 'MAIL_PROVIDER_DENIED', 'message' => 'ผู้ให้บริการอีเมลปฏิเสธสิทธิ์ ตรวจ RESEND_API_KEY และโดเมนผู้ส่งที่ยืนยันแล้ว'];
        if ($status === 429) return ['code' => 'MAIL_RATE_LIMIT', 'message' => 'ผู้ให้บริการจำกัดจำนวนการส่ง กรุณารอแล้วลองใหม่'];
        return ['code' => 'MAIL_PROVIDER_FAILED', 'message' => 'ส่งไปยังผู้ให้บริการอีเมลไม่สำเร็จ ตรวจการเชื่อมต่อ โควตา และการตั้งค่าผู้ส่ง'];
    }
    return ['code' => 'MAIL_DELIVERY_FAILED', 'message' => 'ส่งอีเมลไม่สำเร็จ กรุณาให้ผู้ดูแลตรวจการตั้งค่าและ Log ของเซิร์ฟเวอร์'];
}
