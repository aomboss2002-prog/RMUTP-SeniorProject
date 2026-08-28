<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/helpers.php';
require_once __DIR__ . '/../app/public-catalog.php';

$errors = [];
$fixture = [
    'settings' => ['academic_year' => '2026'],
    'students' => [
        ['id' => 'STU-A', 'first_name' => 'Ada', 'last_name' => 'Lovelace', 'faculty' => 'คณะบริหารธุรกิจ', 'major' => 'Data'],
        ['id' => 'STU-B', 'first_name' => 'Grace', 'last_name' => 'Hopper', 'faculty' => 'คณะบริหารธุรกิจ', 'major' => 'Data'],
    ],
    'projects' => [
        ['id' => 'PRJ-C', 'student_id' => 'STU-A', 'code' => 'SP-2026-001', 'title' => 'Complete AI Research', 'category' => 'Research'],
        ['id' => 'PRJ-P', 'student_id' => 'STU-B', 'code' => 'SP-2026-002', 'title' => 'Pending AI Research', 'category' => 'Research'],
        ['id' => 'PRJ-M', 'student_id' => 'STU-B', 'code' => 'SP-2026-003', 'title' => 'Complete Missing File', 'category' => 'Thesis'],
    ],
    'groups' => [
        ['id' => 'GRP-C', 'project_id' => 'PRJ-C', 'leader_id' => 'STU-A', 'member_ids' => ['STU-A'], 'faculty' => 'คณะบริหารธุรกิจ'],
    ],
    'documents' => [
        ['id' => 'DOC-C', 'project_id' => 'PRJ-C', 'student_id' => 'STU-A', 'group_id' => 'GRP-C', 'type' => 'complete', 'status' => 'Approved', 'filename' => 'complete-prj005.pdf', 'approved_at' => '2026-08-20 10:00:00'],
        ['id' => 'DOC-D', 'project_id' => 'PRJ-C', 'student_id' => 'STU-A', 'type' => 'draft', 'status' => 'Approved', 'filename' => 'draft.pdf'],
        ['id' => 'DOC-P', 'project_id' => 'PRJ-P', 'student_id' => 'STU-B', 'type' => 'complete', 'status' => 'Pending', 'filename' => 'pending.pdf'],
        ['id' => 'DOC-M', 'project_id' => 'PRJ-M', 'student_id' => 'STU-B', 'type' => 'complete', 'status' => 'Completed', 'filename' => 'missing-file.pdf', 'approved_at' => '2026-08-21 10:00:00'],
    ],
];

// Add enough completed projects to verify newest-first ordering and
// five-items-per-page pagination.
for ($number = 4; $number <= 8; $number++) {
    $fixture['projects'][] = [
        'id' => 'PRJ-' . $number,
        'student_id' => 'STU-B',
        'code' => 'SP-2026-00' . $number,
        'title' => 'Archive Project ' . $number,
        'category' => 'Archive',
    ];
    $fixture['documents'][] = [
        'id' => 'DOC-' . $number,
        'project_id' => 'PRJ-' . $number,
        'student_id' => 'STU-B',
        'type' => 'complete',
        'status' => 'Approved',
        'filename' => 'missing-' . $number . '.pdf',
        'approved_at' => '2026-08-' . (18 + $number) . ' 10:00:00',
    ];
}

$catalog = public_completed_catalog($fixture);
$ids = array_column($catalog['items'], 'document_id');
if ($catalog['pagination']['total'] !== 7) $errors[] = 'Only accepted Complete documents must be listed.';
if ($catalog['pagination']['page_size'] !== 5 || count($catalog['items']) !== 5) $errors[] = 'Public catalog must show five items per page.';
if ($ids !== ['DOC-8', 'DOC-7', 'DOC-6', 'DOC-5', 'DOC-4']) $errors[] = 'Completed documents are not sorted newest first.';
$secondPage = public_completed_catalog($fixture, ['page' => 2]);
if ($secondPage['pagination']['page'] !== 2 || array_column($secondPage['items'], 'document_id') !== ['DOC-M', 'DOC-C']) {
    $errors[] = 'Public catalog next page is incorrect.';
}
$allPublishedItems = array_merge($catalog['items'], $secondPage['items']);
$allPublishedIds = array_column($allPublishedItems, 'document_id');
if (in_array('DOC-D', $allPublishedIds, true)) $errors[] = 'Draft leaked into the public catalog.';
if (in_array('DOC-P', $allPublishedIds, true)) $errors[] = 'Pending Complete document leaked into the public catalog.';
if (!in_array('DOC-C', $allPublishedIds, true) || !in_array('DOC-M', $allPublishedIds, true)) $errors[] = 'Accepted Complete document is missing.';

$available = null;
$missing = null;
foreach ($allPublishedItems as $item) {
    if ($item['document_id'] === 'DOC-C') $available = $item;
    if ($item['document_id'] === 'DOC-M') $missing = $item;
}
if (!$available || !$available['available'] || $available['download_url'] === '') $errors[] = 'Available Complete PDF has no secure download URL.';
if (!$missing || $missing['available'] || $missing['download_url'] !== '') $errors[] = 'Missing PDF should be listed without a download URL.';

$search = public_completed_catalog($fixture, ['q' => 'Ada']);
if ($search['pagination']['total'] !== 1 || ($search['items'][0]['document_id'] ?? '') !== 'DOC-C') $errors[] = 'Author search failed.';
$pendingSearch = public_completed_catalog($fixture, ['q' => 'Pending AI']);
if ($pendingSearch['pagination']['total'] !== 0) $errors[] = 'Search returned a non-complete project.';
$filtered = public_completed_catalog($fixture, ['category' => 'Thesis']);
if ($filtered['pagination']['total'] !== 1 || ($filtered['items'][0]['document_id'] ?? '') !== 'DOC-M') $errors[] = 'Complete-only category filter failed.';

if (public_is_complete_document($fixture['documents'][1])) $errors[] = 'Draft passed the download eligibility check.';
if (public_is_complete_document($fixture['documents'][2])) $errors[] = 'Pending document passed the download eligibility check.';
if (!public_is_complete_document($fixture['documents'][0])) $errors[] = 'Approved Complete failed the download eligibility check.';

if ($errors) {
    fwrite(STDERR, implode(PHP_EOL, $errors) . PHP_EOL);
    exit(1);
}

echo "PUBLIC_CATALOG_OK\n";
