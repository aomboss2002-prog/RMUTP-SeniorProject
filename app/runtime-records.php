<?php
declare(strict_types=1);

/** Namespaced records in the existing durable app_state store (also on Vercel). */
function runtime_record_key(string $kind, string|int $id): string
{
    $prefix = ['title' => 'ai-title:', 'risk' => 'ai-risk:', 'job' => 'job-run:'][$kind] ?? throw new InvalidArgumentException('Unknown runtime kind');
    $key = $prefix . ($kind === 'risk' ? (string) $id : str_pad((string) $id, 20, '0', STR_PAD_LEFT));
    if (strlen($key) > 40) throw new InvalidArgumentException('Runtime key too long');
    return $key;
}

function runtime_record_get(PDO $pdo, string $kind, string|int $id, bool $lock = false): ?array
{
    $query = $pdo->prepare('SELECT state_json FROM app_state WHERE state_key=?' . ($lock ? ' FOR UPDATE' : ''));
    $query->execute([runtime_record_key($kind, $id)]);
    $json = $query->fetchColumn();
    return $json === false ? null : json_decode($json, true, 512, JSON_THROW_ON_ERROR);
}

function runtime_record_put(PDO $pdo, string $kind, string|int $id, array $value): void
{
    $pdo->prepare('INSERT INTO app_state (state_key,state_json) VALUES (?,?) ON DUPLICATE KEY UPDATE state_json=VALUES(state_json)')
        ->execute([runtime_record_key($kind, $id), json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)]);
}

/** Caller owns the transaction; the counter also serializes queue mutations. */
function runtime_sequence(PDO $pdo, string $name, bool $advance = true): int
{
    if (!$pdo->inTransaction()) throw new LogicException('Runtime sequence requires a transaction');
    $key = 'sequence:' . $name;
    $pdo->prepare("INSERT IGNORE INTO app_state (state_key,state_json) VALUES (?, '0')")->execute([$key]);
    $query = $pdo->prepare('SELECT state_json FROM app_state WHERE state_key=? FOR UPDATE');
    $query->execute([$key]);
    $id = (int) $query->fetchColumn();
    if ($advance) $pdo->prepare('UPDATE app_state SET state_json=? WHERE state_key=?')->execute([(string) ++$id, $key]);
    return $id;
}

/** SQL projection, not a table/view. Prefix lookups use app_state's primary key. */
function runtime_records_sql(string $kind): string
{
    $fields = [
        'title' => 'id project_id title status engine model max_similarity risk_level matches_json error_message attempts created_at started_at completed_at',
        'risk' => 'project_id score risk_level confidence stage progress_snapshot last_activity_at factors_json recommendation engine calculated_at',
        'job' => 'id job_name status started_at finished_at duration_ms summary_json error_code created_at',
    ][$kind] ?? throw new InvalidArgumentException('Unknown runtime kind');
    $columns = [];
    foreach (explode(' ', $fields) as $field) {
        $value = "NULLIF(JSON_UNQUOTE(JSON_EXTRACT(state_json, '$.{$field}')), 'null')";
        if (in_array($field, ['id', 'attempts', 'score', 'confidence', 'progress_snapshot', 'duration_ms'], true)) $value = "CAST({$value} AS UNSIGNED)";
        $columns[] = "{$value} AS `{$field}`";
    }
    $prefix = ['title' => 'ai-title:', 'risk' => 'ai-risk:', 'job' => 'job-run:'][$kind];
    return '(SELECT ' . implode(', ', $columns) . " FROM app_state WHERE state_key LIKE '{$prefix}%')";
}

function runtime_job_start(PDO $pdo): int
{
    $pdo->beginTransaction();
    try {
        $id = runtime_sequence($pdo, 'job');
        runtime_record_put($pdo, 'job', $id, ['id' => $id, 'job_name' => 'ai-web-worker', 'status' => 'started',
            'started_at' => date('Y-m-d H:i:s'), 'created_at' => date('Y-m-d H:i:s'), 'finished_at' => null,
            'duration_ms' => null, 'summary_json' => null, 'error_code' => null]);
        $pdo->commit();
        return $id;
    } catch (Throwable $error) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $error; }
}

function runtime_job_finish(PDO $pdo, int $id, string $status, int $duration, ?string $summary = null, ?string $error = null): void
{
    $row = runtime_record_get($pdo, 'job', $id);
    if ($row === null) throw new RuntimeException('Missing worker run');
    runtime_record_put($pdo, 'job', $id, array_replace($row, ['status' => $status,
        'finished_at' => date('Y-m-d H:i:s'), 'duration_ms' => $duration, 'summary_json' => $summary, 'error_code' => $error]));
}
