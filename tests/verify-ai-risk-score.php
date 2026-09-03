<?php
declare(strict_types=1);

$webMode = in_array('--web', $argv, true);
if ($webMode) putenv('AI_WEB_PROCESSING_ENABLED=true');

require_once dirname(__DIR__) . '/app/store.php';
require_once dirname(__DIR__) . '/app/ai-risk.php';

$pdo = database_connection();
$suffix = strtoupper(substr(bin2hex(random_bytes(4)), 0, 8));
$staleId = 'RISKST' . $suffix;
$referenceId = 'RISKRF' . $suffix;
$completeId = 'RISKOK' . $suffix;
$projectInsert = $pdo->prepare(
    "INSERT INTO projects (id, code, title, student_id, advisor_id, category, status, progress, updated_at)
     VALUES (:id, NULL, :title, NULL, NULL, 'Test', :status, :progress, :updated_at)"
);
$projectInsert->execute(['id' => $staleId, 'title' => 'Stale risk test', 'status' => 'Pending', 'progress' => 0, 'updated_at' => date('Y-m-d H:i:s', strtotime('-60 days'))]);
$projectInsert->execute(['id' => $referenceId, 'title' => 'Reference progress test', 'status' => 'Draft', 'progress' => 80, 'updated_at' => date('Y-m-d H:i:s')]);
$projectInsert->execute(['id' => $completeId, 'title' => 'Completed risk test', 'status' => 'Completed', 'progress' => 100, 'updated_at' => date('Y-m-d H:i:s')]);

$documentInsert = $pdo->prepare(
    "INSERT INTO documents (id, project_id, student_id, type, chapter, title, filename, size, status, uploaded_at)
     VALUES (:id, :project_id, NULL, 'draft', :chapter, 'Risk test document', 'risk-test.pdf', '1 KB', 'NeedsRevision', :uploaded_at)"
);
for ($chapter = 1; $chapter <= 4; $chapter++) {
    $documentInsert->execute([
        'id' => 'RK' . $chapter . $suffix, 'project_id' => $staleId, 'chapter' => $chapter,
        'uploaded_at' => date('Y-m-d H:i:s', strtotime('-60 days')),
    ]);
}

try {
    $median = active_project_median_progress($pdo);
    $projectStatement = $pdo->prepare('SELECT id, student_id, advisor_id, status, progress, updated_at FROM projects WHERE id = :id');
    $projectStatement->execute(['id' => $staleId]);
    $staleRisk = calculate_project_risk($projectStatement->fetch(), $median);
    if (!$webMode) save_project_risk_score($staleRisk);
    $storedStale = latest_project_risk_score($staleId);
    if (!$storedStale || $storedStale['score'] < 60) throw new RuntimeException('Stale project was not marked high risk.');
    if (count($storedStale['factors']) < 3) throw new RuntimeException('Risk reasons were not stored.');

    $projectStatement->execute(['id' => $completeId]);
    $completeRisk = calculate_project_risk($projectStatement->fetch(), $median);
    if ($completeRisk['score'] !== 0 || $completeRisk['risk_level'] !== 'low') {
        throw new RuntimeException('Completed project did not remain low risk.');
    }

    echo 'AI_RISK_SCORE_OK stale=' . $storedStale['score'] . '% level=' . $storedStale['risk_level']
        . ' factors=' . count($storedStale['factors']) . ' completed=0%' . PHP_EOL;
} finally {
    $pdo->prepare('DELETE FROM documents WHERE project_id = :project_id')->execute(['project_id' => $staleId]);
    foreach ([$staleId, $referenceId, $completeId] as $id) {
        $pdo->prepare('DELETE FROM projects WHERE id = :id')->execute(['id' => $id]);
    }
}
