<?php
declare(strict_types=1);

function admin_dashboard_payload(PDO $pdo): array
{
    $result = [
        'summary' => ['students' => 0, 'advisors' => 0, 'projects' => 0, 'pending' => 0],
        'project_status' => [], 'uploads' => [],
        'risk_overview' => ['total' => 0, 'latest_calculated_at' => null,
            'counts' => ['low' => 0, 'watch' => 0, 'high' => 0, 'critical' => 0]],
        'activities' => [], 'files' => [], 'notifications' => [], 'approvals' => [],
    ];
    // One round trip for all mandatory aggregates; no user rows are transferred.
    $rows = $pdo->query("SELECT 'summary' AS section, 'students' AS label, COUNT(*) AS total FROM students
        UNION ALL SELECT 'summary', 'advisors', COUNT(*) FROM advisors
        UNION ALL SELECT 'project_status', status, COUNT(*) FROM projects GROUP BY status
        UNION ALL SELECT 'uploads', type, COUNT(*) FROM documents GROUP BY type")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $row) {
        $result[$row['section']][$row['label']] = (int) $row['total'];
    }
    $result['summary']['projects'] = array_sum($result['project_status']);
    $result['summary']['pending'] = $result['project_status']['Pending'] ?? 0;

    // This optional table may not exist before its migration is installed.
    try {
        $risks = $pdo->query('SELECT LOWER(risk_level) AS risk_level, COUNT(*) AS total, MAX(calculated_at) AS latest_calculated_at FROM project_risk_scores GROUP BY LOWER(risk_level)')->fetchAll(PDO::FETCH_ASSOC);
        foreach ($risks as $risk) {
            if (!array_key_exists($risk['risk_level'], $result['risk_overview']['counts'])) continue;
            $result['risk_overview']['counts'][$risk['risk_level']] = (int) $risk['total'];
            $result['risk_overview']['total'] += (int) $risk['total'];
            $latest = $risk['latest_calculated_at'];
            if ($latest && (!$result['risk_overview']['latest_calculated_at'] || $latest > $result['risk_overview']['latest_calculated_at'])) {
                $result['risk_overview']['latest_calculated_at'] = $latest;
            }
        }
    } catch (PDOException $error) {
        // Match the existing optional risk widget behavior, not a fallback for core data.
        error_log('[DASHBOARD] Optional risk summary unavailable');
    }

    $result['files'] = $pdo->query('SELECT title, type, status FROM documents ORDER BY uploaded_at DESC, id DESC LIMIT 5')->fetchAll(PDO::FETCH_ASSOC);

    // These collections still have their authoritative writes in runtime JSON.
    // Extract only the existing first five entries, preserving their stored order.
    $fields = ['activities' => ['title', 'actor', 'created_at'],
        'notifications' => ['title', 'message'], 'approvals' => ['step', 'reviewer', 'status', 'created_at']];
    $expressions = [];
    foreach ($fields as $collection => $_) {
        $paths = [];
        for ($i = 0; $i < 5; $i++) $paths[] = "'$.{$collection}[{$i}]'";
        $expressions[] = 'JSON_EXTRACT(state_json, ' . implode(', ', $paths) . ") AS {$collection}";
    }
    $recent = $pdo->query('SELECT ' . implode(', ', $expressions) . " FROM app_state WHERE state_key = 'runtime' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    foreach ($fields as $collection => $allowed) {
        $items = json_decode((string) ($recent[$collection] ?? 'null'), true);
        foreach (is_array($items) ? $items : [] as $item) {
            if (is_array($item)) $result[$collection][] = array_intersect_key($item, array_flip($allowed));
        }
    }
    return $result;
}
