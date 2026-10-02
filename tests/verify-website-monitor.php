<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/website-monitor.php';
$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec('CREATE TABLE students (id TEXT, password_hash TEXT);
    CREATE TABLE advisors (id TEXT);
    CREATE TABLE projects (id TEXT, status TEXT);
    CREATE TABLE documents (id TEXT, title TEXT, type TEXT, chapter INTEGER, status TEXT, uploaded_at TEXT, filename TEXT);
    CREATE TABLE activities (progress_id INTEGER, event_type TEXT, stage TEXT, chapter INTEGER, actor TEXT, occurred_at TEXT, new_value INTEGER, metadata_json TEXT, project_id TEXT, document_id TEXT, old_value INTEGER, actor_type TEXT, actor_id TEXT, event_key TEXT, created_at TEXT, activity_type TEXT DEFAULT "progress_updated")');
function monitor_expect(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
$empty = website_monitor_snapshot($pdo);
monitor_expect($empty['available'] && $empty['summary']['documents'] === 0 && $empty['recent_activity'] === [], 'Empty database');
$pdo->exec("INSERT INTO students VALUES ('S1','PRIVATE_PASSWORD'), ('S2','PRIVATE_PASSWORD');
    INSERT INTO advisors VALUES ('A1');
    INSERT INTO projects VALUES ('P1','Pending'), ('P2','Completed'), ('P3','Review')");
$insert = $pdo->prepare('INSERT INTO documents VALUES (?, ?, ?, ?, ?, ?, ?)');
$history = $pdo->prepare('INSERT INTO activities (progress_id,event_type,stage,chapter,actor,occurred_at,new_value,metadata_json) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
for ($i = 1; $i <= 30; $i++) {
    $insert->execute([sprintf('D%03d', $i), 'PDF ' . $i, 'draft', 1, $i % 2 ? 'Review' : 'Approved', '2026-09-27 12:00:00', 'PRIVATE_PATH']);
    $history->execute([$i, 'submitted', 'draft', 1, 'Admin', '2026-09-27 12:00:00', 30, 'PRIVATE_METADATA']);
}
$snapshot = website_monitor_snapshot($pdo);
monitor_expect($snapshot['summary'] === ['students' => 2, 'advisors' => 1, 'projects' => 3, 'documents' => 30, 'awaiting_review' => 15, 'completed_projects' => 1], 'Real counts');
monitor_expect(count($snapshot['recent_documents']) === 6 && $snapshot['recent_documents'][0]['id'] === 'D030', 'Bounded documents stable order');
monitor_expect(count($snapshot['recent_activity']) === 6 && (int) $snapshot['recent_activity'][0]['id'] === 30, 'Bounded real history');
monitor_expect(!str_contains(json_encode($snapshot), 'PRIVATE_'), 'No private fields');
$pdo->exec("UPDATE documents SET status='Approved' WHERE id='D001'; DELETE FROM students WHERE id='S2'");
$updated = website_monitor_snapshot($pdo);
monitor_expect($updated['summary']['students'] === 1 && $updated['summary']['awaiting_review'] === 14, 'Next snapshot reflects changes');
$pdo->exec('DROP TABLE activities');
monitor_expect(website_monitor_snapshot($pdo)['activity_available'] === false, 'Missing history is not success');
monitor_expect(website_monitor_unavailable()['summary'] === null, 'Failed read is not zero data');
echo "WEBSITE_MONITOR_OK: real counts, bounded history, changed data, empty/missing data and field allowlist (SQLite)\n";
