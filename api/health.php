<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/store.php';
require_once __DIR__ . '/../app/storage.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$startedAt = microtime(true);
$checks = ['database' => false, 'storage' => false];
$status = 200;
$timings = [];

try {
    $databaseStarted = microtime(true);
    $pdo = database_connection();
    $timings[] = 'db_connect;dur=' . round((microtime(true) - $databaseStarted) * 1000, 1);
    $queryStarted = microtime(true);
    $checks['database'] = (int) $pdo->query('SELECT 1')->fetchColumn() === 1;
    $timings[] = 'db_query;dur=' . round((microtime(true) - $queryStarted) * 1000, 1);
} catch (Throwable $exception) {
    $status = 503;
    error_log('Health check database failure: ' . $exception->getMessage());
}

try {
    $driver = storage_driver();
    if ($driver === 'vercel_blob') {
        storage_blob_token();
    }
    $checks['storage'] = true;
} catch (Throwable $exception) {
    $status = 503;
    error_log('Health check storage failure: ' . $exception->getMessage());
}

http_response_code($status);
$timings[] = 'health;dur=' . round((microtime(true) - $startedAt) * 1000, 1);
header('Server-Timing: ' . implode(', ', $timings));
echo json_encode([
    'status' => $status === 200 ? 'ok' : 'degraded',
    'checks' => $checks,
    'response_ms' => round((microtime(true) - $startedAt) * 1000, 1),
    'timestamp' => date(DATE_ATOM),
], JSON_UNESCAPED_SLASHES);
