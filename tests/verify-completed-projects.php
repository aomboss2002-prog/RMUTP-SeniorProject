<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/store.php';
require_once __DIR__ . '/../app/helpers.php';
require_once __DIR__ . '/../app/public-catalog.php';

$errors = [];
$data = load_data();
$testProjects = array_values(array_filter(
    $data['projects'] ?? [],
    static fn(array $row): bool => str_starts_with((string) ($row['id'] ?? ''), 'TESTPRJ')
));
$testStudents = array_values(array_filter(
    $data['students'] ?? [],
    static fn(array $row): bool => str_starts_with((string) ($row['id'] ?? ''), 'TESTSTU')
));
$testDocuments = array_values(array_filter(
    $data['documents'] ?? [],
    static fn(array $row): bool => str_starts_with((string) ($row['id'] ?? ''), 'TDC')
));
$testApprovals = array_values(array_filter(
    $data['approvals'] ?? [],
    static fn(array $row): bool => str_starts_with((string) ($row['id'] ?? ''), 'TAP')
));

if (count($testStudents) !== 20) $errors[] = 'Expected 20 test students, found ' . count($testStudents);
if (count($testProjects) !== 20) $errors[] = 'Expected 20 test projects, found ' . count($testProjects);
if (count($testDocuments) !== 140) $errors[] = 'Expected 140 test documents, found ' . count($testDocuments);
if (count($testApprovals) !== 140) $errors[] = 'Expected 140 test approvals, found ' . count($testApprovals);

$studentsById = array_column($testStudents, null, 'id');
foreach ($testProjects as $project) {
    $projectId = (string) ($project['id'] ?? '');
    $studentId = (string) ($project['student_id'] ?? '');
    $documents = array_values(array_filter(
        $testDocuments,
        static fn(array $row): bool => (string) ($row['project_id'] ?? '') === $projectId
    ));
    $draftChapters = array_map(
        static fn(array $row): int => (int) ($row['chapter'] ?? 0),
        array_filter($documents, static fn(array $row): bool => ($row['type'] ?? '') === 'draft')
    );
    sort($draftChapters);
    $complete = array_values(array_filter($documents, static fn(array $row): bool => ($row['type'] ?? '') === 'complete'));

    if (!isset($studentsById[$studentId])) $errors[] = $projectId . ': missing student';
    if (count($documents) !== 7) $errors[] = $projectId . ': expected 7 documents';
    if ($draftChapters !== [1, 2, 3, 4, 5]) $errors[] = $projectId . ': draft chapters are incomplete';
    if (count($complete) !== 1 || !in_array((string) ($complete[0]['status'] ?? ''), ['Approved', 'Completed'], true)) {
        $errors[] = $projectId . ': Complete document is not approved';
    }
    if (calculated_project_progress($documents) !== 100) $errors[] = $projectId . ': calculated progress is not 100';
    if (empty($project['code'])) $errors[] = $projectId . ': project code is missing';
}

$catalogFirst = public_completed_catalog($data, ['page' => 1]);
$catalogLast = public_completed_catalog($data, ['page' => 5]);
$testCatalogItems = [];
for ($page = 1; $page <= (int) $catalogFirst['pagination']['total_pages']; $page++) {
    foreach (public_completed_catalog($data, ['page' => $page])['items'] as $item) {
        if (str_starts_with((string) ($item['code'] ?? ''), 'TEST-2026-')) $testCatalogItems[] = $item;
    }
}

if ((int) $catalogFirst['pagination']['page_size'] !== 5) $errors[] = 'Public catalog page size is not 5';
if (count($catalogFirst['items']) !== 5) $errors[] = 'Public catalog first page does not contain 5 items';
if (count($testCatalogItems) !== 20) $errors[] = 'Expected 20 public test projects, found ' . count($testCatalogItems);
if (array_filter($testCatalogItems, static fn(array $item): bool => empty($item['available']))) {
    $errors[] = 'At least one Complete PDF is unavailable for download';
}

$testDates = array_column($testCatalogItems, 'completed_at');
$sortedDates = $testDates;
rsort($sortedDates);
if ($testDates !== $sortedDates) $errors[] = 'Public test projects are not sorted newest first';

$pdo = database_connection();
$relationalChecks = $pdo->query(
    "SELECT
        (SELECT COUNT(*) FROM documents WHERE id LIKE 'TDC%' AND group_id LIKE 'TESTGRP%') AS grouped_documents,
        (SELECT COUNT(*) FROM approvals WHERE id LIKE 'TAP%' AND group_id LIKE 'TESTGRP%') AS grouped_approvals,
        (SELECT COUNT(*) FROM project_groups WHERE id LIKE 'TESTGRP%') AS project_groups,
        (SELECT COUNT(*) FROM project_group_members WHERE group_id LIKE 'TESTGRP%') AS group_members"
)->fetch();
if ((int) $relationalChecks['grouped_documents'] !== 140) $errors[] = 'Relational documents are not linked to test groups';
if ((int) $relationalChecks['grouped_approvals'] !== 140) $errors[] = 'Relational approvals are not linked to test groups';
if ((int) $relationalChecks['project_groups'] !== 20) $errors[] = 'Expected 20 relational test groups';
if ((int) $relationalChecks['group_members'] !== 20) $errors[] = 'Expected 20 relational test group members';

if ($errors) {
    fwrite(STDERR, implode(PHP_EOL, $errors) . PHP_EOL);
    exit(1);
}

echo 'TEST_COMPLETED_PROJECTS_VERIFIED ' . json_encode([
    'students' => count($testStudents),
    'projects' => count($testProjects),
    'documents' => count($testDocuments),
    'approvals' => count($testApprovals),
    'catalog_total' => $catalogFirst['pagination']['total'],
    'catalog_pages' => $catalogFirst['pagination']['total_pages'],
    'first_code' => $catalogFirst['items'][0]['code'] ?? '',
    'last_page_count' => count($catalogLast['items']),
    'relational_groups' => (int) $relationalChecks['project_groups'],
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
