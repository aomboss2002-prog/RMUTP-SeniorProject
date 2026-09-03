<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/app/store.php';

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$apply = in_array('--apply', $argv, true);
$data = load_data();
$events = [];
$projects = [];
foreach ($data['projects'] ?? [] as $project) $projects[(string) ($project['id'] ?? '')] = $project;
foreach ($projects as $projectId => $_project) {
    if ($projectId === '') continue;
    $documents = tracking_documents_for_project($data['documents'] ?? [], $projectId);
    usort($documents, static fn(array $a, array $b): int => strcmp((string) ($a['uploaded_at'] ?? ''), (string) ($b['uploaded_at'] ?? '')));
    $state = [];
    foreach ($documents as $document) {
        $id = (string) ($document['id'] ?? '');
        $stage = strtolower((string) ($document['type'] ?? 'project'));
        $chapter = $stage === 'draft' ? max(1, min(5, (int) ($document['chapter'] ?? 1))) : null;
        $submittedAt = (string) (($document['uploaded_at'] ?? '') ?: date('Y-m-d H:i:s'));
        $pending = $document; $pending['status'] = 'Pending';
        $previous = calculated_project_progress(array_values($state));
        $state[$id] = $pending;
        $current = calculated_project_progress(array_values($state));
        $events[] = [
            'project_id' => $projectId, 'document_id' => $id ?: null, 'event_type' => 'submitted',
            'stage' => $stage, 'chapter' => $chapter, 'previous_progress' => $previous, 'current_progress' => $current,
            'actor_type' => 'system', 'actor_id' => 'backfill', 'actor_name' => 'Tracking backfill',
            'occurred_at' => $submittedAt, 'metadata' => ['backfilled' => true, 'source' => 'current_document'],
            'event_key' => hash('sha256', implode('|', ['backfill', $projectId, $id, 'submitted', $submittedAt])),
        ];
        $status = (string) ($document['status'] ?? 'Pending');
        if ($status === 'Pending' || $status === 'Review' || $status === 'Resubmitted') continue;
        $decisionAt = (string) (($document['approved_at'] ?? '') ?: $submittedAt);
        $previous = calculated_project_progress(array_values($state));
        $state[$id] = $document;
        $current = calculated_project_progress(array_values($state));
        $type = in_array($status, ['Approved', 'Completed'], true) ? 'approved' : ($status === 'NeedsRevision' ? 'revision_requested' : 'rejected');
        $events[] = [
            'project_id' => $projectId, 'document_id' => $id ?: null, 'event_type' => $type,
            'stage' => $stage, 'chapter' => $chapter, 'previous_progress' => $previous, 'current_progress' => $current,
            'actor_type' => 'system', 'actor_id' => 'backfill', 'actor_name' => 'Tracking backfill',
            'occurred_at' => $decisionAt, 'metadata' => ['backfilled' => true, 'source' => 'current_document', 'status' => $status],
            'event_key' => hash('sha256', implode('|', ['backfill', $projectId, $id, $type, $decisionAt, $status])),
        ];
    }
}

echo ($apply ? 'APPLY' : 'DRY RUN') . ' defensible_events=' . count($events) . PHP_EOL;
echo "Backfill never invents missing resubmission attempts.\n";
if (!$apply) { echo "Run again with --apply to persist.\n"; exit(0); }
$pdo = database_connection();
$pdo->beginTransaction();
try { persist_project_tracking_events($pdo, $events); $pdo->commit(); }
catch (Throwable $error) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $error; }
echo "PROJECT_TRACKING_BACKFILL_COMPLETE\n";

