<?php
declare(strict_types=1);

/** Project tracking is derived from workflow documents; it never owns progress state. */

function ensure_project_tracking_schema(PDO $pdo): void
{
    static $ready = false;
    if ($ready) return;
    $pdo->exec("CREATE TABLE IF NOT EXISTS project_progress_history (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        project_id VARCHAR(20) NOT NULL,
        document_id VARCHAR(20) NULL,
        event_type VARCHAR(40) NOT NULL,
        stage VARCHAR(30) NOT NULL,
        chapter TINYINT UNSIGNED NULL,
        previous_progress TINYINT UNSIGNED NOT NULL DEFAULT 0,
        current_progress TINYINT UNSIGNED NOT NULL DEFAULT 0,
        actor_type VARCHAR(20) NOT NULL DEFAULT 'system',
        actor_id VARCHAR(40) NOT NULL DEFAULT 'system',
        actor_name VARCHAR(180) NOT NULL DEFAULT 'System',
        event_key CHAR(64) NOT NULL,
        metadata_json JSON NULL,
        occurred_at DATETIME NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_project_progress_event_key (event_key),
        INDEX idx_project_progress_time (project_id, occurred_at, id),
        INDEX idx_document_progress_time (document_id, occurred_at, id),
        CONSTRAINT fk_progress_history_project FOREIGN KEY (project_id) REFERENCES projects(id) ON UPDATE CASCADE ON DELETE CASCADE,
        CONSTRAINT fk_progress_history_document FOREIGN KEY (document_id) REFERENCES documents(id) ON UPDATE CASCADE ON DELETE SET NULL,
        CONSTRAINT chk_progress_history_previous CHECK (previous_progress BETWEEN 0 AND 100),
        CONSTRAINT chk_progress_history_current CHECK (current_progress BETWEEN 0 AND 100)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE IF NOT EXISTS advisor_followups (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        project_id VARCHAR(20) NOT NULL,
        advisor_id VARCHAR(20) NULL,
        note VARCHAR(1000) NOT NULL,
        issue VARCHAR(1000) NOT NULL DEFAULT '',
        next_action VARCHAR(1000) NOT NULL DEFAULT '',
        followup_at DATE NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_advisor_followups_project_time (project_id, created_at, id),
        INDEX idx_advisor_followups_advisor (advisor_id, created_at),
        INDEX idx_advisor_followups_date (followup_at),
        CONSTRAINT fk_advisor_followups_project FOREIGN KEY (project_id) REFERENCES projects(id) ON UPDATE CASCADE ON DELETE CASCADE,
        CONSTRAINT fk_advisor_followups_advisor FOREIGN KEY (advisor_id) REFERENCES advisors(id) ON UPDATE CASCADE ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $ready = true;
}

function tracking_actor(): array
{
    $advisor = $_SESSION['advisor_user'] ?? null;
    $user = $_SESSION['app_user'] ?? null;
    $source = is_array($advisor) ? $advisor : (is_array($user) ? $user : []);
    $role = is_array($advisor) ? 'advisor' : (string) ($source['role'] ?? 'system');
    if (!in_array($role, ['admin', 'advisor', 'student'], true)) $role = 'system';
    return [
        'type' => $role,
        'id' => (string) ($source['id'] ?? 'system'),
        'name' => trim((string) ($source['name'] ?? '')) ?: ($role === 'system' ? 'System' : ucfirst($role)),
    ];
}

function tracking_documents_for_project(array $documents, string $projectId): array
{
    return array_values(array_filter($documents, static fn(array $row): bool => (string) ($row['project_id'] ?? '') === $projectId));
}

function tracking_stage_key(array $document): string
{
    $type = strtolower((string) ($document['type'] ?? ''));
    return $type === 'draft' ? 'draft-' . max(1, min(5, (int) ($document['chapter'] ?? 1))) : $type;
}

function tracking_document_event(string $oldStatus, string $newStatus, bool $isNew, bool $resubmitted): string
{
    if ($isNew) return 'submitted';
    if ($resubmitted) return 'resubmitted';
    if (in_array($newStatus, ['Approved', 'Completed'], true)) return 'approved';
    if ($newStatus === 'NeedsRevision') return 'revision_requested';
    if ($newStatus === 'Rejected') return 'rejected';
    return 'progress_changed';
}

/**
 * Returns retry-safe event rows and updates project activity timestamps in-memory.
 * Call persist_project_tracking_events() inside the same transaction as app_state.
 */
function prepare_project_tracking_events(array $previous, array &$current): array
{
    $oldDocuments = [];
    foreach ($previous['documents'] ?? [] as $row) $oldDocuments[(string) ($row['id'] ?? '')] = $row;
    $newDocuments = [];
    foreach ($current['documents'] ?? [] as $row) $newDocuments[(string) ($row['id'] ?? '')] = $row;
    $projects = [];
    foreach (array_merge($previous['projects'] ?? [], $current['projects'] ?? []) as $row) {
        if (!empty($row['id'])) $projects[(string) $row['id']] = true;
    }
    $actor = tracking_actor();
    $events = [];
    $activity = [];
    foreach ($projects as $projectId => $_) {
        $oldProjectDocs = tracking_documents_for_project(array_values($oldDocuments), $projectId);
        $newProjectDocs = tracking_documents_for_project(array_values($newDocuments), $projectId);
        $oldProgress = calculated_project_progress($oldProjectDocs);
        $newProgress = calculated_project_progress($newProjectDocs);
        foreach ($newProjectDocs as $document) {
            $id = (string) ($document['id'] ?? '');
            $old = $oldDocuments[$id] ?? null;
            $isNew = $old === null;
            $uploadedChanged = !$isNew && (string) ($old['uploaded_at'] ?? '') !== (string) ($document['uploaded_at'] ?? '');
            $statusChanged = !$isNew && (string) ($old['status'] ?? '') !== (string) ($document['status'] ?? '');
            if (!$isNew && !$uploadedChanged && !$statusChanged) continue;
            $newStatus = (string) ($document['status'] ?? 'Pending');
            $eventType = tracking_document_event((string) ($old['status'] ?? ''), $newStatus, $isNew, $uploadedChanged);
            $occurred = (string) (($eventType === 'approved' || str_contains($eventType, 'revision') || $eventType === 'rejected')
                ? ($document['approved_at'] ?? '') : ($document['uploaded_at'] ?? ''));
            if ($occurred === '') $occurred = date('Y-m-d H:i:s');
            $stage = strtolower((string) ($document['type'] ?? 'project'));
            $chapter = $stage === 'draft' ? max(1, min(5, (int) ($document['chapter'] ?? 1))) : null;
            $events[] = [
                'project_id' => $projectId, 'document_id' => $id ?: null, 'event_type' => $eventType,
                'stage' => $stage, 'chapter' => $chapter, 'previous_progress' => $oldProgress,
                'current_progress' => $newProgress, 'actor_type' => $actor['type'], 'actor_id' => $actor['id'],
                'actor_name' => $actor['name'], 'occurred_at' => $occurred,
                'metadata' => ['status' => $newStatus],
                'event_key' => hash('sha256', implode('|', [$projectId, $id, $eventType, $occurred, $newStatus, (string) $newProgress])),
            ];
            $activity[$projectId] = $occurred;
        }
        foreach ($oldProjectDocs as $document) {
            $id = (string) ($document['id'] ?? '');
            if ($id === '' || isset($newDocuments[$id])) continue;
            $occurred = date('Y-m-d H:i:s');
            $stage = strtolower((string) ($document['type'] ?? 'project'));
            $events[] = [
                'project_id' => $projectId, 'document_id' => null, 'event_type' => 'document_deleted',
                'stage' => $stage, 'chapter' => $stage === 'draft' ? (int) ($document['chapter'] ?? 1) : null,
                'previous_progress' => $oldProgress, 'current_progress' => $newProgress,
                'actor_type' => $actor['type'], 'actor_id' => $actor['id'], 'actor_name' => $actor['name'],
                'occurred_at' => $occurred, 'metadata' => ['document_id' => $id, 'status' => $document['status'] ?? ''],
                'event_key' => hash('sha256', implode('|', [$projectId, $id, 'document_deleted', $occurred, (string) $newProgress])),
            ];
            $activity[$projectId] = $occurred;
        }
    }
    foreach ($current['projects'] ?? [] as &$project) {
        $id = (string) ($project['id'] ?? '');
        if (isset($activity[$id])) $project['updated_at'] = $activity[$id];
    }
    unset($project);
    return $events;
}

function persist_project_tracking_events(PDO $pdo, array $events): void
{
    if (!$events) return;
    // MySQL DDL implicitly commits an active transaction. The schema is already
    // prepared by database_connection(), so only perform the fallback check when
    // this helper is called independently outside a transaction.
    if (!$pdo->inTransaction()) ensure_project_tracking_schema($pdo);
    $statement = $pdo->prepare("INSERT IGNORE INTO project_progress_history
        (project_id, document_id, event_type, stage, chapter, previous_progress, current_progress,
         actor_type, actor_id, actor_name, event_key, metadata_json, occurred_at)
        VALUES (:project_id, :document_id, :event_type, :stage, :chapter, :previous_progress, :current_progress,
         :actor_type, :actor_id, :actor_name, :event_key, :metadata_json, :occurred_at)");
    foreach ($events as $event) {
        $metadata = $event['metadata'] ?? [];
        unset($event['metadata']);
        $event['metadata_json'] = json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $statement->execute($event);
    }
}

function sync_workflow_documents_to_database(PDO $pdo, array $current, array $previous): void
{
    $old = [];
    foreach ($previous as $row) if (!empty($row['id'])) $old[(string) $row['id']] = $row;
    $now = [];
    foreach ($current as $row) if (!empty($row['id'])) $now[(string) $row['id']] = $row;
    $upsert = $pdo->prepare("INSERT INTO documents
        (id, project_id, student_id, group_id, type, chapter, title, filename, size, status, uploaded_at, approved_at)
        VALUES (:id, :project_id, :student_id, :group_id, :type, :chapter, :title, :filename, :size, :status, :uploaded_at, :approved_at)
        ON DUPLICATE KEY UPDATE project_id=VALUES(project_id), student_id=VALUES(student_id), group_id=VALUES(group_id),
        type=VALUES(type), chapter=VALUES(chapter), title=VALUES(title), filename=VALUES(filename), size=VALUES(size),
        status=VALUES(status), uploaded_at=VALUES(uploaded_at), approved_at=VALUES(approved_at)");
    foreach ($now as $id => $row) {
        if (isset($old[$id]) && $old[$id] === $row) continue;
        $upsert->execute([
            'id' => $id, 'project_id' => ($row['project_id'] ?? '') ?: null,
            'student_id' => ($row['student_id'] ?? '') ?: null, 'group_id' => ($row['group_id'] ?? '') ?: null,
            'type' => $row['type'] ?? 'document', 'chapter' => $row['chapter'] ?? null,
            'title' => $row['title'] ?? ucfirst((string) ($row['type'] ?? 'Document')),
            'filename' => $row['filename'] ?? '', 'size' => $row['size'] ?? '',
            'status' => $row['status'] ?? 'Review', 'uploaded_at' => ($row['uploaded_at'] ?? '') ?: date('Y-m-d H:i:s'),
            'approved_at' => ($row['approved_at'] ?? '') ?: null,
        ]);
    }
    $delete = $pdo->prepare('DELETE FROM documents WHERE id = :id');
    foreach ($old as $id => $_) if (!isset($now[$id])) $delete->execute(['id' => $id]);
}

function project_tracking_history(string $projectId): array
{
    if ($projectId === '') return [];
    $pdo = database_connection();
    ensure_project_tracking_schema($pdo);
    $statement = $pdo->prepare('SELECT id, project_id, document_id, event_type, stage, chapter,
        previous_progress, current_progress, actor_type, actor_id, actor_name, occurred_at
        FROM project_progress_history WHERE project_id = :project_id ORDER BY occurred_at ASC, id ASC');
    $statement->execute(['project_id' => $projectId]);
    return $statement->fetchAll() ?: [];
}

function project_followups(string $projectId): array
{
    if ($projectId === '') return [];
    $pdo = database_connection();
    ensure_project_tracking_schema($pdo);
    $statement = $pdo->prepare('SELECT f.id, f.project_id, f.advisor_id, f.note, f.issue, f.next_action,
        f.followup_at, f.created_at, f.updated_at, COALESCE(a.name, \'Former advisor\') AS advisor_name
        FROM advisor_followups f LEFT JOIN advisors a ON a.id = f.advisor_id
        WHERE f.project_id = :project_id ORDER BY f.created_at DESC, f.id DESC');
    $statement->execute(['project_id' => $projectId]);
    return $statement->fetchAll() ?: [];
}

function milestone_document_status(?array $document): string
{
    if ($document === null) return 'not_started';
    $status = (string) ($document['status'] ?? 'Pending');
    if (in_array($status, ['Approved', 'Completed'], true)) return 'completed';
    if (in_array($status, ['NeedsRevision', 'Rejected'], true)) return 'needs_revision';
    return 'awaiting_review';
}

function derive_project_tracking(array $documents, array $history = []): array
{
    $latest = [];
    foreach ($documents as $document) {
        $key = tracking_stage_key($document);
        $stamp = (string) ($document['uploaded_at'] ?? '');
        if (!isset($latest[$key]) || $stamp >= (string) ($latest[$key]['uploaded_at'] ?? '')) $latest[$key] = $document;
    }
    $definitions = [['proposal', 'Proposal', null]];
    for ($chapter = 1; $chapter <= 5; $chapter++) $definitions[] = ['draft-' . $chapter, 'Draft chapter ' . $chapter, $chapter];
    $definitions[] = ['complete', 'Complete', null];
    $milestones = [];
    $currentIndex = 0;
    foreach ($definitions as $index => [$key, $label, $chapter]) {
        $document = $latest[$key] ?? null;
        $status = milestone_document_status($document);
        if ($status !== 'completed' && ($currentIndex === 0 || ($milestones[$currentIndex]['status'] ?? '') === 'completed')) $currentIndex = $index;
        $milestones[] = [
            'key' => $key, 'label' => $label, 'stage' => $chapter ? 'draft' : $key, 'chapter' => $chapter,
            'status' => $status, 'submitted_at' => $document['uploaded_at'] ?? null,
            'completed_at' => $status === 'completed' ? ($document['approved_at'] ?? $document['uploaded_at'] ?? null) : null,
        ];
    }
    $allComplete = count(array_filter($milestones, static fn(array $m): bool => $m['status'] === 'completed')) === count($milestones);
    if ($allComplete) $currentIndex = count($milestones) - 1;
    foreach ($milestones as $index => &$milestone) $milestone['current'] = $index === $currentIndex;
    unset($milestone);
    $current = $milestones[$currentIndex];
    $lastActivity = null;
    foreach ($history as $row) if (($row['occurred_at'] ?? '') > ($lastActivity ?? '')) $lastActivity = $row['occurred_at'];
    if ($lastActivity === null) foreach ($documents as $row) {
        $stamp = (string) ($row['approved_at'] ?: ($row['uploaded_at'] ?? ''));
        if ($stamp > ($lastActivity ?? '')) $lastActivity = $stamp;
    }
    $status = $current['status'];
    $responsible = $status === 'awaiting_review' ? 'advisor' : ($allComplete ? 'none' : 'student');
    $nextAction = $allComplete ? 'Project workflow complete'
        : ($status === 'awaiting_review' ? 'Waiting for advisor review'
            : ($status === 'needs_revision' ? 'Revise and resubmit ' . $current['label'] : 'Submit ' . $current['label']));
    return [
        'progress' => calculated_project_progress($documents), 'milestones' => $milestones,
        'current_stage' => $current, 'next_action' => $nextAction, 'responsible_role' => $responsible,
        'last_activity' => $lastActivity,
        'inactive_days' => $lastActivity ? max(0, (int) floor((time() - strtotime($lastActivity)) / 86400)) : null,
    ];
}

function project_tracking_payload(string $projectId, array $documents, bool $includeFollowups = true): array
{
    $history = project_tracking_history($projectId);
    $tracking = derive_project_tracking($documents, $history);
    $tracking['history'] = $history;
    $tracking['followups'] = $includeFollowups ? project_followups($projectId) : [];
    return $tracking;
}

function advisor_is_assigned_to_project(array $appData, string $advisorId, string $projectId): bool
{
    foreach ($appData['groups'] ?? [] as $group) {
        if ((string) ($group['project_id'] ?? '') === $projectId
            && in_array($advisorId, array_values($group['advisor_roles'] ?? []), true)) return true;
    }
    foreach ($appData['students'] ?? [] as $student) {
        if ((string) ($student['project_id'] ?? '') === $projectId
            && in_array($advisorId, array_values($student['advisor_roles'] ?? []), true)) return true;
    }
    return false;
}

function validate_advisor_followup_values(array $payload): array
{
    $note = trim((string) ($payload['note'] ?? ''));
    $issue = trim((string) ($payload['issue'] ?? ''));
    $nextAction = trim((string) ($payload['next_action'] ?? ''));
    $followupAt = trim((string) ($payload['followup_at'] ?? ''));
    if ($note === '') throw new InvalidArgumentException('Summary note is required.');
    if (mb_strlen($note) > 1000 || mb_strlen($issue) > 1000 || mb_strlen($nextAction) > 1000) {
        throw new InvalidArgumentException('Each follow-up field must not exceed 1,000 characters.');
    }
    if ($followupAt !== '') {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $followupAt);
        if (!$date || $date->format('Y-m-d') !== $followupAt) throw new InvalidArgumentException('Invalid follow-up date.');
    }
    return ['note'=>$note, 'issue'=>$issue, 'next_action'=>$nextAction, 'followup_at'=>$followupAt ?: null];
}
