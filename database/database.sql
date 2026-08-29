CREATE DATABASE IF NOT EXISTS rmutp_senior_project
CHARACTER SET utf8mb4
COLLATE utf8mb4_unicode_ci;

USE rmutp_senior_project;

CREATE TABLE IF NOT EXISTS advisors (
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
);

CREATE TABLE IF NOT EXISTS students (
    id VARCHAR(20) PRIMARY KEY,
    code VARCHAR(40) NULL UNIQUE,
    first_name VARCHAR(120) NOT NULL,
    last_name VARCHAR(120) NOT NULL,
    email VARCHAR(160) NOT NULL UNIQUE,
    phone VARCHAR(60) DEFAULT '',
    faculty VARCHAR(160) DEFAULT '',
    major VARCHAR(160) DEFAULT '',
    year_level INT DEFAULT 4,
    advisor_id VARCHAR(20),
    project_id VARCHAR(20),
    status VARCHAR(40) DEFAULT 'Pending',
    photo VARCHAR(255) DEFAULT 'assets/img/profile-student.svg',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_students_advisor (advisor_id),
    INDEX idx_students_project (project_id),
    CONSTRAINT fk_students_advisor FOREIGN KEY (advisor_id) REFERENCES advisors(id)
        ON UPDATE CASCADE ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS student_advisors (
    student_id VARCHAR(20) NOT NULL,
    advisor_id VARCHAR(20) NOT NULL,
    advisor_role ENUM('chair', 'vice_chair', 'committee') NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (student_id, advisor_role),
    UNIQUE KEY unique_student_advisor (student_id, advisor_id),
    CONSTRAINT fk_student_advisors_student FOREIGN KEY (student_id) REFERENCES students(id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_student_advisors_advisor FOREIGN KEY (advisor_id) REFERENCES advisors(id)
        ON UPDATE CASCADE ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS advisor_invitations (
    id VARCHAR(20) PRIMARY KEY,
    group_id VARCHAR(20) NOT NULL,
    student_id VARCHAR(20) NOT NULL,
    advisor_id VARCHAR(20) NOT NULL,
    advisor_role ENUM('chair', 'vice_chair', 'committee') NOT NULL,
    status ENUM('Pending', 'Accepted', 'Rejected') DEFAULT 'Pending',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    responded_at TIMESTAMP NULL,
    INDEX idx_advisor_invitations_group (group_id),
    INDEX idx_advisor_invitations_student (student_id),
    INDEX idx_advisor_invitations_advisor_status (advisor_id, status)
);

CREATE TABLE IF NOT EXISTS group_invitations (
    id VARCHAR(20) PRIMARY KEY,
    group_id VARCHAR(20) NOT NULL,
    invited_student_id VARCHAR(20) NOT NULL,
    invited_by_student_id VARCHAR(20) NOT NULL,
    status ENUM('Pending', 'Accepted', 'Rejected') DEFAULT 'Pending',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    responded_at TIMESTAMP NULL,
    INDEX idx_group_invitations_group (group_id),
    INDEX idx_group_invitations_student_status (invited_student_id, status),
    INDEX idx_group_invitations_sender (invited_by_student_id)
);

CREATE TABLE IF NOT EXISTS projects (
    id VARCHAR(20) PRIMARY KEY,
    code VARCHAR(40) NOT NULL UNIQUE,
    title VARCHAR(255) NOT NULL,
    student_id VARCHAR(20),
    advisor_id VARCHAR(20),
    category VARCHAR(120) DEFAULT '',
    status VARCHAR(40) DEFAULT 'Pending',
    progress INT DEFAULT 0,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_projects_student (student_id),
    INDEX idx_projects_advisor (advisor_id),
    CONSTRAINT fk_projects_student FOREIGN KEY (student_id) REFERENCES students(id)
        ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT fk_projects_advisor FOREIGN KEY (advisor_id) REFERENCES advisors(id)
        ON UPDATE CASCADE ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS project_groups (
    id VARCHAR(20) PRIMARY KEY,
    name VARCHAR(180) NOT NULL,
    leader_id VARCHAR(20) NOT NULL,
    project_id VARCHAR(20) NULL,
    faculty VARCHAR(180) NOT NULL,
    created_at DATETIME NOT NULL,
    INDEX idx_project_groups_project (project_id),
    CONSTRAINT fk_group_leader FOREIGN KEY (leader_id) REFERENCES students(id)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_group_project FOREIGN KEY (project_id) REFERENCES projects(id)
        ON UPDATE CASCADE ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS project_group_members (
    group_id VARCHAR(20) NOT NULL,
    student_id VARCHAR(20) NOT NULL UNIQUE,
    joined_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (group_id, student_id),
    CONSTRAINT fk_member_group FOREIGN KEY (group_id) REFERENCES project_groups(id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_member_student FOREIGN KEY (student_id) REFERENCES students(id)
        ON UPDATE CASCADE ON DELETE RESTRICT
);

CREATE TABLE IF NOT EXISTS group_messages (
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
    INDEX idx_group_messages_created (created_at),
    CONSTRAINT fk_message_group FOREIGN KEY (group_id) REFERENCES project_groups(id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_message_student FOREIGN KEY (student_id) REFERENCES students(id)
        ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT fk_message_advisor FOREIGN KEY (advisor_id) REFERENCES advisors(id)
        ON UPDATE CASCADE ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS documents (
    id VARCHAR(20) PRIMARY KEY,
    project_id VARCHAR(20),
    student_id VARCHAR(20),
    group_id VARCHAR(20),
    type VARCHAR(40) NOT NULL,
    chapter TINYINT UNSIGNED NULL,
    title VARCHAR(255) NOT NULL,
    filename VARCHAR(255) NOT NULL,
    size VARCHAR(40) DEFAULT '',
    status VARCHAR(40) DEFAULT 'Review',
    uploaded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    approved_at DATETIME NULL,
    INDEX idx_documents_project (project_id),
    INDEX idx_documents_student (student_id),
    INDEX idx_documents_group (group_id),
    INDEX idx_documents_stage_status (type, chapter, status),
    INDEX idx_documents_uploaded (uploaded_at),
    CONSTRAINT fk_documents_project FOREIGN KEY (project_id) REFERENCES projects(id)
        ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT fk_documents_student FOREIGN KEY (student_id) REFERENCES students(id)
        ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT fk_documents_group FOREIGN KEY (group_id) REFERENCES project_groups(id)
        ON UPDATE CASCADE ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS notifications (
    id VARCHAR(20) PRIMARY KEY,
    group_id VARCHAR(20) NULL,
    student_id VARCHAR(20) NULL,
    advisor_id VARCHAR(20) NULL,
    scope VARCHAR(20) NULL,
    title VARCHAR(255) NOT NULL,
    message TEXT NOT NULL,
    type VARCHAR(80) DEFAULT 'System',
    read_status TINYINT(1) DEFAULT 0,
    read_by LONGTEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_notifications_group_created (group_id, created_at),
    INDEX idx_notifications_student_created (student_id, created_at),
    INDEX idx_notifications_advisor_created (advisor_id, created_at),
    CONSTRAINT fk_notifications_group FOREIGN KEY (group_id) REFERENCES project_groups(id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_notifications_student FOREIGN KEY (student_id) REFERENCES students(id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_notifications_advisor FOREIGN KEY (advisor_id) REFERENCES advisors(id)
        ON UPDATE CASCADE ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS activities (
    id VARCHAR(20) PRIMARY KEY,
    title VARCHAR(255) NOT NULL,
    actor VARCHAR(160) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS comments (
    id VARCHAR(20) PRIMARY KEY,
    student_id VARCHAR(20) NOT NULL,
    document_id VARCHAR(20) NULL,
    author_id VARCHAR(20) NULL,
    author VARCHAR(160) NOT NULL,
    message TEXT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_comments_student_created (student_id, created_at),
    INDEX idx_comments_document_created (document_id, created_at),
    CONSTRAINT fk_comments_student FOREIGN KEY (student_id) REFERENCES students(id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_comments_document FOREIGN KEY (document_id) REFERENCES documents(id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_comments_advisor FOREIGN KEY (author_id) REFERENCES advisors(id)
        ON UPDATE CASCADE ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS approvals (
    id VARCHAR(20) PRIMARY KEY,
    student_id VARCHAR(20) NOT NULL,
    document_id VARCHAR(20) NULL,
    group_id VARCHAR(20) NULL,
    reviewer_id VARCHAR(20) NULL,
    step VARCHAR(120) NOT NULL,
    reviewer VARCHAR(160) NOT NULL,
    status VARCHAR(40) DEFAULT 'Review',
    message TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    approved_at DATETIME NULL,
    INDEX idx_approvals_student_created (student_id, created_at),
    INDEX idx_approvals_document_created (document_id, created_at),
    INDEX idx_approvals_group_created (group_id, created_at),
    INDEX idx_approvals_reviewer_status (reviewer_id, status),
    CONSTRAINT fk_approvals_student FOREIGN KEY (student_id) REFERENCES students(id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_approvals_document FOREIGN KEY (document_id) REFERENCES documents(id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_approvals_group FOREIGN KEY (group_id) REFERENCES project_groups(id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_approvals_advisor FOREIGN KEY (reviewer_id) REFERENCES advisors(id)
        ON UPDATE CASCADE ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS app_state (
    state_key VARCHAR(40) PRIMARY KEY,
    state_json LONGTEXT NOT NULL,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS schema_migrations (
    version VARCHAR(80) PRIMARY KEY,
    applied_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS audit_logs (
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
);

CREATE TABLE IF NOT EXISTS user_sessions (
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
);

CREATE TABLE IF NOT EXISTS php_sessions (
    session_id VARCHAR(128) PRIMARY KEY,
    session_data LONGTEXT NOT NULL,
    expires_at DATETIME NOT NULL,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_php_sessions_expires (expires_at)
);

CREATE TABLE IF NOT EXISTS password_reset_tokens (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_type ENUM('admin', 'advisor', 'student') NOT NULL,
    user_id VARCHAR(40) NOT NULL,
    token_hash CHAR(64) NOT NULL UNIQUE,
    requested_ip VARCHAR(45) DEFAULT '',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    expires_at DATETIME NOT NULL,
    used_at DATETIME NULL,
    INDEX idx_password_reset_user (user_type, user_id),
    INDEX idx_password_reset_expires (expires_at)
);

CREATE TABLE IF NOT EXISTS notification_reads (
    notification_id VARCHAR(20) NOT NULL,
    reader_type ENUM('admin', 'advisor', 'student') NOT NULL,
    reader_id VARCHAR(40) NOT NULL,
    read_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (notification_id, reader_type, reader_id),
    INDEX idx_notification_reads_reader (reader_type, reader_id),
    CONSTRAINT fk_notification_reads_notification
        FOREIGN KEY (notification_id) REFERENCES notifications(id)
        ON UPDATE CASCADE ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS settings (
    setting_key VARCHAR(80) PRIMARY KEY,
    setting_value VARCHAR(255) NOT NULL
);

INSERT INTO advisors (id, name, email, phone, faculty, department, students, status) VALUES
('ADV001', 'Dr. Anan Chaiyo', 'anan@rmutp.ac.th', '02-665-3777', 'คณะบริหารธุรกิจ', 'บช.บ. บัญชีบัณฑิต (ได้รับการรับรองจากสภาวิชาชีพบัญชี)', 12, 'Active'),
('ADV002', 'Asst. Prof. Mali Srisuk', 'mali@rmutp.ac.th', '02-665-3778', 'คณะบริหารธุรกิจ', 'บธ.บ. สาขาวิชาการจัดการ', 9, 'Active'),
('ADV003', 'Dr. Preecha Wong', 'preecha@rmutp.ac.th', '02-665-3779', 'คณะบริหารธุรกิจ', 'บธ.บ. สาขาวิชาการตลาด', 8, 'Active'),
('ADV004', 'Lect. Orathai Noon', 'orathai@rmutp.ac.th', '02-665-3780', 'คณะบริหารธุรกิจ', 'บธ.บ. สาขาวิชาระบบสารสนเทศและนวัตกรรมดิจิทัล', 11, 'Active')
ON DUPLICATE KEY UPDATE name = VALUES(name), email = VALUES(email), department = VALUES(department);

INSERT INTO students (id, code, first_name, last_name, email, phone, faculty, major, year_level, advisor_id, project_id, status) VALUES
('STU001', '66010001', 'Narin', 'Sukjai', 'narin@rmutp.ac.th', '089-100-0001', 'คณะบริหารธุรกิจ', 'บช.บ. บัญชีบัณฑิต (ได้รับการรับรองจากสภาวิชาชีพบัญชี)', 4, 'ADV001', 'PRJ001', 'Review'),
('STU002', '66010002', 'Sirinya', 'Kamon', 'sirinya@rmutp.ac.th', '089-100-0002', 'คณะบริหารธุรกิจ', 'บธ.บ. สาขาวิชาการจัดการ', 4, 'ADV002', 'PRJ002', 'Approved'),
('STU003', '66010003', 'Pawat', 'Rattanakul', 'pawat@rmutp.ac.th', '089-100-0003', 'คณะบริหารธุรกิจ', 'บธ.บ. สาขาวิชาการตลาด', 3, 'ADV003', 'PRJ003', 'Draft'),
('STU004', '66010004', 'Kanyarat', 'Meesuk', 'kanyarat@rmutp.ac.th', '089-100-0004', 'คณะบริหารธุรกิจ', 'วท.บ. สาขาวิชาการวิเคราะห์ข้อมูลทางธุรกิจ', 4, 'ADV001', 'PRJ004', 'Pending'),
('STU005', '66010005', 'Thanawat', 'Naksri', 'thanawat@rmutp.ac.th', '089-100-0005', 'คณะบริหารธุรกิจ', 'บธ.บ. สาขาวิชาระบบสารสนเทศและนวัตกรรมดิจิทัล', 4, 'ADV004', 'PRJ005', 'Completed')
ON DUPLICATE KEY UPDATE first_name = VALUES(first_name), last_name = VALUES(last_name), status = VALUES(status);

INSERT INTO projects (id, code, title, student_id, advisor_id, category, status, progress) VALUES
('PRJ001', 'SP-2026-001', 'Smart Senior Project Tracking System', 'STU001', 'ADV001', 'Web Application', 'Review', 70),
('PRJ002', 'SP-2026-002', 'IoT Attendance Gateway', 'STU002', 'ADV002', 'IoT', 'Approved', 86),
('PRJ003', 'SP-2026-003', 'Faculty Document Workflow', 'STU003', 'ADV003', 'Workflow', 'Draft', 42),
('PRJ004', 'SP-2026-004', 'Predictive Student Risk Dashboard', 'STU004', 'ADV001', 'Analytics', 'Pending', 55),
('PRJ005', 'SP-2026-005', 'Mobile Portfolio Review', 'STU005', 'ADV004', 'Mobile', 'Completed', 100)
ON DUPLICATE KEY UPDATE title = VALUES(title), status = VALUES(status), progress = VALUES(progress);

INSERT INTO documents (id, project_id, student_id, type, title, filename, size, status) VALUES
('DOC001', 'PRJ001', 'STU001', 'proposal', 'Project Proposal', 'proposal-prj001.pdf', '1.6 MB', 'Review'),
('DOC002', 'PRJ002', 'STU002', 'draft', 'Chapter 1-3 Draft', 'draft-prj002.pdf', '2.4 MB', 'Approved'),
('DOC003', 'PRJ005', 'STU005', 'complete', 'Complete Report', 'complete-prj005.pdf', '5.1 MB', 'Completed')
ON DUPLICATE KEY UPDATE title = VALUES(title), status = VALUES(status);

INSERT INTO notifications (id, title, message, type, read_status) VALUES
('NOT001', 'Proposal waiting for review', 'Smart Senior Project Tracking System needs advisor approval.', 'Approval', 0),
('NOT002', 'Draft uploaded', 'Chapter 1-3 Draft was uploaded by Sirinya.', 'Upload', 0),
('NOT003', 'System settings updated', 'Academic year was updated to 2026.', 'System', 1)
ON DUPLICATE KEY UPDATE message = VALUES(message), read_status = VALUES(read_status);

INSERT INTO activities (id, title, actor) VALUES
('ACT001', 'Proposal submitted', 'Narin Sukjai'),
('ACT002', 'Advisor approved draft', 'Asst. Prof. Mali Srisuk'),
('ACT003', 'Final document completed', 'Thanawat Naksri')
ON DUPLICATE KEY UPDATE title = VALUES(title), actor = VALUES(actor);

INSERT INTO comments (id, student_id, author, message) VALUES
('COM001', 'STU001', 'Dr. Anan Chaiyo', 'Please refine the system architecture diagram.'),
('COM002', 'STU001', 'Admin Office', 'Proposal document was received.')
ON DUPLICATE KEY UPDATE message = VALUES(message);

INSERT INTO approvals (id, student_id, step, reviewer, status) VALUES
('APR001', 'STU001', 'Proposal', 'Dr. Anan Chaiyo', 'Review'),
('APR002', 'STU002', 'Draft', 'Asst. Prof. Mali Srisuk', 'Approved'),
('APR003', 'STU005', 'Complete', 'Lect. Orathai Noon', 'Completed')
ON DUPLICATE KEY UPDATE status = VALUES(status);

INSERT INTO settings (setting_key, setting_value) VALUES
('system_name', 'RMUTP Senior Project System'),
('academic_year', '2026'),
('approval_mode', 'advisor-first'),
('notification_refresh', '15000')
ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value);
