<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/store.php';
require_once __DIR__ . '/../app/ai-title-check.php';
require_once __DIR__ . '/../app/ai-risk.php';
require_once __DIR__ . '/../app/system-health.php';

header('Content-Type: application/json; charset=utf-8');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    http_response_code(405);
    header('Allow: GET');
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}

$config = env_config();
$secret = trim((string) ($config['CRON_SECRET'] ?? ''));
$authorization = trim((string) (
    $_SERVER['HTTP_AUTHORIZATION']
    ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION']
    ?? ''
));
if ($secret === '' || !hash_equals('Bearer ' . $secret, $authorization)) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized.']);
    exit;
}

$titleSummary = ['processed' => 0, 'failed' => 0];
$runId = null;
$runStarted = microtime(true);
try {
    $pdo = database_connection();
    $pdo->exec("CREATE TABLE IF NOT EXISTS system_job_runs (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, job_name VARCHAR(80) NOT NULL,
        status ENUM('started','success','failed') NOT NULL DEFAULT 'started', started_at DATETIME NOT NULL,
        finished_at DATETIME NULL, duration_ms INT UNSIGNED NULL, summary_json TEXT NULL,
        error_code VARCHAR(80) NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_system_job_name_started (job_name, started_at), INDEX idx_system_job_status_started (status, started_at)
    ) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $startStatement = $pdo->prepare("INSERT INTO system_job_runs (job_name, status, started_at) VALUES ('ai-web-worker', 'started', NOW())");
    $startStatement->execute();
    $runId = (int) $pdo->lastInsertId();
    // Keep each serverless invocation short. Remaining work is picked up by
    // the next web request, local worker, or scheduled invocation.
    for ($index = 0; $index < 10; $index++) {
        $job = claim_project_title_check_job();
        if (!$job) break;
        try {
            process_project_title_check_job($job);
            $titleSummary['processed']++;
        } catch (Throwable $error) {
            fail_project_title_check_job($job, $error);
            $titleSummary['failed']++;
        }
    }

    $riskSummary = filter_var((string) ($config['AI_RISK_ENABLED'] ?? 'true'), FILTER_VALIDATE_BOOLEAN)
        ? process_project_risk_scores(20)
        : ['processed' => 0, 'levels' => [], 'disabled' => true];

    $payload = [
        'success' => true,
        'title_checks' => $titleSummary,
        'risk_scores' => $riskSummary,
        'processed_at' => date(DATE_ATOM),
    ];
    if ($runId) {
        $duration = (int) round((microtime(true) - $runStarted) * 1000);
        $summary = json_encode(['title_processed' => $titleSummary['processed'], 'title_failed' => $titleSummary['failed'], 'risk_processed' => (int) ($riskSummary['processed'] ?? 0)], JSON_UNESCAPED_SLASHES);
        $finish = $pdo->prepare("UPDATE system_job_runs SET status='success', finished_at=NOW(), duration_ms=:duration, summary_json=:summary WHERE id=:id");
        $finish->execute(['duration' => $duration, 'summary' => $summary, 'id' => $runId]);
    }
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
} catch (Throwable $error) {
    error_log('[AI WEB WORKER] ' . $error->getMessage());
    if ($runId && isset($pdo) && $pdo instanceof PDO) {
        try {
            $code = $error instanceof PDOException ? 'database_error' : 'worker_error';
            $finish = $pdo->prepare("UPDATE system_job_runs SET status='failed', finished_at=NOW(), duration_ms=:duration, error_code=:code WHERE id=:id");
            $finish->execute(['duration' => (int) round((microtime(true) - $runStarted) * 1000), 'code' => $code, 'id' => $runId]);
        } catch (Throwable) { /* Keep the original worker failure response safe. */ }
    }
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'AI web processing failed.']);
}
