<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
putenv('DB_AUTO_MIGRATE=false');
require_once dirname(__DIR__) . '/app/store.php';
require_once dirname(__DIR__) . '/app/schema-repair.php';
try {
    repair_missing_ai_tables(database_connection());
    echo "AI_RUNTIME_STORAGE_READY app_state\n";
} catch (Throwable $error) {
    fwrite(STDERR, "AI_RUNTIME_STORAGE_UNAVAILABLE: check database connection and run the twenty-table migration.\n");
    exit(1);
}
