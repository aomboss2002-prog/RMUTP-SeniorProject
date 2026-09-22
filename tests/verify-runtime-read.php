<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/runtime-read.php';
$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec('CREATE TABLE app_state (state_key TEXT PRIMARY KEY, state_json TEXT)');
$rows = [
    ['id' => 'own', 'student_id' => 'S1', 'read_by' => ['S1']],
    ['id' => 'other', 'student_id' => 'S2'],
    ['id' => 'group', 'group_id' => 'G1'],
    ['id' => 'other-group', 'group_id' => 'G2'],
    ['id' => 'global', 'scope' => 'system'],
    ['id' => 'legacy', 'scope' => 'legacy'],
    ['id' => 'advisor', 'advisor_id' => 'A1'],
    ['id' => 'duplicate-match', 'student_id' => 'S1', 'group_id' => 'G1'],
];
$statement = $pdo->prepare('INSERT INTO app_state VALUES (?, ?)');
$statement->execute(['runtime', json_encode(['notifications' => $rows, 'groups' => [['id' => 'G1', 'member_ids' => ['S1']]],
    'students' => [['password_hash' => 'private']], 'documents' => array_fill(0, 2000, ['title' => 'not-needed'])])]);
$selected = runtime_read_collections($pdo, ['groups', 'notifications']);
$messageRows = [
    ['id' => 'personal', 'student_id' => 'S1'],
    ['id' => 'teammate', 'student_id' => 'S2', 'group_id' => 'G1'],
    ['id' => 'old-group', 'student_id' => 'S1', 'group_id' => 'G2'],
    ['id' => 'outsider', 'student_id' => 'S2'],
];
if (array_column(student_visible_messages($messageRows, 'S1', null), 'id') !== ['personal']) throw new RuntimeException('Personal message scope');
if (array_column(student_visible_messages($messageRows, 'S1', ['id' => 'G1']), 'id') !== ['teammate']) throw new RuntimeException('Group message scope');
$minimalTimeline = runtime_read_collections($pdo, ['students', 'groups', 'projects']);
if (isset($minimalTimeline['documents']) || isset($minimalTimeline['notifications'])) throw new RuntimeException('Timeline read unrelated data');
$minimalMessages = runtime_read_collections($pdo, ['students', 'groups', 'advisors', 'messages']);
if (isset($minimalMessages['documents']) || isset($minimalMessages['notifications'])) throw new RuntimeException('Messages read unrelated data');
if (array_keys($selected) !== ['groups', 'notifications'] || $selected['notifications'] !== $rows) throw new RuntimeException('Projection changed data');
foreach (['G1' => ['own', 'group', 'global', 'duplicate-match'], '' => ['own', 'global', 'duplicate-match']] as $group => $expected) {
    if (array_column(student_visible_notifications($rows, 'S1', $group), 'id') !== $expected) throw new RuntimeException('Audience scope regression');
}
try {
    runtime_read_collections($pdo, ['settings']);
    throw new RuntimeException('Allowlist failed');
} catch (InvalidArgumentException $expected) {}
$pdo->exec('DELETE FROM app_state');
if (runtime_read_collections($pdo, ['groups']) !== ['groups' => []]) throw new RuntimeException('Missing state regression');
echo "RUNTIME_READ_OK: projection, read state, notification/message scopes and source order (SQLite)\n";
