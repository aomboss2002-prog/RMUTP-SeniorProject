<?php
declare(strict_types=1);

/** A bounded, read-only SQL snapshot. Never writes a backup into the web root. */
function database_backup_identifier(string $name): string
{
    return '`' . str_replace('`', '``', $name) . '`';
}

function database_backup_value(mixed $value, string $type): string
{
    if ($value === null) return 'NULL';
    $hex = "X'" . bin2hex((string) $value) . "'";
    // Binary values must not undergo character-set conversion (sessions may contain NUL).
    return preg_match('/^(?:binary|varbinary|tinyblob|blob|mediumblob|longblob|bit)\b/i', $type)
        ? $hex : 'CONVERT(' . $hex . ' USING utf8mb4)';
}

function database_backup_export(PDO $pdo, int $maxCompressed = 2097152, int $maxSql = 25165824, float $maxSeconds = 20): array
{
    if (!function_exists('deflate_init')) throw new RuntimeException('BACKUP_ZLIB_MISSING');
    if ($pdo->inTransaction()) throw new RuntimeException('BACKUP_TRANSACTION_ACTIVE');
    $started = microtime(true);
    $checkTime = static function () use ($started, $maxSeconds): void {
        if (microtime(true) - $started > $maxSeconds) throw new RuntimeException('BACKUP_LIMIT');
    };
    $gzip = deflate_init(ZLIB_ENCODING_GZIP, ['level' => 6]);
    if ($gzip === false) throw new RuntimeException('BACKUP_COMPRESSION_FAILED');
    $compressed = '';
    $sqlBytes = 0;
    $append = static function (string $sql, bool $finish = false) use (&$compressed, &$sqlBytes, $gzip, $maxCompressed, $maxSql, $checkTime): void {
        $checkTime();
        $sqlBytes += strlen($sql);
        if ($sqlBytes > $maxSql) throw new RuntimeException('BACKUP_LIMIT');
        $chunk = deflate_add($gzip, $sql, $finish ? ZLIB_FINISH : ZLIB_NO_FLUSH);
        if ($chunk === false) throw new RuntimeException('BACKUP_COMPRESSION_FAILED');
        $compressed .= $chunk;
        if (strlen($compressed) > $maxCompressed) throw new RuntimeException('BACKUP_LIMIT');
    };

    $tables = $pdo->query('SELECT TABLE_NAME, TABLE_TYPE, ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() ORDER BY TABLE_NAME')->fetchAll(PDO::FETCH_ASSOC);
    if (!$tables) throw new RuntimeException('BACKUP_EMPTY_DATABASE');
    foreach ($tables as $table) {
        if ($table['TABLE_TYPE'] !== 'BASE TABLE' || strcasecmp((string) $table['ENGINE'], 'InnoDB') !== 0) {
            throw new RuntimeException('BACKUP_UNSUPPORTED_SCHEMA');
        }
    }
    // This exporter handles application tables, not programmable database objects.
    foreach (['TRIGGERS' => 'TRIGGER_SCHEMA', 'ROUTINES' => 'ROUTINE_SCHEMA', 'EVENTS' => 'EVENT_SCHEMA'] as $object => $schemaColumn) {
        if ((int) $pdo->query("SELECT COUNT(*) FROM information_schema.$object WHERE $schemaColumn = DATABASE()")->fetchColumn() > 0) {
            throw new RuntimeException('BACKUP_UNSUPPORTED_SCHEMA');
        }
    }
    $oldTimezone = (string) $pdo->query('SELECT @@SESSION.time_zone')->fetchColumn();
    $oldBuffered = $pdo->getAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY);
    $cursor = null;
    $rows = 0;
    try {
        $pdo->exec("SET SESSION time_zone = '+00:00'");
        $pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        $pdo->exec('SET TRANSACTION READ ONLY');
        $pdo->beginTransaction();
        $append("-- RMUTP database table backup (UTC) " . gmdate('c') . "\n-- Sensitive: includes personal data, password hashes and sessions.\n-- Restore ONLY into a NEW EMPTY database. No uploaded files or .env included.\nSET NAMES utf8mb4;\nSET @BACKUP_OLD_FK=@@FOREIGN_KEY_CHECKS;\nSET FOREIGN_KEY_CHECKS=0;\nSET @BACKUP_OLD_MODE=@@SQL_MODE;\nSET SQL_MODE='NO_AUTO_VALUE_ON_ZERO';\nSET @BACKUP_OLD_TZ=@@TIME_ZONE;\nSET TIME_ZONE='+00:00';\n");
        foreach ($tables as $table) {
            $checkTime();
            $name = database_backup_identifier($table['TABLE_NAME']);
            $ddl = $pdo->query('SHOW CREATE TABLE ' . $name)->fetch(PDO::FETCH_NUM);
            if (!$ddl || !isset($ddl[1])) throw new RuntimeException('BACKUP_SCHEMA_FAILED');
            $append("\n" . $ddl[1] . ";\n");
            $columns = array_values(array_filter(
                $pdo->query('SHOW FULL COLUMNS FROM ' . $name)->fetchAll(PDO::FETCH_ASSOC),
                static fn(array $c): bool => !preg_match('/(?:VIRTUAL|STORED|PERSISTENT) GENERATED/i', (string) $c['Extra'])
            ));
            if (!$columns) throw new RuntimeException('BACKUP_UNSUPPORTED_SCHEMA');
            foreach ($columns as $column) {
                if (preg_match('/^(?:geometry|point|linestring|polygon|multipoint|multilinestring|multipolygon|geometrycollection)\b/i', $column['Type'])) {
                    throw new RuntimeException('BACKUP_UNSUPPORTED_SCHEMA');
                }
            }
            $columnList = implode(',', array_map(static fn(array $c): string => database_backup_identifier($c['Field']), $columns));
            $pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, false);
            $cursor = $pdo->query('SELECT ' . $columnList . ' FROM ' . $name);
            while (($row = $cursor->fetch(PDO::FETCH_NUM)) !== false) {
                // Hex expansion doubles raw values; refuse huge rows before allocating SQL.
                $rowBytes = 0;
                foreach ($row as $value) $rowBytes += strlen((string) $value);
                if ($rowBytes * 2 > $maxSql) throw new RuntimeException('BACKUP_LIMIT');
                $values = [];
                foreach ($row as $i => $value) $values[] = database_backup_value($value, $columns[$i]['Type']);
                $append('INSERT INTO ' . $name . ' (' . $columnList . ') VALUES (' . implode(',', $values) . ");\n");
                $rows++;
            }
            $cursor->closeCursor();
            $cursor = null;
            $pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, $oldBuffered);
        }
        $append("\nSET TIME_ZONE=@BACKUP_OLD_TZ;\nSET SQL_MODE=@BACKUP_OLD_MODE;\nSET FOREIGN_KEY_CHECKS=@BACKUP_OLD_FK;\n-- END RMUTP BACKUP\n", true);
        $pdo->commit();
    } finally {
        if ($cursor instanceof PDOStatement) $cursor->closeCursor();
        if ($pdo->inTransaction()) $pdo->rollBack();
        $pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, $oldBuffered);
        $pdo->exec('SET SESSION time_zone = ' . $pdo->quote($oldTimezone));
    }
    return [
        'filename' => 'rmutp-database-' . gmdate('Ymd-His') . '.sql.gz',
        'content_base64' => base64_encode($compressed),
        'bytes' => strlen($compressed), 'tables' => count($tables), 'rows' => $rows,
        'sha256' => hash('sha256', $compressed),
    ];
}
