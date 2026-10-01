<?php
declare(strict_types=1);

/** Read-only, bounded snapshot for the admin live monitor. No runtime store scan. */
function website_monitor_snapshot(PDO $pdo): array
{
    $summary = ['students' => 0, 'advisors' => 0, 'projects' => 0, 'documents' => 0, 'awaiting_review' => 0, 'completed_projects' => 0];
    $statuses = [];
    $counts = $pdo->query("SELECT 'summary' AS section, 'students' AS label, COUNT(*) AS total FROM students
        UNION ALL SELECT 'summary', 'advisors', COUNT(*) FROM advisors
        UNION ALL SELECT 'summary', 'documents', COUNT(*) FROM documents
        UNION ALL SELECT 'summary', 'awaiting_review', COUNT(*) FROM documents WHERE status = 'Review'
        UNION ALL SELECT 'projects', status, COUNT(*) FROM projects GROUP BY status")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($counts as $row) {
        if ($row['section'] === 'summary') $summary[$row['label']] = (int) $row['total'];
        else $statuses[(string) ($row['label'] ?? '')] = (int) $row['total'];
    }
    $summary['projects'] = array_sum($statuses);
    $summary['completed_projects'] = $statuses['Completed'] ?? 0;
    $documents = $pdo->query('SELECT id, title, type, chapter, status, uploaded_at FROM documents ORDER BY uploaded_at DESC, id DESC LIMIT 6')->fetchAll(PDO::FETCH_ASSOC);
    $activityAvailable = true;
    $activity = [];
    try {
        $activity = $pdo->query('SELECT id, event_type, stage, chapter, actor_name, occurred_at, current_progress
            FROM project_progress_history ORDER BY occurred_at DESC, id DESC LIMIT 6')->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException) {
        // Existing installations may not have tracking history. Do not fabricate events.
        $activityAvailable = false;
    }
    return ['available' => true, 'error_code' => null, 'summary' => $summary,
        'project_status' => (object) $statuses, 'recent_documents' => $documents,
        'activity_available' => $activityAvailable, 'recent_activity' => $activity];
}

function website_monitor_unavailable(): array
{
    return ['available' => false, 'error_code' => 'WEBSITE_DATA_UNAVAILABLE', 'summary' => null,
        'project_status' => (object) [], 'recent_documents' => [], 'activity_available' => false, 'recent_activity' => []];
}
