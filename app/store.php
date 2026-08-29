<?php

date_default_timezone_set('Asia/Bangkok');

function app_data_path(): string
{
    return __DIR__ . '/../database/app-data.json';
}

function env_config(): array
{
    static $config = null;
    if ($config !== null) {
        return $config;
    }
    $config = [];
    $path = __DIR__ . '/../.env';
    foreach (is_file($path) ? file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : [] as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
            continue;
        }
        [$key, $value] = array_map('trim', explode('=', $line, 2));
        $config[$key] = trim($value, "\"'");
    }
    foreach (getenv() ?: [] as $key => $value) {
        if (is_string($key) && is_scalar($value)) {
            $config[$key] = (string) $value;
        }
    }
    return $config;
}

function ensure_database_column(PDO $pdo, string $table, string $column, string $definition): void
{
    $columnExists = static function () use ($pdo, $table, $column): bool {
        $statement = $pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table_name AND COLUMN_NAME = :column_name'
        );
        $statement->execute(['table_name' => $table, 'column_name' => $column]);
        return (int) $statement->fetchColumn() > 0;
    };
    if (!$columnExists()) {
        try {
            $pdo->exec("ALTER TABLE `{$table}` ADD COLUMN `{$column}` {$definition}");
        } catch (PDOException $error) {
            // Another serverless invocation may have applied the same DDL
            // after our information_schema check.
            if (!$columnExists()) {
                throw $error;
            }
        }
    }
}

function ensure_database_index(PDO $pdo, string $table, string $index, string $columns, bool $unique = false): void
{
    $indexExists = static function () use ($pdo, $table, $index): bool {
        $statement = $pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table_name AND INDEX_NAME = :index_name'
        );
        $statement->execute(['table_name' => $table, 'index_name' => $index]);
        return (int) $statement->fetchColumn() > 0;
    };
    if (!$indexExists()) {
        try {
            $pdo->exec(sprintf(
                'ALTER TABLE `%s` ADD %sINDEX `%s` (%s)',
                $table,
                $unique ? 'UNIQUE ' : '',
                $index,
                $columns
            ));
        } catch (PDOException $error) {
            // Treat an index created concurrently as success, but surface any
            // genuine DDL failure.
            if (!$indexExists()) {
                throw $error;
            }
        }
    }
}

function ensure_database_foreign_key(
    PDO $pdo,
    string $table,
    string $column,
    string $referencedTable,
    string $constraint,
    string $onDelete = 'RESTRICT'
): void {
    $findForeignKey = static function () use ($pdo, $table, $column, $referencedTable): bool {
        $statement = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.KEY_COLUMN_USAGE
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table_name
           AND COLUMN_NAME = :column_name AND REFERENCED_TABLE_NAME = :referenced_table'
        );
        $statement->execute([
            'table_name' => $table,
            'column_name' => $column,
            'referenced_table' => $referencedTable,
        ]);
        return (int) $statement->fetchColumn() > 0;
    };
    if ($findForeignKey()) {
        return;
    }

    $orphanStatement = $pdo->prepare(
        "SELECT COUNT(*) FROM `{$table}` child
         LEFT JOIN `{$referencedTable}` parent ON parent.id = child.`{$column}`
         WHERE child.`{$column}` IS NOT NULL AND parent.id IS NULL"
    );
    $orphanStatement->execute();
    if ((int) $orphanStatement->fetchColumn() > 0) {
        throw new RuntimeException(
            "Cannot add {$constraint}: {$table}.{$column} contains values missing from {$referencedTable}.id."
        );
    }

    try {
        $pdo->exec("ALTER TABLE `{$table}` ADD CONSTRAINT `{$constraint}`
                    FOREIGN KEY (`{$column}`) REFERENCES `{$referencedTable}` (`id`)
                    ON UPDATE CASCADE ON DELETE {$onDelete}");
    } catch (PDOException $error) {
        // Serverless requests may initialize the same empty database at the
        // same time. If another request won the race, the relation now exists.
        if ($findForeignKey()) {
            return;
        }
        throw $error;
    }
}

function ensure_primary_database_schema(PDO $pdo): void
{
    $requiredTables = ['students', 'projects', 'documents', 'notifications', 'activities', 'comments', 'approvals', 'settings'];
    $placeholders = implode(',', array_fill(0, count($requiredTables), '?'));
    $statement = $pdo->prepare(
        "SELECT COUNT(*) FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ({$placeholders})"
    );
    $statement->execute($requiredTables);
    if ((int) $statement->fetchColumn() === count($requiredTables)) {
        return;
    }

    $schemaPath = __DIR__ . '/../database/database.sql';
    $schema = is_file($schemaPath) ? file_get_contents($schemaPath) : false;
    if (!is_string($schema) || $schema === '') {
        throw new RuntimeException('Database schema file is missing.');
    }

    // Run only CREATE TABLE statements in the database selected by DB_NAME /
    // DB_DATABASE. CREATE DATABASE, USE and seed INSERT statements must never
    // redirect a hosted connection to a different database.
    if (!preg_match_all('/CREATE\s+TABLE\s+IF\s+NOT\s+EXISTS\s+.*?;/is', $schema, $matches)) {
        throw new RuntimeException('Database schema does not contain table definitions.');
    }
    foreach ($matches[0] as $createTableSql) {
        $pdo->exec($createTableSql);
    }
}

function normalize_database_collations(PDO $pdo): void
{
    $statement = $pdo->prepare(
        "SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND CHARACTER_SET_NAME IS NOT NULL
           AND COLLATION_NAME <> 'utf8mb4_unicode_ci'"
    );
    $statement->execute();
    if ((int) $statement->fetchColumn() === 0) {
        return;
    }

    $tables = $pdo->query(
        "SELECT TABLE_NAME FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE'"
    )->fetchAll(PDO::FETCH_COLUMN);

    $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
    try {
        foreach ($tables as $table) {
            if (!is_string($table) || !preg_match('/^[A-Za-z0-9_]+$/', $table)) {
                continue;
            }
            $pdo->exec("ALTER TABLE `{$table}` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        }
    } finally {
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
    }
}

function database_schema_is_current(PDO $pdo, string $version): bool
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS schema_migrations (
        version VARCHAR(80) PRIMARY KEY,
        applied_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

    $statement = $pdo->prepare('SELECT COUNT(*) FROM schema_migrations WHERE version = :version');
    $statement->execute(['version' => $version]);
    if ((int) $statement->fetchColumn() > 0) {
        return true;
    }

    // Older deployments may have completed the migration before the marker
    // table existed. Detect that state once instead of repeating dozens of
    // information_schema queries on every serverless request.
    $relationCount = (int) $pdo->query(
        "SELECT COUNT(*) FROM information_schema.KEY_COLUMN_USAGE
         WHERE TABLE_SCHEMA = DATABASE()
           AND REFERENCED_TABLE_NAME IS NOT NULL
           AND TABLE_NAME IN (
               'students', 'projects', 'project_groups', 'project_group_members',
               'student_advisors', 'advisor_invitations', 'group_invitations',
               'group_messages', 'documents', 'comments', 'approvals',
               'notifications', 'notification_reads'
           )"
    )->fetchColumn();
    $requiredIndexes = [
        'idx_advisor_invitations_group', 'idx_advisor_invitations_student',
        'idx_advisor_invitations_advisor_status', 'idx_group_invitations_group',
        'idx_group_invitations_student_status', 'idx_group_invitations_sender',
        'idx_documents_stage_status', 'idx_documents_uploaded',
        'idx_notifications_group_created', 'idx_notifications_student_created',
        'idx_notifications_advisor_created', 'idx_comments_student_created',
        'idx_comments_document_created', 'idx_approvals_student_created',
        'idx_approvals_document_created', 'idx_approvals_group_created',
        'idx_approvals_reviewer_status', 'idx_password_reset_ip_created',
    ];
    $indexPlaceholders = implode(',', array_fill(0, count($requiredIndexes), '?'));
    $indexStatement = $pdo->prepare(
        "SELECT COUNT(DISTINCT INDEX_NAME) FROM information_schema.STATISTICS
         WHERE TABLE_SCHEMA = DATABASE() AND INDEX_NAME IN ({$indexPlaceholders})"
    );
    $indexStatement->execute($requiredIndexes);
    $requiredIndexCount = (int) $indexStatement->fetchColumn();
    if ($relationCount >= 32 && $requiredIndexCount === count($requiredIndexes)) {
        $insert = $pdo->prepare('INSERT IGNORE INTO schema_migrations (version) VALUES (:version)');
        $insert->execute(['version' => $version]);
        return true;
    }
    return false;
}

function mark_database_schema_current(PDO $pdo, string $version): void
{
    $statement = $pdo->prepare('INSERT IGNORE INTO schema_migrations (version) VALUES (:version)');
    $statement->execute(['version' => $version]);
}

function database_connection(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }
    $config = env_config();
    $host = $config['DB_HOST'] ?? 'localhost';
    $port = (int) ($config['DB_PORT'] ?? 3306);
    // Support both the local/XAMPP variable names and the shorter aliases
    // commonly configured on Vercel/Railway.
    $database = $config['DB_DATABASE'] ?? $config['DB_NAME'] ?? 'rmutp_senior_project';
    $username = $config['DB_USERNAME'] ?? $config['DB_USER'] ?? 'root';
    $password = $config['DB_PASSWORD'] ?? $config['DB_PASS'] ?? '';
    $pdo = new PDO(
        "mysql:host={$host};port={$port};dbname={$database};charset=utf8mb4",
        $username,
        $password,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );
    $pdo->exec("SET time_zone = '+07:00'");

    // Local installations may bootstrap/migrate automatically. Hosted requests
    // use an already imported schema, so avoid multiple information_schema and
    // DDL round trips to a remote database on every serverless invocation.
    $autoMigrateValue = $config['DB_AUTO_MIGRATE'] ?? null;
    $hostedRuntime = strtolower((string) ($config['APP_ENV'] ?? '')) === 'production'
        || getenv('VERCEL') !== false;
    $autoMigrate = $autoMigrateValue === null
        ? !$hostedRuntime
        : filter_var($autoMigrateValue, FILTER_VALIDATE_BOOLEAN);
    if (!$autoMigrate) {
        return $pdo;
    }

    $schemaVersion = '20260829_03_password_reset';
    if (database_schema_is_current($pdo, $schemaVersion)) {
        return $pdo;
    }
    ensure_primary_database_schema($pdo);
    normalize_database_collations($pdo);
    $pdo->exec("CREATE TABLE IF NOT EXISTS advisors (
        id VARCHAR(20) PRIMARY KEY,
        name VARCHAR(160) NOT NULL,
        email VARCHAR(160) NOT NULL UNIQUE,
        phone VARCHAR(60) DEFAULT '',
        faculty VARCHAR(180) DEFAULT '',
        department VARCHAR(160) NOT NULL,
        students INT DEFAULT 0,
        status VARCHAR(40) DEFAULT 'Active',
        password_hash VARCHAR(255) DEFAULT '',
        photo VARCHAR(255) DEFAULT 'assets/img/profile-advisor.svg',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    ensure_database_column($pdo, 'advisors', 'faculty', "VARCHAR(180) DEFAULT '' AFTER phone");
    ensure_database_column($pdo, 'advisors', 'password_hash', "VARCHAR(255) DEFAULT '' AFTER status");
    ensure_database_column($pdo, 'advisors', 'photo', "VARCHAR(255) DEFAULT 'assets/img/profile-advisor.svg' AFTER password_hash");
    $projectTableExists = (int) $pdo->query(
        "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'projects'"
    )->fetchColumn() > 0;
    if ($projectTableExists) {
        $pdo->exec('ALTER TABLE projects MODIFY code VARCHAR(40) NULL');
    }
    $pdo->exec("CREATE TABLE IF NOT EXISTS student_advisors (
        student_id VARCHAR(20) NOT NULL,
        advisor_id VARCHAR(20) NOT NULL,
        advisor_role ENUM('chair', 'vice_chair', 'committee') NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (student_id, advisor_role),
        UNIQUE KEY unique_student_advisor (student_id, advisor_id)
    ) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE IF NOT EXISTS advisor_invitations (
        id VARCHAR(20) PRIMARY KEY,
        group_id VARCHAR(20) NOT NULL,
        student_id VARCHAR(20) NOT NULL,
        advisor_id VARCHAR(20) NOT NULL,
        advisor_role ENUM('chair', 'vice_chair', 'committee') NOT NULL,
        status ENUM('Pending', 'Accepted', 'Rejected') DEFAULT 'Pending',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        responded_at TIMESTAMP NULL
    ) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE IF NOT EXISTS group_invitations (
        id VARCHAR(20) PRIMARY KEY,
        group_id VARCHAR(20) NOT NULL,
        invited_student_id VARCHAR(20) NOT NULL,
        invited_by_student_id VARCHAR(20) NOT NULL,
        status ENUM('Pending', 'Accepted', 'Rejected') DEFAULT 'Pending',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        responded_at TIMESTAMP NULL
    ) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE IF NOT EXISTS app_state (
        state_key VARCHAR(40) PRIMARY KEY,
        state_json LONGTEXT NOT NULL,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE IF NOT EXISTS audit_logs (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        actor_type VARCHAR(30) NOT NULL,
        actor_id VARCHAR(20) NOT NULL,
        action VARCHAR(80) NOT NULL,
        entity_type VARCHAR(40) NOT NULL,
        entity_id VARCHAR(40) DEFAULT '',
        details_json TEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_audit_actor (actor_type, actor_id),
        INDEX idx_audit_entity (entity_type, entity_id)
    ) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE IF NOT EXISTS user_sessions (
        session_id VARCHAR(128) PRIMARY KEY,
        user_type ENUM('admin', 'advisor', 'student') NOT NULL,
        user_id VARCHAR(40) NOT NULL,
        ip_address VARCHAR(45) DEFAULT '',
        user_agent VARCHAR(500) DEFAULT '',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        last_activity_at DATETIME NOT NULL,
        expires_at DATETIME NOT NULL,
        INDEX idx_user_sessions_user (user_type, user_id),
        INDEX idx_user_sessions_expires (expires_at)
    ) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE IF NOT EXISTS php_sessions (
        session_id VARCHAR(128) PRIMARY KEY,
        session_data LONGTEXT NOT NULL,
        expires_at DATETIME NOT NULL,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_php_sessions_expires (expires_at)
    ) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE IF NOT EXISTS password_reset_tokens (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_type ENUM('admin', 'advisor', 'student') NOT NULL,
        user_id VARCHAR(40) NOT NULL,
        token_hash CHAR(64) NOT NULL UNIQUE,
        requested_ip VARCHAR(45) DEFAULT '',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        expires_at DATETIME NOT NULL,
        used_at DATETIME NULL,
        INDEX idx_password_reset_user (user_type, user_id),
        INDEX idx_password_reset_expires (expires_at),
        INDEX idx_password_reset_ip_created (requested_ip, created_at)
    ) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE IF NOT EXISTS notification_reads (
        notification_id VARCHAR(20) NOT NULL,
        reader_type ENUM('admin', 'advisor', 'student') NOT NULL,
        reader_id VARCHAR(40) NOT NULL,
        read_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (notification_id, reader_type, reader_id),
        INDEX idx_notification_reads_reader (reader_type, reader_id)
    ) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE IF NOT EXISTS project_groups (
        id VARCHAR(20) PRIMARY KEY,
        name VARCHAR(180) NOT NULL,
        leader_id VARCHAR(20) NOT NULL,
        project_id VARCHAR(20) NULL,
        faculty VARCHAR(180) NOT NULL,
        created_at DATETIME NOT NULL,
        CONSTRAINT fk_group_leader FOREIGN KEY (leader_id) REFERENCES students(id) ON UPDATE CASCADE,
        CONSTRAINT fk_group_project FOREIGN KEY (project_id) REFERENCES projects(id) ON UPDATE CASCADE ON DELETE SET NULL
    ) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE IF NOT EXISTS project_group_members (
        group_id VARCHAR(20) NOT NULL,
        student_id VARCHAR(20) NOT NULL UNIQUE,
        joined_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (group_id, student_id),
        CONSTRAINT fk_member_group FOREIGN KEY (group_id) REFERENCES project_groups(id) ON DELETE CASCADE ON UPDATE CASCADE,
        CONSTRAINT fk_member_student FOREIGN KEY (student_id) REFERENCES students(id) ON UPDATE CASCADE
    ) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE IF NOT EXISTS group_messages (
        id VARCHAR(24) PRIMARY KEY,
        group_id VARCHAR(20) NULL,
        student_id VARCHAR(20) NULL,
        advisor_id VARCHAR(20) NULL,
        sender VARCHAR(180) NOT NULL,
        receiver VARCHAR(180) NOT NULL,
        subject VARCHAR(255) NOT NULL,
        message TEXT NOT NULL,
        attachment VARCHAR(255) DEFAULT '',
        read_status TINYINT(1) DEFAULT 0,
        created_at DATETIME NOT NULL,
        INDEX idx_group_messages_group (group_id),
        INDEX idx_group_messages_student (student_id),
        INDEX idx_group_messages_advisor (advisor_id),
        INDEX idx_group_messages_created (created_at)
    ) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

    ensure_database_column($pdo, 'documents', 'group_id', 'VARCHAR(20) NULL AFTER student_id');
    ensure_database_column($pdo, 'documents', 'chapter', 'TINYINT UNSIGNED NULL AFTER type');
    ensure_database_column($pdo, 'documents', 'approved_at', 'DATETIME NULL AFTER uploaded_at');
    ensure_database_column($pdo, 'comments', 'document_id', 'VARCHAR(20) NULL AFTER student_id');
    ensure_database_column($pdo, 'comments', 'author_id', 'VARCHAR(20) NULL AFTER document_id');
    ensure_database_column($pdo, 'approvals', 'document_id', 'VARCHAR(20) NULL AFTER student_id');
    ensure_database_column($pdo, 'approvals', 'group_id', 'VARCHAR(20) NULL AFTER document_id');
    ensure_database_column($pdo, 'approvals', 'reviewer_id', 'VARCHAR(20) NULL AFTER group_id');
    ensure_database_column($pdo, 'approvals', 'message', "TEXT NULL AFTER status");
    ensure_database_column($pdo, 'approvals', 'approved_at', 'DATETIME NULL AFTER created_at');
    ensure_database_column($pdo, 'notifications', 'group_id', 'VARCHAR(20) NULL AFTER id');
    ensure_database_column($pdo, 'notifications', 'student_id', 'VARCHAR(20) NULL AFTER group_id');
    ensure_database_column($pdo, 'notifications', 'advisor_id', 'VARCHAR(20) NULL AFTER student_id');
    ensure_database_column($pdo, 'notifications', 'scope', "VARCHAR(20) NULL AFTER advisor_id");
    ensure_database_column($pdo, 'notifications', 'read_by', 'LONGTEXT NULL AFTER read_status');

    ensure_database_index($pdo, 'students', 'idx_students_advisor', '`advisor_id`');
    ensure_database_index($pdo, 'students', 'idx_students_project', '`project_id`');
    ensure_database_index($pdo, 'projects', 'idx_projects_student', '`student_id`');
    ensure_database_index($pdo, 'projects', 'idx_projects_advisor', '`advisor_id`');
    ensure_database_index($pdo, 'documents', 'idx_documents_project', '`project_id`');
    ensure_database_index($pdo, 'documents', 'idx_documents_student', '`student_id`');
    ensure_database_index($pdo, 'documents', 'idx_documents_group', '`group_id`');
    ensure_database_index($pdo, 'documents', 'idx_documents_stage_status', '`type`, `chapter`, `status`');
    ensure_database_index($pdo, 'documents', 'idx_documents_uploaded', '`uploaded_at`');
    ensure_database_index($pdo, 'project_groups', 'idx_project_groups_project', '`project_id`');
    ensure_database_index($pdo, 'advisor_invitations', 'idx_advisor_invitations_group', '`group_id`');
    ensure_database_index($pdo, 'advisor_invitations', 'idx_advisor_invitations_student', '`student_id`');
    ensure_database_index($pdo, 'advisor_invitations', 'idx_advisor_invitations_advisor_status', '`advisor_id`, `status`');
    ensure_database_index($pdo, 'group_invitations', 'idx_group_invitations_group', '`group_id`');
    ensure_database_index($pdo, 'group_invitations', 'idx_group_invitations_student_status', '`invited_student_id`, `status`');
    ensure_database_index($pdo, 'group_invitations', 'idx_group_invitations_sender', '`invited_by_student_id`');
    ensure_database_index($pdo, 'notifications', 'idx_notifications_group_created', '`group_id`, `created_at`');
    ensure_database_index($pdo, 'notifications', 'idx_notifications_student_created', '`student_id`, `created_at`');
    ensure_database_index($pdo, 'notifications', 'idx_notifications_advisor_created', '`advisor_id`, `created_at`');
    ensure_database_index($pdo, 'comments', 'idx_comments_student_created', '`student_id`, `created_at`');
    ensure_database_index($pdo, 'comments', 'idx_comments_document_created', '`document_id`, `created_at`');
    ensure_database_index($pdo, 'approvals', 'idx_approvals_student_created', '`student_id`, `created_at`');
    ensure_database_index($pdo, 'approvals', 'idx_approvals_document_created', '`document_id`, `created_at`');
    ensure_database_index($pdo, 'approvals', 'idx_approvals_group_created', '`group_id`, `created_at`');
    ensure_database_index($pdo, 'approvals', 'idx_approvals_reviewer_status', '`reviewer_id`, `status`');
    ensure_database_index($pdo, 'password_reset_tokens', 'idx_password_reset_ip_created', '`requested_ip`, `created_at`');

    ensure_database_foreign_key($pdo, 'students', 'advisor_id', 'advisors', 'fk_students_advisor', 'SET NULL');
    ensure_database_foreign_key($pdo, 'projects', 'student_id', 'students', 'fk_projects_student', 'SET NULL');
    ensure_database_foreign_key($pdo, 'projects', 'advisor_id', 'advisors', 'fk_projects_advisor', 'SET NULL');
    ensure_database_foreign_key($pdo, 'project_groups', 'leader_id', 'students', 'fk_group_leader', 'RESTRICT');
    ensure_database_foreign_key($pdo, 'project_groups', 'project_id', 'projects', 'fk_group_project', 'SET NULL');
    ensure_database_foreign_key($pdo, 'project_group_members', 'group_id', 'project_groups', 'fk_member_group', 'CASCADE');
    ensure_database_foreign_key($pdo, 'project_group_members', 'student_id', 'students', 'fk_member_student', 'RESTRICT');
    ensure_database_foreign_key($pdo, 'student_advisors', 'student_id', 'students', 'fk_student_advisors_student', 'CASCADE');
    ensure_database_foreign_key($pdo, 'student_advisors', 'advisor_id', 'advisors', 'fk_student_advisors_advisor', 'CASCADE');
    ensure_database_foreign_key($pdo, 'advisor_invitations', 'group_id', 'project_groups', 'fk_advisor_invitation_group', 'CASCADE');
    ensure_database_foreign_key($pdo, 'advisor_invitations', 'student_id', 'students', 'fk_advisor_invitation_student', 'CASCADE');
    ensure_database_foreign_key($pdo, 'advisor_invitations', 'advisor_id', 'advisors', 'fk_advisor_invitation_advisor', 'CASCADE');
    ensure_database_foreign_key($pdo, 'group_invitations', 'group_id', 'project_groups', 'fk_group_invitation_group', 'CASCADE');
    ensure_database_foreign_key($pdo, 'group_invitations', 'invited_student_id', 'students', 'fk_group_invitation_student', 'CASCADE');
    ensure_database_foreign_key($pdo, 'group_invitations', 'invited_by_student_id', 'students', 'fk_group_invitation_sender', 'CASCADE');
    ensure_database_foreign_key($pdo, 'group_messages', 'group_id', 'project_groups', 'fk_message_group', 'CASCADE');
    ensure_database_foreign_key($pdo, 'group_messages', 'student_id', 'students', 'fk_message_student', 'SET NULL');
    ensure_database_foreign_key($pdo, 'group_messages', 'advisor_id', 'advisors', 'fk_message_advisor', 'SET NULL');
    ensure_database_foreign_key($pdo, 'documents', 'project_id', 'projects', 'fk_documents_project', 'SET NULL');
    ensure_database_foreign_key($pdo, 'documents', 'student_id', 'students', 'fk_documents_student', 'SET NULL');
    ensure_database_foreign_key($pdo, 'documents', 'group_id', 'project_groups', 'fk_documents_group', 'CASCADE');
    ensure_database_foreign_key($pdo, 'comments', 'student_id', 'students', 'fk_comments_student', 'CASCADE');
    ensure_database_foreign_key($pdo, 'comments', 'document_id', 'documents', 'fk_comments_document', 'CASCADE');
    ensure_database_foreign_key($pdo, 'comments', 'author_id', 'advisors', 'fk_comments_advisor', 'SET NULL');
    ensure_database_foreign_key($pdo, 'approvals', 'student_id', 'students', 'fk_approvals_student', 'CASCADE');
    ensure_database_foreign_key($pdo, 'approvals', 'document_id', 'documents', 'fk_approvals_document', 'CASCADE');
    ensure_database_foreign_key($pdo, 'approvals', 'group_id', 'project_groups', 'fk_approvals_group', 'CASCADE');
    ensure_database_foreign_key($pdo, 'approvals', 'reviewer_id', 'advisors', 'fk_approvals_advisor', 'SET NULL');
    ensure_database_foreign_key($pdo, 'notifications', 'group_id', 'project_groups', 'fk_notifications_group', 'CASCADE');
    ensure_database_foreign_key($pdo, 'notifications', 'student_id', 'students', 'fk_notifications_student', 'CASCADE');
    ensure_database_foreign_key($pdo, 'notifications', 'advisor_id', 'advisors', 'fk_notifications_advisor', 'CASCADE');
    ensure_database_foreign_key($pdo, 'notification_reads', 'notification_id', 'notifications', 'fk_notification_reads_notification', 'CASCADE');
    mark_database_schema_current($pdo, $schemaVersion);
    return $pdo;
}

function sync_student_advisors_to_database(array $student): void
{
    $roles = $student['advisor_roles'] ?? [];
    $pdo = database_connection();
    $pdo->prepare('DELETE FROM student_advisors WHERE student_id = :student_id')
        ->execute(['student_id' => $student['id']]);
    $statement = $pdo->prepare(
        'INSERT INTO student_advisors (student_id, advisor_id, advisor_role)
         VALUES (:student_id, :advisor_id, :advisor_role)'
    );
    foreach (['chair', 'vice_chair', 'committee'] as $role) {
        if (!empty($roles[$role])) {
            $statement->execute([
                'student_id' => $student['id'],
                'advisor_id' => $roles[$role],
                'advisor_role' => $role,
            ]);
        }
    }
}

function sync_advisor_invitations_to_database(array $invitations): void
{
    $statement = database_connection()->prepare(
        'INSERT INTO advisor_invitations
        (id, group_id, student_id, advisor_id, advisor_role, status, created_at, responded_at)
        VALUES (:id, :group_id, :student_id, :advisor_id, :advisor_role, :status, :created_at, :responded_at)
        ON DUPLICATE KEY UPDATE advisor_id = VALUES(advisor_id), advisor_role = VALUES(advisor_role),
        status = VALUES(status), responded_at = VALUES(responded_at)'
    );
    foreach ($invitations as $invitation) {
        $statement->execute([
            'id' => $invitation['id'],
            'group_id' => $invitation['group_id'],
            'student_id' => $invitation['student_id'],
            'advisor_id' => $invitation['advisor_id'],
            'advisor_role' => $invitation['role'],
            'status' => $invitation['status'] ?? 'Pending',
            'created_at' => $invitation['created_at'] ?? date('Y-m-d H:i:s'),
            'responded_at' => ($invitation['responded_at'] ?? '') ?: null,
        ]);
    }
}

function sync_group_invitations_to_database(array $invitations): void
{
    $statement = database_connection()->prepare(
        'INSERT INTO group_invitations
        (id, group_id, invited_student_id, invited_by_student_id, status, created_at, responded_at)
        VALUES (:id, :group_id, :invited_student_id, :invited_by_student_id, :status, :created_at, :responded_at)
        ON DUPLICATE KEY UPDATE status = VALUES(status), responded_at = VALUES(responded_at)'
    );
    foreach ($invitations as $invitation) {
        $statement->execute([
            'id' => $invitation['id'], 'group_id' => $invitation['group_id'],
            'invited_student_id' => $invitation['invited_student_id'],
            'invited_by_student_id' => $invitation['invited_by_student_id'],
            'status' => $invitation['status'] ?? 'Pending',
            'created_at' => $invitation['created_at'] ?? date('Y-m-d H:i:s'),
            'responded_at' => ($invitation['responded_at'] ?? '') ?: null,
        ]);
    }
}

function sync_student_to_database(array $student): void
{
    $sql = 'INSERT INTO students
        (id, code, first_name, last_name, email, phone, faculty, major, year_level, advisor_id, project_id, status, photo)
        VALUES
        (:id, :code, :first_name, :last_name, :email, :phone, :faculty, :major, :year_level, :advisor_id, :project_id, :status, :photo)
        ON DUPLICATE KEY UPDATE
        code = VALUES(code), first_name = VALUES(first_name), last_name = VALUES(last_name),
        email = VALUES(email), phone = VALUES(phone), faculty = VALUES(faculty), major = VALUES(major),
        year_level = VALUES(year_level), advisor_id = VALUES(advisor_id), project_id = VALUES(project_id),
        status = VALUES(status), photo = VALUES(photo)';
    database_connection()->prepare($sql)->execute([
        'id' => $student['id'],
        'code' => $student['code'],
        'first_name' => $student['first_name'],
        'last_name' => $student['last_name'],
        'email' => $student['email'],
        'phone' => $student['phone'] ?? '',
        'faculty' => $student['faculty'] ?? '',
        'major' => $student['major'] ?? '',
        'year_level' => (int) ($student['year_level'] ?? 4),
        'advisor_id' => ($student['advisor_id'] ?? '') ?: null,
        'project_id' => ($student['project_id'] ?? '') ?: null,
        'status' => $student['status'] ?? 'Pending',
        'photo' => $student['photo'] ?? 'assets/img/profile-student.svg',
    ]);
}

function sync_advisor_to_database(array $advisor): void
{
    $sql = 'INSERT INTO advisors
        (id, name, email, phone, faculty, department, students, status, password_hash, photo)
        VALUES
        (:id, :name, :email, :phone, :faculty, :department, :students, :status, :password_hash, :photo)
        ON DUPLICATE KEY UPDATE
        name = VALUES(name), email = VALUES(email), phone = VALUES(phone), faculty = VALUES(faculty),
        department = VALUES(department), students = VALUES(students), status = VALUES(status),
        password_hash = VALUES(password_hash), photo = VALUES(photo)';
    database_connection()->prepare($sql)->execute([
        'id' => $advisor['id'],
        'name' => $advisor['name'],
        'email' => strtolower((string) $advisor['email']),
        'phone' => $advisor['phone'] ?? '',
        'faculty' => $advisor['faculty'] ?? '',
        'department' => $advisor['department'] ?? '',
        'students' => (int) ($advisor['students'] ?? 0),
        'status' => $advisor['status'] ?? 'Active',
        'password_hash' => $advisor['password_hash'] ?? '',
        'photo' => $advisor['photo'] ?? 'assets/img/profile-advisor.svg',
    ]);
}

function sync_project_to_database(array $project): void
{
    $sql = 'INSERT INTO projects
        (id, code, title, student_id, advisor_id, category, status, progress, updated_at)
        VALUES (:id, :code, :title, :student_id, :advisor_id, :category, :status, :progress, :updated_at)
        ON DUPLICATE KEY UPDATE code = VALUES(code), title = VALUES(title), student_id = VALUES(student_id),
        advisor_id = VALUES(advisor_id), category = VALUES(category), status = VALUES(status),
        progress = VALUES(progress), updated_at = VALUES(updated_at)';
    database_connection()->prepare($sql)->execute([
        'id' => $project['id'], 'code' => ($project['code'] ?? '') ?: null, 'title' => $project['title'],
        'student_id' => ($project['student_id'] ?? '') ?: null,
        'advisor_id' => ($project['advisor_id'] ?? '') ?: null,
        'category' => $project['category'] ?? '', 'status' => $project['status'] ?? 'Pending',
        'progress' => (int) ($project['progress'] ?? 0),
        'updated_at' => $project['updated_at'] ?? date('Y-m-d H:i:s'),
    ]);
}

function sync_groups_to_database(array $groups): void
{
    $pdo = database_connection();
    $studentIds = array_fill_keys($pdo->query('SELECT id FROM students')->fetchAll(PDO::FETCH_COLUMN), true);
    $projectIds = array_fill_keys($pdo->query('SELECT id FROM projects')->fetchAll(PDO::FETCH_COLUMN), true);
    $validGroups = array_values(array_filter($groups, static function (array $group) use ($studentIds): bool {
        $leaderId = (string) ($group['leader_id'] ?? '');
        return $leaderId !== '' && isset($studentIds[$leaderId]);
    }));
    $ids = array_column($validGroups, 'id');
    if ($ids) {
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $pdo->prepare("DELETE FROM project_groups WHERE id NOT IN ($placeholders)")->execute($ids);
    } else {
        $pdo->exec('DELETE FROM project_groups');
    }
    $groupStatement = $pdo->prepare(
        'INSERT INTO project_groups (id, name, leader_id, project_id, faculty, created_at)
         VALUES (:id, :name, :leader_id, :project_id, :faculty, :created_at)
         ON DUPLICATE KEY UPDATE name=VALUES(name), leader_id=VALUES(leader_id), project_id=VALUES(project_id), faculty=VALUES(faculty)'
    );
    $memberStatement = $pdo->prepare('INSERT INTO project_group_members (group_id, student_id) VALUES (:group_id, :student_id)');
    foreach ($validGroups as $group) {
        $projectId = (string) ($group['project_id'] ?? '');
        $groupStatement->execute([
            'id' => $group['id'], 'name' => $group['name'], 'leader_id' => $group['leader_id'],
            'project_id' => $projectId !== '' && isset($projectIds[$projectId]) ? $projectId : null,
            'faculty' => $group['faculty'] ?? '',
            'created_at' => $group['created_at'] ?? date('Y-m-d H:i:s'),
        ]);
        $pdo->prepare('DELETE FROM project_group_members WHERE group_id = :group_id')->execute(['group_id' => $group['id']]);
        foreach (array_unique($group['member_ids'] ?? []) as $studentId) {
            if (!isset($studentIds[$studentId])) continue;
            $memberStatement->execute(['group_id' => $group['id'], 'student_id' => $studentId]);
        }
    }
}

function sync_messages_to_database(array $messages): void
{
    $pdo = database_connection();
    $groupIds = array_fill_keys($pdo->query('SELECT id FROM project_groups')->fetchAll(PDO::FETCH_COLUMN), true);
    $studentIds = array_fill_keys($pdo->query('SELECT id FROM students')->fetchAll(PDO::FETCH_COLUMN), true);
    $advisorIds = array_fill_keys($pdo->query('SELECT id FROM advisors')->fetchAll(PDO::FETCH_COLUMN), true);
    $statement = $pdo->prepare(
        'INSERT INTO group_messages (id, group_id, student_id, advisor_id, sender, receiver, subject, message, attachment, read_status, created_at)
         VALUES (:id, :group_id, :student_id, :advisor_id, :sender, :receiver, :subject, :message, :attachment, :read_status, :created_at)
         ON DUPLICATE KEY UPDATE subject=VALUES(subject), message=VALUES(message), attachment=VALUES(attachment), read_status=VALUES(read_status)'
    );
    foreach ($messages as $message) {
        $groupId = (string) ($message['group_id'] ?? '');
        $studentId = (string) ($message['student_id'] ?? '');
        $advisorId = (string) ($message['advisor_id'] ?? '');
        $statement->execute([
            'id' => $message['id'], 'group_id' => $groupId !== '' && isset($groupIds[$groupId]) ? $groupId : null,
            'student_id' => $studentId !== '' && isset($studentIds[$studentId]) ? $studentId : null,
            'advisor_id' => $advisorId !== '' && isset($advisorIds[$advisorId]) ? $advisorId : null,
            'sender' => $message['sender'] ?? '', 'receiver' => $message['receiver'] ?? '',
            'subject' => $message['subject'] ?? '', 'message' => $message['message'] ?? '',
            'attachment' => $message['attachment'] ?? '', 'read_status' => empty($message['read']) ? 0 : 1,
            'created_at' => $message['created_at'] ?? date('Y-m-d H:i:s'),
        ]);
    }
}

function delete_student_from_database(string $id): void
{
    database_connection()->prepare('DELETE FROM student_advisors WHERE student_id = :id')->execute(['id' => $id]);
    $statement = database_connection()->prepare('DELETE FROM students WHERE id = :id');
    $statement->execute(['id' => $id]);
}

function next_student_id(array $students): string
{
    $max = 0;
    foreach ($students as $student) {
        if (preg_match('/(\d+)$/', (string) ($student['id'] ?? ''), $matches)) {
            $max = max($max, (int) $matches[1]);
        }
    }
    $databaseMax = database_connection()->query(
        "SELECT COALESCE(MAX(CAST(SUBSTRING(id, 4) AS UNSIGNED)), 0) FROM students WHERE id LIKE 'STU%'"
    )->fetchColumn();
    $max = max($max, (int) $databaseMax);
    return 'STU' . str_pad((string) ($max + 1), 3, '0', STR_PAD_LEFT);
}

function app_faculties(): array
{
    return ['คณะบริหารธุรกิจ'];
}

function app_majors(): array
{
    return [
        'บช.บ. บัญชีบัณฑิต (ได้รับการรับรองจากสภาวิชาชีพบัญชี)',
        'บธ.บ. สาขาวิชาการจัดการ',
        'บธ.บ. สาขาวิชาการจัดการโลจิสติกส์และโซ่อุปทาน',
        'บธ.บ. สาขาวิชาการตลาด',
        'บธ.บ. สาขาวิชานวัตกรรมทางการเงินและการลงทุน',
        'บธ.บ. สาขาวิชาระบบสารสนเทศและนวัตกรรมดิจิทัล',
        'บธ.บ. สาขาวิชาการจัดการธุรกิจระหว่างประเทศ (หลักสูตรนานาชาติ)',
        'วท.บ. สาขาวิชาการวิเคราะห์ข้อมูลทางธุรกิจ',
        'บธ.บ. สาขาวิชาการเป็นผู้ประกอบการ',
        'บธ.บ. สาขาวิชานวัตกรรมธุรกิจบริการยั่งยืน',
    ];
}

function normalize_faculty_data(array $data): array
{
    $data['groups'] = is_array($data['groups'] ?? null) ? $data['groups'] : [];
    $data['advisor_invitations'] = is_array($data['advisor_invitations'] ?? null) ? $data['advisor_invitations'] : [];
    $data['group_invitations'] = is_array($data['group_invitations'] ?? null) ? $data['group_invitations'] : [];
    $faculties = app_faculties();
    $majors = app_majors();
    foreach (['students', 'advisors'] as $collection) {
        foreach ($data[$collection] ?? [] as $index => $row) {
            if (!in_array($row['faculty'] ?? '', $faculties, true)) {
                $data[$collection][$index]['faculty'] = $faculties[0];
            }
            $academicField = $collection === 'students' ? 'major' : 'department';
            if (!in_array($row[$academicField] ?? '', $majors, true)) {
                $data[$collection][$index][$academicField] = $majors[0];
            }
        }
    }
    return $data;
}

function normalize_legacy_relations(array $data): array
{
    $students = [];
    foreach ($data['students'] ?? [] as $student) {
        if (!empty($student['id'])) $students[(string) $student['id']] = $student;
    }
    $advisors = [];
    foreach ($data['advisors'] ?? [] as $advisor) {
        if (!empty($advisor['id'])) $advisors[(string) $advisor['id']] = $advisor;
    }

    $groups = [];
    foreach ($data['groups'] ?? [] as $group) {
        $leaderId = (string) ($group['leader_id'] ?? '');
        if ($leaderId === '' || !isset($students[$leaderId])) continue;
        $faculty = (string) ($group['faculty'] ?? '');
        $members = array_values(array_filter(
            array_unique(array_map('strval', $group['member_ids'] ?? [])),
            static fn(string $id): bool => isset($students[$id])
                && ($faculty === '' || (string) ($students[$id]['faculty'] ?? '') === $faculty)
        ));
        if (!in_array($leaderId, $members, true)) array_unshift($members, $leaderId);
        $group['member_ids'] = array_slice(array_values(array_unique($members)), 0, 5);
        $roles = [];
        $usedAdvisors = [];
        foreach (['chair', 'vice_chair', 'committee'] as $role) {
            $advisorId = (string) ($group['advisor_roles'][$role] ?? '');
            if ($advisorId === '' || isset($usedAdvisors[$advisorId])) continue;
            $roles[$role] = $advisorId;
            $usedAdvisors[$advisorId] = true;
        }
        $group['advisor_roles'] = $roles;
        $groups[] = $group;
    }
    $data['groups'] = $groups;
    $groupIds = array_fill_keys(array_map(static fn(array $group): string => (string) $group['id'], $groups), true);

    $data['advisor_invitations'] = array_values(array_filter(
        $data['advisor_invitations'] ?? [],
        static fn(array $invitation): bool => !empty($invitation['group_id'])
            && isset($groupIds[(string) $invitation['group_id']])
            && !empty($invitation['advisor_id'])
            && isset($advisors[(string) $invitation['advisor_id']])
    ));
    foreach ($data['advisor_invitations'] as &$invitation) {
        if (($invitation['status'] ?? '') === 'Accepted' && empty($invitation['responded_at'])) {
            $invitation['status'] = 'Pending';
        }
    }
    unset($invitation);

    if (!isset($data['notifications']) || !is_array($data['notifications'])) {
        $data['notifications'] = [];
    }
    foreach ($data['notifications'] as &$notification) {
        if (!empty($notification['group_id']) && !isset($groupIds[(string) $notification['group_id']])) {
            $notification['group_id'] = '';
        }
        $hasAudience = !empty($notification['group_id']) || !empty($notification['student_id'])
            || !empty($notification['advisor_id']) || in_array($notification['scope'] ?? '', ['system', 'legacy'], true);
        if (!$hasAudience) $notification['scope'] = 'legacy';
    }
    unset($notification);

    $data['calendar'] = array_values(array_filter(
        $data['calendar'] ?? [],
        static fn(array $event): bool => !empty($event['group_id']) && isset($groupIds[(string) $event['group_id']])
    ));
    return $data;
}

function merge_database_rows(array $jsonRows, array $databaseRows): array
{
    $jsonById = [];
    foreach ($jsonRows as $row) {
        $jsonById[$row['id'] ?? ''] = $row;
    }
    $merged = [];
    foreach ($databaseRows as $row) {
        $merged[] = array_merge($jsonById[$row['id']] ?? [], $row);
    }
    return $merged;
}

function collection_rows_by_id(array $rows): array
{
    $indexed = [];
    foreach ($rows as $row) {
        if (is_array($row) && isset($row['id'])) {
            $indexed[(string) $row['id']] = $row;
        }
    }
    return $indexed;
}

function changed_collection_rows(array $rows, array $previousRows): array
{
    $previousById = collection_rows_by_id($previousRows);
    return array_values(array_filter(
        $rows,
        static fn(array $row): bool => !isset($previousById[(string) ($row['id'] ?? '')])
            || $previousById[(string) ($row['id'] ?? '')] !== $row
    ));
}

function load_primary_database_collections(array $data): array
{
    $pdo = database_connection();
    $advisors = $pdo->query('SELECT id, name, email, phone, faculty, department, students, status, password_hash, photo, created_at FROM advisors ORDER BY id')->fetchAll();
    $students = $pdo->query('SELECT id, code, first_name, last_name, email, phone, faculty, major, year_level, advisor_id, project_id, status, photo FROM students ORDER BY id')->fetchAll();
    $projects = $pdo->query('SELECT id, code, title, student_id, advisor_id, category, status, progress, updated_at FROM projects ORDER BY id')->fetchAll();
    $data['advisors'] = merge_database_rows($data['advisors'] ?? [], $advisors);
    $data['students'] = merge_database_rows($data['students'] ?? [], $students);
    $data['projects'] = merge_database_rows($data['projects'] ?? [], $projects);
    return $data;
}

function seed_data(): array
{
    $now = date('Y-m-d H:i:s');
    return [
        'students' => [
            ['id' => 'STU001', 'code' => '66010001', 'first_name' => 'Narin', 'last_name' => 'Sukjai', 'email' => 'narin@rmutp.ac.th', 'phone' => '089-100-0001', 'faculty' => 'Industrial Education', 'major' => 'Computer Engineering', 'year_level' => 4, 'advisor_id' => 'ADV001', 'project_id' => 'PRJ001', 'status' => 'Review', 'photo' => 'assets/img/profile-student.svg'],
            ['id' => 'STU002', 'code' => '66010002', 'first_name' => 'Sirinya', 'last_name' => 'Kamon', 'email' => 'sirinya@rmutp.ac.th', 'phone' => '089-100-0002', 'faculty' => 'Engineering', 'major' => 'Information Technology', 'year_level' => 4, 'advisor_id' => 'ADV002', 'project_id' => 'PRJ002', 'status' => 'Approved', 'photo' => 'assets/img/profile-student.svg'],
            ['id' => 'STU003', 'code' => '66010003', 'first_name' => 'Pawat', 'last_name' => 'Rattanakul', 'email' => 'pawat@rmutp.ac.th', 'phone' => '089-100-0003', 'faculty' => 'Business Administration', 'major' => 'Business Computer', 'year_level' => 3, 'advisor_id' => 'ADV003', 'project_id' => 'PRJ003', 'status' => 'Draft', 'photo' => 'assets/img/profile-student.svg'],
            ['id' => 'STU004', 'code' => '66010004', 'first_name' => 'Kanyarat', 'last_name' => 'Meesuk', 'email' => 'kanyarat@rmutp.ac.th', 'phone' => '089-100-0004', 'faculty' => 'Science', 'major' => 'Data Science', 'year_level' => 4, 'advisor_id' => 'ADV001', 'project_id' => 'PRJ004', 'status' => 'Pending', 'photo' => 'assets/img/profile-student.svg'],
            ['id' => 'STU005', 'code' => '66010005', 'first_name' => 'Thanawat', 'last_name' => 'Naksri', 'email' => 'thanawat@rmutp.ac.th', 'phone' => '089-100-0005', 'faculty' => 'Engineering', 'major' => 'Software Engineering', 'year_level' => 4, 'advisor_id' => 'ADV004', 'project_id' => 'PRJ005', 'status' => 'Completed', 'photo' => 'assets/img/profile-student.svg'],
        ],
        'advisors' => [
            ['id' => 'ADV001', 'name' => 'Dr. Anan Chaiyo', 'email' => 'anan@rmutp.ac.th', 'phone' => '02-665-3777', 'department' => 'Computer Engineering', 'students' => 12, 'status' => 'Active'],
            ['id' => 'ADV002', 'name' => 'Asst. Prof. Mali Srisuk', 'email' => 'mali@rmutp.ac.th', 'phone' => '02-665-3778', 'department' => 'Information Technology', 'students' => 9, 'status' => 'Active'],
            ['id' => 'ADV003', 'name' => 'Dr. Preecha Wong', 'email' => 'preecha@rmutp.ac.th', 'phone' => '02-665-3779', 'department' => 'Business Computer', 'students' => 8, 'status' => 'Active'],
            ['id' => 'ADV004', 'name' => 'Lect. Orathai Noon', 'email' => 'orathai@rmutp.ac.th', 'phone' => '02-665-3780', 'department' => 'Software Engineering', 'students' => 11, 'status' => 'Active'],
        ],
        'projects' => [
            ['id' => 'PRJ001', 'code' => 'SP-2026-001', 'title' => 'Smart Senior Project Tracking System', 'student_id' => 'STU001', 'advisor_id' => 'ADV001', 'category' => 'Web Application', 'status' => 'Review', 'progress' => 70, 'updated_at' => $now],
            ['id' => 'PRJ002', 'code' => 'SP-2026-002', 'title' => 'IoT Attendance Gateway', 'student_id' => 'STU002', 'advisor_id' => 'ADV002', 'category' => 'IoT', 'status' => 'Approved', 'progress' => 86, 'updated_at' => $now],
            ['id' => 'PRJ003', 'code' => 'SP-2026-003', 'title' => 'Faculty Document Workflow', 'student_id' => 'STU003', 'advisor_id' => 'ADV003', 'category' => 'Workflow', 'status' => 'Draft', 'progress' => 42, 'updated_at' => $now],
            ['id' => 'PRJ004', 'code' => 'SP-2026-004', 'title' => 'Predictive Student Risk Dashboard', 'student_id' => 'STU004', 'advisor_id' => 'ADV001', 'category' => 'Analytics', 'status' => 'Pending', 'progress' => 55, 'updated_at' => $now],
            ['id' => 'PRJ005', 'code' => 'SP-2026-005', 'title' => 'Mobile Portfolio Review', 'student_id' => 'STU005', 'advisor_id' => 'ADV004', 'category' => 'Mobile', 'status' => 'Completed', 'progress' => 100, 'updated_at' => $now],
        ],
        'documents' => [
            ['id' => 'DOC001', 'project_id' => 'PRJ001', 'student_id' => 'STU001', 'type' => 'proposal', 'title' => 'Project Proposal', 'filename' => 'proposal-prj001.pdf', 'size' => '1.6 MB', 'status' => 'Review', 'uploaded_at' => $now],
            ['id' => 'DOC002', 'project_id' => 'PRJ002', 'student_id' => 'STU002', 'type' => 'draft', 'title' => 'Chapter 1-3 Draft', 'filename' => 'draft-prj002.pdf', 'size' => '2.4 MB', 'status' => 'Approved', 'uploaded_at' => $now],
            ['id' => 'DOC003', 'project_id' => 'PRJ005', 'student_id' => 'STU005', 'type' => 'complete', 'title' => 'Complete Report', 'filename' => 'complete-prj005.pdf', 'size' => '5.1 MB', 'status' => 'Completed', 'uploaded_at' => $now],
        ],
        'notifications' => [
            ['id' => 'NOT001', 'student_id' => 'STU001', 'title' => 'Proposal waiting for review', 'message' => 'Smart Senior Project Tracking System needs advisor approval.', 'type' => 'Approval', 'read_by' => [], 'created_at' => $now],
            ['id' => 'NOT002', 'student_id' => 'STU002', 'title' => 'Draft uploaded', 'message' => 'Chapter 1-3 Draft was uploaded by Sirinya.', 'type' => 'Upload', 'read_by' => [], 'created_at' => $now],
            ['id' => 'NOT003', 'scope' => 'system', 'title' => 'System settings updated', 'message' => 'Academic year was updated to 2026.', 'type' => 'System', 'read_by' => [], 'created_at' => $now],
        ],
        'activities' => [
            ['id' => 'ACT001', 'title' => 'Proposal submitted', 'actor' => 'Narin Sukjai', 'created_at' => $now],
            ['id' => 'ACT002', 'title' => 'Advisor approved draft', 'actor' => 'Asst. Prof. Mali Srisuk', 'created_at' => $now],
            ['id' => 'ACT003', 'title' => 'Final document completed', 'actor' => 'Thanawat Naksri', 'created_at' => $now],
        ],
        'comments' => [
            ['id' => 'COM001', 'student_id' => 'STU001', 'author' => 'Dr. Anan Chaiyo', 'message' => 'Please refine the system architecture diagram.', 'created_at' => $now],
            ['id' => 'COM002', 'student_id' => 'STU001', 'author' => 'Admin Office', 'message' => 'Proposal document was received.', 'created_at' => $now],
        ],
        'approvals' => [
            ['id' => 'APR001', 'student_id' => 'STU001', 'step' => 'Proposal', 'reviewer' => 'Dr. Anan Chaiyo', 'status' => 'Review', 'created_at' => $now],
            ['id' => 'APR002', 'student_id' => 'STU002', 'step' => 'Draft', 'reviewer' => 'Asst. Prof. Mali Srisuk', 'status' => 'Approved', 'created_at' => $now],
            ['id' => 'APR003', 'student_id' => 'STU005', 'step' => 'Complete', 'reviewer' => 'Lect. Orathai Noon', 'status' => 'Completed', 'created_at' => $now],
        ],
        'settings' => [
            'system_name' => 'RMUTP Senior Project System',
            'academic_year' => '2026',
            'approval_mode' => 'advisor-first',
            'notification_refresh' => 15000,
        ],
        'profile' => [
            'name' => 'RMUTP Administrator',
            'email' => 'admin@rmutp.ac.th',
            'role' => 'System Administrator',
            'avatar' => 'assets/img/profile-admin.svg',
        ],
    ];
}

function calculated_project_progress(array $documents): int
{
    $latestByStage = [];
    $draftByChapter = [];
    foreach ($documents as $document) {
        $stage = strtolower((string) ($document['type'] ?? ''));
        if (in_array($stage, ['proposal', 'draft', 'complete'], true)) {
            $latestByStage[$stage] = $document;
        }
        if ($stage === 'draft') {
            $chapter = (int) ($document['chapter'] ?? 0);
            if ($chapter >= 1 && $chapter <= 5) $draftByChapter[$chapter] = $document;
        }
    }

    $isApproved = static fn(?array $document): bool => $document !== null
        && in_array($document['status'] ?? '', ['Approved', 'Completed'], true);

    $proposal = $latestByStage['proposal'] ?? null;
    if ($proposal === null) return 0;
    if (!$isApproved($proposal)) return 15;

    if (!$draftByChapter) return 30;
    $approvedDrafts = count(array_filter($draftByChapter, $isApproved));
    if (count($draftByChapter) < 5 || $approvedDrafts < 5) return 30 + ($approvedDrafts * 8);

    $complete = $latestByStage['complete'] ?? null;
    if ($complete === null) return 70;
    if (!$isApproved($complete)) return 85;

    return 100;
}

function apply_calculated_project_progress(array $data): array
{
    foreach ($data['projects'] ?? [] as $index => $project) {
        $projectId = (string) ($project['id'] ?? '');
        $documents = array_values(array_filter(
            $data['documents'] ?? [],
            static fn(array $document): bool => (string) ($document['project_id'] ?? '') === $projectId
        ));
        $data['projects'][$index]['progress'] = calculated_project_progress($documents);
    }
    return $data;
}

function apply_project_code_eligibility(array $data): array
{
    $eligibleProjectIds = [];
    foreach ($data['groups'] ?? [] as $group) {
        if (empty($group['advisor_roles']['chair']) || empty($group['project_id'])) {
            continue;
        }
        foreach ($data['documents'] ?? [] as $document) {
            $belongsToGroup = ($document['group_id'] ?? '') === ($group['id'] ?? '')
                || (($document['project_id'] ?? '') !== '' && ($document['project_id'] ?? '') === ($group['project_id'] ?? ''));
            if ($belongsToGroup
                && ($document['type'] ?? '') === 'proposal'
                && in_array($document['status'] ?? '', ['Approved', 'Completed'], true)) {
                $eligibleProjectIds[] = $group['project_id'];
                break;
            }
        }
    }
    foreach ($data['projects'] ?? [] as $index => $project) {
        if (in_array($project['id'] ?? '', $eligibleProjectIds, true)) {
            if (empty($project['code'])) {
                $data['projects'][$index]['code'] = 'SP-' . date('Y') . '-' . substr((string) $project['id'], -3);
            }
        } else {
            $data['projects'][$index]['code'] = '';
        }
    }
    return $data;
}

function load_data(): array
{
    $pdo = database_connection();
    $statement = $pdo->prepare('SELECT state_json FROM app_state WHERE state_key = :state_key');
    $statement->execute(['state_key' => 'runtime']);
    $json = $statement->fetchColumn();
    if ($json === false) {
        $legacyPath = app_data_path();
        $legacy = is_file($legacyPath) ? json_decode((string) file_get_contents($legacyPath), true) : null;
        // Database collections must be loaded before they are normalized. Loading
        // them afterwards would overwrite the repaired faculty/major values with
        // legacy seed data and make a fresh installation fail its invariants.
        $databaseData = load_primary_database_collections(is_array($legacy) ? $legacy : seed_data());
        $data = apply_project_code_eligibility(apply_calculated_project_progress(
            normalize_legacy_relations(normalize_faculty_data($databaseData))
        ));
        $data['_runtime_version'] = '20260828_02_fast_state';
        save_data($data);
        return $data;
    }
    $data = json_decode((string) $json, true);
    if (!is_array($data)) {
        $data = seed_data();
    }

    // app_state is the canonical runtime snapshot. Every application write also
    // synchronizes advisors, students and projects to their relational tables,
    // so re-reading those complete tables here only adds three remote database
    // round trips to every page/API request. Normalize older snapshots once,
    // persist the result, then serve subsequent requests with one SELECT.
    $runtimeVersion = '20260828_02_fast_state';
    if (($data['_runtime_version'] ?? '') !== $runtimeVersion) {
        $data = apply_project_code_eligibility(apply_calculated_project_progress(
            normalize_legacy_relations(normalize_faculty_data($data))
        ));
        $data['_runtime_version'] = $runtimeVersion;
        save_data($data);
    }

    return $data;
}

function save_data(array $data): void
{
    $data = apply_project_code_eligibility(apply_calculated_project_progress($data));
    $pdo = database_connection();
    $previousStatement = $pdo->prepare('SELECT state_json FROM app_state WHERE state_key = :state_key');
    $previousStatement->execute(['state_key' => 'runtime']);
    $previousJson = $previousStatement->fetchColumn();
    $previousData = is_string($previousJson) ? json_decode($previousJson, true) : null;
    $previousData = is_array($previousData) ? $previousData : [];

    $advisorsToSync = changed_collection_rows($data['advisors'] ?? [], $previousData['advisors'] ?? []);
    $studentsToSync = changed_collection_rows($data['students'] ?? [], $previousData['students'] ?? []);
    $projectsToSync = changed_collection_rows($data['projects'] ?? [], $previousData['projects'] ?? []);
    $messagesToSync = changed_collection_rows($data['messages'] ?? [], $previousData['messages'] ?? []);
    $advisorInvitationsToSync = changed_collection_rows(
        $data['advisor_invitations'] ?? [],
        $previousData['advisor_invitations'] ?? []
    );
    $groupInvitationsToSync = changed_collection_rows(
        $data['group_invitations'] ?? [],
        $previousData['group_invitations'] ?? []
    );
    $groupsChanged = ($data['groups'] ?? []) !== ($previousData['groups'] ?? []);
    $previousStudents = collection_rows_by_id($previousData['students'] ?? []);

    $ownsTransaction = !$pdo->inTransaction();
    if ($ownsTransaction) $pdo->beginTransaction();
    try {
        foreach ($advisorsToSync as $advisor) sync_advisor_to_database($advisor);
        foreach ($studentsToSync as $student) {
            sync_student_to_database($student);
            $previousStudent = $previousStudents[(string) ($student['id'] ?? '')] ?? [];
            if (($student['advisor_roles'] ?? []) !== ($previousStudent['advisor_roles'] ?? [])) {
                sync_student_advisors_to_database($student);
            }
        }
        foreach ($projectsToSync as $project) sync_project_to_database($project);
        if ($groupsChanged) sync_groups_to_database($data['groups'] ?? []);
        if ($messagesToSync) sync_messages_to_database($messagesToSync);
        if ($advisorInvitationsToSync) sync_advisor_invitations_to_database($advisorInvitationsToSync);
        if ($groupInvitationsToSync) sync_group_invitations_to_database($groupInvitationsToSync);
        $statement = $pdo->prepare(
            'INSERT INTO app_state (state_key, state_json) VALUES (:state_key, :state_json)
             ON DUPLICATE KEY UPDATE state_json = VALUES(state_json)'
        );
        $statement->execute([
            'state_key' => 'runtime',
            'state_json' => json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        ]);
        if ($ownsTransaction) $pdo->commit();
    } catch (Throwable $error) {
        if ($ownsTransaction && $pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    }
}

function audit_log(string $action, string $entityType, string $entityId = '', array $details = []): void
{
    $user = $_SESSION['app_user'] ?? $_SESSION['advisor_user'] ?? [];
    database_connection()->prepare(
        'INSERT INTO audit_logs (actor_type, actor_id, action, entity_type, entity_id, details_json)
         VALUES (:actor_type, :actor_id, :action, :entity_type, :entity_id, :details_json)'
    )->execute([
        'actor_type' => $user['role'] ?? (!empty($_SESSION['advisor_user']) ? 'advisor' : 'system'),
        'actor_id' => $user['id'] ?? 'system', 'action' => $action,
        'entity_type' => $entityType, 'entity_id' => $entityId,
        'details_json' => json_encode($details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    ]);
}

function next_id(array $rows, string $prefix): string
{
    $max = 0;
    foreach ($rows as $row) {
        if (isset($row['id']) && preg_match('/(\d+)$/', $row['id'], $matches)) {
            $max = max($max, (int) $matches[1]);
        }
    }
    return $prefix . str_pad((string) ($max + 1), 3, '0', STR_PAD_LEFT);
}

function issue_project_code_if_eligible(array &$data, string $groupId): bool
{
    $group = find_row($data['groups'] ?? [], $groupId);
    if (!$group || empty($group['advisor_roles']['chair']) || empty($group['project_id'])) {
        return false;
    }
    $proposalApproved = false;
    foreach ($data['documents'] ?? [] as $document) {
        if ((($document['group_id'] ?? '') === $groupId
                || (($document['project_id'] ?? '') !== '' && ($document['project_id'] ?? '') === ($group['project_id'] ?? '')))
            && ($document['type'] ?? '') === 'proposal'
            && in_array($document['status'] ?? '', ['Approved', 'Completed'], true)) {
            $proposalApproved = true;
            break;
        }
    }
    if (!$proposalApproved) {
        return false;
    }
    foreach ($data['projects'] ?? [] as &$project) {
        if (($project['id'] ?? '') !== ($group['project_id'] ?? '')) {
            continue;
        }
        if (empty($project['code'])) {
            $project['code'] = 'SP-' . date('Y') . '-' . substr((string) $project['id'], -3);
            $project['updated_at'] = date('Y-m-d H:i:s');
        }
        unset($project);
        return true;
    }
    unset($project);
    return false;
}

function find_row(array $rows, string $id): ?array
{
    foreach ($rows as $row) {
        if (($row['id'] ?? '') === $id) {
            return $row;
        }
    }
    return null;
}
