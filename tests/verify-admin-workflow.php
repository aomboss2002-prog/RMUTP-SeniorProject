<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/admin-workflow.php';

function expect_admin(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
}
function reject_admin(callable $action): void {
    try { $action(); } catch (InvalidArgumentException) { return; }
    throw new RuntimeException('Expected validation failure');
}
$rows = [
    ['id' => 1, 'updated_at' => '2026-09-01 00:00:00'],
    ['id' => 2, 'updated_at' => '2026-09-02 23:59:59'],
    ['id' => 3, 'updated_at' => '2026-09-03 00:00:00'],
    ['id' => 4],
];
expect_admin(count(admin_report_filter($rows, 'updated_at', '', '')) === 4, 'Unfiltered report');
expect_admin(array_column(admin_report_filter($rows, 'updated_at', '2026-09-01', '2026-09-02'), 'id') === [1, 2], 'Inclusive date bounds');
expect_admin(array_column(admin_report_filter($rows, 'updated_at', '2026-09-03', ''), 'id') === [3], 'Open end');
expect_admin(array_column(admin_report_filter($rows, 'updated_at', '', '2026-09-01'), 'id') === [1], 'Open start');
reject_admin(fn() => admin_report_filter($rows, 'updated_at', '2026-02-30', ''));
reject_admin(fn() => admin_report_filter($rows, 'updated_at', '2026-09-03', '2026-09-01'));
expect_admin(admin_timeline_rows([]) === [], 'Do not invent history');
$timeline = admin_timeline_rows([
    ['event_type' => 'submitted', 'stage' => 'draft', 'chapter' => 3, 'actor_name' => 'Test', 'occurred_at' => '2026-09-01', 'current_progress' => 46],
    ['event_type' => 'approved', 'stage' => 'draft', 'chapter' => 3, 'occurred_at' => '2026-09-02', 'current_progress' => 54],
    ['event_type' => 'document_deleted', 'stage' => 'draft', 'chapter' => 3, 'occurred_at' => '2026-09-03', 'current_progress' => 46],
]);
expect_admin(count($timeline) === 3 && $timeline[0]['status'] === 'Review' && $timeline[1]['status'] === 'Approved', 'Actual event statuses');
expect_admin(str_contains($timeline[0]['step'], '3') && $timeline[0]['reviewer'] === 'Test', 'Chapter and actor');
expect_admin($timeline[2]['status'] === '' && $timeline[2]['progress'] === 46, 'Deleted is not approved');
$data = ['students' => [['id' => 'S1'], ['id' => 'S2']], 'projects' => [['id' => 'P1', 'student_id' => 'S1']]];
$payload = ['student_id' => 'S1', 'project_id' => 'P1', 'type' => 'draft', 'chapter' => 3];
$context = admin_upload_context($data, $payload);
expect_admin($context['chapter'] === 3 && $context['group_id'] === null, 'Solo document context');
reject_admin(fn() => admin_upload_context($data, array_replace($payload, ['student_id' => 'missing'])));
reject_admin(fn() => admin_upload_context($data, array_replace($payload, ['project_id' => 'missing'])));
reject_admin(fn() => admin_upload_context($data, array_replace($payload, ['student_id' => 'S2'])));
foreach ([0, 6, 'oops', '2.5'] as $chapter) reject_admin(fn() => admin_upload_context($data, array_replace($payload, ['chapter' => $chapter])));
$data['groups'] = [['id' => 'G1', 'project_id' => 'P1', 'member_ids' => ['S1', 'S2']]];
expect_admin(admin_upload_context($data, array_replace($payload, ['student_id' => 'S2']))['group_id'] === 'G1', 'Group ownership preserved');
$temp = tempnam(sys_get_temp_dir(), 'admin-pdf-test-');
try {
    file_put_contents($temp, "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\n%%EOF");
    expect_admin(admin_validate_pdf($temp, 'test.PDF') === filesize($temp), 'Actual PDF size');
    reject_admin(fn() => admin_validate_pdf($temp, 'test.txt'));
    file_put_contents($temp, '<html>not a PDF</html>');
    clearstatcache(true, $temp);
    reject_admin(fn() => admin_validate_pdf($temp, 'test.pdf'));
    $stream = fopen($temp, 'wb');
    ftruncate($stream, 20 * 1024 * 1024 + 1);
    fclose($stream);
    clearstatcache(true, $temp);
    reject_admin(fn() => admin_validate_pdf($temp, 'large.pdf'));
} finally { unlink($temp); }
echo "ADMIN_WORKFLOW_OK: dates, real history mapping, ownership, chapters and PDF validation; no application database used\n";
