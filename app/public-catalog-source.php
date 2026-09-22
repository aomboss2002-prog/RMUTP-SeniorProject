<?php
declare(strict_types=1);

function public_catalog_source(PDO $pdo): array
{
    // Retain legacy group/author order and academic-year semantics without
    // loading messages, approvals, notifications, or the entire runtime store.
    $row = $pdo->query("SELECT JSON_EXTRACT(state_json, '$.students') AS students,
        JSON_EXTRACT(state_json, '$.projects') AS projects,
        JSON_EXTRACT(state_json, '$.groups') AS groups,
        JSON_EXTRACT(state_json, '$.settings') AS settings
        FROM app_state WHERE state_key='runtime' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    $data = [];
    foreach (['students', 'projects', 'groups', 'settings'] as $collection) {
        $decoded = json_decode((string) ($row[$collection] ?? 'null'), true);
        $data[$collection] = is_array($decoded) ? $decoded : [];
    }
    $data['documents'] = $pdo->query("SELECT id, project_id, student_id, group_id, type, status, filename, title, approved_at, uploaded_at
        FROM documents WHERE LOWER(TRIM(type))='complete' AND status IN ('Approved','Completed')")->fetchAll(PDO::FETCH_ASSOC);
    return $data;
}
