<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/advisor-counts.php';

function check_counts(array $data, array $expected): void
{
    $before = $data;
    $rows = admin_advisors_with_student_counts($data);
    if (array_column($rows, 'students', 'id') !== $expected) throw new RuntimeException('Incorrect advisor count');
    if ($data !== $before) throw new RuntimeException('Read must not modify stored data');
    foreach ($rows as $row) if (isset($row['password_hash'])) throw new RuntimeException('Credential leaked');
}
$data = [
    'advisors' => [
        ['id' => 'A1', 'students' => 99, 'password_hash' => 'private'],
        ['id' => 'A2', 'students' => 0], ['id' => 'A3', 'students' => 0],
    ],
    'students' => [
        ['id' => 'S1', 'advisor_id' => 'A1', 'advisor_roles' => ['chair' => 'A1']],
        ['id' => 'S2', 'advisor_id' => 'A3'], // stale role is overridden by current group
        ['id' => 'S3', 'advisor_roles' => ['chair' => 'A1', 'committee' => 'A1']],
        ['id' => 'S4', 'advisor_id' => 'A2'], // legacy individual assignment
        ['id' => 'S5', 'advisor_id' => 'A3', 'advisor_roles' => []], // explicitly cleared
    ],
    'groups' => [[
        'id' => 'G1', 'member_ids' => ['S1', 'S2', 'S2', 'DELETED'],
        'advisor_roles' => ['chair' => 'A1', 'vice_chair' => 'A2', 'committee' => ''],
    ]],
    'advisor_invitations' => [
        ['group_id' => 'G1', 'advisor_id' => 'A3', 'status' => 'Pending'],
        ['student_id' => 'S3', 'advisor_id' => 'A3', 'status' => 'Rejected'],
        ['group_id' => 'OLD', 'advisor_id' => 'A3', 'status' => 'Accepted'],
    ],
];
check_counts($data, ['A1' => 3, 'A2' => 3, 'A3' => 0]);
// Acceptance assigns the role; every existing group member now counts once.
$data['groups'][0]['advisor_roles']['committee'] = 'A3';
check_counts($data, ['A1' => 3, 'A2' => 3, 'A3' => 2]);
// Deleted students do not count, even if old membership references remain.
$data['students'] = array_values(array_filter($data['students'], fn($row) => $row['id'] !== 'S2'));
check_counts($data, ['A1' => 2, 'A2' => 2, 'A3' => 1]);
$data['groups'][0]['advisor_roles'] = [];
check_counts($data, ['A1' => 1, 'A2' => 1, 'A3' => 0]);
check_counts(['advisors' => $data['advisors']], ['A1' => 0, 'A2' => 0, 'A3' => 0]);
check_counts([], []);
echo "ADVISOR_COUNTS_OK: groups, individual roles, legacy assignments, pending/rejected invitations, deduplication, deletions and credential exclusion\n";
