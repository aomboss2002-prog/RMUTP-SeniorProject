<?php
declare(strict_types=1);
require_once __DIR__ . '/consolidated-schema.php';
require_once __DIR__ . '/runtime-records.php';

const LEGACY_APPLICATION_TABLES = ['php_sessions','project_progress_history','advisor_followups','project_title_checks','project_risk_scores','system_job_runs','schema_migrations'];

function twenty_table_inventory(PDO $pdo): array
{
    return $pdo->query("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_TYPE='BASE TABLE' ORDER BY TABLE_NAME")->fetchAll(PDO::FETCH_COLUMN);
}

/** Never overwrite a conflicting destination. Re-running a completed copy is safe. */
function migration_copy_row(PDO $pdo, string $table, string $key, array $row): void
{
    $fields = array_keys($row);
    $query = $pdo->prepare('SELECT ' . implode(',', $fields) . " FROM {$table} WHERE {$key}=?");
    $query->execute([$row[$key]]);
    $existing = $query->fetch(PDO::FETCH_ASSOC);
    if (!$existing) {
        $pdo->prepare('INSERT INTO ' . $table . ' (' . implode(',', $fields) . ') VALUES (' . implode(',', array_fill(0, count($fields), '?')) . ')')->execute(array_values($row));
        $query->execute([$row[$key]]);
        $existing = $query->fetch(PDO::FETCH_ASSOC);
    }
    foreach ($row as $field => $value) {
        if (($existing[$field] === null) !== ($value === null) || (string) $existing[$field] !== (string) $value) {
            throw new RuntimeException("Migration verification conflict: {$table}.{$field}");
        }
    }
}

/** Run with web traffic and workers stopped. All copies verify before any DROP. */
function migrate_twenty_tables(PDO $pdo, bool $drop = false): array
{
    $tables = twenty_table_inventory($pdo);
    if (array_diff(APPLICATION_TABLES, $tables)) throw new RuntimeException('Install the base application schema first');
    if (array_diff($tables, array_merge(APPLICATION_TABLES, LEGACY_APPLICATION_TABLES))) throw new RuntimeException('Unexpected tables: manual review required');
    ensure_consolidated_columns($pdo); // DDL is deliberately outside the data transaction.
    $counts = [];
    $pdo->beginTransaction();
    try {
        foreach (LEGACY_APPLICATION_TABLES as $table) {
            if (!in_array($table, $tables, true)) continue;
            $counts[$table] = 0;
            // Buffered source result permits verified prepared writes on the same connection.
            $source = $pdo->query("SELECT * FROM {$table}");
            while ($row = $source->fetch(PDO::FETCH_ASSOC)) {
                if ($table === 'schema_migrations') {
                    migration_copy_row($pdo, 'settings', 'setting_key', ['setting_key' => 'migration:' . hash('sha256', $row['version']),
                        'setting_value' => json_encode($row, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)]);
                } elseif ($table === 'php_sessions') {
                    $query = $pdo->prepare('SELECT session_data FROM user_sessions WHERE session_id=?');
                    $query->execute([$row['session_id']]);
                    $existing = $query->fetch(PDO::FETCH_ASSOC);
                    if ($existing && $existing['session_data'] === null) {
                        $pdo->prepare('UPDATE user_sessions SET session_data=?,expires_at=? WHERE session_id=?')->execute([$row['session_data'], $row['expires_at'], $row['session_id']]);
                    } elseif (!$existing) {
                        $pdo->prepare('INSERT INTO user_sessions (session_id,session_data,last_activity_at,expires_at) VALUES (?,?,?,?)')
                            ->execute([$row['session_id'], $row['session_data'], $row['updated_at'], $row['expires_at']]);
                    }
                    migration_copy_row($pdo, 'user_sessions', 'session_id', ['session_id' => $row['session_id'], 'session_data' => $row['session_data'], 'expires_at' => $row['expires_at']]);
                } elseif ($table === 'project_progress_history') {
                    migration_copy_row($pdo, 'activities', 'id', [
                        'id' => 'PH' . str_pad(base_convert((string) $row['id'], 10, 36), 18, '0', STR_PAD_LEFT), 'progress_id' => $row['id'], 'title' => $row['event_type'], 'actor' => $row['actor_name'],
                        'activity_type' => 'progress_updated', 'project_id' => $row['project_id'], 'document_id' => $row['document_id'],
                        'event_type' => $row['event_type'], 'stage' => $row['stage'], 'chapter' => $row['chapter'],
                        'old_value' => $row['previous_progress'], 'new_value' => $row['current_progress'],
                        'actor_type' => $row['actor_type'], 'actor_id' => $row['actor_id'], 'event_key' => $row['event_key'],
                        'metadata_json' => $row['metadata_json'], 'occurred_at' => $row['occurred_at'], 'created_at' => $row['created_at'],
                    ]);
                } elseif ($table === 'advisor_followups') {
                    migration_copy_row($pdo, 'comments', 'id', [
                        'id' => 'FU' . base_convert((string) $row['id'], 10, 36), 'project_id' => $row['project_id'],
                        'author_id' => $row['advisor_id'], 'author' => $row['advisor_id'] ?? 'Former advisor',
                        'message' => $row['note'], 'comment_type' => 'advisor_followup', 'followup_id' => $row['id'],
                        'issue' => $row['issue'], 'next_action' => $row['next_action'], 'followup_at' => $row['followup_at'],
                        'created_at' => $row['created_at'], 'updated_at' => $row['updated_at'],
                    ]);
                    migration_copy_row($pdo, 'activities', 'id', [
                        'id' => 'FM' . base_convert((string) $row['id'], 10, 36), 'title' => 'followup_imported',
                        'actor' => $row['advisor_id'] ?? 'Former advisor', 'activity_type' => 'followup_imported',
                        'project_id' => $row['project_id'], 'actor_type' => 'advisor', 'actor_id' => $row['advisor_id'],
                        'metadata_json' => json_encode($row, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                        'occurred_at' => $row['updated_at'], 'created_at' => $row['created_at'],
                    ]);
                } else {
                    $kind = ['project_title_checks' => 'title', 'project_risk_scores' => 'risk', 'system_job_runs' => 'job'][$table];
                    migration_copy_row($pdo, 'app_state', 'state_key', [
                        'state_key' => runtime_record_key($kind, $kind === 'risk' ? $row['project_id'] : $row['id']),
                        'state_json' => json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                    ]);
                }
                $counts[$table]++;
            }
        }
        foreach (['title' => 'project_title_checks', 'job' => 'system_job_runs', 'followup' => 'advisor_followups', 'progress' => 'project_progress_history'] as $kind => $table) {
            if (!isset($counts[$table])) continue;
            $maximum = (int) $pdo->query("SELECT COALESCE(MAX(id),0) FROM {$table}")->fetchColumn();
            $current = runtime_sequence($pdo, $kind, false);
            $pdo->prepare('UPDATE app_state SET state_json=? WHERE state_key=?')->execute([(string) max($maximum, $current), 'sequence:' . $kind]);
        }
        $pdo->commit();
    } catch (Throwable $error) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $error; }
    if ($drop) {
        // MySQL DROP commits implicitly. Copies above are durable and verified first.
        foreach (array_keys($counts) as $table) $pdo->exec("DROP TABLE `{$table}`");
        $remaining = twenty_table_inventory($pdo);
        if (count($remaining) !== 20 || array_diff(APPLICATION_TABLES, $remaining)) throw new RuntimeException('Final table inventory mismatch');
        mark_database_schema_current($pdo, '20261002_01_twenty_tables');
    }
    return $counts;
}
