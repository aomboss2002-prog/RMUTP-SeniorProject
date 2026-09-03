<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/app/store.php';
require_once dirname(__DIR__) . '/app/ai-title-check.php';
require_once dirname(__DIR__) . '/app/ai-risk.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$watch = in_array('--watch', $argv, true);
$quiet = in_array('--quiet', $argv, true);
$sleepSeconds = 3;
$riskScanInterval = max(60, (int) ai_title_config('AI_RISK_SCAN_INTERVAL', '300'));
$riskEnabled = filter_var(ai_title_config('AI_RISK_ENABLED', 'true'), FILTER_VALIDATE_BOOLEAN);
foreach ($argv as $argument) {
    if (preg_match('/^--sleep=(\d+)$/', $argument, $matches)) $sleepSeconds = max(1, min(60, (int) $matches[1]));
}

$runtimeDirectory = dirname(__DIR__) . '/cache';
if (!is_dir($runtimeDirectory)) @mkdir($runtimeDirectory, 0775, true);
$lock = fopen($runtimeDirectory . '/ai-title-worker.lock', 'c+');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
    if (!$quiet) fwrite(STDOUT, "AI background worker is already running.\n");
    exit(0);
}

if (!$quiet) fwrite(STDOUT, 'AI background worker ready (title=' . ai_title_config('AI_TITLE_ENGINE', 'auto')
    . ', risk=' . ($riskEnabled ? 'enabled' : 'disabled') . ").\n");
$nextRiskScanAt = 0;
do {
    $worked = false;
    $job = null;
    try {
        $job = claim_project_title_check_job();
        if ($job) {
            $result = process_project_title_check_job($job);
            $worked = true;
            if (!$quiet) {
                $percent = number_format(((float) ($result['max_similarity'] ?? 0)) * 100, 1);
                fwrite(STDOUT, "Checked title job #{$job['id']} project {$job['project_id']}: {$percent}% ({$result['engine']})\n");
            }
        }
    } catch (Throwable $error) {
        if (is_array($job)) fail_project_title_check_job($job, $error);
        fwrite(STDERR, '[AI TITLE WORKER] ' . $error->getMessage() . "\n");
        if (!$watch) exit(1);
    }

    if ($riskEnabled && time() >= $nextRiskScanAt) {
        try {
            $summary = process_project_risk_scores();
            $worked = true;
            if (!$quiet) {
                fwrite(STDOUT, 'Risk scan: ' . $summary['processed'] . ' projects; '
                    . 'low=' . $summary['levels']['low'] . ', watch=' . $summary['levels']['watch']
                    . ', high=' . $summary['levels']['high'] . ', critical=' . $summary['levels']['critical'] . "\n");
            }
        } catch (Throwable $error) {
            fwrite(STDERR, '[AI RISK WORKER] ' . $error->getMessage() . "\n");
            if (!$watch) exit(1);
        }
        $nextRiskScanAt = time() + $riskScanInterval;
    }

    if (!$watch) break;
    if (!$worked) sleep($sleepSeconds);
} while ($watch);

flock($lock, LOCK_UN);
fclose($lock);
