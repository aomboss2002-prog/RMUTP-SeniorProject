<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/helpers.php';
require_once __DIR__ . '/../app/public-catalog.php';
require_once __DIR__ . '/../app/public-catalog-source.php';
$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec('CREATE TABLE app_state (state_key TEXT, state_json TEXT);
    CREATE TABLE documents (id TEXT, project_id TEXT, student_id TEXT, group_id TEXT, type TEXT, status TEXT, filename TEXT, title TEXT, approved_at TEXT, uploaded_at TEXT)');
$data = ['students' => [['id' => 'S', 'first_name' => 'Student', 'last_name' => 'One', 'password_hash' => 'secret-value']],
    'projects' => [], 'groups' => [], 'settings' => ['academic_year' => '2026'], 'documents' => [], 'messages' => ['private-message']];
$insert = $pdo->prepare('INSERT INTO documents VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
for ($i = 1; $i <= 60; $i++) {
    $id = sprintf('%03d', $i);
    $data['projects'][] = ['id' => 'P' . $id, 'student_id' => 'S', 'title' => 'Project ' . $id, 'category' => 'Research'];
    $doc = ['id' => 'D' . $id, 'project_id' => 'P' . $id, 'student_id' => 'S', 'group_id' => '',
        'type' => $i === 60 ? 'draft' : 'complete', 'status' => $i === 59 ? 'Review' : 'Approved',
        'filename' => $id . '.pdf', 'title' => 'Document ' . $id, 'approved_at' => '2026-09-19', 'uploaded_at' => '2026-09-18'];
    $data['documents'][] = $doc;
    $insert->execute(array_values($doc));
}
$pdo->prepare('INSERT INTO app_state VALUES (?, ?)')->execute(['runtime', json_encode($data)]);
$source = public_catalog_source($pdo);
if (isset($source['messages']) || count($source['documents']) !== 58) throw new RuntimeException('Read unrelated or ineligible data');
$calls = 0;
$available = static function (array $doc) use (&$calls): bool { $calls++; return $doc['id'] !== 'D058'; };
$result = public_completed_catalog($source, [], $available);
if ($calls !== 5 || $result['pagination']['total'] !== 58 || $result['items'][0]['available']) throw new RuntimeException('Storage lookup/pagination regression');
if (str_contains(json_encode($result), 'secret-value')) throw new RuntimeException('Private student data exposed');
$expected = public_completed_catalog($data, [], static fn(array $doc): bool => $doc['id'] !== 'D058');
if ($result !== $expected) throw new RuntimeException('SQL source changed public result');
$calls = 0;
$filtered = public_completed_catalog($source, ['q' => 'missing'], $available);
if ($calls !== 0 || $filtered['items'] !== []) throw new RuntimeException('Unneeded storage reads');
$calls = 0;
$last = public_completed_catalog($source, ['page' => 999], $available);
if ($calls !== 3 || $last['pagination']['page'] !== 12) throw new RuntimeException('Last page storage bound');
echo "CATALOG_PERFORMANCE_OK: 58 eligible projects, 5 storage probes on first page, 0 on empty search; SQLite source parity\n";
