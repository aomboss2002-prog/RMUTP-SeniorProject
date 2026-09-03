<?php
declare(strict_types=1);

require_once __DIR__ . '/store.php';
require_once __DIR__ . '/storage.php';
require_once __DIR__ . '/mailer.php';

function system_health_table_exists(PDO $pdo, string $table): bool
{
    $statement = $pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table');
    $statement->execute(['table' => $table]);
    return (int) $statement->fetchColumn() > 0;
}

function system_health_state(string $status, string $label, string $message, array $extra = []): array
{
    return $extra + ['status' => $status, 'label' => $label, 'message' => $message];
}

function system_health_mask_email(string $sender): string
{
    try { $email = mailer_parse_sender($sender)['email']; } catch (Throwable) { return 'ยังไม่กำหนด'; }
    [$local, $domain] = array_pad(explode('@', $email, 2), 2, '');
    return substr($local, 0, min(2, strlen($local))) . str_repeat('•', max(3, strlen($local) - 2)) . '@' . $domain;
}

function system_health_snapshot(): array
{
    $started = microtime(true);
    $checkedAt = date(DATE_ATOM);
    $config = env_config();
    $services = [];
    $pdo = null;

    try {
        $dbStarted = microtime(true);
        $pdo = database_connection();
        $connected = (int) $pdo->query('SELECT 1')->fetchColumn() === 1;
        $latency = round((microtime(true) - $dbStarted) * 1000, 1);
        $schemaReady = system_health_table_exists($pdo, 'project_title_checks') && system_health_table_exists($pdo, 'project_risk_scores');
        $services['database'] = system_health_state($connected && $schemaReady ? 'healthy' : 'degraded', $connected && $schemaReady ? 'พร้อมใช้งาน' : 'ควรตรวจสอบ', $schemaReady ? 'เชื่อมต่อฐานข้อมูลและโครงสร้างหลักพร้อมใช้งาน' : 'เชื่อมต่อได้ แต่โครงสร้างบางส่วนยังไม่ครบ', ['metric' => $latency . ' ms', 'latency_ms' => $latency, 'schema_ready' => $schemaReady]);
    } catch (Throwable $error) {
        error_log('[SYSTEM HEALTH] database: ' . $error->getMessage());
        $services['database'] = system_health_state('critical', 'เชื่อมต่อไม่ได้', 'กรุณาตรวจสอบบริการฐานข้อมูลและ Environment Variables', ['metric' => 'ไม่พร้อม', 'latency_ms' => null, 'schema_ready' => false]);
    }

    try {
        $driver = storage_driver();
        if ($driver === 'vercel_blob') storage_blob_token();
        $localWritable = $driver !== 'local' || (is_dir(dirname(__DIR__) . '/uploads') && is_writable(dirname(__DIR__) . '/uploads'));
        $ok = $driver === 'vercel_blob' || $localWritable;
        $services['storage'] = system_health_state($ok ? 'healthy' : 'degraded', $ok ? 'พร้อมใช้งาน' : 'เขียนไม่ได้', $driver === 'vercel_blob' ? 'Vercel Blob ได้รับการกำหนดค่าแล้ว' : ($ok ? 'โฟลเดอร์อัปโหลดในเครื่องพร้อมเขียน' : 'กรุณาตรวจสอบสิทธิ์โฟลเดอร์ uploads'), ['metric' => $driver === 'vercel_blob' ? 'Vercel Blob' : 'Local disk', 'driver' => $driver, 'configured' => $ok]);
    } catch (Throwable $error) {
        error_log('[SYSTEM HEALTH] storage: ' . $error->getMessage());
        $services['storage'] = system_health_state('degraded', 'ยังไม่พร้อม', 'กรุณาตรวจสอบการตั้งค่า Storage', ['metric' => 'ตั้งค่าไม่ครบ', 'driver' => storage_driver(), 'configured' => false]);
    }

    $transport = mailer_transport();
    $mailRequired = $transport === 'smtp' ? ['SMTP_HOST', 'SMTP_PORT', 'SMTP_USERNAME', 'SMTP_PASSWORD', 'MAIL_FROM'] : ($transport === 'resend' ? ['RESEND_API_KEY', 'MAIL_FROM'] : []);
    $mailConfigured = $transport === 'log';
    if (!$mailConfigured) {
        $mailConfigured = true;
        foreach ($mailRequired as $key) if (trim((string) ($config[$key] ?? '')) === '') $mailConfigured = false;
    }
    $services['email'] = system_health_state($mailConfigured ? 'healthy' : 'degraded', $mailConfigured ? 'ตั้งค่าแล้ว' : 'ต้องตั้งค่า', $mailConfigured ? 'ระบบส่งอีเมลพร้อมสำหรับข้อความทดสอบ' : 'กรุณากำหนดค่าผู้ให้บริการอีเมลให้ครบ', ['metric' => strtoupper($transport), 'transport' => $transport, 'sender' => system_health_mask_email((string) ($config['MAIL_FROM'] ?? ''))]);

    $titleCounts = ['queued' => 0, 'processing' => 0, 'completed' => 0, 'failed' => 0];
    $latestTitle = null;
    $latestRisk = null;
    if ($pdo instanceof PDO) {
        try {
            if (system_health_table_exists($pdo, 'project_title_checks')) {
                foreach ($pdo->query('SELECT status, COUNT(*) total FROM project_title_checks GROUP BY status')->fetchAll() as $row) {
                    $key = strtolower((string) $row['status']); if (isset($titleCounts[$key])) $titleCounts[$key] = (int) $row['total'];
                }
                $latestTitle = $pdo->query("SELECT MAX(completed_at) FROM project_title_checks WHERE status = 'completed'")->fetchColumn() ?: null;
            }
            if (system_health_table_exists($pdo, 'project_risk_scores')) $latestRisk = $pdo->query('SELECT MAX(calculated_at) FROM project_risk_scores')->fetchColumn() ?: null;
        } catch (Throwable $error) { error_log('[SYSTEM HEALTH] ai: ' . $error->getMessage()); }
    }
    $aiEnabled = ai_web_processing_enabled() || filter_var((string) ($config['AI_RISK_ENABLED'] ?? 'true'), FILTER_VALIDATE_BOOLEAN);
    $aiFailed = $titleCounts['failed'];
    $services['ai'] = system_health_state(!$aiEnabled ? 'disabled' : ($aiFailed > 0 ? 'degraded' : 'healthy'), !$aiEnabled ? 'ปิดใช้งาน' : ($aiFailed > 0 ? 'มีงานล้มเหลว' : 'ทำงานปกติ'), !$aiEnabled ? 'เปิด AI_WEB_PROCESSING_ENABLED เมื่อต้องการประมวลผลบนเว็บ' : 'ตรวจชื่อซ้ำและ Risk Score พร้อมประมวลผล', ['metric' => ($titleCounts['queued'] + $titleCounts['processing']) . ' งานรอ', 'enabled' => $aiEnabled, 'title_engine' => (string) ($config['AI_TITLE_ENGINE'] ?? 'auto'), 'title_model' => (string) ($config['AI_OLLAMA_MODEL'] ?? 'bge-m3'), 'queue' => $titleCounts, 'latest_completion' => $latestTitle, 'risk_latest' => $latestRisk]);

    $history = [];
    if ($pdo instanceof PDO) {
        try {
            if (system_health_table_exists($pdo, 'system_job_runs')) $history = $pdo->query("SELECT status, started_at, finished_at, duration_ms, error_code FROM system_job_runs WHERE job_name = 'ai-web-worker' ORDER BY id DESC LIMIT 6")->fetchAll();
        } catch (Throwable $error) { error_log('[SYSTEM HEALTH] cron: ' . $error->getMessage()); }
    }
    $last = $history[0] ?? null;
    $cronStatus = !$last ? 'unknown' : (($last['status'] ?? '') === 'success' ? 'healthy' : (($last['status'] ?? '') === 'started' ? 'degraded' : 'critical'));
    $services['cron'] = system_health_state($cronStatus, !$last ? 'ยังไม่เคยทำงาน' : (($last['status'] ?? '') === 'success' ? 'ล่าสุดสำเร็จ' : 'ควรตรวจสอบ'), !$last ? 'ประวัติจะปรากฏหลัง Scheduled worker ทำงานครั้งแรก' : 'ประวัติการทำงานถูกบันทึกโดยไม่เก็บข้อมูลลับ', ['metric' => !$last ? 'รอการทำงาน' : (($last['duration_ms'] ?? null) !== null ? ((int) $last['duration_ms']) . ' ms' : 'กำลังทำงาน'), 'schedule_utc' => '02:00 UTC ทุกวัน', 'schedule_th' => '09:00 น. ประเทศไทย', 'last_run' => $last, 'history' => $history]);

    $states = array_column($services, 'status');
    $overall = in_array('critical', $states, true) ? 'critical' : (count(array_intersect($states, ['degraded', 'unknown', 'disabled'])) > 0 ? 'degraded' : 'healthy');
    return ['overall' => ['status' => $overall, 'label' => $overall === 'healthy' ? 'ระบบพร้อมใช้งาน' : ($overall === 'critical' ? 'พบระบบสำคัญขัดข้อง' : 'ระบบทำงานได้ แต่ควรตรวจสอบ'), 'response_ms' => round((microtime(true) - $started) * 1000, 1)], 'services' => $services, 'checked_at' => $checkedAt];
}

function system_health_storage_probe(): array
{
    $driver = storage_driver();
    $name = 'health-' . bin2hex(random_bytes(8)) . '.txt';
    if ($driver === 'local') {
        $directory = dirname(__DIR__) . '/uploads/.health-probes';
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) throw new RuntimeException('Unable to create the storage probe directory.');
        $path = $directory . '/' . $name;
        try { if (file_put_contents($path, 'ok', LOCK_EX) !== 2 || file_get_contents($path) !== 'ok') throw new RuntimeException('Storage probe could not be verified.'); }
        finally { if (is_file($path)) @unlink($path); }
        return ['driver' => 'local'];
    }
    $temporary = tempnam(sys_get_temp_dir(), 'rmutp-health-');
    if ($temporary === false || file_put_contents($temporary, 'ok', LOCK_EX) !== 2) throw new RuntimeException('Unable to create the cloud storage probe.');
    $pathname = storage_blob_pathname('proposal', $name);
    try {
        storage_put_uploaded_file($temporary, 'proposal', $name, 'text/plain');
        if (!storage_exists('proposal', $name)) throw new RuntimeException('Cloud storage probe could not be verified.');
        $payload = json_encode(['urls' => [$pathname]], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $handle = storage_curl('https://vercel.com/api/blob/delete', [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . storage_blob_token(),
                'Content-Type: application/json',
                'x-api-version: 12',
                'x-vercel-blob-store-id: ' . storage_blob_store_id(),
            ],
        ]);
        $response = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        unset($handle);
        if ($response === false || $status < 200 || $status >= 300) throw new RuntimeException('Cloud storage probe cleanup failed.');
    } finally {
        if (is_file($temporary)) @unlink($temporary);
    }
    return ['driver' => 'vercel_blob'];
}

function system_health_test_email(string $recipient): array
{
    $subject = 'ทดสอบระบบอีเมล - RMUTP Senior Project';
    $text = "ระบบส่งอีเมลทำงานสำเร็จ\nเวลาทดสอบ: " . date(DATE_ATOM);
    $html = '<div style="font-family:Arial,sans-serif;color:#17233d"><h2 style="color:#0b3c8c">ทดสอบระบบอีเมลสำเร็จ</h2><p>ข้อความนี้ส่งจากหน้า System Health ของ RMUTP Senior Project</p><p>ไม่มีข้อมูลบัญชีหรือรหัสผ่านอยู่ในข้อความนี้</p></div>';
    return send_system_email($recipient, $subject, $html, $text, 'system-health|' . $recipient . '|' . date('Y-m-d-H-i'));
}
