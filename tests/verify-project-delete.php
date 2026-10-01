<?php
declare(strict_types=1);
require dirname(__DIR__) . '/app/storage.php';
require dirname(__DIR__) . '/app/admin-project-delete.php';
function deletion_check(bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); }
$state = [
    'projects' => [['id' => 'PRJ001', 'title' => 'Delete'], ['id' => 'PRJ002', 'title' => 'Keep']],
    'students' => [['id' => 'S1', 'project_id' => 'PRJ001', 'status' => 'Active'], ['id' => 'S2', 'project_id' => 'PRJ002']],
    'advisors' => [['id' => 'A1']], 'groups' => [['id' => 'G1', 'project_id' => 'PRJ001', 'member_ids' => ['S1']]],
    'documents' => [['id' => 'D1', 'project_id' => 'PRJ001', 'type' => 'proposal', 'filename' => 'delete.pdf'], ['id' => 'D2', 'project_id' => 'PRJ002', 'type' => 'proposal', 'filename' => 'shared.pdf'], ['id' => 'D3', 'project_id' => 'PRJ001', 'type' => 'proposal', 'filename' => 'shared.pdf']],
    'comments' => [['id' => 'C1', 'document_id' => 'D1'], ['id' => 'C2', 'student_id' => 'S1']],
    'approvals' => [['id' => 'AP1', 'document_id' => 'D1']],
    'activities' => [['id' => 'ACT1', 'project_id' => 'PRJ001'], ['id' => 'ACT2', 'project_id' => 'PRJ002']],
    'group_invitations' => [['id' => 'I1', 'previous_project_id' => 'PRJ001']],
];
$after = admin_project_deleted_state($state, 'PRJ001', ['D1', 'D3']);
deletion_check(count($after['projects']) === 1 && count($after['documents']) === 1, 'Target project and documents only');
deletion_check(count($after['students']) === 2 && $after['students'][0]['project_id'] === '' && $after['students'][1]['project_id'] === 'PRJ002', 'Preserve student accounts');
deletion_check($after['advisors'] === $state['advisors'] && $after['groups'][0]['member_ids'] === ['S1'], 'Preserve advisor and group');
deletion_check(array_column($after['comments'], 'id') === ['C2'], 'Preserve unrelated student comments');
deletion_check($after['group_invitations'][0]['previous_project_id'] === '', 'Prevent restoring deleted project');
foreach ([['proposal', '../danger.pdf'], ['student', 'photo.pdf'], ['draft', '.htaccess']] as $unsafe) {
    try { admin_project_remove_file(...$unsafe); throw new LogicException('Unsafe deletion accepted'); }
    catch (RuntimeException $e) { deletion_check($e->getMessage() === 'Unsafe file target', 'Target guard'); }
}
$api = file_get_contents(dirname(__DIR__) . '/api/index.php');
$action = strpos($api, "require_once __DIR__ . '/../app/admin-project-delete.php'");
deletion_check(strpos($api, "!== 'admin'") < $action && strpos($api, 'require_csrf_token();') < $action, 'Admin and CSRF guards');
deletion_check(str_contains($api, "(\$payload['confirm_id'] ?? null) !== \$id"), 'Typed confirmation required');
echo "PROJECT_DELETE_UNIT_OK\n";

$port = (int) (getenv('PROJECT_DELETE_TEST_PORT') ?: 0);
if (!$port) { echo "PROJECT_DELETE_DATABASE_SKIPPED: use isolated temporary MariaDB\n"; exit; }
deletion_check($port > 1024 && $port !== 3306 && $port <= 65535, 'Isolated port required');
$pdo = new PDO("mysql:host=127.0.0.1;port=$port;charset=utf8mb4", 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$dir = str_replace('\\', '/', (string) $pdo->query('SELECT @@datadir')->fetchColumn());
$prefix = rtrim(str_replace('\\', '/', sys_get_temp_dir()), '/') . '/project-delete-';
deletion_check(str_starts_with(strtolower($dir), strtolower($prefix)), 'Refuse non-test server');
$schema = 'project_delete_test_' . bin2hex(random_bytes(8));
$pdo->exec("CREATE DATABASE `$schema` CHARACTER SET utf8mb4");
try {
    $pdo->exec("USE `$schema`");
    $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
    preg_match_all('/CREATE TABLE IF NOT EXISTS\s+\w+\s*\([\s\S]*?\);/', file_get_contents(dirname(__DIR__) . '/database/database.sql'), $tables);
    foreach ($tables[0] as $ddl) $pdo->exec($ddl);
    $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
    $pdo->exec("INSERT INTO advisors (id,name,email,department) VALUES ('A1','Advisor','a@example.invalid','Major')");
    $pdo->exec("INSERT INTO students (id,first_name,last_name,email) VALUES ('S1','One','Test','one@example.invalid'),('S2','Two','Test','two@example.invalid')");
    $pdo->exec("INSERT INTO projects (id,code,title,student_id,advisor_id) VALUES ('PRJ001','ONE','Delete','S1','A1'),('PRJ002','TWO','Keep','S2','A1')");
    $pdo->exec("UPDATE students SET project_id=IF(id='S1','PRJ001','PRJ002')");
    $pdo->exec("INSERT INTO project_groups (id,name,leader_id,project_id,faculty,created_at) VALUES ('G1','Group','S1','PRJ001','Faculty',NOW())");
    $pdo->exec("INSERT INTO project_group_members (group_id,student_id) VALUES ('G1','S1')");
    foreach ($state['documents'] as $doc) $pdo->prepare('INSERT INTO documents (id,project_id,type,title,filename) VALUES (?,?,?,?,?)')->execute([$doc['id'], $doc['project_id'], $doc['type'], 'Test', $doc['filename']]);
    $pdo->exec("INSERT INTO comments (id,student_id,document_id,author,message) VALUES ('C1','S1','D1','Advisor','Delete'),('C2','S1',NULL,'Advisor','Keep')");
    $pdo->exec("INSERT INTO approvals (id,student_id,document_id,step,reviewer) VALUES ('AP1','S1','D1','Proposal','Advisor')");
    $pdo->exec("INSERT INTO activities (id,title,actor) VALUES ('ACT1','Delete','Test'),('ACT2','Keep','Test')");
    $pdo->exec("INSERT INTO project_title_checks (project_id,title) VALUES ('PRJ001','Delete')");
    $pdo->exec("INSERT INTO project_risk_scores (project_id) VALUES ('PRJ001')");
    $pdo->prepare("INSERT INTO app_state VALUES ('runtime',?,CURRENT_TIMESTAMP)")->execute([json_encode($state, JSON_THROW_ON_ERROR)]);
    // Force a failure late in the transaction: documents must be restored by rollback.
    $pdo->exec("CREATE TRIGGER fail_delete BEFORE DELETE ON projects FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='test failure'");
    try { admin_project_delete($pdo, 'PRJ001'); throw new LogicException('Expected rollback'); }
    catch (PDOException) { deletion_check((int) $pdo->query('SELECT COUNT(*) FROM documents')->fetchColumn() === 3, 'Rollback preserves documents'); }
    $pdo->exec('DROP TRIGGER fail_delete');
    $result = admin_project_delete($pdo, 'PRJ001');
    deletion_check((int) $pdo->query('SELECT COUNT(*) FROM projects')->fetchColumn() === 1, 'Only target deleted');
    deletion_check((int) $pdo->query('SELECT COUNT(*) FROM students')->fetchColumn() === 2 && (int) $pdo->query('SELECT COUNT(*) FROM advisors')->fetchColumn() === 1, 'Accounts retained');
    deletion_check((int) $pdo->query('SELECT COUNT(*) FROM documents')->fetchColumn() === 1 && (int) $pdo->query('SELECT COUNT(*) FROM comments')->fetchColumn() === 1, 'Documents/comments removed');
    foreach (['project_title_checks','project_risk_scores','approvals'] as $table) deletion_check((int) $pdo->query("SELECT COUNT(*) FROM $table")->fetchColumn() === 0, 'History cleared: ' . $table);
    deletion_check((int) $pdo->query('SELECT COUNT(*) FROM audit_logs')->fetchColumn() === 1, 'Deletion audit retained');
    $failed = admin_project_cleanup_files($pdo, $result['cleanup_job'], static function () { throw new RuntimeException('Simulated storage failure'); });
    deletion_check($failed['pending'] === 1, 'Failed file retained for retry; shared file skipped');
    deletion_check(count(admin_project_cleanup_jobs($pdo)) === 1, 'Queue survives retry');
    $removed = [];
    $done = admin_project_cleanup_files($pdo, $result['cleanup_job'], static function ($type, $filename) use (&$removed) { $removed[] = "$type/$filename"; });
    deletion_check($done['pending'] === 0 && $removed === ['proposal/delete.pdf'], 'Only orphan target file deleted');
    deletion_check(admin_project_cleanup_jobs($pdo) === [], 'Completed queue removed');
    deletion_check(admin_project_cleanup_files($pdo, $result['cleanup_job'])['pending'] === 0, 'Retry is idempotent');
    try { admin_project_delete($pdo, 'PRJ001'); throw new LogicException('Missing project should fail'); } catch (OutOfBoundsException) {}
    echo "PROJECT_DELETE_DATABASE_OK: transactional rollback, scoped deletion, preserved accounts/groups, audit, shared files, durable retry, idempotence\n";
} finally {
    if ($pdo->inTransaction()) $pdo->rollBack();
    deletion_check(preg_match('/^project_delete_test_[a-f0-9]{16}$/D', $schema) === 1, 'Cleanup target');
    $pdo->exec("DROP DATABASE `$schema`");
}
