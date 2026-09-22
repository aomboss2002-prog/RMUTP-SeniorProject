<?php
declare(strict_types=1);

function student_visible_messages(array $rows, string $studentId, ?array $group): array
{
    return array_values(array_filter($rows, static fn(array $row): bool => $group
        ? (($row['group_id'] ?? '') === ($group['id'] ?? ''))
        : (($row['student_id'] ?? '') === $studentId && empty($row['group_id']))
    ));
}

function student_visible_notifications(array $rows, string $studentId, string $groupId): array
{
    return array_values(array_filter($rows, static fn(array $row): bool =>
        ($row['student_id'] ?? '') === $studentId
        || ($groupId !== '' && ($row['group_id'] ?? '') === $groupId)
        || ($row['scope'] ?? '') === 'system'
    ));
}

/** Read selected legacy collections without transferring the whole runtime store. */
function runtime_read_collections(PDO $pdo, array $collections): array
{
    $allowed = ['groups', 'notifications', 'students', 'advisors', 'projects', 'documents', 'approvals', 'messages'];
    $select = [];
    foreach ($collections as $name) {
        if (!in_array($name, $allowed, true)) throw new InvalidArgumentException('Unsupported runtime collection');
        $select[] = "JSON_EXTRACT(state_json, '$.{$name}') AS {$name}";
    }
    if (!$select) return [];
    $row = $pdo->query('SELECT ' . implode(', ', $select) . " FROM app_state WHERE state_key='runtime' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    $result = [];
    foreach ($collections as $name) {
        $decoded = json_decode((string) ($row[$name] ?? 'null'), true);
        $result[$name] = is_array($decoded) ? $decoded : [];
    }
    return $result;
}
