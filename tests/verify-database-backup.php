<?php
declare(strict_types=1);
require dirname(__DIR__) . '/app/database-backup.php';

function backup_check(bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException($message);
}
backup_check(database_backup_identifier('a`b') === '`a``b`', 'Identifier escaping');
backup_check(database_backup_value(null, 'text') === 'NULL', 'NULL');
backup_check(database_backup_value("\0\xff", 'blob') === "X'00ff'", 'Binary bytes');
backup_check(database_backup_value('', 'varchar(30)') === "CONVERT(X'' USING utf8mb4)", 'Empty text');
$api = file_get_contents(dirname(__DIR__) . '/api/index.php');
$handler = strpos($api, "if (\$action === 'backup-database')");
backup_check($handler > strpos($api, "!== 'admin'"), 'Admin gate');
backup_check($handler > strpos($api, 'require_csrf_token();'), 'CSRF gate');
$action = substr($api, $handler, strpos($api, "if (\$method === 'GET')", $handler) - $handler);
backup_check(str_contains($action, "\$_SERVER['REQUEST_METHOD'] !== 'POST'"), 'Actual POST gate');
backup_check(str_contains($action, "(request_json()['confirm'] ?? false) !== true"), 'Explicit confirmation');
backup_check(str_contains($action, 'session_write_close();'), 'Release session before export');
backup_check(str_contains($action, "header('Retry-After: 60')"), 'Rate limit');
echo "DATABASE_BACKUP_UNIT_OK\n";

// Optional real round-trip. ONLY a dedicated temporary MariaDB instance, never .env/production.
$port = (int) (getenv('BACKUP_TEST_PORT') ?: 0);
if (!$port) { echo "ROUND_TRIP_SKIPPED: set BACKUP_TEST_PORT for an isolated temporary MariaDB\n"; exit; }
backup_check($port >= 1024 && $port <= 65535 && $port !== 3306, 'Non-default isolated port required');
$connect = static fn(): PDO => new PDO('mysql:host=127.0.0.1;port=' . $port . ';charset=utf8mb4', 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo = $connect();
$datadir = str_replace('\\', '/', (string) $pdo->query('SELECT @@datadir')->fetchColumn());
$temp = rtrim(str_replace('\\', '/', sys_get_temp_dir()), '/') . '/database-backup-';
backup_check(str_starts_with(strtolower($datadir), strtolower($temp)), 'Refuse non-isolated server');
$suffix = bin2hex(random_bytes(6));
$source = 'backup_test_source_' . $suffix;
$target = 'backup_test_restore_' . $suffix;
$created = [];
try {
    foreach ([$source, $target] as $schema) {
        $pdo->exec('CREATE DATABASE ' . database_backup_identifier($schema) . ' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        $created[] = $schema;
    }
    $pdo->exec('USE ' . database_backup_identifier($source));
    $pdo->exec("SET time_zone='+07:00'");
    $pdo->exec('CREATE TABLE parent (id INT PRIMARY KEY, title VARCHAR(255), nullable TEXT NULL, payload LONGBLOB, stamp TIMESTAMP NULL, amount DECIMAL(12,3), doubled INT GENERATED ALWAYS AS (id*2) STORED) ENGINE=InnoDB');
    $pdo->exec('CREATE TABLE child (id INT PRIMARY KEY, parent_id INT, CONSTRAINT child_parent FOREIGN KEY(parent_id) REFERENCES parent(id)) ENGINE=InnoDB');
    $pdo->exec('CREATE TABLE app_state (state_key VARCHAR(80) PRIMARY KEY, state_json JSON) ENGINE=InnoDB');
    $pdo->exec('CREATE TABLE empty_table (id INT) ENGINE=InnoDB');
    $stmt = $pdo->prepare('INSERT INTO parent (id,title,nullable,payload,stamp,amount) VALUES (?,?,?,?,?,?)');
    $stmt->execute([0, "นักศึกษา 'ทดสอบ' \\ \n 😀", null, "\0\xff\r\n'\\", '2026-09-28 14:52:47', '123.456']);
    $stmt->execute([2, '', '', '', null, '-0.001']);
    $pdo->exec('INSERT INTO child VALUES (1,0)');
    $pdo->prepare('INSERT INTO app_state VALUES (?,?)')->execute(['runtime', json_encode(['settings' => ['system_name' => 'โครงงาน'], 'value' => "\0"])]);
    $snapshot = [];
    foreach (['parent', 'child', 'app_state', 'empty_table'] as $table) $snapshot[$table] = $pdo->query('SELECT * FROM ' . $table)->fetchAll(PDO::FETCH_ASSOC);
    $backup = database_backup_export($pdo);
    $gzip = base64_decode($backup['content_base64'], true);
    backup_check(hash('sha256', $gzip) === $backup['sha256'], 'Checksum');
    backup_check($backup['tables'] === 4 && $backup['rows'] === 4, 'Counts');
    backup_check($pdo->query('SELECT @@time_zone')->fetchColumn() === '+07:00' && !$pdo->inTransaction(), 'Connection state restored');
    $sql = gzdecode($gzip);
    backup_check(str_ends_with($sql, "-- END RMUTP BACKUP\n"), 'Complete dump');
    $restore = $connect();
    $restore->exec('USE ' . database_backup_identifier($target));
    $restore->exec("SET time_zone='+07:00'");
    $restore->exec($sql);
    foreach ($snapshot as $table => $rows) {
        backup_check($restore->query('SELECT * FROM ' . $table)->fetchAll(PDO::FETCH_ASSOC) === $rows, 'Restored content: ' . $table);
        backup_check($pdo->query('SELECT * FROM ' . $table)->fetchAll(PDO::FETCH_ASSOC) === $rows, 'Source unchanged: ' . $table);
    }
    foreach ([[10, 25165824, 20], [2097152, 10, 20], [2097152, 25165824, 0]] as $limits) {
        try { database_backup_export($pdo, ...$limits); throw new LogicException('Limit should fail'); }
        catch (RuntimeException $e) { backup_check($e->getMessage() === 'BACKUP_LIMIT', 'Bounded failure'); }
        backup_check(!$pdo->inTransaction() && $pdo->query('SELECT @@time_zone')->fetchColumn() === '+07:00', 'Rollback after failure');
    }
    $pdo->exec('CREATE VIEW unsupported_view AS SELECT id FROM parent');
    try { database_backup_export($pdo); throw new LogicException('View should fail'); }
    catch (RuntimeException $e) { backup_check($e->getMessage() === 'BACKUP_UNSUPPORTED_SCHEMA', 'Reject partial schema'); }
    echo "DATABASE_BACKUP_ROUND_TRIP_OK: Thai, emoji, quotes, NUL/binary, JSON, NULL/empty, decimals, timestamp, generated column, foreign key, empty table; limits and unchanged source\n";
} finally {
    foreach ($created as $schema) {
        backup_check(preg_match('/^backup_test_(?:source|restore)_[a-f0-9]{12}$/D', $schema) === 1, 'Cleanup scope');
        $pdo->exec('DROP DATABASE ' . database_backup_identifier($schema));
    }
}
