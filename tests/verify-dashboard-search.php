<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/dashboard-search.php';
$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->sqliteCreateFunction('CONCAT', static fn(...$values) => implode('', $values));
$pdo->exec('CREATE TABLE students (id TEXT PRIMARY KEY, code TEXT, first_name TEXT, last_name TEXT, major TEXT, password_hash TEXT)');
$pdo->exec('CREATE TABLE projects (id TEXT PRIMARY KEY, code TEXT, title TEXT, student_id TEXT)');
$student = $pdo->prepare('INSERT INTO students VALUES (?, ?, ?, ?, ?, ?)');
$project = $pdo->prepare('INSERT INTO projects VALUES (?, ?, ?, ?)');
for ($i = 0; $i < 60; $i++) {
    $student->execute(['S' . $i, 'CODE' . $i, 'นักศึกษา', 'ทดสอบ ' . $i, 'IT', 'not-public']);
    $project->execute(['P' . $i, null, 'โครงงาน ' . $i, 'S' . $i]);
}
$student->execute(['literal', '100%_!', 'Unique', 'Person', 'Business', 'not-public']);
$checks = [
    dashboard_search($pdo, '') === ['students' => [], 'projects' => []],
    count(dashboard_search($pdo, 'ทดสอบ')['students']) === 4,
    count(dashboard_search($pdo, 'ทดสอบ')['projects']) === 4,
    count(dashboard_search($pdo, 'โครงงาน')['projects']) === 4,
    count(dashboard_search($pdo, '100%_!')['students']) === 1,
    dashboard_search($pdo, "' OR 1=1 --") === ['students' => [], 'projects' => []],
    !array_key_exists('password_hash', dashboard_search($pdo, 'Unique')['students'][0]),
];
if (in_array(false, $checks, true)) throw new RuntimeException('Dashboard search regression');
echo "DASHBOARD_SEARCH_OK: bounded results, Thai names, NULL codes, wildcard escaping, private field exclusion (SQLite)\n";
