<?php
declare(strict_types=1);

/** Remove only records explicitly associated with this project/document set. */
function admin_project_deleted_state(array $data, string $id, array $documentIds): array
{
    $documents = array_fill_keys($documentIds, true);
    $data['projects'] = array_values(array_filter($data['projects'] ?? [], static fn($r) => ($r['id'] ?? '') !== $id));
    foreach (['documents', 'comments', 'approvals', 'activities', 'notifications', 'calendar', 'messages'] as $collection) {
        if (!isset($data[$collection])) continue;
        $data[$collection] = array_values(array_filter($data[$collection], static fn($r) =>
            ($r['project_id'] ?? '') !== $id
            && !isset($documents[(string) ($r['document_id'] ?? '')])
            && !($collection === 'documents' && isset($documents[(string) ($r['id'] ?? '')]))));
    }
    foreach (['students', 'groups'] as $collection) {
        foreach ($data[$collection] ?? [] as $index => $row) {
            if (($row['project_id'] ?? '') === $id) $data[$collection][$index]['project_id'] = '';
        }
    }
    // A pending group invitation must not restore a deleted former project.
    foreach ($data['group_invitations'] ?? [] as $index => $row) {
        if (($row['previous_project_id'] ?? '') === $id) $data['group_invitations'][$index]['previous_project_id'] = '';
    }
    return $data;
}

function admin_project_delete(PDO $pdo, string $id): array
{
    if ($id === '' || strlen($id) > 20) throw new InvalidArgumentException('รหัสโครงงานไม่ถูกต้อง');
    $pdo->beginTransaction();
    try {
        $stateQuery = $pdo->query("SELECT state_json FROM app_state WHERE state_key='runtime' FOR UPDATE");
        $data = json_decode((string) $stateQuery->fetchColumn(), true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($data)) throw new RuntimeException('Runtime state unavailable');
        $query = $pdo->prepare('SELECT id, title FROM projects WHERE id=? FOR UPDATE');
        $query->execute([$id]);
        $project = $query->fetch(PDO::FETCH_ASSOC);
        if (!$project) throw new OutOfBoundsException('ไม่พบโครงงาน หรือโครงงานถูกลบแล้ว');
        $query = $pdo->prepare('SELECT id, type, filename FROM documents WHERE project_id=? FOR UPDATE');
        $query->execute([$id]);
        $documents = [];
        foreach ($query->fetchAll(PDO::FETCH_ASSOC) as $document) $documents[$document['id']] = $document;
        foreach ($data['documents'] ?? [] as $document) {
            if (($document['project_id'] ?? '') === $id) $documents[$document['id']] ??= $document;
        }
        $checkDocument = $pdo->prepare('SELECT project_id FROM documents WHERE id=? FOR UPDATE');
        foreach (array_keys($documents) as $docId) {
            $checkDocument->execute([$docId]);
            $owner = $checkDocument->fetchColumn();
            if ($owner !== false && $owner !== null && $owner !== '' && $owner !== $id) throw new RuntimeException('Conflicting document ownership');
        }
        $after = admin_project_deleted_state($data, $id, array_keys($documents));
        // Explicit deletes support older installations as well as FK cascades.
        foreach (['comments', 'approvals'] as $table) {
            $delete = $pdo->prepare("DELETE FROM $table WHERE document_id=?");
            foreach (array_keys($documents) as $docId) $delete->execute([$docId]);
        }
        foreach (['activities' => 'activities', 'notifications' => 'notifications', 'comments' => 'comments', 'approvals' => 'approvals', 'messages' => 'group_messages'] as $collection => $table) {
            $remaining = array_fill_keys(array_column($after[$collection] ?? [], 'id'), true);
            $delete = $pdo->prepare("DELETE FROM $table WHERE id=?");
            foreach ($data[$collection] ?? [] as $row) if (!isset($remaining[$row['id']])) $delete->execute([$row['id']]);
        }
        $delete = $pdo->prepare('DELETE FROM documents WHERE id=? AND (project_id=? OR project_id IS NULL)');
        foreach (array_keys($documents) as $docId) $delete->execute([$docId, $id]);
        foreach (['project_title_checks', 'project_risk_scores', 'project_progress_history', 'advisor_followups'] as $table) {
            $pdo->prepare("DELETE FROM $table WHERE project_id=?")->execute([$id]);
        }
        foreach (['students', 'project_groups'] as $table) $pdo->prepare("UPDATE $table SET project_id=NULL WHERE project_id=?")->execute([$id]);
        $pdo->prepare('DELETE FROM projects WHERE id=?')->execute([$id]);
        $pdo->prepare("UPDATE app_state SET state_json=? WHERE state_key='runtime'")->execute([json_encode($after, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)]);
        $jobId = 'project-delete-files-' . bin2hex(random_bytes(8));
        $files = [];
        foreach ($documents as $document) {
            if (empty($document['filename'])) continue;
            $file = ['type' => (string) $document['type'], 'filename' => (string) $document['filename']];
            $files[hash('sha256', $file['type'] . '/' . $file['filename'])] = $file;
        }
        if ($files) $pdo->prepare('INSERT INTO app_state (state_key,state_json) VALUES (?,?)')->execute([
            $jobId, json_encode(['project_id' => $id, 'title' => $project['title'], 'driver' => storage_driver(),
                'store' => storage_driver() === 'local' ? dirname(__DIR__) : storage_blob_store_id() . '/' . storage_blob_prefix(),
                'files' => array_values($files)], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
        ]);
        $pdo->prepare('INSERT INTO audit_logs (actor_type,actor_id,action,entity_type,entity_id,details_json) VALUES (?,?,?,?,?,?)')->execute([
            'admin', (string) ($_SESSION['app_user']['id'] ?? 'admin'), 'project_deleted', 'project', $id,
            json_encode(['title' => $project['title'], 'documents' => count($documents), 'cleanup_job' => $files ? $jobId : null], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
        ]);
        $pdo->commit();
        return ['documents' => count($documents), 'cleanup_job' => $files ? $jobId : null];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

function admin_project_cleanup_jobs(PDO $pdo): array
{
    $rows = $pdo->query("SELECT state_key,state_json FROM app_state WHERE state_key LIKE 'project-delete-files-%' ORDER BY state_key")->fetchAll(PDO::FETCH_ASSOC);
    return array_map(static function ($row) {
        $job = json_decode($row['state_json'], true, 512, JSON_THROW_ON_ERROR);
        return ['id' => $row['state_key'], 'title' => $job['title'], 'files' => count($job['files'])];
    }, $rows);
}

/** Durable retry queue: never delete objects before committing project removal. */
function admin_project_cleanup_files(PDO $pdo, string $jobId, ?callable $remove = null): array
{
    if (!preg_match('/^project-delete-files-[a-f0-9]{16}$/D', $jobId)) throw new InvalidArgumentException('รายการลบไฟล์ไม่ถูกต้อง');
    $remove ??= 'admin_project_remove_file';
    $pdo->beginTransaction();
    try {
        $query = $pdo->prepare('SELECT state_json FROM app_state WHERE state_key=? FOR UPDATE');
        $query->execute([$jobId]);
        $json = $query->fetchColumn();
        if ($json === false) { $pdo->commit(); return ['pending' => 0]; }
        $job = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        if ($job['driver'] !== storage_driver()) throw new RuntimeException('Storage driver changed');
        if ($job['store'] !== (storage_driver() === 'local' ? dirname(__DIR__) : storage_blob_store_id() . '/' . storage_blob_prefix())) throw new RuntimeException('Storage location changed');
        $runtime = json_decode((string) $pdo->query("SELECT state_json FROM app_state WHERE state_key='runtime'")->fetchColumn(), true, 512, JSON_THROW_ON_ERROR);
        $pending = [];
        $started = microtime(true);
        foreach ($job['files'] as $file) {
            if (microtime(true) - $started > 15) { $pending[] = $file; continue; }
            $query = $pdo->prepare('SELECT COUNT(*) FROM documents WHERE type=? AND filename=?');
            $query->execute([$file['type'], $file['filename']]);
            $shared = (int) $query->fetchColumn() > 0;
            foreach ($runtime['documents'] ?? [] as $document) {
                if (($document['type'] ?? '') === $file['type'] && ($document['filename'] ?? '') === $file['filename']) $shared = true;
            }
            if ($shared) continue; // Never delete files still used by another project.
            try { $remove($file['type'], $file['filename']); }
            catch (Throwable) { $pending[] = $file; }
        }
        if ($pending) {
            $job['files'] = $pending;
            $pdo->prepare('UPDATE app_state SET state_json=? WHERE state_key=?')->execute([json_encode($job, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), $jobId]);
        } else $pdo->prepare('DELETE FROM app_state WHERE state_key=?')->execute([$jobId]);
        $pdo->commit();
        return ['pending' => count($pending)];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

function admin_project_remove_file(string $type, string $filename): void
{
    if (!in_array($type, ['proposal', 'draft', 'complete'], true)
        || !preg_match('/^[A-Za-z0-9_-][A-Za-z0-9._-]*\.pdf$/iD', $filename)) throw new RuntimeException('Unsafe file target');
    if (storage_driver() === 'local') {
        $path = storage_local_path($type, $filename);
        if (!file_exists($path)) return;
        $directory = realpath(dirname(__DIR__) . '/uploads/' . $type);
        $resolved = realpath($path);
        $expected = str_replace('\\', '/', dirname(__DIR__) . '/uploads/' . $type);
        if ($directory === false || $resolved === false
            || strcasecmp(str_replace('\\', '/', $directory), $expected) !== 0
            || dirname($resolved) !== $directory || is_link($path)) throw new RuntimeException('Unsafe file path');
        if (!unlink($resolved)) throw new RuntimeException('File deletion failed');
        return;
    }
    // Matches @vercel/blob del(): POST /delete {urls:[pathname]} with server token.
    $handle = storage_curl('https://vercel.com/api/blob/delete', [
        CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CONNECTTIMEOUT => 3, CURLOPT_TIMEOUT => 8,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . storage_blob_token(), 'Content-Type: application/json', 'x-api-version: 12'],
        CURLOPT_POSTFIELDS => json_encode(['urls' => [storage_blob_pathname($type, $filename)]], JSON_THROW_ON_ERROR),
    ]);
    $response = curl_exec($handle);
    $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
    unset($handle);
    if ($response === false || $status < 200 || $status >= 300) throw new RuntimeException('Blob deletion failed');
}
