<?php
declare(strict_types=1);

/** Build a bounded list query. Column names are never taken from user input. */
function student_list_query(array $input): array
{
    $scalar = static fn($value): string => is_scalar($value) ? (string) $value : '';
    $start = max(0, (int) $scalar($input['start'] ?? 0));
    $length = max(1, min(50, (int) $scalar($input['length'] ?? 25)));
    $search = trim(mb_substr($scalar($input['search_text'] ?? ''), 0, 160));
    $status = trim(mb_substr($scalar($input['status'] ?? ''), 0, 40));
    $columns = ['s.code', 's.first_name', 's.major', 'a.name', 's.status'];
    $column = $columns[(int) $scalar($input['sort_column'] ?? 0)] ?? 's.code';
    $direction = strtolower($scalar($input['sort_direction'] ?? 'asc')) === 'desc' ? 'DESC' : 'ASC';
    $conditions = [];
    $parameters = [];
    if ($status !== '') {
        $conditions[] = 's.status = ?';
        $parameters[] = $status;
    }
    if ($search !== '') {
        // Treat %, _ and the escape character as literal search text.
        $pattern = '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $search) . '%';
        $conditions[] = "(s.code LIKE ? ESCAPE '!' OR CONCAT(s.first_name, ' ', s.last_name) LIKE ? ESCAPE '!' OR s.email LIKE ? ESCAPE '!' OR s.major LIKE ? ESCAPE '!' OR a.name LIKE ? ESCAPE '!')";
        array_push($parameters, $pattern, $pattern, $pattern, $pattern, $pattern);
    }
    $predicate = $conditions ? implode(' AND ', $conditions) : '1=1';
    $from = ' FROM students s LEFT JOIN advisors a ON a.id = s.advisor_id';
    return [
        'draw' => max(0, (int) $scalar($input['draw'] ?? 0)),
        'parameters' => $parameters,
        'count_sql' => $conditions
            ? "SELECT COUNT(*) AS total, COALESCE(SUM({$predicate}), 0) AS filtered{$from}"
            : 'SELECT COUNT(*) AS total, COUNT(*) AS filtered FROM students',
        'rows_sql' => "SELECT s.id, s.code, s.first_name, s.last_name, s.email, s.major, s.status, COALESCE(NULLIF(a.name, ''), s.advisor_id, '') AS advisor_name{$from} WHERE {$predicate} ORDER BY {$column} {$direction}, s.id ASC LIMIT {$length} OFFSET {$start}",
    ];
}

function student_list_page(PDO $pdo, array $input): array
{
    $query = student_list_query($input);
    $count = $pdo->prepare($query['count_sql']);
    $count->execute($query['parameters']);
    $totals = $count->fetch(PDO::FETCH_ASSOC);
    $rows = $pdo->prepare($query['rows_sql']);
    $rows->execute($query['parameters']);
    return ['success' => true, 'draw' => $query['draw'], 'recordsTotal' => (int) $totals['total'],
        'recordsFiltered' => (int) $totals['filtered'], 'data' => $rows->fetchAll(PDO::FETCH_ASSOC)];
}
