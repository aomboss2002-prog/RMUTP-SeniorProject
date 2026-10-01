<?php
declare(strict_types=1);

// Never run migrations through an HTTP request or the monitoring poll.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
putenv('DB_AUTO_MIGRATE=false');
require_once dirname(__DIR__) . '/app/store.php';

$apply = in_array('--apply', $argv, true);
try {
    $pdo = database_connection();
    $exists = $pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');
    $exists->execute(['projects']);
    if (!(int) $exists->fetchColumn()) {
        fwrite(STDERR, "PROJECTS_TABLE_MISSING: install the base schema first.\n");
        exit(1);
    }
    foreach (['project_title_checks' => 'ai-title-check.sql', 'project_risk_scores' => 'ai-risk-score.sql'] as $table => $file) {
        $exists->execute([$table]);
        if ((int) $exists->fetchColumn()) {
            echo "$table: PRESENT (unchanged)\n";
            continue;
        }
        echo "$table: MISSING\n";
        if (!$apply) continue;
        $sql = file_get_contents(dirname(__DIR__) . '/database/' . $file);
        if ($sql === false) throw new RuntimeException('Migration unavailable');
        $pdo->exec($sql);
        $exists->execute([$table]);
        if (!(int) $exists->fetchColumn()) throw new RuntimeException('Verification failed');
        echo "$table: CREATED\n";
    }
    echo $apply ? "AI_SCHEMA_REPAIR_COMPLETE\n" : "READ_ONLY: back up the target database, then use --apply to create missing tables.\n";
} catch (Throwable $error) {
    // Do not expose connection credentials or SQL parameters.
    fwrite(STDERR, 'AI_SCHEMA_CHECK_FAILED code=' . $error->getCode() . " (check connection, permissions and projects.id compatibility)\n");
    exit(1);
}
