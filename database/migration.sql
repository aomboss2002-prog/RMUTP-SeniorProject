USE rmutp_senior_project;

CREATE TABLE IF NOT EXISTS app_state (
    state_key VARCHAR(40) PRIMARY KEY,
    state_json LONGTEXT NOT NULL,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS project_groups (
    id VARCHAR(20) PRIMARY KEY,
    name VARCHAR(180) NOT NULL,
    leader_id VARCHAR(20) NOT NULL,
    project_id VARCHAR(20),
    faculty VARCHAR(180) NOT NULL,
    created_at DATETIME NOT NULL,
    CONSTRAINT fk_group_leader FOREIGN KEY (leader_id) REFERENCES students(id) ON UPDATE CASCADE,
    CONSTRAINT fk_group_project FOREIGN KEY (project_id) REFERENCES projects(id) ON UPDATE CASCADE ON DELETE SET NULL
) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS project_group_members (
    group_id VARCHAR(20) NOT NULL,
    student_id VARCHAR(20) NOT NULL UNIQUE,
    joined_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (group_id, student_id),
    CONSTRAINT fk_member_group FOREIGN KEY (group_id) REFERENCES project_groups(id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_member_student FOREIGN KEY (student_id) REFERENCES students(id) ON UPDATE CASCADE
) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS group_messages (
    id VARCHAR(24) PRIMARY KEY,
    group_id VARCHAR(20), student_id VARCHAR(20), advisor_id VARCHAR(20),
    sender VARCHAR(180) NOT NULL, receiver VARCHAR(180) NOT NULL,
    subject VARCHAR(255) NOT NULL, message TEXT NOT NULL,
    attachment VARCHAR(255) DEFAULT '', read_status TINYINT(1) DEFAULT 0,
    created_at DATETIME NOT NULL,
    CONSTRAINT fk_message_group FOREIGN KEY (group_id) REFERENCES project_groups(id) ON DELETE CASCADE,
    CONSTRAINT fk_message_student FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE SET NULL,
    CONSTRAINT fk_message_advisor FOREIGN KEY (advisor_id) REFERENCES advisors(id) ON DELETE SET NULL
) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS audit_logs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    actor_type VARCHAR(30) NOT NULL, actor_id VARCHAR(20) NOT NULL,
    action VARCHAR(80) NOT NULL, entity_type VARCHAR(40) NOT NULL,
    entity_id VARCHAR(40) DEFAULT '', details_json TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_audit_actor (actor_type, actor_id),
    INDEX idx_audit_entity (entity_type, entity_id)
) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
