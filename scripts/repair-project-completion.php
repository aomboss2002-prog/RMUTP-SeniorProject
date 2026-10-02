<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../app/store.php';
$apply = in_array('--apply', $argv, true);
$backup = '';
foreach ($argv as $arg) if (str_starts_with($arg, '--backup=')) $backup = substr($arg, 9);
if ($apply && ($backup === '' || file_exists($backup))) {
    throw new RuntimeException('Supply a new --backup=absolute-path file before applying');
}
$pdo = database_connection();
if ($apply) {
    // This repair is intentionally limited to the user's local XAMPP database.
    $dataDir = str_replace('\\', '/', (string) $pdo->query('SELECT @@datadir')->fetchColumn());
    if (strtolower(rtrim($dataDir, '/')) !== 'c:/xampp/mysql/data') {
        throw new RuntimeException('Apply is restricted to local XAMPP');
    }
}
$pdo->beginTransaction();
try {
    $runtimeJson = $pdo->query("SELECT state_json FROM app_state WHERE state_key = 'runtime' FOR UPDATE")->fetchColumn();
    $runtime = is_string($runtimeJson) ? json_decode($runtimeJson, true, 512, JSON_THROW_ON_ERROR) : null;
    if (!is_array($runtime)) throw new RuntimeException('Runtime state missing');
    $projects = $pdo->query('SELECT * FROM projects FOR UPDATE')->fetchAll(PDO::FETCH_ASSOC);
    $documents = $pdo->query('SELECT * FROM documents ORDER BY uploaded_at, id FOR UPDATE')->fetchAll(PDO::FETCH_ASSOC);
    $normalized = apply_calculated_project_progress(['projects' => $projects, 'documents' => $documents]);
    $changes = [];
    foreach ($normalized['projects'] as $i => $project) {
        if ($project['status'] !== $projects[$i]['status'] || (int) $project['progress'] !== (int) $projects[$i]['progress']) {
            $changes[] = $project;
            echo $project['id'] . ': ' . $projects[$i]['status'] . ' -> ' . $project['status'] . ', ' . $project['progress'] . "%\n";
        }
    }
    if ($apply && $changes) {
        $handle = fopen($backup, 'x');
        if (!$handle) throw new RuntimeException('Cannot create backup');
        $payload = json_encode(['projects' => $projects, 'runtime_json' => $runtimeJson], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        try {
            if (fwrite($handle, $payload) !== strlen($payload) || !fflush($handle)) throw new RuntimeException('Backup incomplete');
        } finally { fclose($handle); }
        $update = $pdo->prepare('UPDATE projects SET status = ?, progress = ? WHERE id = ?');
        foreach ($changes as $project) {
            $update->execute([$project['status'], $project['progress'], $project['id']]);
            foreach ($runtime['projects'] as &$row) {
                if ($row['id'] === $project['id']) {
                    $row['status'] = $project['status'];
                    $row['progress'] = $project['progress'];
                }
            }
            unset($row);
        }
        $pdo->prepare("UPDATE app_state SET state_json = ? WHERE state_key = 'runtime'")
            ->execute([json_encode($runtime, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)]);
    }
    if ($apply) $pdo->commit(); else $pdo->rollBack();
    echo ($apply ? 'APPLIED ' : 'DRY_RUN ') . count($changes) . " project(s)\n";
} catch (Throwable $error) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    throw $error;
}
