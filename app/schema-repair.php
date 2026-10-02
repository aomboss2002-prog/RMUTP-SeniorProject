<?php
declare(strict_types=1);

/** AI state uses app_state; this action must never recreate removed tables. */
function repair_missing_ai_tables(PDO $pdo): array
{
    $query = $pdo->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='app_state'");
    if ((int) $query->fetchColumn() !== 1) throw new RuntimeException('BASE_SCHEMA_MISSING');
    return ['created' => [], 'unchanged' => ['app_state']];
}
