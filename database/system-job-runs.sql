CREATE TABLE IF NOT EXISTS system_job_runs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    job_name VARCHAR(80) NOT NULL,
    status ENUM('started', 'success', 'failed') NOT NULL DEFAULT 'started',
    started_at DATETIME NOT NULL,
    finished_at DATETIME NULL,
    duration_ms INT UNSIGNED NULL,
    summary_json TEXT NULL,
    error_code VARCHAR(80) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_system_job_name_started (job_name, started_at),
    INDEX idx_system_job_status_started (status, started_at)
) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
