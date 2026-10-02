<?php
declare(strict_types=1);

const APPLICATION_TABLES = ['activities','advisors','advisor_invitations','approvals','app_state','audit_logs','comments','documents','group_invitations','group_messages','notifications','notification_reads','password_reset_tokens','projects','project_groups','project_group_members','settings','students','student_advisors','user_sessions'];

/** Additive only; data conversion and legacy DROP belong to the explicit CLI migration. */
function consolidated_columns(): array
{
    return [
        'activities' => [
            'progress_id' => 'BIGINT UNSIGNED NULL',
            'activity_type' => "VARCHAR(40) NOT NULL DEFAULT 'activity'",
            'project_id' => 'VARCHAR(20) NULL', 'document_id' => 'VARCHAR(20) NULL',
            'event_type' => 'VARCHAR(40) NULL', 'stage' => 'VARCHAR(30) NULL', 'chapter' => 'TINYINT UNSIGNED NULL',
            'old_value' => 'TINYINT UNSIGNED NULL', 'new_value' => 'TINYINT UNSIGNED NULL',
            'actor_type' => 'VARCHAR(20) NULL', 'actor_id' => 'VARCHAR(40) NULL',
            'event_key' => 'CHAR(64) NULL', 'metadata_json' => 'LONGTEXT NULL', 'occurred_at' => 'DATETIME NULL',
        ],
        'comments' => [
            'project_id' => 'VARCHAR(20) NULL', 'comment_type' => "VARCHAR(30) NOT NULL DEFAULT 'comment'",
            'followup_id' => 'BIGINT UNSIGNED NULL', 'issue' => "VARCHAR(1000) NOT NULL DEFAULT ''",
            'next_action' => "VARCHAR(1000) NOT NULL DEFAULT ''", 'followup_at' => 'DATE NULL',
            'updated_at' => 'DATETIME NULL',
        ],
        'user_sessions' => ['session_data' => 'LONGTEXT NULL'],
    ];
}

function ensure_consolidated_columns(PDO $pdo): void
{
    if ($pdo->inTransaction()) throw new LogicException('Schema changes require a separate migration phase');
    foreach (consolidated_columns() as $table => $columns) {
        foreach ($columns as $column => $definition) ensure_database_column($pdo, $table, $column, $definition);
    }
    $pdo->exec('ALTER TABLE activities MODIFY actor VARCHAR(180) NOT NULL');
    $pdo->exec('ALTER TABLE comments MODIFY student_id VARCHAR(20) NULL');
    $pdo->exec("ALTER TABLE user_sessions MODIFY user_type ENUM('admin','advisor','student') NULL, MODIFY user_id VARCHAR(40) NULL");
    ensure_database_index($pdo, 'activities', 'uq_activity_progress', 'progress_id', true);
    ensure_database_index($pdo, 'activities', 'uq_activity_event', 'event_key', true);
    ensure_database_index($pdo, 'activities', 'idx_activity_project_time', 'project_id, occurred_at, id');
    ensure_database_index($pdo, 'activities', 'idx_activity_type_time', 'activity_type, occurred_at, id');
    ensure_database_index($pdo, 'activities', 'idx_activity_document_time', 'document_id, occurred_at, id');
    ensure_database_index($pdo, 'comments', 'idx_comment_author_time', 'author_id, created_at');
    ensure_database_index($pdo, 'comments', 'idx_comment_followup_date', 'followup_at');
    foreach (['old_value', 'new_value'] as $column) {
        $name = 'chk_activity_' . $column;
        $check = $pdo->prepare("SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE() AND TABLE_NAME='activities' AND CONSTRAINT_NAME=?");
        $check->execute([$name]);
        if (!(int) $check->fetchColumn()) $pdo->exec("ALTER TABLE activities ADD CONSTRAINT {$name} CHECK ({$column} BETWEEN 0 AND 100)");
    }
    ensure_database_index($pdo, 'comments', 'uq_comment_followup', 'followup_id', true);
    ensure_database_index($pdo, 'comments', 'idx_comment_project_time', 'project_id, comment_type, created_at');
    ensure_database_foreign_key($pdo, 'activities', 'project_id', 'projects', 'fk_activity_project', 'CASCADE');
    ensure_database_foreign_key($pdo, 'activities', 'document_id', 'documents', 'fk_activity_document', 'SET NULL');
    ensure_database_foreign_key($pdo, 'comments', 'project_id', 'projects', 'fk_comment_project', 'CASCADE');
}

function progress_history_sql(): string
{
    return "(SELECT progress_id AS id, project_id, document_id, event_type, stage, chapter, old_value AS previous_progress,
        new_value AS current_progress, actor_type, actor_id, actor AS actor_name, occurred_at, event_key, metadata_json, created_at
        FROM activities WHERE activity_type='progress_updated')";
}

function followups_sql(): string
{
    return "(SELECT followup_id AS id, project_id, author_id AS advisor_id, message AS note, issue, next_action,
        followup_at, created_at, updated_at FROM comments WHERE comment_type='advisor_followup')";
}
