<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/admin-list.php';
$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->sqliteCreateFunction('CONCAT', static fn(...$values) => implode('', $values));
$pdo->exec('CREATE TABLE projects (id TEXT PRIMARY KEY, code TEXT, title TEXT, category TEXT, progress INTEGER, status TEXT, student_id TEXT, advisor_id TEXT)');
$pdo->exec('CREATE TABLE students (id TEXT PRIMARY KEY, first_name TEXT, last_name TEXT)');
$pdo->exec('CREATE TABLE advisors (id TEXT PRIMARY KEY, name TEXT)');
$pdo->exec('CREATE TABLE documents (id TEXT PRIMARY KEY, project_id TEXT, student_id TEXT, type TEXT, title TEXT, filename TEXT, size TEXT, status TEXT, uploaded_at TEXT)');
$pdo->exec("INSERT INTO students VALUES ('S', 'นักศึกษา', 'ทดสอบ'); INSERT INTO advisors VALUES ('A', 'อาจารย์')");
$project = $pdo->prepare('INSERT INTO projects VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
$document = $pdo->prepare('INSERT INTO documents VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
for ($i = 1; $i <= 63; $i++) {
    $id = sprintf('P%03d', $i);
    $project->execute([$id, $id, 'โครงงาน ' . $i, 'IT', $i, $i % 2 ? 'Pending' : 'Completed', 'S', 'A']);
    $document->execute(['D' . $id, $id, 'S', $i % 2 ? 'proposal' : 'draft', 'เอกสาร ' . $i, 'test.pdf', '1 MB', 'Review', '2026-09-01']);
}
$document->execute(['COMP1', 'P001', 'S', 'complete', 'ฉบับเก่า', 'old.pdf', '1 MB', 'Approved', '2026-09-01']);
$document->execute(['COMP2', 'P001', 'S', 'complete', '100%_!', 'new.pdf', '1 MB', 'Review', '2026-09-02']);
function check_page(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
$page = admin_list_page($pdo, 'projects', []);
check_page(count($page['data']) === 25 && $page['recordsTotal'] === 63, 'Project page');
check_page($page['data'][0]['complete_approved'] === false, 'Newest complete must override old approval');
check_page($page['data'][0]['student_name'] === 'นักศึกษา ทดสอบ', 'Joined student name');
$page = admin_list_page($pdo, 'projects', ['sort_column' => 4, 'sort_direction' => 'desc', 'start' => 50]);
check_page(count($page['data']) === 13 && $page['data'][0]['progress'] === 13, 'Progress sort / last page');
$page = admin_list_page($pdo, 'projects', ['status' => 'Completed']);
check_page($page['recordsFiltered'] === 31 && $page['recordsTotal'] === 63, 'Status counts');
$page = admin_list_page($pdo, 'documents', []);
check_page(count($page['data']) === 25 && $page['recordsTotal'] === 65 && (int) $page['counts']['complete'] === 2, 'Document totals');
$page = admin_list_page($pdo, 'documents', ['type' => 'complete', 'search_text' => '100%_!', 'status' => 'Review']);
check_page($page['recordsTotal'] === 2 && $page['recordsFiltered'] === 1 && $page['data'][0]['id'] === 'COMP2', 'Scoped filters / binding order');
check_page(!isset($page['counts']), 'No unneeded totals on stage page');
foreach (['projects', 'documents'] as $resource) {
    $page = admin_list_page($pdo, $resource, ['length' => 999, 'start' => -10, 'draw' => '<script>']);
    check_page(count($page['data']) === 50 && $page['draw'] === 0, 'Bounded request');
    $page = admin_list_page($pdo, $resource, ['search_text' => "' OR 1=1 --", 'sort_column' => 900, 'sort_direction' => 'DESC; DROP TABLE projects']);
    check_page($page['recordsFiltered'] === 0, 'SQL injection protection');
    check_page(admin_list_page($pdo, $resource, ['start' => 999])['data'] === [], 'Out-of-range page');
}
$pdo->exec('DELETE FROM documents; DELETE FROM projects');
check_page(admin_list_page($pdo, 'projects', [])['recordsFiltered'] === 0, 'Empty table');
echo "ADMIN_PAGINATION_OK: isolated SQLite integration; MySQL production verification still required\n";
