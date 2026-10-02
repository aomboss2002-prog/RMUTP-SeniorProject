<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
putenv('DB_AUTO_MIGRATE=false');
require_once dirname(__DIR__) . '/app/store.php';
require_once dirname(__DIR__) . '/app/twenty-table-migration.php';
try {
    $pdo = database_connection();
    $tables = twenty_table_inventory($pdo);
    echo 'TABLES=' . count($tables) . PHP_EOL;
    foreach (array_intersect(LEGACY_APPLICATION_TABLES, $tables) as $table) {
        echo $table . ': ' . $pdo->query("SELECT COUNT(*) FROM {$table}")->fetchColumn() . ' rows' . PHP_EOL;
    }
    if (!in_array('--apply', $argv, true)) { echo "READ_ONLY: no changes made.\n"; exit; }
    if (!in_array('--maintenance-confirmed', $argv, true) || !in_array('--backup-confirmed', $argv, true)) {
        throw new RuntimeException('Stop web/worker writes and back up the database; then pass --maintenance-confirmed --backup-confirmed');
    }
    $drop = in_array('--drop-legacy', $argv, true);
    $counts = migrate_twenty_tables($pdo, $drop);
    echo json_encode(['verified_rows' => $counts, 'legacy_dropped' => $drop, 'tables' => twenty_table_inventory($pdo)], JSON_PRETTY_PRINT) . PHP_EOL;
} catch (Throwable $error) {
    fwrite(STDERR, 'MIGRATION_FAILED: ' . ($error instanceof PDOException ? 'database error ' . $error->getCode() : $error->getMessage()) . PHP_EOL);
    exit(1);
}
