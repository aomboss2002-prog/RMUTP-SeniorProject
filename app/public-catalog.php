<?php
declare(strict_types=1);

require_once __DIR__ . '/storage.php';

function public_is_complete_document(array $document): bool
{
    return strtolower(trim((string) ($document['type'] ?? ''))) === 'complete'
        && in_array((string) ($document['status'] ?? ''), ['Approved', 'Completed'], true);
}

function public_catalog_text(string $value): string
{
    $value = trim($value);
    return function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
}

function public_catalog_academic_year(array $data): string
{
    $value = trim((string) (($data['settings']['academic_year'] ?? '') ?: date('Y')));
    if (ctype_digit($value)) {
        $year = (int) $value;
        if ($year >= 1900 && $year < 2400) {
            return (string) ($year + 543);
        }
    }
    return $value;
}

function public_catalog_file_available(array $document): bool
{
    $filename = basename((string) ($document['filename'] ?? ''));
    if ($filename === '') return false;
    return storage_exists('complete', $filename);
}

function public_catalog_authors(array $document, array $project, array $groupsById, array $groupsByProject, array $studentsById): array
{
    $group = null;
    $groupId = (string) ($document['group_id'] ?? '');
    $projectId = (string) ($project['id'] ?? '');
    if ($groupId !== '' && isset($groupsById[$groupId])) {
        $group = $groupsById[$groupId];
    } elseif ($projectId !== '' && isset($groupsByProject[$projectId])) {
        $group = $groupsByProject[$projectId];
    }

    $studentIds = $group
        ? array_values(array_unique(array_map('strval', $group['member_ids'] ?? [])))
        : [(string) (($document['student_id'] ?? '') ?: ($project['student_id'] ?? ''))];

    $authors = [];
    foreach ($studentIds as $studentId) {
        $student = $studentsById[$studentId] ?? null;
        if (!$student) continue;
        $name = trim((string) ($student['first_name'] ?? '') . ' ' . (string) ($student['last_name'] ?? ''));
        if ($name !== '') $authors[] = $name;
    }
    return array_values(array_unique($authors));
}

function public_completed_catalog(array $data, array $query = []): array
{
    $studentsById = [];
    foreach ($data['students'] ?? [] as $student) {
        if (!empty($student['id'])) $studentsById[(string) $student['id']] = $student;
    }
    $projectsById = [];
    foreach ($data['projects'] ?? [] as $project) {
        if (!empty($project['id'])) $projectsById[(string) $project['id']] = $project;
    }
    $groupsById = [];
    $groupsByProject = [];
    foreach ($data['groups'] ?? [] as $group) {
        if (!empty($group['id'])) $groupsById[(string) $group['id']] = $group;
        if (!empty($group['project_id'])) $groupsByProject[(string) $group['project_id']] = $group;
    }

    // Security boundary: remove every non-complete document before search,
    // filters, pagination, or response mapping occurs.
    $eligibleDocuments = array_values(array_filter(
        $data['documents'] ?? [],
        static fn(array $document): bool => public_is_complete_document($document)
    ));
    usort($eligibleDocuments, static function (array $left, array $right): int {
        $leftDate = (string) (($left['approved_at'] ?? '') ?: ($left['uploaded_at'] ?? ''));
        $rightDate = (string) (($right['approved_at'] ?? '') ?: ($right['uploaded_at'] ?? ''));
        return strcmp($rightDate, $leftDate);
    });

    // Publish only the latest accepted Complete version for each project.
    $latestByProject = [];
    foreach ($eligibleDocuments as $document) {
        $projectId = (string) ($document['project_id'] ?? '');
        $key = $projectId !== '' ? $projectId : 'document:' . (string) ($document['id'] ?? '');
        if (!isset($latestByProject[$key])) $latestByProject[$key] = $document;
    }

    $academicYear = public_catalog_academic_year($data);
    $items = [];
    foreach ($latestByProject as $document) {
        $projectId = (string) ($document['project_id'] ?? '');
        $project = $projectsById[$projectId] ?? null;
        if (!$project || empty($document['id'])) continue;

        $authors = public_catalog_authors($document, $project, $groupsById, $groupsByProject, $studentsById);
        $ownerId = (string) (($document['student_id'] ?? '') ?: ($project['student_id'] ?? ''));
        $owner = $studentsById[$ownerId] ?? [];
        $group = $groupsById[(string) ($document['group_id'] ?? '')] ?? ($groupsByProject[$projectId] ?? []);
        $leader = $studentsById[(string) ($group['leader_id'] ?? '')] ?? $owner;
        $faculty = trim((string) (($group['faculty'] ?? '') ?: ($leader['faculty'] ?? $owner['faculty'] ?? '')));
        $major = trim((string) (($leader['major'] ?? '') ?: ($owner['major'] ?? '')));
        $category = trim((string) ($project['category'] ?? ''));
        $available = public_catalog_file_available($document);

        $items[] = [
            'document_id' => (string) $document['id'],
            'title' => trim((string) (($project['title'] ?? '') ?: ($document['title'] ?? ''))),
            'authors' => $authors,
            'code' => trim((string) ($project['code'] ?? '')),
            'academic_year' => $academicYear,
            'faculty' => $faculty,
            'major' => $major,
            'category' => $category,
            'status' => 'Completed',
            'completed_at' => (string) (($document['approved_at'] ?? '') ?: ($document['uploaded_at'] ?? $project['updated_at'] ?? '')),
            'available' => $available,
            'download_url' => $available
                ? rtrim(app_base_url(), '/') . '/api/file.php?' . http_build_query(['id' => (string) $document['id'], 'mode' => 'public-download'])
                : '',
        ];
    }

    $filterValues = static function (array $rows, string $field): array {
        $values = array_values(array_unique(array_filter(array_map(
            static fn(array $row): string => trim((string) ($row[$field] ?? '')),
            $rows
        ), static fn(string $value): bool => $value !== '')));
        natcasesort($values);
        return array_values($values);
    };
    $filters = [
        'years' => $filterValues($items, 'academic_year'),
        'faculties' => $filterValues($items, 'faculty'),
        'majors' => $filterValues($items, 'major'),
        'categories' => $filterValues($items, 'category'),
    ];

    $search = public_catalog_text((string) ($query['q'] ?? ''));
    $year = trim((string) ($query['year'] ?? ''));
    $faculty = trim((string) ($query['faculty'] ?? ''));
    $major = trim((string) ($query['major'] ?? ''));
    $category = trim((string) ($query['category'] ?? ''));
    $items = array_values(array_filter($items, static function (array $item) use ($search, $year, $faculty, $major, $category): bool {
        if ($year !== '' && (string) $item['academic_year'] !== $year) return false;
        if ($faculty !== '' && (string) $item['faculty'] !== $faculty) return false;
        if ($major !== '' && (string) $item['major'] !== $major) return false;
        if ($category !== '' && (string) $item['category'] !== $category) return false;
        if ($search === '') return true;
        $haystack = public_catalog_text(implode(' ', [
            (string) $item['title'],
            implode(' ', $item['authors']),
            (string) $item['code'],
            (string) $item['faculty'],
            (string) $item['major'],
            (string) $item['category'],
        ]));
        return str_contains($haystack, $search);
    }));

    // Keep the public archive deterministic: the most recently approved
    // Complete document is always shown first, including after filtering.
    usort($items, static function (array $left, array $right): int {
        $dateOrder = strcmp((string) ($right['completed_at'] ?? ''), (string) ($left['completed_at'] ?? ''));
        if ($dateOrder !== 0) return $dateOrder;
        return strcmp((string) ($right['document_id'] ?? ''), (string) ($left['document_id'] ?? ''));
    });

    $pageSize = 5;
    $total = count($items);
    $totalPages = max(1, (int) ceil($total / $pageSize));
    $page = max(1, min((int) ($query['page'] ?? 1), $totalPages));

    return [
        'items' => array_slice($items, ($page - 1) * $pageSize, $pageSize),
        'filters' => $filters,
        'pagination' => [
            'page' => $page,
            'page_size' => $pageSize,
            'total' => $total,
            'total_pages' => $totalPages,
        ],
    ];
}
