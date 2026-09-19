<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/ai-risk.php';

// This suite cannot connect to a database: calculation must use supplied inputs.
function database_connection(): PDO {
    throw new RuntimeException('Unexpected database access during preloaded calculation');
}
for ($i = 0; $i < 60; $i++) {
    $completed = $i % 3 === 0;
    $project = ['id' => 'R' . $i, 'student_id' => 'S' . $i, 'advisor_id' => 'A',
        'status' => $completed ? 'Completed' : 'Pending', 'progress' => $completed ? 100 : 0,
        'updated_at' => date('Y-m-d H:i:s', strtotime('-60 days'))];
    $risk = calculate_project_risk($project, 70.0, ['documents' => [], 'approvals' => []]);
    if ($risk['project_id'] !== $project['id'] || ($completed && $risk['score'] !== 0)
        || (!$completed && $risk['score'] < 60)) {
        throw new RuntimeException('Risk calculation regression at ' . $i);
    }
}
echo "RISK_PRELOADED_OK: 60 scenarios, zero database calls\n";
