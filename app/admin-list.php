<?php
declare(strict_types=1);

/** Admin-only caller; fixed SQL identifiers, parameterized values, bounded rows. */
function admin_list_page(PDO $pdo, string $resource, array $input): array
{
    $scalar = static fn($value): string => is_scalar($value) ? (string) $value : '';
    $start = max(0, (int) $scalar($input['start'] ?? 0));
    $length = max(1, min(50, (int) $scalar($input['length'] ?? 25)));
    $search = trim(mb_substr($scalar($input['search_text'] ?? ''), 0, 160));
    $status = trim(mb_substr($scalar($input['status'] ?? ''), 0, 40));
    $type = $scalar($input['type'] ?? '');
    $scope = '1=1';
    $scopeValues = [];
    if ($resource === 'projects') {
        $from = ' FROM projects p LEFT JOIN students s ON s.id=p.student_id LEFT JOIN advisors a ON a.id=p.advisor_id';
        $select = "p.id, p.code, p.title, p.category, p.progress, p.status,
            TRIM(CONCAT(COALESCE(s.first_name,''), ' ', COALESCE(s.last_name,''))) AS student_name,
            COALESCE(a.name,'') AS advisor_name,
            (SELECT d.status FROM documents d WHERE d.project_id=p.id AND LOWER(d.type)='complete'
             ORDER BY d.uploaded_at DESC, d.id DESC LIMIT 1) AS complete_status";
        $columns = ['p.code', 'p.title', 'student_name', 'a.name', 'p.progress', 'p.status'];
        $searchColumns = ['p.code', 'p.title', 'p.category', "CONCAT(COALESCE(s.first_name,''),' ',COALESCE(s.last_name,''))", 'a.name', 'p.status'];
        $statusColumn = 'p.status';
        $idColumn = 'p.id';
    } elseif ($resource === 'documents') {
        $from = ' FROM documents d LEFT JOIN projects p ON p.id=d.project_id LEFT JOIN students s ON s.id=d.student_id';
        $select = "d.id, d.title, d.filename, d.type, d.size, d.status, d.uploaded_at,
            COALESCE(NULLIF(p.title,''), d.project_id, '') AS project_title,
            COALESCE(NULLIF(TRIM(CONCAT(COALESCE(s.first_name,''),' ',COALESCE(s.last_name,''))),''),d.student_id,'') AS student_name";
        $columns = $type !== '' ? ['d.title', 'student_name', 'd.size', 'd.status', 'd.uploaded_at']
            : ['d.title', 'd.type', 'p.title', 'd.size', 'd.status', 'd.uploaded_at'];
        $searchColumns = ['d.title', 'd.filename', 'd.type', 'p.title', "CONCAT(COALESCE(s.first_name,''),' ',COALESCE(s.last_name,''))", 'd.status', 'd.size', 'd.uploaded_at'];
        $statusColumn = 'd.status';
        $idColumn = 'd.id';
        if ($type !== '') {
            $scope = 'd.type = ?';
            $scopeValues[] = $type;
        }
    } else {
        throw new InvalidArgumentException('Unsupported list resource');
    }
    $conditions = [];
    $values = [];
    if ($status !== '') { $conditions[] = "{$statusColumn} = ?"; $values[] = $status; }
    if ($search !== '') {
        $pattern = '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $search) . '%';
        $conditions[] = '(' . implode(' OR ', array_map(static fn($column) => "{$column} LIKE ? ESCAPE '!'", $searchColumns)) . ')';
        array_push($values, ...array_fill(0, count($searchColumns), $pattern));
    }
    $filter = $conditions ? implode(' AND ', $conditions) : '1=1';
    $count = $pdo->prepare("SELECT COUNT(*) AS total, COALESCE(SUM({$filter}),0) AS filtered{$from} WHERE {$scope}");
    $count->execute(array_merge($values, $scopeValues));
    $totals = $count->fetch(PDO::FETCH_ASSOC);
    $column = $columns[(int) $scalar($input['sort_column'] ?? 0)] ?? $columns[0];
    $direction = strtolower($scalar($input['sort_direction'] ?? 'asc')) === 'desc' ? 'DESC' : 'ASC';
    $statement = $pdo->prepare("SELECT {$select}{$from} WHERE {$scope} AND {$filter} ORDER BY {$column} {$direction}, {$idColumn} ASC LIMIT {$length} OFFSET {$start}");
    $statement->execute(array_merge($scopeValues, $values));
    $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
    if ($resource === 'projects') {
        foreach ($rows as &$row) {
            $row['progress'] = (int) $row['progress']; // Persisted by save_data's workflow calculation.
            $row['complete_approved'] = in_array($row['complete_status'], ['Approved', 'Completed'], true);
            unset($row['complete_status']);
        }
        unset($row);
    }
    $response = ['success' => true, 'draw' => max(0, (int) $scalar($input['draw'] ?? 0)),
        'recordsTotal' => (int) $totals['total'], 'recordsFiltered' => (int) $totals['filtered'], 'data' => $rows];
    if ($resource === 'documents' && $type === '') {
        $response['counts'] = $pdo->query('SELECT type, COUNT(*) FROM documents GROUP BY type')->fetchAll(PDO::FETCH_KEY_PAIR);
    }
    return $response;
}
