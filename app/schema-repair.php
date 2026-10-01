<?php
declare(strict_types=1);

/** Explicit admin action only. Existing tables and records are never altered. */
function repair_missing_ai_tables(PDO $pdo): array
{
    $exists = $pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
    $hasTable = static function (string $table) use ($exists): bool {
        $exists->execute([$table]);
        return (int) $exists->fetchColumn() > 0;
    };
    if (!$hasTable('projects')) throw new RuntimeException('BASE_SCHEMA_MISSING');
    $created = [];
    $unchanged = [];
    foreach (['project_title_checks' => 'ai-title-check.sql', 'project_risk_scores' => 'ai-risk-score.sql'] as $table => $file) {
        if ($hasTable($table)) { $unchanged[] = $table; continue; }
        $sql = file_get_contents(dirname(__DIR__) . '/database/' . $file);
        if ($sql === false) throw new RuntimeException('MIGRATION_UNAVAILABLE');
        $pdo->exec($sql);
        if (!$hasTable($table)) throw new RuntimeException('SCHEMA_VERIFICATION_FAILED');
        $created[] = $table;
    }
    return ['created' => $created, 'unchanged' => $unchanged];
}
