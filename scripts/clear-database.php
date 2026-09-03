<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/app/store.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
if (!in_array('--yes', $argv, true)) {
    fwrite(STDERR, "This removes all application records. Run again with --yes.\n");
    exit(2);
}

$pdo = database_connection();
$tables = [
    'notification_reads', 'password_reset_tokens', 'php_sessions', 'user_sessions',
    'advisor_followups', 'project_progress_history',
    'group_messages', 'approvals', 'comments', 'documents',
    'advisor_invitations', 'group_invitations', 'project_group_members', 'project_groups',
    'student_advisors', 'project_title_checks', 'project_risk_scores',
    'notifications', 'activities', 'audit_logs', 'projects', 'students', 'advisors',
];

$emptyRuntime = seed_data();
foreach ([
    'students', 'advisors', 'projects', 'documents', 'notifications', 'activities',
    'comments', 'approvals', 'groups', 'messages', 'advisor_invitations', 'group_invitations',
] as $collection) {
    $emptyRuntime[$collection] = [];
}
$emptyRuntime['_runtime_version'] = '20260828_02_fast_state';
$emptyAdvisorPortal = [
    'advisor' => [], 'students' => [], 'projects' => [], 'documents' => [],
    'comments' => [], 'messages' => [], 'notifications' => [], 'calendar' => [],
];

$pdo->beginTransaction();
try {
    $deleted = [];
    foreach ($tables as $table) {
        $deleted[$table] = $pdo->exec("DELETE FROM `{$table}`");
    }
    $state = $pdo->prepare(
        'INSERT INTO app_state (state_key, state_json) VALUES (:state_key, :state_json)
         ON DUPLICATE KEY UPDATE state_json = VALUES(state_json)'
    );
    $state->execute([
        'state_key' => 'runtime',
        'state_json' => json_encode($emptyRuntime, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
    ]);
    $state->execute([
        'state_key' => 'advisor_portal',
        'state_json' => json_encode($emptyAdvisorPortal, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
    ]);
    $pdo->commit();
} catch (Throwable $error) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    throw $error;
}

echo 'DATABASE_APPLICATION_DATA_CLEARED rows=' . array_sum($deleted) . PHP_EOL;
echo 'PRESERVED tables=settings,schema_migrations,app_state profile/settings' . PHP_EOL;
