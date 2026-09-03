<?php
declare(strict_types=1);

putenv('AI_TITLE_ENGINE=local');
require_once dirname(__DIR__) . '/app/store.php';
require_once dirname(__DIR__) . '/app/ai-title-check.php';

$pdo = database_connection();
$suffix = strtoupper(substr(bin2hex(random_bytes(5)), 0, 10));
$candidateId = 'AICAND' . $suffix;
$projectId = 'AITEST' . $suffix;
$candidateTitle = 'ระบบติดตามโครงงานนักศึกษาด้วยปัญญาประดิษฐ์';
$insert = $pdo->prepare(
    "INSERT INTO projects (id, code, title, student_id, advisor_id, category, status, progress)
     VALUES (:id, NULL, :title, NULL, NULL, 'Test', 'Pending', 0)"
);
$insert->execute(['id' => $candidateId, 'title' => $candidateTitle]);
$insert->execute(['id' => $projectId, 'title' => 'Temporary AI title check']);

try {
    $queued = queue_project_title_check($projectId, $candidateTitle);
    if (!$queued || !in_array(($queued['status'] ?? ''), ['queued', 'completed'], true)) {
        throw new RuntimeException('Job was neither queued nor completed inline.');
    }
    if (($queued['status'] ?? '') === 'completed') {
        $result = $queued;
    } elseif (in_array('--background', $argv, true)) {
        // A cold Ollama model may need extra time to load on the first job.
        $deadline = microtime(true) + 45;
        do {
            usleep(250000);
            $result = latest_project_title_check($projectId, (int) $queued['id']) ?? [];
        } while (microtime(true) < $deadline && !in_array($result['status'] ?? '', ['completed', 'failed'], true));
    } else {
        $pdo->prepare("UPDATE project_title_checks SET status='processing', attempts=1, started_at=NOW() WHERE id=:id")
            ->execute(['id' => $queued['id']]);
        $result = process_project_title_check_job([
            'id' => $queued['id'], 'project_id' => $projectId,
            'title' => $candidateTitle, 'attempts' => 1,
        ]);
    }
    if (($result['status'] ?? '') !== 'completed') throw new RuntimeException('Job did not complete.');
    if ((float) ($result['max_similarity'] ?? 0) < 0.99) throw new RuntimeException('Exact duplicate was not detected.');
    if (($result['risk_level'] ?? '') !== 'high') throw new RuntimeException('Exact duplicate was not marked high risk.');
    echo "AI_TITLE_WORKER_OK score=" . number_format((float) $result['max_similarity'] * 100, 1)
        . "% engine=" . ($result['engine'] ?? '') . PHP_EOL;
} finally {
    $pdo->prepare('DELETE FROM projects WHERE id = :id')->execute(['id' => $projectId]);
    $pdo->prepare('DELETE FROM projects WHERE id = :id')->execute(['id' => $candidateId]);
}
