<?php
declare(strict_types=1);

function public_catalog_source(PDO $pdo): array
{
    // Retain legacy group/author order and academic-year semantics without
    // loading messages, approvals, notifications, or the entire runtime store.
    $stateRows = $pdo->query("SELECT state_key, state_json FROM app_state WHERE state_key IN ('runtime', 'advisor_portal')")->fetchAll(PDO::FETCH_KEY_PAIR);
    $runtime = json_decode((string) ($stateRows['runtime'] ?? ''), true);
    $advisorPortal = json_decode((string) ($stateRows['advisor_portal'] ?? ''), true);
    $runtime = is_array($runtime) ? $runtime : [];
    $advisorPortal = is_array($advisorPortal) ? $advisorPortal : [];
    $data = [];
    foreach (['students', 'projects', 'groups', 'settings', 'documents'] as $collection) {
        if ($collection === 'settings') {
            $data[$collection] = is_array($runtime[$collection] ?? null)
                ? $runtime[$collection]
                : (is_array($advisorPortal[$collection] ?? null) ? $advisorPortal[$collection] : []);
            continue;
        }
        $data[$collection] = [];
        foreach (is_array($runtime[$collection] ?? null) ? $runtime[$collection] : [] as $row) {
            if (!empty($row['id'])) {
                $data[$collection][(string) $row['id']] = $row;
            }
        }
        foreach (is_array($advisorPortal[$collection] ?? null) ? $advisorPortal[$collection] : [] as $row) {
            if (!empty($row['id'])) {
                $data[$collection][(string) $row['id']] = $row;
            }
        }
        $data[$collection] = array_values($data[$collection]);
    }
    $databaseDocuments = $pdo->query("SELECT id, project_id, student_id, group_id, type, status, filename, title, approved_at, uploaded_at
        FROM documents WHERE LOWER(TRIM(type))='complete' AND LOWER(TRIM(status)) IN ('approved','completed')")->fetchAll(PDO::FETCH_ASSOC);
    $documentsById = [];
    foreach ($data['documents'] as $document) {
        if (!empty($document['id'])) {
            $documentsById[(string) $document['id']] = $document;
        }
    }
    foreach ($databaseDocuments as $document) {
        $documentId = (string) ($document['id'] ?? '');
        if ($documentId === '') continue;
        $documentsById[$documentId] = array_merge($documentsById[$documentId] ?? [], $document);
    }
    $data['documents'] = array_values($documentsById);

    $databaseStudents = [];
    $databaseProjects = [];
    $databaseGroups = [];
    $groupMembers = [];
    try {
        $databaseStudents = $pdo->query('SELECT id, code, first_name, last_name, faculty, major, project_id FROM students')->fetchAll(PDO::FETCH_ASSOC);
        $databaseProjects = $pdo->query('SELECT id, code, title, student_id, category, status, updated_at FROM projects')->fetchAll(PDO::FETCH_ASSOC);
        $databaseGroups = $pdo->query('SELECT id, name, leader_id, project_id, faculty, created_at FROM project_groups')->fetchAll(PDO::FETCH_ASSOC);
        $groupMembers = $pdo->query('SELECT group_id, student_id FROM project_group_members ORDER BY group_id, joined_at, student_id')->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $error) {
        error_log('[PUBLIC CATALOG] Optional relational catalog query failed: ' . $error->getMessage());
    }
    $membersByGroup = [];
    foreach ($groupMembers as $member) {
        $membersByGroup[(string) $member['group_id']][] = (string) $member['student_id'];
    }
    foreach ($databaseGroups as &$group) {
        $group['member_ids'] = $membersByGroup[(string) $group['id']] ?? [(string) $group['leader_id']];
    }
    unset($group);
    foreach (['students' => $databaseStudents, 'projects' => $databaseProjects, 'groups' => $databaseGroups] as $collection => $rows) {
        $rowsById = [];
        foreach ($data[$collection] as $row) {
            if (!empty($row['id'])) $rowsById[(string) $row['id']] = $row;
        }
        foreach ($rows as $row) {
            if (!empty($row['id'])) $rowsById[(string) $row['id']] = array_merge($rowsById[(string) $row['id']] ?? [], $row);
        }
        $data[$collection] = array_values($rowsById);
    }
    return $data;
}
