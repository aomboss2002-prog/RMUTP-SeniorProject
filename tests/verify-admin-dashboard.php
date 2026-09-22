<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/admin-dashboard.php';

// Isolated SQL tests: no application bootstrap, .env, or real database writes.
$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec('CREATE TABLE students (id TEXT); CREATE TABLE advisors (id TEXT);
    CREATE TABLE projects (status TEXT);
    CREATE TABLE documents (id TEXT, title TEXT, type TEXT, status TEXT, uploaded_at TEXT);
    CREATE TABLE project_risk_scores (risk_level TEXT, calculated_at TEXT);
    CREATE TABLE app_state (state_key TEXT PRIMARY KEY, state_json TEXT)');
function dashboard_expect(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
}
$empty = admin_dashboard_payload($pdo);
dashboard_expect($empty['summary'] === ['students' => 0, 'advisors' => 0, 'projects' => 0, 'pending' => 0], 'Empty summary');
dashboard_expect($empty['activities'] === [] && $empty['files'] === [], 'Missing runtime');
$document = $pdo->prepare('INSERT INTO documents VALUES (?, ?, ?, ?, ?)');
for ($i = 0; $i < 100; $i++) {
    $pdo->exec("INSERT INTO students VALUES ('S{$i}')");
    $pdo->exec("INSERT INTO projects VALUES ('" . ($i % 2 ? 'Pending' : 'Completed') . "')");
    $document->execute([sprintf('D%03d', $i), 'Document ' . $i, $i % 2 ? 'draft' : 'proposal', 'Review', '2026-09-19 10:00:00']);
}
$pdo->exec("INSERT INTO advisors VALUES ('A'); INSERT INTO project_risk_scores VALUES ('HIGH', '2026-09-19'), ('low', '2026-09-18'), ('unknown', '2026-09-20')");
$runtime = ['students' => array_fill(0, 1000, ['password_hash' => 'never-transfer']), 'activities' => [], 'notifications' => [], 'approvals' => []];
for ($i = 0; $i < 30; $i++) {
    $runtime['activities'][] = ['title' => 'Activity ' . $i, 'actor' => 'Tester', 'created_at' => '2026-09-19', 'private' => 'omit'];
    $runtime['notifications'][] = ['title' => 'Notice ' . $i, 'message' => 'Message', 'private' => 'omit'];
    $runtime['approvals'][] = ['step' => 'Step ' . $i, 'reviewer' => 'Advisor', 'status' => 'Review', 'created_at' => '2026-09-19', 'private' => 'omit'];
}
$save = $pdo->prepare("INSERT INTO app_state VALUES ('runtime', ?)");
$save->execute([json_encode($runtime)]);
$result = admin_dashboard_payload($pdo);
dashboard_expect($result['summary'] === ['students' => 100, 'advisors' => 1, 'projects' => 100, 'pending' => 50], 'Relational counts, not JSON counts');
dashboard_expect($result['uploads']['draft'] === 50 && $result['project_status']['Completed'] === 50, 'Aggregates');
dashboard_expect($result['risk_overview']['total'] === 2 && $result['risk_overview']['latest_calculated_at'] === '2026-09-19', 'Risk normalization');
dashboard_expect(count($result['files']) === 5 && $result['files'][0]['title'] === 'Document 99', 'Bounded newest files, stable tie order');
foreach (['activities', 'notifications', 'approvals'] as $collection) {
    dashboard_expect(count($result[$collection]) === 5, 'Bounded ' . $collection);
    dashboard_expect(!isset($result[$collection][0]['private']), 'Field allowlist');
}
dashboard_expect($result['activities'][0]['title'] === 'Activity 0', 'Preserve runtime order');
dashboard_expect(!str_contains(json_encode($result), 'never-transfer'), 'Do not transfer runtime users');
$pdo->exec("UPDATE app_state SET state_json='{}'");
dashboard_expect(admin_dashboard_payload($pdo)['notifications'] === [], 'Missing collections');
$pdo->exec('DROP TABLE project_risk_scores');
dashboard_expect(admin_dashboard_payload($pdo)['risk_overview']['total'] === 0, 'Optional risk migration');
echo "ADMIN_DASHBOARD_OK: bounded SQL summary / JSON projection (SQLite); MySQL still requires verification\n";
