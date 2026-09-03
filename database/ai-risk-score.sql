CREATE TABLE IF NOT EXISTS project_risk_scores (
    project_id VARCHAR(20) PRIMARY KEY,
    score TINYINT UNSIGNED NOT NULL DEFAULT 0,
    risk_level VARCHAR(20) NOT NULL DEFAULT 'low',
    confidence TINYINT UNSIGNED NOT NULL DEFAULT 0,
    stage VARCHAR(40) NOT NULL DEFAULT 'proposal',
    progress_snapshot TINYINT UNSIGNED NOT NULL DEFAULT 0,
    last_activity_at DATETIME NULL,
    factors_json LONGTEXT NULL,
    recommendation VARCHAR(500) DEFAULT '',
    engine VARCHAR(80) NOT NULL DEFAULT 'behavior-risk-v1',
    calculated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_risk_level_score (risk_level, score),
    INDEX idx_risk_calculated (calculated_at),
    CONSTRAINT fk_risk_project FOREIGN KEY (project_id) REFERENCES projects(id)
        ON UPDATE CASCADE ON DELETE CASCADE
) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
