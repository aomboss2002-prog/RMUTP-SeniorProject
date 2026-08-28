<?php
declare(strict_types=1);

require __DIR__ . '/../app/store.php';

$pdo = database_connection();
$errors = [];
$requiredTables = [
    'user_sessions' => ['PRIMARY', 'idx_user_sessions_user', 'idx_user_sessions_expires'],
    'password_reset_tokens' => ['PRIMARY', 'token_hash', 'idx_password_reset_user', 'idx_password_reset_expires'],
    'notification_reads' => ['PRIMARY', 'idx_notification_reads_reader'],
];

$tableStatement = $pdo->prepare(
    'SELECT COUNT(*) FROM information_schema.TABLES
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table_name'
);
$indexStatement = $pdo->prepare(
    'SELECT COUNT(*) FROM information_schema.STATISTICS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table_name AND INDEX_NAME = :index_name'
);

foreach ($requiredTables as $table => $indexes) {
    $tableStatement->execute(['table_name' => $table]);
    if ((int) $tableStatement->fetchColumn() !== 1) {
        $errors[] = "Missing table: {$table}";
        continue;
    }

    foreach ($indexes as $index) {
        $indexStatement->execute(['table_name' => $table, 'index_name' => $index]);
        if ((int) $indexStatement->fetchColumn() === 0) {
            $errors[] = "Missing index: {$table}.{$index}";
        }
    }
}

$foreignKeyStatement = $pdo->query(
    "SELECT COUNT(*) FROM information_schema.KEY_COLUMN_USAGE
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME = 'notification_reads'
       AND COLUMN_NAME = 'notification_id'
       AND REFERENCED_TABLE_NAME = 'notifications'"
);
if ((int) $foreignKeyStatement->fetchColumn() !== 1) {
    $errors[] = 'Missing foreign key: notification_reads.notification_id -> notifications.id';
}

if ($errors !== []) {
    fwrite(STDERR, implode(PHP_EOL, $errors) . PHP_EOL);
    exit(1);
}

echo "BACKEND_TABLES_OK\n";
