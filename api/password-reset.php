<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/store.php';
require_once __DIR__ . '/../app/session.php';
require_once __DIR__ . '/../app/helpers.php';
require_once __DIR__ . '/../app/mailer.php';
start_app_session();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function password_reset_response(array $payload, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function password_reset_payload(): array
{
    $decoded = json_decode((string) file_get_contents('php://input'), true);
    return is_array($decoded) ? $decoded : $_POST;
}

/** @return array{type:string,id:string,name:string,email:string}|null */
function password_reset_account(array $data, string $email): ?array
{
    $config = env_config();
    $adminEmail = strtolower(trim((string) ($config['ADMIN_EMAIL'] ?? '')));
    $adminRecoveryEmail = strtolower(trim((string) ($config['ADMIN_RECOVERY_EMAIL'] ?? '')));
    if ($adminRecoveryEmail !== '' && ($email === $adminEmail || $email === $adminRecoveryEmail)) {
        return [
            'type' => 'admin',
            'id' => 'admin',
            'name' => (string) ($data['profile']['name'] ?? 'RMUTP Administrator'),
            'email' => $adminRecoveryEmail,
        ];
    }
    foreach ($data['students'] ?? [] as $student) {
        if (strtolower((string) ($student['email'] ?? '')) === $email) {
            return [
                'type' => 'student', 'id' => (string) ($student['id'] ?? ''),
                'name' => trim((string) ($student['first_name'] ?? '') . ' ' . (string) ($student['last_name'] ?? '')),
                'email' => (string) ($student['email'] ?? ''),
            ];
        }
    }
    foreach ($data['advisors'] ?? [] as $advisor) {
        if (strtolower((string) ($advisor['email'] ?? '')) === $email && ($advisor['status'] ?? 'Active') === 'Active') {
            return [
                'type' => 'advisor', 'id' => (string) ($advisor['id'] ?? ''),
                'name' => (string) ($advisor['name'] ?? ''), 'email' => (string) ($advisor['email'] ?? ''),
            ];
        }
    }
    return null;
}

function password_reset_url(string $token): string
{
    $config = env_config();
    $configured = rtrim(trim((string) ($config['APP_URL'] ?? '')), '/');
    if ($configured !== '') return $configured . '/reset-password.php?token=' . rawurlencode($token);
    $https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    $host = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');
    return ($https ? 'https://' : 'http://') . $host . rtrim(app_base_url(), '/')
        . '/reset-password.php?token=' . rawurlencode($token);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    password_reset_response(['success' => false, 'message' => 'Method not allowed.'], 405);
}
require_csrf_token();

$payload = password_reset_payload();
$action = strtolower(trim((string) ($payload['action'] ?? 'request')));
try {
    $pdo = database_connection();
    $data = load_data();
} catch (Throwable $exception) {
    error_log('Password reset database connection failed: ' . $exception->getMessage());
    password_reset_response([
        'success' => false,
        'message' => 'ฐานข้อมูลยังไม่พร้อมใช้งาน กรุณาเปิด MySQL แล้วลองใหม่อีกครั้ง',
    ], 503);
}

if ($action === 'request') {
    $email = strtolower(trim((string) ($payload['email'] ?? '')));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        password_reset_response(['success' => false, 'message' => 'กรุณากรอกอีเมลให้ถูกต้อง'], 422);
    }
    if (mailer_transport() === 'resend') {
        $mailConfig = env_config();
        if (trim((string) ($mailConfig['RESEND_API_KEY'] ?? '')) === ''
            || trim((string) ($mailConfig['MAIL_FROM'] ?? '')) === '') {
            password_reset_response([
                'success' => false,
                'message' => 'ระบบส่งอีเมลยังไม่ได้ตั้งค่า กรุณาแจ้งผู้ดูแลให้กำหนด RESEND_API_KEY และ MAIL_FROM',
            ], 503);
        }
    }
    $isLogTransport = mailer_transport() === 'log';
    $genericMessage = $isLogTransport
        ? 'ระบบอยู่ในโหมดทดสอบ จึงบันทึกลิงก์ไว้ในไฟล์ Log และยังไม่ได้ส่งเข้าอีเมลจริง'
        : 'หากอีเมลนี้อยู่ในระบบ เราได้ส่งลิงก์ตั้งรหัสผ่านใหม่ให้แล้ว ลิงก์มีอายุ 15 นาที';
    $ipAddress = substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);

    $pdo->prepare('DELETE FROM password_reset_tokens WHERE expires_at < DATE_SUB(NOW(), INTERVAL 1 DAY) OR used_at < DATE_SUB(NOW(), INTERVAL 1 DAY)')->execute();
    $rateStatement = $pdo->prepare(
        'SELECT COUNT(*) FROM password_reset_tokens
         WHERE requested_ip = :requested_ip AND created_at >= DATE_SUB(NOW(), INTERVAL 15 MINUTE)'
    );
    $rateStatement->execute(['requested_ip' => $ipAddress]);
    if ((int) $rateStatement->fetchColumn() >= 5) {
        password_reset_response(['success' => true, 'message' => $genericMessage]);
    }

    $account = password_reset_account($data, $email);
    if ($account === null || $account['id'] === '') {
        usleep(random_int(100000, 250000));
        password_reset_response(['success' => true, 'message' => $genericMessage]);
    }
    $recentStatement = $pdo->prepare(
        'SELECT COUNT(*) FROM password_reset_tokens
         WHERE user_type = :user_type AND user_id = :user_id
           AND created_at >= DATE_SUB(NOW(), INTERVAL 60 SECOND)'
    );
    $recentStatement->execute(['user_type' => $account['type'], 'user_id' => $account['id']]);
    if ((int) $recentStatement->fetchColumn() > 0) {
        password_reset_response(['success' => true, 'message' => $genericMessage]);
    }

    $token = bin2hex(random_bytes(32));
    $tokenHash = hash('sha256', $token);
    $pdo->beginTransaction();
    try {
        $pdo->prepare(
            'UPDATE password_reset_tokens SET used_at = NOW()
             WHERE user_type = :user_type AND user_id = :user_id AND used_at IS NULL'
        )->execute(['user_type' => $account['type'], 'user_id' => $account['id']]);
        $pdo->prepare(
            'INSERT INTO password_reset_tokens
             (user_type, user_id, token_hash, requested_ip, expires_at)
             VALUES (:user_type, :user_id, :token_hash, :requested_ip, DATE_ADD(NOW(), INTERVAL 15 MINUTE))'
        )->execute([
            'user_type' => $account['type'], 'user_id' => $account['id'],
            'token_hash' => $tokenHash, 'requested_ip' => $ipAddress,
        ]);
        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('Password reset token creation failed: ' . $exception->getMessage());
        password_reset_response(['success' => true, 'message' => $genericMessage]);
    }

    try {
        send_password_reset_email($account['email'], $account['name'], password_reset_url($token), 15);
    } catch (Throwable $exception) {
        // Never reveal account existence or provider details to the requester.
        error_log('Password reset email failed: ' . $exception->getMessage());
        // A token that was not delivered must not remain usable.
        $pdo->prepare('UPDATE password_reset_tokens SET used_at = NOW() WHERE token_hash = :token_hash')
            ->execute(['token_hash' => $tokenHash]);
    }
    password_reset_response(['success' => true, 'message' => $genericMessage]);
}

if ($action === 'reset') {
    $token = strtolower(trim((string) ($payload['token'] ?? '')));
    $password = (string) ($payload['password'] ?? '');
    $confirmation = (string) ($payload['password_confirmation'] ?? '');
    if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
        password_reset_response(['success' => false, 'message' => 'ลิงก์ตั้งรหัสผ่านไม่ถูกต้องหรือหมดอายุแล้ว'], 422);
    }
    if (strlen($password) < 8) {
        password_reset_response(['success' => false, 'message' => 'รหัสผ่านใหม่ต้องมีอย่างน้อย 8 ตัวอักษร'], 422);
    }
    if (!hash_equals($password, $confirmation)) {
        password_reset_response(['success' => false, 'message' => 'ยืนยันรหัสผ่านไม่ตรงกัน'], 422);
    }

    $pdo->beginTransaction();
    try {
        $statement = $pdo->prepare(
            'SELECT id, user_type, user_id FROM password_reset_tokens
             WHERE token_hash = :token_hash AND used_at IS NULL AND expires_at > NOW()
             LIMIT 1 FOR UPDATE'
        );
        $statement->execute(['token_hash' => hash('sha256', $token)]);
        $reset = $statement->fetch();
        if (!is_array($reset)) {
            $pdo->rollBack();
            password_reset_response(['success' => false, 'message' => 'ลิงก์ตั้งรหัสผ่านไม่ถูกต้องหรือหมดอายุแล้ว'], 422);
        }

        $accountFound = false;
        if (($reset['user_type'] ?? '') === 'admin' && ($reset['user_id'] ?? '') === 'admin') {
            $data['profile']['admin_password_hash'] = secure_password_hash($password);
            $accountFound = true;
        } else {
            $collection = ($reset['user_type'] ?? '') === 'advisor' ? 'advisors' : 'students';
            foreach ($data[$collection] ?? [] as $index => $account) {
                if (($account['id'] ?? '') === ($reset['user_id'] ?? '')) {
                    $data[$collection][$index]['password_hash'] = secure_password_hash($password);
                    $accountFound = true;
                    break;
                }
            }
        }
        if (!$accountFound) throw new RuntimeException('Password reset account no longer exists.');
        save_data($data);
        $pdo->prepare('UPDATE password_reset_tokens SET used_at = NOW() WHERE id = :id')
            ->execute(['id' => $reset['id']]);
        $pdo->prepare('DELETE FROM user_sessions WHERE user_type = :user_type AND user_id = :user_id')
            ->execute(['user_type' => $reset['user_type'], 'user_id' => $reset['user_id']]);
        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('Password reset failed: ' . $exception->getMessage());
        password_reset_response(['success' => false, 'message' => 'ไม่สามารถตั้งรหัสผ่านใหม่ได้ กรุณาขอลิงก์ใหม่'], 422);
    }
    password_reset_response(['success' => true, 'message' => 'ตั้งรหัสผ่านใหม่สำเร็จ กรุณาเข้าสู่ระบบ']);
}

password_reset_response(['success' => false, 'message' => 'Invalid password reset action.'], 422);
