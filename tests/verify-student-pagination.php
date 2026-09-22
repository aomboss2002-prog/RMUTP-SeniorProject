<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/student-list.php';

// Isolated SQL integration test. Never loads .env or connects to application DB.
$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->sqliteCreateFunction('CONCAT', static fn(...$values) => implode('', $values));
$pdo->exec('CREATE TABLE students (id TEXT PRIMARY KEY, code TEXT, first_name TEXT, last_name TEXT, email TEXT, major TEXT, status TEXT, advisor_id TEXT, password_hash TEXT)');
$pdo->exec('CREATE TABLE advisors (id TEXT PRIMARY KEY, name TEXT)');
$pdo->exec("INSERT INTO advisors VALUES ('A1', 'อาจารย์ หนึ่ง')");
$insert = $pdo->prepare('INSERT INTO students VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
for ($i = 1; $i <= 123; $i++) {
    $insert->execute(['S' . $i, sprintf('%013d', $i), 'นักศึกษา', 'ทดสอบ ' . $i,
        "student{$i}@example.test", $i === 123 ? '100%_!' : 'IT', $i % 2 ? 'Pending' : 'Completed',
        $i % 3 ? 'A1' : null, 'must-not-be-returned']);
}
function expect(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
}
$first = student_list_page($pdo, []);
expect(count($first['data']) === 25 && $first['recordsTotal'] === 123, 'Default page/count');
expect($first['data'][0]['id'] === 'S1', 'Stable numeric-code sort');
expect(!isset($first['data'][0]['password_hash']), 'Sensitive field exposure');
$last = student_list_page($pdo, ['start' => 100, 'length' => 25, 'draw' => 8]);
expect(count($last['data']) === 23 && $last['draw'] === 8, 'Last page/draw');
$filtered = student_list_page($pdo, ['status' => 'Completed', 'length' => 999]);
expect($filtered['recordsFiltered'] === 61 && count($filtered['data']) === 50, 'Filter/count/hard cap');
$searched = student_list_page($pdo, ['search_text' => '100%_!']);
expect($searched['recordsFiltered'] === 1 && $searched['data'][0]['id'] === 'S123', 'Literal wildcard search');
$name = student_list_page($pdo, ['search_text' => 'อาจารย์ หนึ่ง']);
expect($name['recordsFiltered'] === 82, 'Advisor join search');
$descending = student_list_page($pdo, ['sort_direction' => 'desc', 'sort_column' => 0]);
expect($descending['data'][0]['id'] === 'S123', 'Descending sort');
$attack = student_list_page($pdo, ['search_text' => "' OR 1=1 --", 'sort_direction' => 'desc; DROP TABLE students', 'sort_column' => 999]);
expect($attack['recordsFiltered'] === 0, 'Untrusted input must remain literal');
$invalid = student_list_page($pdo, ['start' => -1, 'length' => -1, 'draw' => '<script>', 'search_text' => []]);
expect(count($invalid['data']) === 1 && $invalid['draw'] === 0, 'Malformed inputs');
expect(student_list_page($pdo, ['start' => 999])['data'] === [], 'Out of range page');
echo "STUDENT_PAGINATION_OK: isolated SQLite SQL tests; MySQL deployment still requires verification\n";
