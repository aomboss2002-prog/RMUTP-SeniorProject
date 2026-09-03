CREATE TABLE IF NOT EXISTS project_title_checks (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    project_id VARCHAR(20) NOT NULL,
    title VARCHAR(255) NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'queued',
    engine VARCHAR(80) DEFAULT '',
    model VARCHAR(120) NULL,
    max_similarity DECIMAL(7,6) NULL,
    risk_level VARCHAR(20) DEFAULT '',
    matches_json LONGTEXT NULL,
    error_message TEXT NULL,
    attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    started_at DATETIME NULL,
    completed_at DATETIME NULL,
    INDEX idx_title_checks_queue (status, created_at),
    INDEX idx_title_checks_project (project_id, id),
    CONSTRAINT fk_title_checks_project FOREIGN KEY (project_id) REFERENCES projects(id)
        ON UPDATE CASCADE ON DELETE CASCADE
) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
