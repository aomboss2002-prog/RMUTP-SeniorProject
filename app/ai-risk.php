<?php
declare(strict_types=1);

/**
 * Explainable, deadline-free project delay risk scoring.
 *
 * The score intentionally uses observable workflow signals instead of asking
 * a language model to invent a probability. Every point is stored with a
 * human-readable reason so advisors can audit the result.
 */

function latest_project_risk_score(string $projectId): ?array
{
    if ($projectId === '') return null;
    if (ai_web_processing_enabled()
        && filter_var(ai_title_config_value('AI_RISK_ENABLED', 'true'), FILTER_VALIDATE_BOOLEAN)) {
        try {
            refresh_project_risk_score_if_stale($projectId);
        } catch (Throwable $error) {
            // Risk scoring is advisory; a temporary failure must not break the portal.
            error_log('[AI WEB RISK] ' . $error->getMessage());
        }
    }
    $statement = database_connection()->prepare(
        'SELECT project_id, score, risk_level, confidence, stage, progress_snapshot,
                last_activity_at, factors_json, recommendation, engine, calculated_at
         FROM project_risk_scores WHERE project_id = :project_id LIMIT 1'
    );
    $statement->execute(['project_id' => $projectId]);
    $row = $statement->fetch();
    if (!is_array($row)) return null;
    $factors = json_decode((string) ($row['factors_json'] ?? ''), true);
    $row['factors'] = is_array($factors) ? $factors : [];
    unset($row['factors_json']);
    foreach (['score', 'confidence', 'progress_snapshot'] as $field) $row[$field] = (int) ($row[$field] ?? 0);
    return $row;
}

function ai_title_config_value(string $key, string $default = ''): string
{
    $config = env_config();
    return trim((string) ($config[$key] ?? $default));
}

function refresh_project_risk_score_if_stale(string $projectId): void
{
    $pdo = database_connection();
    $interval = max(60, (int) ai_title_config_value('AI_RISK_SCAN_INTERVAL', '300'));
    $stale = $pdo->prepare(
        'SELECT COUNT(*) FROM project_risk_scores
         WHERE project_id = :project_id
           AND calculated_at >= DATE_SUB(NOW(), INTERVAL :age SECOND)'
    );
    $stale->bindValue(':project_id', $projectId);
    $stale->bindValue(':age', $interval, PDO::PARAM_INT);
    $stale->execute();
    if ((int) $stale->fetchColumn() > 0) return;

    $statement = $pdo->prepare(
        "SELECT id, title, student_id, advisor_id, status, progress, updated_at
         FROM projects WHERE id = :project_id LIMIT 1"
    );
    $statement->execute(['project_id' => $projectId]);
    $project = $statement->fetch();
    if (!is_array($project)) return;

    $risk = calculate_project_risk($project, active_project_median_progress($pdo));
    save_project_risk_score($risk);
}

function risk_days_since(?string $date): int
{
    if (!$date) return 0;
    $timestamp = strtotime($date);
    return $timestamp === false ? 0 : max(0, (int) floor((time() - $timestamp) / 86400));
}

function risk_latest_date(array $dates): string
{
    $dates = array_values(array_filter(array_map(static fn($date): string => trim((string) $date), $dates)));
    if (!$dates) return date('Y-m-d H:i:s');
    rsort($dates, SORT_STRING);
    return $dates[0];
}

function active_project_median_progress(PDO $pdo): ?float
{
    $values = array_map('intval', $pdo->query(
        "SELECT progress FROM projects WHERE status NOT IN ('Completed', 'Cancelled') ORDER BY progress"
    )->fetchAll(PDO::FETCH_COLUMN));
    if (!$values) return null;
    $middle = intdiv(count($values), 2);
    return count($values) % 2 ? (float) $values[$middle] : ($values[$middle - 1] + $values[$middle]) / 2;
}

function calculate_project_risk(array $project, ?float $medianProgress): array
{
    $pdo = database_connection();
    $projectId = (string) $project['id'];
    $studentId = (string) ($project['student_id'] ?? '');
    $documentStatement = $pdo->prepare(
        'SELECT id, type, chapter, status, uploaded_at, approved_at
         FROM documents WHERE project_id = :project_id ORDER BY uploaded_at'
    );
    $documentStatement->execute(['project_id' => $projectId]);
    $documents = $documentStatement->fetchAll();

    $approvalStatement = $pdo->prepare(
        'SELECT approvals.status, approvals.created_at, approvals.approved_at
         FROM approvals
         LEFT JOIN documents ON documents.id = approvals.document_id
         WHERE documents.project_id = :project_id
            OR (approvals.document_id IS NULL AND approvals.student_id = :student_id)'
    );
    $approvalStatement->execute(['project_id' => $projectId, 'student_id' => $studentId]);
    $approvals = $approvalStatement->fetchAll();

    $activityDates = [(string) ($project['updated_at'] ?? '')];
    foreach ($documents as $document) {
        $activityDates[] = $document['uploaded_at'] ?? '';
        $activityDates[] = $document['approved_at'] ?? '';
    }
    foreach ($approvals as $approval) {
        $activityDates[] = $approval['created_at'] ?? '';
        $activityDates[] = $approval['approved_at'] ?? '';
    }
    $lastActivity = risk_latest_date($activityDates);
    $inactiveDays = risk_days_since($lastActivity);
    $progress = max(0, min(100, (int) ($project['progress'] ?? 0)));
    $completed = $progress >= 100 || in_array((string) ($project['status'] ?? ''), ['Completed', 'Cancelled'], true);

    $approved = static fn(array $row): bool => in_array((string) ($row['status'] ?? ''), ['Approved', 'Completed'], true);
    $proposalApproved = false;
    $approvedDraftChapters = [];
    $completeSubmitted = false;
    foreach ($documents as $document) {
        if (($document['type'] ?? '') === 'proposal' && $approved($document)) $proposalApproved = true;
        if (($document['type'] ?? '') === 'draft' && $approved($document)) {
            $chapter = (int) ($document['chapter'] ?? 0);
            if ($chapter >= 1 && $chapter <= 5) $approvedDraftChapters[$chapter] = true;
        }
        if (($document['type'] ?? '') === 'complete') $completeSubmitted = true;
    }
    $stage = !$proposalApproved ? 'proposal' : (count($approvedDraftChapters) < 5 ? 'draft' : ($completeSubmitted ? 'complete-review' : 'complete'));
    if ($completed) $stage = 'completed';

    $factors = [];
    $addFactor = static function (string $code, int $points, string $message) use (&$factors): void {
        if ($points > 0) $factors[] = compact('code', 'points', 'message');
    };

    if (!$completed) {
        if ($inactiveDays > 30) $addFactor('inactive', 30, "ไม่มีกิจกรรมล่าสุด {$inactiveDays} วัน");
        elseif ($inactiveDays > 14) $addFactor('inactive', 18, "ไม่มีกิจกรรมล่าสุด {$inactiveDays} วัน");
        elseif ($inactiveDays > 7) $addFactor('inactive', 8, "ไม่มีกิจกรรมล่าสุด {$inactiveDays} วัน");

        if (!$documents && $inactiveDays > 21) $addFactor('no_submission', 20, 'ยังไม่มีการส่งเอกสารและโครงงานหยุดนิ่งเกิน 21 วัน');
        elseif (!$documents && $inactiveDays > 10) $addFactor('no_submission', 10, 'ยังไม่มีการส่งเอกสารเกิน 10 วัน');

        $pendingReviewDays = 0;
        foreach ($documents as $document) {
            if (in_array((string) ($document['status'] ?? ''), ['Pending', 'Review', 'Resubmitted'], true)) {
                $pendingReviewDays = max($pendingReviewDays, risk_days_since((string) ($document['uploaded_at'] ?? '')));
            }
        }
        if ($pendingReviewDays > 14) $addFactor('awaiting_review', 10, "มีเอกสารรอพิจารณา {$pendingReviewDays} วัน");
        elseif ($pendingReviewDays > 7) $addFactor('awaiting_review', 5, "มีเอกสารรอพิจารณา {$pendingReviewDays} วัน");

        $revisionCount = 0;
        foreach (array_merge($documents, $approvals) as $row) {
            if (in_array((string) ($row['status'] ?? ''), ['Rejected', 'NeedsRevision', 'Revision'], true)) $revisionCount++;
        }
        if ($revisionCount > 0) $addFactor('revisions', min(20, $revisionCount * 5), "ถูกส่งกลับแก้ไขหรือปฏิเสธ {$revisionCount} ครั้ง");

        if ($progress < 15 && $inactiveDays > 14) $addFactor('low_progress', 15, "ความคืบหน้าเพียง {$progress}% และไม่มีความเคลื่อนไหวต่อเนื่อง");
        elseif ($progress < 40 && $inactiveDays > 30) $addFactor('low_progress', 10, "ความคืบหน้าอยู่ที่ {$progress}% หลังหยุดนิ่งเกิน 30 วัน");

        if ($medianProgress !== null) {
            $behind = (int) round($medianProgress - $progress);
            if ($behind >= 25) $addFactor('behind_cohort', 10, "ความคืบหน้าต่ำกว่าค่ากลางของโครงงานที่กำลังดำเนินการ {$behind}%");
            elseif ($behind >= 15) $addFactor('behind_cohort', 5, "ความคืบหน้าต่ำกว่าค่ากลางของโครงงานที่กำลังดำเนินการ {$behind}%");
        }

        if (empty($project['advisor_id'])) $addFactor('no_advisor', 5, 'โครงงานยังไม่มีอาจารย์ที่ปรึกษาหลัก');
    }

    $score = min(100, array_sum(array_column($factors, 'points')));
    $riskLevel = $completed || $score < 30 ? 'low' : ($score < 60 ? 'watch' : ($score < 80 ? 'high' : 'critical'));
    $confidence = min(95, 35 + min(32, count($documents) * 8) + (count($approvals) ? 8 : 0) + ($medianProgress !== null ? 10 : 0) + (!empty($project['advisor_id']) ? 5 : 0));
    if ($completed) $confidence = max($confidence, 90);

    $recommendation = 'ติดตามความคืบหน้าตามปกติ';
    if ($riskLevel === 'watch') $recommendation = 'ควรสอบถามความคืบหน้าในการติดตามครั้งถัดไป';
    if ($riskLevel === 'high') $recommendation = 'ควรให้อาจารย์ติดต่อและกำหนดแผนงานระยะสั้นร่วมกับนักศึกษา';
    if ($riskLevel === 'critical') $recommendation = 'ควรติดต่อนักศึกษาทันทีและทบทวนอุปสรรคของโครงงาน';

    return [
        'project_id' => $projectId, 'score' => $score, 'risk_level' => $riskLevel,
        'confidence' => $confidence, 'stage' => $stage, 'progress_snapshot' => $progress,
        'last_activity_at' => $lastActivity, 'factors' => $factors,
        'recommendation' => $recommendation, 'engine' => 'behavior-risk-v1',
    ];
}

function save_project_risk_score(array $risk): void
{
    $statement = database_connection()->prepare(
        'INSERT INTO project_risk_scores
         (project_id, score, risk_level, confidence, stage, progress_snapshot, last_activity_at,
          factors_json, recommendation, engine, calculated_at)
         VALUES
         (:project_id, :score, :risk_level, :confidence, :stage, :progress_snapshot, :last_activity_at,
          :factors_json, :recommendation, :engine, NOW())
         ON DUPLICATE KEY UPDATE score=VALUES(score), risk_level=VALUES(risk_level),
          confidence=VALUES(confidence), stage=VALUES(stage), progress_snapshot=VALUES(progress_snapshot),
          last_activity_at=VALUES(last_activity_at), factors_json=VALUES(factors_json),
          recommendation=VALUES(recommendation), engine=VALUES(engine), calculated_at=NOW()'
    );
    $statement->execute([
        'project_id' => $risk['project_id'], 'score' => $risk['score'],
        'risk_level' => $risk['risk_level'], 'confidence' => $risk['confidence'],
        'stage' => $risk['stage'], 'progress_snapshot' => $risk['progress_snapshot'],
        'last_activity_at' => $risk['last_activity_at'],
        'factors_json' => json_encode($risk['factors'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        'recommendation' => $risk['recommendation'], 'engine' => $risk['engine'],
    ]);
}

function process_project_risk_scores(int $limit = 100): array
{
    $pdo = database_connection();
    $limit = max(1, min(500, $limit));
    $projects = $pdo->query(
        "SELECT projects.id, projects.title, projects.student_id, projects.advisor_id,
                projects.status, projects.progress, projects.updated_at
         FROM projects
         LEFT JOIN project_risk_scores ON project_risk_scores.project_id = projects.id
         ORDER BY project_risk_scores.calculated_at IS NULL DESC,
                  project_risk_scores.calculated_at ASC, projects.id ASC
         LIMIT {$limit}"
    )->fetchAll();
    $median = active_project_median_progress($pdo);
    $levels = ['low' => 0, 'watch' => 0, 'high' => 0, 'critical' => 0];
    foreach ($projects as $project) {
        $risk = calculate_project_risk($project, $median);
        save_project_risk_score($risk);
        $levels[$risk['risk_level']]++;
    }
    return ['processed' => count($projects), 'levels' => $levels, 'median_progress' => $median];
}
