<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/session.php';

start_app_session();
header('Content-Type: application/json; charset=utf-8');

const ADVISOR_DATA = __DIR__ . '/../data/advisor-data.json';
const APP_DATA = __DIR__ . '/../database/app-data.json';

function respond(array $payload, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function seed_data(): array
{
    return [
        'advisor' => [
            'id' => 'ADV001',
            'name' => 'ผศ.ดร.กิตติพงศ์ ใจดี',
            'department' => 'เทคโนโลยีสารสนเทศ',
            'email' => 'advisor@rmutp.ac.th',
            'phone' => '02-665-3777',
            'photo' => 'assets/img/profile-advisor.svg',
        ],
        'students' => [
            ['id' => 'STU001', 'code' => '66010001', 'name' => 'ณัฐวุฒิ แสงทอง', 'department' => 'เทคโนโลยีสารสนเทศ', 'email' => '66010001@rmutp.ac.th', 'phone' => '089-111-2222', 'photo' => 'assets/img/profile-student.svg', 'advisor_id' => 'ADV001'],
            ['id' => 'STU002', 'code' => '66010002', 'name' => 'ปริญญา วงศ์ชัย', 'department' => 'เทคโนโลยีสารสนเทศ', 'email' => '66010002@rmutp.ac.th', 'phone' => '089-333-4444', 'photo' => 'assets/img/profile-student.svg', 'advisor_id' => 'ADV001'],
            ['id' => 'STU003', 'code' => '66010003', 'name' => 'ศิริพร รัตนาวดี', 'department' => 'ระบบสารสนเทศ', 'email' => '66010003@rmutp.ac.th', 'phone' => '089-555-6666', 'photo' => 'assets/img/profile-student.svg', 'advisor_id' => 'ADV001'],
        ],
        'projects' => [
            ['id' => 'PRJ001', 'student_id' => 'STU001', 'code' => 'SP-2569-001', 'title' => 'ระบบติดตามโครงงานอาวุโสด้วยบาร์โค้ด', 'category' => 'Web Application', 'status' => 'Pending', 'progress' => 65],
            ['id' => 'PRJ002', 'student_id' => 'STU002', 'code' => 'SP-2569-002', 'title' => 'แดชบอร์ดวิเคราะห์ความคืบหน้านักศึกษา', 'category' => 'Data Dashboard', 'status' => 'Review', 'progress' => 45],
            ['id' => 'PRJ003', 'student_id' => 'STU003', 'code' => 'SP-2569-003', 'title' => 'ระบบแจ้งเตือนกำหนดส่งเอกสาร', 'category' => 'Notification System', 'status' => 'Approved', 'progress' => 90],
        ],
        'documents' => [
            ['id' => 'DOC001', 'student_id' => 'STU001', 'project_id' => 'PRJ001', 'type' => 'proposal', 'title' => 'ข้อเสนอโครงงาน', 'filename' => 'sample-proposal.pdf', 'status' => 'Pending', 'uploaded_at' => date('Y-m-d H:i:s', strtotime('-2 days')), 'approved_at' => ''],
            ['id' => 'DOC002', 'student_id' => 'STU001', 'project_id' => 'PRJ001', 'type' => 'draft', 'title' => 'ฉบับร่าง', 'filename' => 'sample-draft.pdf', 'status' => 'Review', 'uploaded_at' => date('Y-m-d H:i:s', strtotime('-1 day')), 'approved_at' => ''],
            ['id' => 'DOC003', 'student_id' => 'STU002', 'project_id' => 'PRJ002', 'type' => 'proposal', 'title' => 'ข้อเสนอโครงงาน', 'filename' => 'sample-proposal.pdf', 'status' => 'Pending', 'uploaded_at' => date('Y-m-d H:i:s', strtotime('-3 hours')), 'approved_at' => ''],
            ['id' => 'DOC004', 'student_id' => 'STU003', 'project_id' => 'PRJ003', 'type' => 'complete', 'title' => 'ฉบับสมบูรณ์', 'filename' => 'sample-complete.pdf', 'status' => 'Approved', 'uploaded_at' => date('Y-m-d H:i:s', strtotime('-5 days')), 'approved_at' => date('Y-m-d H:i:s')],
        ],
        'comments' => [
            ['id' => 'CMT001', 'student_id' => 'STU001', 'document_id' => 'DOC001', 'author_id' => 'ADV001', 'author' => 'ผศ.ดร.กิตติพงศ์ ใจดี', 'message' => 'ควรเพิ่มขอบเขตการทดสอบระบบให้ชัดเจนขึ้น', 'created_at' => date('Y-m-d H:i:s', strtotime('-1 day'))],
        ],
        'messages' => [
            ['id' => 'MSG001', 'student_id' => 'STU001', 'sender' => 'ณัฐวุฒิ แสงทอง', 'receiver' => 'ผศ.ดร.กิตติพงศ์ ใจดี', 'subject' => 'ขอนัดปรึกษา', 'message' => 'ต้องการสอบถามเรื่องเอกสารข้อเสนอครับ', 'attachment' => '', 'read' => false, 'created_at' => date('Y-m-d H:i:s', strtotime('-2 hours'))],
        ],
        'notifications' => [
            ['id' => 'NTF001', 'title' => 'มีเอกสารใหม่', 'message' => 'ณัฐวุฒิ อัปโหลดฉบับร่างใหม่', 'type' => 'Upload', 'read' => false, 'created_at' => date('Y-m-d H:i:s', strtotime('-20 minutes'))],
            ['id' => 'NTF002', 'title' => 'ข้อความจากนักศึกษา', 'message' => 'มีข้อความรออ่าน 1 รายการ', 'type' => 'Message', 'read' => false, 'created_at' => date('Y-m-d H:i:s', strtotime('-2 hours'))],
        ],
        'calendar' => [
            ['date' => date('Y-m-d', strtotime('+1 day')), 'title' => 'นัดตรวจฉบับร่างกับ ณัฐวุฒิ', 'type' => 'ประชุม'],
            ['date' => date('Y-m-d', strtotime('+3 days')), 'title' => 'กำหนดส่งข้อเสนอโครงงาน', 'type' => 'กำหนดส่ง'],
            ['date' => date('Y-m-d', strtotime('+7 days')), 'title' => 'ประชุมติดตามความคืบหน้า', 'type' => 'นัดหมาย'],
        ],
    ];
}

function load_data(): array
{
    $statement = shared_database_connection()->prepare('SELECT state_json FROM app_state WHERE state_key = :state_key');
    $statement->execute(['state_key' => 'advisor_portal']);
    $json = $statement->fetchColumn();
    if ($json === false) {
        $legacy = is_file(ADVISOR_DATA) ? json_decode((string) file_get_contents(ADVISOR_DATA), true) : null;
        $data = is_array($legacy) ? $legacy : seed_data();
        save_data($data);
        return $data;
    }
    return json_decode((string) $json, true) ?: seed_data();
}

function save_data(array $data): void
{
    shared_database_connection()->prepare(
        'INSERT INTO app_state (state_key, state_json) VALUES (:state_key, :state_json)
         ON DUPLICATE KEY UPDATE state_json = VALUES(state_json)'
    )->execute(['state_key' => 'advisor_portal', 'state_json' => json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);
}

function input_json(): array
{
    return json_decode((string) file_get_contents('php://input'), true) ?: $_POST;
}

function advisor_accounts(): array
{
    $appData = shared_app_data();
    return is_array($appData['advisors'] ?? null) ? $appData['advisors'] : [];
}

function shared_app_data(): array
{
    $statement = shared_database_connection()->prepare('SELECT state_json FROM app_state WHERE state_key = :state_key');
    $statement->execute(['state_key' => 'runtime']);
    $appData = json_decode((string) ($statement->fetchColumn() ?: ''), true);
    return is_array($appData) ? $appData : [];
}

function save_shared_app_data(array $appData): void
{
    shared_database_connection()->prepare(
        'INSERT INTO app_state (state_key, state_json) VALUES (:state_key, :state_json)
         ON DUPLICATE KEY UPDATE state_json = VALUES(state_json)'
    )->execute(['state_key' => 'runtime', 'state_json' => json_encode($appData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);
    sync_shared_advisor_links($appData);
}

function advisor_issue_project_code_if_eligible(array &$appData, string $groupId): bool
{
    $group = by_id($appData['groups'] ?? [], $groupId);
    if (!$group || empty($group['advisor_roles']['chair']) || empty($group['project_id'])) {
        return false;
    }
    $proposalApproved = false;
    foreach ($appData['documents'] ?? [] as $document) {
        $belongsToGroup = ($document['group_id'] ?? '') === $groupId
            || (($document['project_id'] ?? '') !== '' && ($document['project_id'] ?? '') === ($group['project_id'] ?? ''));
        if ($belongsToGroup
            && ($document['type'] ?? '') === 'proposal'
            && in_array($document['status'] ?? '', ['Approved', 'Completed'], true)) {
            $proposalApproved = true;
            break;
        }
    }
    if (!$proposalApproved) {
        return false;
    }
    foreach ($appData['projects'] ?? [] as &$project) {
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

function advisor_audit(string $action, string $entityType, string $entityId, array $details = []): void
{
    $pdo = shared_database_connection();
    $pdo->exec("CREATE TABLE IF NOT EXISTS audit_logs (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        actor_type VARCHAR(30) NOT NULL, actor_id VARCHAR(20) NOT NULL,
        action VARCHAR(80) NOT NULL, entity_type VARCHAR(40) NOT NULL,
        entity_id VARCHAR(40) DEFAULT '', details_json TEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_audit_actor (actor_type, actor_id), INDEX idx_audit_entity (entity_type, entity_id)
    ) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $pdo->prepare('INSERT INTO audit_logs (actor_type, actor_id, action, entity_type, entity_id, details_json)
        VALUES (\'advisor\', :actor_id, :action, :entity_type, :entity_id, :details_json)')->execute([
        'actor_id' => $_SESSION['advisor_user']['id'] ?? '', 'action' => $action,
        'entity_type' => $entityType, 'entity_id' => $entityId,
        'details_json' => json_encode($details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    ]);
}

function shared_database_connection(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }
    $config = [];
    foreach (is_file(__DIR__ . '/../.env') ? file(__DIR__ . '/../.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : [] as $line) {
        if (!str_contains($line, '=') || str_starts_with(trim($line), '#')) {
            continue;
        }
        [$key, $value] = array_map('trim', explode('=', $line, 2));
        $config[$key] = trim($value, "\"'");
    }
    foreach (getenv() ?: [] as $key => $value) {
        if (is_string($key) && is_scalar($value)) $config[$key] = (string) $value;
    }
    $pdo = new PDO(
        'mysql:host=' . ($config['DB_HOST'] ?? 'localhost') . ';port=' . (int) ($config['DB_PORT'] ?? 3306) . ';dbname=' . ($config['DB_DATABASE'] ?? $config['DB_NAME'] ?? 'rmutp_senior_project') . ';charset=utf8mb4',
        $config['DB_USERNAME'] ?? $config['DB_USER'] ?? 'root',
        $config['DB_PASSWORD'] ?? $config['DB_PASS'] ?? '',
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    $pdo->exec("SET time_zone = '+07:00'");
    $autoMigrateValue = $config['DB_AUTO_MIGRATE'] ?? null;
    $hostedRuntime = strtolower((string) ($config['APP_ENV'] ?? '')) === 'production'
        || getenv('VERCEL') !== false;
    $autoMigrate = $autoMigrateValue === null
        ? !$hostedRuntime
        : filter_var($autoMigrateValue, FILTER_VALIDATE_BOOLEAN);
    if ($autoMigrate) {
        $pdo->exec("CREATE TABLE IF NOT EXISTS app_state (
            state_key VARCHAR(40) PRIMARY KEY,
            state_json LONGTEXT NOT NULL,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        $pdo->exec("CREATE TABLE IF NOT EXISTS php_sessions (
            session_id VARCHAR(128) PRIMARY KEY,
            session_data LONGTEXT NOT NULL,
            expires_at DATETIME NOT NULL,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_php_sessions_expires (expires_at)
        ) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    }
    return $pdo;
}

function sync_shared_advisor_links(array $appData): void
{
    $pdo = shared_database_connection();
    $invitationStatement = $pdo->prepare(
        'INSERT INTO advisor_invitations
        (id, group_id, student_id, advisor_id, advisor_role, status, created_at, responded_at)
        VALUES (:id, :group_id, :student_id, :advisor_id, :advisor_role, :status, :created_at, :responded_at)
        ON DUPLICATE KEY UPDATE status = VALUES(status), responded_at = VALUES(responded_at)'
    );
    foreach ($appData['advisor_invitations'] ?? [] as $invitation) {
        $invitationStatement->execute([
            'id' => $invitation['id'], 'group_id' => $invitation['group_id'],
            'student_id' => $invitation['student_id'], 'advisor_id' => $invitation['advisor_id'],
            'advisor_role' => $invitation['role'], 'status' => $invitation['status'],
            'created_at' => $invitation['created_at'],
            'responded_at' => ($invitation['responded_at'] ?? '') ?: null,
        ]);
    }
    $deleteStatement = $pdo->prepare('DELETE FROM student_advisors WHERE student_id = :student_id');
    $roleStatement = $pdo->prepare(
        'INSERT INTO student_advisors (student_id, advisor_id, advisor_role)
         VALUES (:student_id, :advisor_id, :advisor_role)'
    );
    foreach ($appData['students'] ?? [] as $student) {
        $deleteStatement->execute(['student_id' => $student['id']]);
        foreach ($student['advisor_roles'] ?? [] as $role => $advisorId) {
            if ($advisorId !== '') {
                $roleStatement->execute(['student_id' => $student['id'], 'advisor_id' => $advisorId, 'advisor_role' => $role]);
            }
        }
    }
}

function by_id(array $items, string $id): ?array
{
    foreach ($items as $item) {
        if (($item['id'] ?? '') === $id) {
            return $item;
        }
    }
    return null;
}

function project_for(array $data, string $studentId): array
{
    foreach ($data['projects'] as $project) {
        if ($project['student_id'] === $studentId) {
            return $project;
        }
    }
    return [];
}

function documents_for(array $data, string $studentId): array
{
    return array_values(array_filter($data['documents'], fn($doc) => $doc['student_id'] === $studentId));
}

function stage_status(array $docs, string $stage): string
{
    if ($stage === 'draft') {
        $chapters = [];
        foreach ($docs as $doc) {
            if (($doc['type'] ?? '') === 'draft') {
                $chapter = (int) ($doc['chapter'] ?? 0);
                if ($chapter >= 1 && $chapter <= 5) $chapters[$chapter] = $doc;
            }
        }
        if (!$chapters) return 'Not Started';
        $approved = count(array_filter($chapters, static fn(array $doc): bool => in_array($doc['status'] ?? '', ['Approved', 'Completed'], true)));
        return count($chapters) === 5 && $approved === 5 ? 'Approved' : 'Pending';
    }
    foreach ($docs as $doc) {
        if ($doc['type'] === $stage) {
            return $doc['status'];
        }
    }
    return 'Not Started';
}

function advisor_students(array $data): array
{
    $advisorId = (string) ($_SESSION['advisor_user']['id'] ?? '');
    $sharedData = shared_app_data();
    if ($advisorId !== '' && !empty($sharedData['students'])) {
        $rows = [];
        foreach ($sharedData['students'] as $student) {
            if (!in_array($advisorId, array_values($student['advisor_roles'] ?? []), true)) {
                continue;
            }
            $project = by_id($sharedData['projects'] ?? [], (string) ($student['project_id'] ?? '')) ?? [];
            $documents = array_values(array_filter(
                $sharedData['documents'] ?? [],
                static fn(array $document): bool => ($document['student_id'] ?? '') === ($student['id'] ?? '')
                    || (!empty($document['group_id']) && ($document['group_id'] ?? '') === (student_group_id($sharedData, (string) ($student['id'] ?? ''))))
            ));
            $student['name'] = trim(($student['first_name'] ?? '') . ' ' . ($student['last_name'] ?? ''));
            $student['project_title'] = $project['title'] ?? '-';
            $student['project_id'] = $project['id'] ?? '';
            $student['progress'] = $project['progress'] ?? 0;
            $student['status'] = $project['status'] ?? 'Not Started';
            $student['proposal'] = stage_status($documents, 'proposal');
            $student['draft'] = stage_status($documents, 'draft');
            $student['complete'] = stage_status($documents, 'complete');
            $rows[] = $student;
        }
        return $rows;
    }
    return array_map(function ($student) use ($data) {
        $project = project_for($data, $student['id']);
        $docs = documents_for($data, $student['id']);
        return array_merge($student, [
            'project_title' => $project['title'] ?? '-',
            'project_id' => $project['id'] ?? '',
            'progress' => $project['progress'] ?? 0,
            'status' => $project['status'] ?? 'Not Started',
            'proposal' => stage_status($docs, 'proposal'),
            'draft' => stage_status($docs, 'draft'),
            'complete' => stage_status($docs, 'complete'),
        ]);
    }, array_values(array_filter($data['students'], fn($student) => $student['advisor_id'] === $data['advisor']['id'])));
}

function student_group_id(array $data, string $studentId): string
{
    foreach ($data['groups'] ?? [] as $group) {
        if (in_array($studentId, $group['member_ids'] ?? [], true)) {
            return (string) ($group['id'] ?? '');
        }
    }
    return '';
}

function add_notification(array &$data, string $title, string $message, string $type = 'Approval'): void
{
    array_unshift($data['notifications'], [
        'id' => 'NTF' . strtoupper(bin2hex(random_bytes(3))),
        'advisor_id' => (string) ($_SESSION['advisor_user']['id'] ?? ''),
        'title' => $title,
        'message' => $message,
        'type' => $type,
        'read' => false,
        'created_at' => date('Y-m-d H:i:s'),
    ]);
}

function advisor_notification_rows(array $data, string $advisorId): array
{
    $groupIds = [];
    $studentIds = [];
    $projectIds = [];
    foreach ($data['groups'] ?? [] as $group) {
        if (!in_array($advisorId, array_values($group['advisor_roles'] ?? []), true)) {
            continue;
        }
        $groupIds[] = (string) ($group['id'] ?? '');
        $projectIds[] = (string) ($group['project_id'] ?? '');
        $studentIds = array_merge($studentIds, $group['member_ids'] ?? []);
    }
    return array_values(array_filter($data['notifications'] ?? [], static function (array $row) use ($advisorId, $groupIds, $studentIds, $projectIds): bool {
        if (($row['advisor_id'] ?? '') !== '') {
            return ($row['advisor_id'] ?? '') === $advisorId;
        }
        return (($row['group_id'] ?? '') !== '' && in_array($row['group_id'], $groupIds, true))
            || (($row['student_id'] ?? '') !== '' && in_array($row['student_id'], $studentIds, true))
            || (($row['project_id'] ?? '') !== '' && in_array($row['project_id'], $projectIds, true));
    }));
}

function add_shared_notification(array &$data, array $notification): void
{
    if (!isset($data['notifications']) || !is_array($data['notifications'])) {
        $data['notifications'] = [];
    }
    array_unshift($data['notifications'], array_merge([
        'id' => 'NOT' . strtoupper(bin2hex(random_bytes(4))),
        'group_id' => '', 'student_id' => '', 'advisor_id' => '',
        'title' => 'การแจ้งเตือน', 'message' => '', 'type' => 'System',
        'read_by' => [], 'created_at' => date('Y-m-d H:i:s'),
    ], $notification));
}

$data = load_data();
$method = $_SERVER['REQUEST_METHOD'];
$path = trim(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), '/');
$endpoint = trim((string) ($_GET['endpoint'] ?? preg_replace('#^.*api/advisor/?#', '', $path)), '/');

if ($endpoint === 'login' && $method === 'POST') {
    $payload = input_json();
    $email = strtolower(trim((string) ($payload['email'] ?? '')));
    $password = (string) ($payload['password'] ?? '');
    if (login_rate_limited($email)) {
        respond(['success' => false, 'message' => 'Too many login attempts. Try again in 15 minutes.'], 429);
    }
    $account = null;
    foreach (advisor_accounts() as $advisor) {
        if (strtolower((string) ($advisor['email'] ?? '')) === $email
            && !empty($advisor['password_hash'])
            && secure_password_verify($password, (string) $advisor['password_hash'])) {
            $account = $advisor;
            break;
        }
    }
    if ($account) {
        record_login_attempt($email, true);
        unset($account['password_hash']);
        $data['advisor'] = array_merge($data['advisor'], $account);
        session_regenerate_id(true);
        $_SESSION['advisor_user'] = [
            'id' => $data['advisor']['id'],
            'name' => $data['advisor']['name'],
            'email' => $data['advisor']['email'],
        ];
        session_write_close();
        respond(['success' => true, 'token' => base64_encode($data['advisor']['id'] . '|' . time()), 'user' => $data['advisor'], 'message' => 'เข้าสู่ระบบสำเร็จ']);
    }
    record_login_attempt($email, false);
    respond(['success' => false, 'message' => 'บัญชีอาจารย์หรือรหัสผ่านไม่ถูกต้อง'], 401);
}

if (empty($_SESSION['advisor_user']['id'])) {
    respond(['success' => false, 'message' => 'Advisor sign-in required.'], 401);
}

require_csrf_token();

if (!empty($_SESSION['advisor_user']['id'])) {
    foreach (advisor_accounts() as $advisor) {
        if (($advisor['id'] ?? '') === $_SESSION['advisor_user']['id']) {
            unset($advisor['password_hash']);
            $data['advisor'] = array_merge($data['advisor'], $advisor);
            break;
        }
    }
}

if ($endpoint === 'invitations') {
    $advisorId = (string) ($_SESSION['advisor_user']['id'] ?? '');
    if ($advisorId === '') {
        respond(['success' => false, 'message' => 'Please sign in as an advisor.'], 401);
    }
    $appData = shared_app_data();
    if ($method === 'GET') {
        $latestInvitations = [];
        foreach ($appData['advisor_invitations'] ?? [] as $invitation) {
            if (($invitation['advisor_id'] ?? '') !== $advisorId) {
                continue;
            }
            $group = by_id($appData['groups'] ?? [], (string) ($invitation['group_id'] ?? '')) ?? [];
            $leader = by_id($appData['students'] ?? [], (string) ($invitation['student_id'] ?? '')) ?? [];
            $invitation['group_name'] = $group['name'] ?? '-';
            $invitation['leader_name'] = trim(($leader['first_name'] ?? '') . ' ' . ($leader['last_name'] ?? ''));
            $invitation['student_code'] = $leader['code'] ?? '';
            $key = ($invitation['group_id'] ?? '') . '|' . ($invitation['role'] ?? '');
            if (($latestInvitations[$key]['status'] ?? '') === 'Accepted'
                && ($invitation['status'] ?? '') !== 'Accepted') {
                continue;
            }
            $latestInvitations[$key] = $invitation;
        }
        respond(['success' => true, 'data' => array_values(array_reverse($latestInvitations))]);
    }
    if ($method === 'POST') {
        $payload = input_json();
        $invitationId = (string) ($payload['invitation_id'] ?? '');
        $action = (string) ($payload['action'] ?? '');
        if (!in_array($action, ['accept', 'reject'], true)) {
            respond(['success' => false, 'message' => 'Invalid invitation action.'], 422);
        }
        $matchedInvitation = null;
        foreach ($appData['advisor_invitations'] as &$invitation) {
            if (($invitation['id'] ?? '') === $invitationId && ($invitation['advisor_id'] ?? '') === $advisorId) {
                if (($invitation['status'] ?? '') !== 'Pending') {
                    respond(['success' => false, 'message' => 'This invitation has already been answered.'], 409);
                }
                $invitation['status'] = $action === 'accept' ? 'Accepted' : 'Rejected';
                $invitation['responded_at'] = date('Y-m-d H:i:s');
                $matchedInvitation = $invitation;
                break;
            }
        }
        unset($invitation);
        if (!$matchedInvitation) {
            respond(['success' => false, 'message' => 'Invitation not found.'], 404);
        }
        if ($action === 'accept') {
            foreach ($appData['groups'] as &$group) {
                if (($group['id'] ?? '') === ($matchedInvitation['group_id'] ?? '')) {
                    $role = $matchedInvitation['role'];
                    $group['advisor_roles'][$role] = $advisorId;
                    if ($role === 'chair') {
                        $group['advisor_id'] = $advisorId;
                    }
                    foreach ($appData['students'] as &$student) {
                        if (in_array($student['id'] ?? '', $group['member_ids'] ?? [], true)) {
                            $student['advisor_roles'][$role] = $advisorId;
                            if ($role === 'chair') {
                                $student['advisor_id'] = $advisorId;
                            }
                        }
                    }
                    unset($student);
                    foreach ($appData['projects'] as &$project) {
                        if (($project['id'] ?? '') === ($group['project_id'] ?? '')) {
                            $project['advisor_roles'][$role] = $advisorId;
                            if ($role === 'chair') {
                                $project['advisor_id'] = $advisorId;
                            }
                            break;
                        }
                    }
                    unset($project);
                    break;
                }
            }
            unset($group);
            advisor_issue_project_code_if_eligible($appData, (string) ($matchedInvitation['group_id'] ?? ''));
        }
        $roleLabels = ['chair' => 'ประธาน', 'vice_chair' => 'รองประธาน', 'committee' => 'กรรมการ'];
        add_shared_notification($appData, [
            'group_id' => $matchedInvitation['group_id'] ?? '',
            'title' => $action === 'accept' ? 'อาจารย์ตอบรับคำเชิญแล้ว' : 'อาจารย์ปฏิเสธคำเชิญ',
            'message' => ($data['advisor']['name'] ?? 'อาจารย์') . ' ' . ($action === 'accept' ? 'ตอบรับ' : 'ปฏิเสธ') . 'ตำแหน่ง ' . ($roleLabels[$matchedInvitation['role'] ?? ''] ?? ($matchedInvitation['role'] ?? '')),
            'type' => 'Invitation', 'read_by' => [$advisorId],
        ]);
        save_shared_app_data($appData);
        advisor_audit('invitation_' . $action, 'advisor_invitation', (string) ($matchedInvitation['id'] ?? ''), ['group_id' => $matchedInvitation['group_id'] ?? '']);
        respond(['success' => true, 'message' => $action === 'accept' ? 'Invitation accepted.' : 'Invitation rejected.']);
    }
}

if ($endpoint === 'groups') {
    $advisorId = (string) ($_SESSION['advisor_user']['id'] ?? '');
    if ($advisorId === '') {
        respond(['success' => false, 'message' => 'Please sign in as an advisor.'], 401);
    }
    $appData = shared_app_data();
    $groups = [];
    $roleLabels = ['chair' => 'ประธาน', 'vice_chair' => 'รองประธาน', 'committee' => 'กรรมการ'];
    foreach ($appData['groups'] ?? [] as $group) {
        $role = array_search($advisorId, $group['advisor_roles'] ?? [], true);
        if ($role === false) {
            continue;
        }
        $members = [];
        foreach ($group['member_ids'] ?? [] as $memberId) {
            $student = by_id($appData['students'] ?? [], (string) $memberId);
            if ($student) {
                unset($student['advisor_roles']);
                $student['name'] = trim(($student['first_name'] ?? '') . ' ' . ($student['last_name'] ?? ''));
                $members[] = $student;
            }
        }
        $project = by_id($appData['projects'] ?? [], (string) ($group['project_id'] ?? '')) ?? [];
        $documents = array_values(array_filter(
            $appData['documents'] ?? [],
            static fn(array $document): bool => ($document['group_id'] ?? '') === ($group['id'] ?? '')
        ));
        $groups[] = [
            'id' => $group['id'],
            'name' => $group['name'],
            'faculty' => $group['faculty'] ?? '',
            'role' => $role,
            'role_label' => $roleLabels[$role] ?? $role,
            'members' => $members,
            'member_count' => count($members),
            'project' => $project,
            'documents' => $documents,
        ];
    }
    respond(['success' => true, 'data' => $groups]);
}

if ($endpoint === 'dashboard') {
    $students = advisor_students($data);
    $appData = shared_app_data();
    $advisorId = (string) ($_SESSION['advisor_user']['id'] ?? '');
    $advisorNotifications = advisor_notification_rows($appData, $advisorId);
    $advisorStudentIds = array_column($students, 'id');
    $advisorComments = array_values(array_filter(
        $appData['comments'] ?? [],
        static fn(array $comment): bool => in_array($comment['student_id'] ?? '', $advisorStudentIds, true)
    ));
    $today = date('Y-m-d');
    $groupIds = [];
    foreach ($appData['groups'] ?? [] as $group) {
        if (in_array($advisorId, array_values($group['advisor_roles'] ?? []), true)) $groupIds[] = $group['id'] ?? '';
    }
    $docs = array_values(array_filter($appData['documents'] ?? [], static fn(array $doc): bool => in_array($doc['group_id'] ?? '', $groupIds, true)));
    respond(['success' => true, 'data' => [
        'advisor' => $data['advisor'],
        'summary' => [
            'total_students' => count($students),
            'waiting_proposal' => count(array_filter($docs, fn($doc) => $doc['type'] === 'proposal' && in_array($doc['status'], ['Pending', 'Review', 'Resubmitted'], true))),
            'waiting_draft' => count(array_filter($docs, fn($doc) => $doc['type'] === 'draft' && in_array($doc['status'], ['Pending', 'Review', 'Resubmitted'], true))),
            'waiting_complete' => count(array_filter($docs, fn($doc) => $doc['type'] === 'complete' && in_array($doc['status'], ['Pending', 'Review', 'Resubmitted'], true))),
            'approved_today' => count(array_filter($docs, fn($doc) => str_starts_with($doc['approved_at'] ?? '', $today) && $doc['status'] === 'Approved')),
        ],
        'students' => $students,
        'activities' => array_slice(array_merge($advisorNotifications, $advisorComments), 0, 6),
        'notifications' => array_slice($advisorNotifications, 0, 5),
    ]]);
}

if ($endpoint === 'students') {
    respond(['success' => true, 'data' => advisor_students($data)]);
}

if (preg_match('#^student/([^/]+)$#', $endpoint, $matches)) {
    $advisorId = (string) ($_SESSION['advisor_user']['id'] ?? '');
    $appData = shared_app_data();
    $student = by_id($appData['students'] ?? [], $matches[1]);
    if (!$student) {
        respond(['success' => false, 'message' => 'Student not found.'], 404);
    }
    $group = null;
    foreach ($appData['groups'] ?? [] as $candidateGroup) {
        if (in_array($student['id'] ?? '', $candidateGroup['member_ids'] ?? [], true)) {
            $group = $candidateGroup;
            break;
        }
    }
    $assignedAdvisorIds = $group['advisor_roles'] ?? $student['advisor_roles'] ?? [];
    if (!in_array($advisorId, array_values($assignedAdvisorIds), true)) {
        respond(['success' => false, 'message' => 'You do not have access to this student group.'], 403);
    }
    $projectId = $group['project_id'] ?? $student['project_id'] ?? '';
    $project = by_id($appData['projects'] ?? [], (string) $projectId) ?? [];
    $documents = array_values(array_filter(
        $appData['documents'] ?? [],
        static fn(array $document): bool => $group
            ? (($document['group_id'] ?? '') === ($group['id'] ?? ''))
            : (($document['student_id'] ?? '') === ($student['id'] ?? ''))
    ));
    $memberIds = $group['member_ids'] ?? [$student['id']];
    $members = [];
    foreach ($memberIds as $memberId) {
        $member = by_id($appData['students'] ?? [], (string) $memberId);
        if ($member) {
            $member['name'] = trim(($member['first_name'] ?? '') . ' ' . ($member['last_name'] ?? ''));
            $members[] = $member;
        }
    }
    $comments = array_values(array_filter(
        $appData['comments'] ?? [],
        static fn(array $comment): bool => in_array($comment['student_id'] ?? '', $memberIds, true)
    ));
    $timeline = array_map(static fn(array $document): array => [
        'title' => $document['title'] ?? ucfirst((string) ($document['type'] ?? 'Document')),
        'status' => $document['status'] ?? 'Pending',
        'date' => $document['uploaded_at'] ?? '',
    ], $documents);
    $student['name'] = trim(($student['first_name'] ?? '') . ' ' . ($student['last_name'] ?? ''));
    $student['department'] = $student['major'] ?? '';
    respond(['success' => true, 'data' => compact('student', 'group', 'members', 'project', 'documents', 'comments', 'timeline')]);
}

if (preg_match('#^legacy-student/([^/]+)$#', $endpoint, $matches)) {
    $student = by_id($data['students'], $matches[1]);
    if (!$student) {
        respond(['success' => false, 'message' => 'ไม่พบนักศึกษา'], 404);
    }
    $project = project_for($data, $student['id']);
    $documents = documents_for($data, $student['id']);
    respond(['success' => true, 'data' => [
        'student' => $student,
        'project' => $project,
        'documents' => $documents,
        'comments' => array_values(array_filter($data['comments'], fn($comment) => $comment['student_id'] === $student['id'])),
        'timeline' => [
            ['title' => 'ส่งข้อเสนอโครงงาน', 'status' => stage_status($documents, 'proposal'), 'date' => $documents[0]['uploaded_at'] ?? ''],
            ['title' => 'ส่งฉบับร่าง', 'status' => stage_status($documents, 'draft'), 'date' => $documents[1]['uploaded_at'] ?? ''],
            ['title' => 'ส่งฉบับสมบูรณ์', 'status' => stage_status($documents, 'complete'), 'date' => $documents[2]['uploaded_at'] ?? ''],
            ['title' => 'สร้างบาร์โค้ด', 'status' => !empty($project['code']) ? 'Completed' : 'Not Started', 'date' => date('Y-m-d')],
        ],
    ]]);
}

if (preg_match('#^project/([^/]+)$#', $endpoint, $matches)) {
    $project = by_id($data['projects'], $matches[1]);
    respond($project ? ['success' => true, 'data' => $project] : ['success' => false, 'message' => 'ไม่พบโครงงาน'], $project ? 200 : 404);
}

if (preg_match('#^(proposal|draft|complete)/(approve|reject|revision)$#', $endpoint, $matches) && $method === 'POST') {
    [$stage, $action] = [$matches[1], $matches[2]];
    $payload = input_json();
    $advisorId = (string) ($_SESSION['advisor_user']['id'] ?? '');
    $appData = shared_app_data();
    if (!isset($appData['documents']) || !is_array($appData['documents'])) {
        $appData['documents'] = [];
    }
    foreach ($appData['documents'] as &$document) {
        if (($document['id'] ?? '') !== ($payload['document_id'] ?? '') || ($document['type'] ?? '') !== $stage) {
            continue;
        }
        $group = by_id($appData['groups'] ?? [], (string) ($document['group_id'] ?? ''));
        if (!$group || !in_array($advisorId, array_values($group['advisor_roles'] ?? []), true)) {
            respond(['success' => false, 'message' => 'You do not have access to this group document.'], 403);
        }
        if (in_array($document['status'] ?? '', ['Approved', 'Completed'], true)) {
            respond([
                'success' => false,
                'message' => 'ข้อเสนอโครงงานผ่านแล้วและถูกล็อก ไม่สามารถเปลี่ยนผลพิจารณาได้',
            ], 409);
        }
        $document['status'] = $action === 'approve'
            ? 'Approved'
            : ($action === 'reject' ? 'Rejected' : 'NeedsRevision');
        $document['approved_at'] = date('Y-m-d H:i:s');
        if ($stage === 'proposal' && $action === 'approve') {
            advisor_issue_project_code_if_eligible($appData, (string) ($group['id'] ?? ''));
        }
        if (!empty($payload['comment'])) {
            $appData['comments'][] = [
                'id' => 'COM' . strtoupper(bin2hex(random_bytes(4))),
                'student_id' => $document['student_id'] ?? $group['leader_id'],
                'document_id' => $document['id'], 'author_id' => $advisorId,
                'author' => $data['advisor']['name'], 'message' => trim((string) $payload['comment']),
                'created_at' => date('Y-m-d H:i:s'),
            ];
        }
        $actionLabels = [
            'approve' => 'อนุมัติแล้ว',
            'reject' => 'ยังไม่ผ่าน',
            'revision' => 'ให้กลับไปแก้ไข',
        ];
        add_shared_notification($appData, [
            'group_id' => $group['id'] ?? '',
            'document_id' => $document['id'] ?? '',
            'document_type' => $stage,
            'decision_status' => $document['status'],
            'title' => 'ผลการพิจารณา: ' . ($document['title'] ?? ucfirst($stage)),
            'message' => ($data['advisor']['name'] ?? 'อาจารย์') . ' เปลี่ยนสถานะเป็น ' . ($actionLabels[$action] ?? $document['status']),
            'detail' => trim((string) ($payload['comment'] ?? '')),
            'advisor_name' => $data['advisor']['name'] ?? 'อาจารย์',
            'type' => 'Approval', 'read_by' => [$advisorId],
        ]);
        save_shared_app_data($appData);
        advisor_audit('document_' . $action, 'document', (string) ($document['id'] ?? ''), ['status' => $document['status'] ?? '']);
        respond(['success' => true, 'data' => $document, 'message' => 'Document review saved.']);
    }
    unset($document);
    respond(['success' => false, 'message' => 'Document not found.'], 404);
}

if (preg_match('#^legacy-(proposal|draft|complete)/(approve|reject|revision)$#', $endpoint, $matches) && $method === 'POST') {
    [$stage, $action] = [$matches[1], $matches[2]];
    $payload = input_json();
    foreach ($data['documents'] as &$doc) {
        if ($doc['id'] === ($payload['document_id'] ?? '') && $doc['type'] === $stage) {
            if (in_array($doc['status'] ?? '', ['Approved', 'Completed'], true)) {
                respond([
                    'success' => false,
                    'message' => 'ข้อเสนอโครงงานผ่านแล้วและถูกล็อก ไม่สามารถเปลี่ยนผลพิจารณาได้',
                ], 409);
            }
            $doc['status'] = $action === 'approve'
                ? 'Approved'
                : ($action === 'reject' ? 'Rejected' : 'NeedsRevision');
            $doc['approved_at'] = date('Y-m-d H:i:s');
            if (!empty($payload['comment'])) {
                $data['comments'][] = ['id' => 'CMT' . strtoupper(bin2hex(random_bytes(3))), 'student_id' => $doc['student_id'], 'document_id' => $doc['id'], 'author_id' => $data['advisor']['id'], 'author' => $data['advisor']['name'], 'message' => $payload['comment'], 'created_at' => date('Y-m-d H:i:s')];
            }
            add_notification($data, 'อัปเดตผลการพิจารณา', 'เอกสาร ' . $doc['title'] . ' ถูกปรับสถานะเป็น ' . $doc['status']);
            save_data($data);
            respond(['success' => true, 'data' => $doc, 'message' => 'บันทึกผลการพิจารณาเรียบร้อย']);
        }
    }
    respond(['success' => false, 'message' => 'ไม่พบเอกสาร'], 404);
}

if ($endpoint === 'comment' && $method === 'POST') {
    $payload = input_json();
    $comment = ['id' => 'CMT' . strtoupper(bin2hex(random_bytes(3))), 'student_id' => $payload['student_id'] ?? '', 'document_id' => $payload['document_id'] ?? '', 'author_id' => $data['advisor']['id'], 'author' => $data['advisor']['name'], 'message' => $payload['message'] ?? '', 'created_at' => date('Y-m-d H:i:s')];
    $data['comments'][] = $comment;
    add_notification($data, 'ความคิดเห็นใหม่', 'เพิ่มความคิดเห็นให้นักศึกษาแล้ว', 'Comment');
    save_data($data);
    respond(['success' => true, 'data' => $comment, 'message' => 'เพิ่มความคิดเห็นเรียบร้อย']);
}

if ($endpoint === 'messages') {
    $advisorId = (string) ($_SESSION['advisor_user']['id'] ?? '');
    $appData = shared_app_data();
    $messages = array_values(array_filter(
        $appData['messages'] ?? [],
        static fn(array $message): bool => ($message['advisor_id'] ?? '') === $advisorId
    ));
    respond(['success' => true, 'data' => array_reverse($messages)]);
}

if ($endpoint === 'message' && $method === 'POST') {
    $payload = input_json();
    $advisorId = (string) ($_SESSION['advisor_user']['id'] ?? '');
    $appData = shared_app_data();
    $group = by_id($appData['groups'] ?? [], (string) ($payload['group_id'] ?? ''));
    if (!$group || !in_array($advisorId, array_values($group['advisor_roles'] ?? []), true)) {
        respond(['success' => false, 'message' => 'You can only message groups that accepted your invitation.'], 403);
    }
    $subject = trim((string) ($payload['subject'] ?? ''));
    $messageText = trim((string) ($payload['message'] ?? ''));
    if ($subject === '' || $messageText === '') {
        respond(['success' => false, 'message' => 'Please enter a subject and message.'], 422);
    }
    $message = [
        'id' => 'MSG' . strtoupper(bin2hex(random_bytes(4))),
        'group_id' => $group['id'], 'student_id' => '', 'advisor_id' => $advisorId,
        'sender' => $data['advisor']['name'],
        'receiver' => $group['name'] ?? 'Project group',
        'subject' => $subject, 'message' => $messageText,
        'attachment' => '', 'read' => false,
        'created_at' => date('Y-m-d H:i:s'),
    ];
    $appData['messages'][] = $message;
    add_shared_notification($appData, [
        'group_id' => $group['id'] ?? '',
        'title' => 'ข้อความใหม่: ' . $subject,
        'message' => ($data['advisor']['name'] ?? 'อาจารย์') . ' ส่งข้อความถึงกลุ่ม ' . ($group['name'] ?? ''),
        'type' => 'Message', 'read_by' => [$advisorId],
    ]);
    save_shared_app_data($appData);
    advisor_audit('send_message', 'group', (string) ($group['id'] ?? ''), ['message_id' => $message['id']]);
    respond(['success' => true, 'data' => $message, 'message' => 'Message sent to the project group.']);
}

if ($endpoint === 'legacy-messages') {
    respond(['success' => true, 'data' => $data['messages']]);
}

if ($endpoint === 'legacy-message' && $method === 'POST') {
    $payload = input_json();
    $student = by_id($data['students'], $payload['student_id'] ?? '') ?: $data['students'][0];
    $message = ['id' => 'MSG' . strtoupper(bin2hex(random_bytes(3))), 'student_id' => $student['id'], 'sender' => $data['advisor']['name'], 'receiver' => $student['name'], 'subject' => $payload['subject'] ?? 'ข้อความจากอาจารย์', 'message' => $payload['message'] ?? '', 'attachment' => '', 'read' => false, 'created_at' => date('Y-m-d H:i:s')];
    array_unshift($data['messages'], $message);
    add_notification($data, 'ส่งข้อความแล้ว', 'ส่งข้อความถึง ' . $student['name'], 'Message');
    save_data($data);
    respond(['success' => true, 'data' => $message, 'message' => 'ส่งข้อความเรียบร้อย']);
}

if ($endpoint === 'notifications') {
    $advisorId = (string) ($_SESSION['advisor_user']['id'] ?? '');
    $appData = shared_app_data();
    $advisorNotifications = advisor_notification_rows($appData, $advisorId);
    $visibleIds = array_column($advisorNotifications, 'id');
    if ($method === 'POST') {
        foreach ($appData['notifications'] as &$notification) {
            if (in_array($notification['id'] ?? '', $visibleIds, true)) {
                $notification['read_by'] = array_values(array_unique(array_merge($notification['read_by'] ?? [], [$advisorId])));
            }
        }
        unset($notification);
        save_shared_app_data($appData);
        respond(['success' => true, 'message' => 'ทำเครื่องหมายว่าอ่านแล้ว']);
    }
    $groupsById = [];
    foreach ($appData['groups'] ?? [] as $group) {
        $groupsById[$group['id'] ?? ''] = $group['name'] ?? '-';
    }
    $advisorNotifications = array_map(static function (array $row) use ($advisorId, $groupsById): array {
        $row['read'] = in_array($advisorId, $row['read_by'] ?? [], true) || (!isset($row['read_by']) && !empty($row['read']));
        $row['group_name'] = $groupsById[$row['group_id'] ?? ''] ?? 'ส่วนตัว';
        return $row;
    }, $advisorNotifications);
    respond(['success' => true, 'data' => $advisorNotifications, 'unread' => count(array_filter($advisorNotifications, fn($row) => empty($row['read'])))]);
}

if ($endpoint === 'calendar') {
    $advisorId = (string) ($_SESSION['advisor_user']['id'] ?? '');
    $appData = shared_app_data();
    $groups = array_values(array_filter($appData['groups'] ?? [], static fn(array $group): bool => in_array($advisorId, array_values($group['advisor_roles'] ?? []), true)));
    $groupIds = array_column($groups, 'id');
    if ($method === 'POST') {
        $payload = input_json();
        $group = by_id($groups, (string) ($payload['group_id'] ?? ''));
        $title = trim((string) ($payload['title'] ?? ''));
        $date = trim((string) ($payload['date'] ?? ''));
        $time = trim((string) ($payload['time'] ?? ''));
        if (!$group) respond(['success' => false, 'message' => 'เลือกได้เฉพาะกลุ่มที่อาจารย์ดูแล'], 403);
        if ($title === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) respond(['success' => false, 'message' => 'กรุณาระบุหัวข้อและวันที่นัดหมาย'], 422);
        if ($date < date('Y-m-d')) respond(['success' => false, 'message' => 'ไม่สามารถนัดหมายย้อนหลังได้'], 422);
        $event = [
            'id' => 'CAL' . strtoupper(bin2hex(random_bytes(4))), 'advisor_id' => $advisorId,
            'group_id' => $group['id'], 'title' => $title, 'date' => $date, 'time' => $time,
            'type' => trim((string) ($payload['type'] ?? 'นัดหมาย')) ?: 'นัดหมาย',
            'location' => trim((string) ($payload['location'] ?? '')),
            'details' => trim((string) ($payload['details'] ?? '')), 'created_at' => date('Y-m-d H:i:s'),
        ];
        $appData['calendar'][] = $event;
        array_unshift($appData['notifications'], [
            'id' => 'NOT' . strtoupper(bin2hex(random_bytes(4))), 'group_id' => $group['id'],
            'advisor_id' => $advisorId, 'title' => 'นัดหมายใหม่: ' . $title,
            'message' => 'วันที่ ' . $date . ($time !== '' ? ' เวลา ' . $time : '') . (($event['location'] ?? '') !== '' ? ' ณ ' . $event['location'] : ''),
            'type' => 'Calendar', 'read_by' => [$advisorId], 'created_at' => date('Y-m-d H:i:s'),
        ]);
        save_shared_app_data($appData);
        advisor_audit('create_calendar_event', 'group', (string) $group['id'], ['event_id' => $event['id']]);
        respond(['success' => true, 'message' => 'สร้างนัดหมายและแจ้งเตือนกลุ่มแล้ว', 'data' => $event]);
    }
    $groupNames = [];
    foreach ($groups as $group) $groupNames[$group['id']] = $group['name'] ?? '-';
    $events = array_values(array_filter($appData['calendar'] ?? [], static fn(array $event): bool => ($event['advisor_id'] ?? '') === $advisorId || in_array($event['group_id'] ?? '', $groupIds, true)));
    usort($events, static fn(array $a, array $b): int => (($a['date'] ?? '') . ' ' . ($a['time'] ?? '')) <=> (($b['date'] ?? '') . ' ' . ($b['time'] ?? '')));
    $events = array_map(static function (array $event) use ($groupNames): array { $event['group_name'] = $groupNames[$event['group_id'] ?? ''] ?? '-'; return $event; }, $events);
    respond(['success' => true, 'data' => $events, 'groups' => array_map(static fn(array $group): array => ['id' => $group['id'], 'name' => $group['name'] ?? '-'], $groups)]);
}

if ($endpoint === 'profile') {
    if ($method === 'POST') {
        $payload = $_POST ?: input_json();
        foreach (['email', 'phone'] as $field) {
            if (isset($payload[$field])) {
                $data['advisor'][$field] = trim((string) $payload[$field]);
            }
        }
        save_data($data);
        respond(['success' => true, 'data' => $data['advisor'], 'message' => 'อัปเดตโปรไฟล์เรียบร้อย']);
    }
    respond(['success' => true, 'data' => $data['advisor']]);
}

respond(['success' => false, 'message' => 'ไม่พบ Advisor API endpoint'], 404);




