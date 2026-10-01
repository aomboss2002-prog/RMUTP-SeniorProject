<?php
require_once __DIR__ . '/../app/store.php';
require_once __DIR__ . '/../app/session.php';
require_once __DIR__ . '/../app/storage.php';
require_once __DIR__ . '/../app/system-health.php';
require_once __DIR__ . '/../app/admin-workflow.php';
require_once __DIR__ . '/../app/advisor-counts.php';
start_app_session();

header('Content-Type: application/json; charset=utf-8');

$method = $_POST['_method'] ?? $_SERVER['REQUEST_METHOD'];
$resource = $_GET['resource'] ?? '';
$action = $_GET['action'] ?? '';

function respond(array $payload, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
}

function request_json(): array
{
    $input = file_get_contents('php://input');
    $json = json_decode($input ?: '', true);
    return is_array($json) ? $json : $_POST;
}

function collection_response(array $data, string $key): void
{
    respond(['success' => true, 'data' => array_values($data[$key] ?? [])]);
}

function uploaded_student_photo(string $studentId): ?string
{
    $file = $_FILES['photo_file'] ?? null;
    if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) return null;
    if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
        respond(['success' => false, 'message' => 'อัปโหลดรูปภาพไม่สำเร็จ'], 422);
    }
    if ((int) ($file['size'] ?? 0) > 5 * 1024 * 1024) {
        respond(['success' => false, 'message' => 'รูปภาพต้องมีขนาดไม่เกิน 5 MB'], 422);
    }

    $mime = (new finfo(FILEINFO_MIME_TYPE))->file((string) $file['tmp_name']);
    $extensions = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'];
    if (!isset($extensions[$mime]) || @getimagesize((string) $file['tmp_name']) === false) {
        respond(['success' => false, 'message' => 'รองรับเฉพาะไฟล์ JPG, PNG, WEBP หรือ GIF'], 422);
    }

    $targetDir = __DIR__ . '/../uploads/student';
    if (!is_dir($targetDir) && !mkdir($targetDir, 0775, true) && !is_dir($targetDir)) {
        respond(['success' => false, 'message' => 'ไม่สามารถสร้างโฟลเดอร์รูปภาพได้'], 500);
    }
    $filename = $studentId . '-' . bin2hex(random_bytes(8)) . '.' . $extensions[$mime];
    if (storage_driver() === 'vercel_blob') {
        try {
            storage_put_uploaded_file((string) $file['tmp_name'], 'student', $filename, (string) $mime);
        } catch (Throwable) {
            respond(['success' => false, 'message' => 'Could not save profile picture'], 500);
        }
    } elseif (!move_uploaded_file((string) $file['tmp_name'], $targetDir . '/' . $filename)) {
        respond(['success' => false, 'message' => 'ไม่สามารถบันทึกรูปภาพได้'], 500);
    }
    return 'uploads/student/' . $filename;
}

function normalize_student_phone(string $phone): string
{
    return preg_replace('/\D+/', '', trim($phone)) ?? '';
}

function validate_student_identity(array &$payload, array $students, string $currentId = ''): void
{
    $code = trim((string) ($payload['code'] ?? ''));
    $phone = normalize_student_phone((string) ($payload['phone'] ?? ''));

    if (!preg_match('/^\d{12}-\d$/', $code)) {
        respond([
            'success' => false,
            'message' => 'รหัสนักศึกษาต้องเป็นตัวเลข 12 หลัก ตามด้วยขีดและเลขตรวจสอบ 1 หลัก เช่น 076250101001-6',
        ], 422);
    }
    if (!preg_match('/^\d{9,10}$/', $phone)) {
        respond(['success' => false, 'message' => 'เบอร์โทรศัพท์ต้องเป็นตัวเลข 9-10 หลัก'], 422);
    }

    foreach ($students as $student) {
        if ((string) ($student['id'] ?? '') === $currentId) continue;
        if (trim((string) ($student['code'] ?? '')) === $code) {
            respond(['success' => false, 'message' => 'รหัสนักศึกษานี้มีอยู่ในระบบแล้ว'], 409);
        }
        if (normalize_student_phone((string) ($student['phone'] ?? '')) === $phone) {
            respond(['success' => false, 'message' => 'เบอร์โทรศัพท์นี้มีอยู่ในระบบแล้ว'], 409);
        }
    }

    $statement = database_connection()->prepare(
        'SELECT code, phone FROM students
         WHERE id <> :current_id AND (code = :code OR phone = :phone)
         LIMIT 1'
    );
    $statement->execute(['current_id' => $currentId, 'code' => $code, 'phone' => $phone]);
    $duplicate = $statement->fetch();
    if (is_array($duplicate)) {
        $message = (string) ($duplicate['code'] ?? '') === $code
            ? 'รหัสนักศึกษานี้มีอยู่ในระบบแล้ว'
            : 'เบอร์โทรศัพท์นี้มีอยู่ในระบบแล้ว';
        respond(['success' => false, 'message' => $message], 409);
    }

    $payload['code'] = $code;
    $payload['phone'] = $phone;
}

function authenticate_user(array $payload, array &$data): ?array
{
    $email = trim((string) ($payload['email'] ?? ''));
    $password = trim((string) ($payload['password'] ?? ''));

    $config = env_config();
    $adminPasswordHash = (string) ($data['profile']['admin_password_hash'] ?? '');
    $adminPasswordValid = $adminPasswordHash !== ''
        ? secure_password_verify($password, $adminPasswordHash)
        : (($config['ADMIN_PASSWORD'] ?? '') !== ''
            && hash_equals((string) $config['ADMIN_PASSWORD'], $password));
    if ($email === ($config['ADMIN_EMAIL'] ?? '') && $adminPasswordValid) {
        return [
            'role' => 'admin',
            'id' => 'admin',
            'name' => $data['profile']['name'] ?? 'RMUTP Administrator',
            'email' => $email,
            'redirect_page' => 'dashboard',
        ];
    }

    foreach ($data['advisors'] ?? [] as $advisor) {
        if (strtolower((string) ($advisor['email'] ?? '')) !== strtolower($email)) {
            continue;
        }
        if (($advisor['status'] ?? 'Active') === 'Active'
            && !empty($advisor['password_hash'])
            && secure_password_verify($password, (string) $advisor['password_hash'])) {
            return [
                'role' => 'advisor',
                'id' => $advisor['id'] ?? '',
                'name' => $advisor['name'] ?? '',
                'email' => $advisor['email'] ?? $email,
                'photo' => $advisor['photo'] ?? 'assets/img/profile-advisor.svg',
                'redirect_page' => 'advisor-dashboard',
            ];
        }
        break;
    }

    foreach ($data['students'] as &$student) {
        if (($student['email'] ?? '') === $email || ($student['code'] ?? '') === $email) {
            if (($student['status'] ?? '') === 'Inactive') break;
            $expectedPassword = (string) ($student['code'] ?? '');
            $hasPasswordHash = !empty($student['password_hash']);
            $passwordValid = $hasPasswordHash
                ? secure_password_verify($password, (string) $student['password_hash'])
                : ($expectedPassword !== '' && hash_equals($expectedPassword, $password));
            if ($passwordValid) {
                // Migrate a legacy student-code password to a one-way hash on
                // the first successful login. Future logins never need the
                // plaintext fallback for this account again.
                $passwordMigrated = false;
                if (!$hasPasswordHash) {
                    $student['password_hash'] = secure_password_hash($password);
                    $passwordMigrated = true;
                }
                return [
                    'role' => 'student',
                    'id' => $student['id'] ?? '',
                    'name' => trim(($student['first_name'] ?? '') . ' ' . ($student['last_name'] ?? '')),
                    'email' => $student['email'] ?? $email,
                    'student_code' => $student['code'] ?? '',
                    'photo' => $student['photo'] ?? 'assets/img/profile-student.svg',
                    'redirect_page' => 'portal-dashboard',
                    '_password_migrated' => $passwordMigrated,
                ];
            }
            break;
        }
    }

    return null;
}

function enrich_project(array $project, array $data, ?array $index = null): array
{
    $student = $index !== null ? ($index['students'][$project['student_id'] ?? ''] ?? null) : find_row($data['students'], $project['student_id'] ?? '');
    $advisor = $index !== null ? ($index['advisors'][$project['advisor_id'] ?? ''] ?? null) : find_row($data['advisors'], $project['advisor_id'] ?? '');
    $project['student_name'] = trim(($student['first_name'] ?? '') . ' ' . ($student['last_name'] ?? ''));
    $project['advisor_name'] = $advisor['name'] ?? '';
    $projectId = (string) ($project['id'] ?? '');
    $documents = $index !== null ? ($index['documents'][$projectId] ?? []) : array_values(array_filter(
        $data['documents'] ?? [],
        static fn(array $document): bool => (string) ($document['project_id'] ?? '') === $projectId
    ));
    $project['progress'] = calculated_project_progress($documents);
    $completeDocuments = array_values(array_filter(
        $documents,
        static fn(array $document): bool => strtolower((string) ($document['type'] ?? '')) === 'complete'
    ));
    usort($completeDocuments, static function (array $left, array $right): int {
        $leftKey = (string) ($left['uploaded_at'] ?? '') . '|' . (string) ($left['id'] ?? '');
        $rightKey = (string) ($right['uploaded_at'] ?? '') . '|' . (string) ($right['id'] ?? '');
        return strcmp($rightKey, $leftKey);
    });
    $latestComplete = $completeDocuments[0] ?? null;
    $project['complete_approved'] = $latestComplete !== null
        && in_array((string) ($latestComplete['status'] ?? ''), ['Approved', 'Completed'], true);
    $project['barcode_available'] = $project['complete_approved'] && !empty($project['code']);
    return $project;
}

if ($resource === 'auth' && $action === 'login') {
    $payload = request_json();
    if (!empty($payload['email']) && !empty($payload['password'])) {
        if (login_rate_limited((string) $payload['email'])) {
            respond(['success' => false, 'message' => 'Too many login attempts. Try again in 15 minutes.'], 429);
        }
        $data = load_data();
        $user = authenticate_user($payload, $data);
        if ($user) {
            if (!empty($user['_password_migrated'])) {
                save_data($data);
            }
            unset($user['_password_migrated']);
            record_login_attempt((string) $payload['email'], true);
            session_regenerate_id(true);
            $_SESSION['app_user'] = $user;
            if (($user['role'] ?? '') === 'advisor') {
                $_SESSION['advisor_user'] = [
                    'id' => $user['id'] ?? '',
                    'name' => $user['name'] ?? '',
                    'email' => $user['email'] ?? '',
                ];
            } else {
                unset($_SESSION['advisor_user']);
            }
            set_remembered_session(filter_var($payload['remember'] ?? false, FILTER_VALIDATE_BOOLEAN));
            respond(['success' => true, 'data' => $user, 'message' => 'Login successful']);
        }
        record_login_attempt((string) $payload['email'], false);
        respond(['success' => false, 'message' => 'Invalid email or password.'], 401);
    }
    respond(['success' => false, 'message' => 'Email and password are required.'], 422);
}

if ($resource === 'auth' && $action === 'logout') {
    unset($_SESSION['app_user'], $_SESSION['advisor_user'], $_SESSION['remember_me']);
    session_regenerate_id(true);
    set_remembered_session(false);
    respond(['success' => true, 'message' => 'Logged out']);
}

if (($_SESSION['app_user']['role'] ?? '') !== 'admin') {
    respond(['success' => false, 'message' => 'Administrator access required.'], 403);
}

require_csrf_token();

if ($resource === 'dashboard' && $action === 'search' && $method === 'GET') {
    require_once __DIR__ . '/../app/dashboard-search.php';
    $keyword = is_string($_GET['q'] ?? null) ? $_GET['q'] : '';
    header('Cache-Control: private, no-store');
    respond(['success' => true, 'data' => dashboard_search(database_connection(), $keyword)]);
}

// This scoped read must precede load_data(): do not deserialize the full runtime store.
if (in_array($resource, ['projects', 'documents'], true) && $action === 'page' && $method === 'GET') {
    require_once __DIR__ . '/../app/admin-list.php';
    header('Cache-Control: private, no-store');
    respond(admin_list_page(database_connection(), $resource, $_GET));
}

if ($resource === 'students' && $action === 'page' && $method === 'GET') {
    require_once __DIR__ . '/../app/student-list.php';
    header('Cache-Control: private, no-store');
    respond(student_list_page(database_connection(), $_GET));
}

if ($resource === 'system-health') {
    header('Cache-Control: private, no-store');
    if ($action === 'backup-database') {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST' || $method !== 'POST') respond(['success' => false, 'message' => 'Method not allowed.'], 405);
        if ((request_json()['confirm'] ?? false) !== true) respond(['success' => false, 'message' => 'กรุณายืนยันการสำรองฐานข้อมูล'], 422);
        if (time() - (int) ($_SESSION['database_backup_attempt'] ?? 0) < 60) {
            header('Retry-After: 60');
            respond(['success' => false, 'message' => 'กรุณารอ 1 นาทีก่อนสำรองฐานข้อมูลอีกครั้ง'], 429);
        }
        $_SESSION['database_backup_attempt'] = time();
        session_write_close();
        require_once __DIR__ . '/../app/database-backup.php';
        try {
            $backup = database_backup_export(database_connection());
            header('X-Content-Type-Options: nosniff');
            respond(['success' => true, 'data' => $backup]);
        } catch (Throwable $error) {
            $code = in_array($error->getMessage(), ['BACKUP_LIMIT', 'BACKUP_UNSUPPORTED_SCHEMA'], true) ? $error->getMessage() : 'BACKUP_FAILED';
            error_log('[DATABASE BACKUP] ' . $code);
            respond(['success' => false, 'error_code' => $code, 'message' => $code === 'BACKUP_LIMIT'
                ? 'ข้อมูลเกินขนาดหรือเวลาที่สำรองผ่านเว็บได้ กรุณาสำรองผ่านผู้ให้บริการฐานข้อมูลหรือ mysqldump'
                : ($code === 'BACKUP_UNSUPPORTED_SCHEMA'
                    ? 'ฐานข้อมูลมีโครงสร้างที่เครื่องมือสำรองผ่านเว็บยังไม่รองรับ กรุณาใช้เครื่องมือของผู้ให้บริการฐานข้อมูลหรือ mysqldump'
                    : 'สำรองฐานข้อมูลไม่สำเร็จ กรุณาตรวจการเชื่อมต่อและสิทธิ์อ่านฐานข้อมูล ไม่มีการดาวน์โหลดไฟล์สำรองที่ไม่ครบ')], 503);
        }
    }
    if ($method === 'GET') {
        respond(['success' => true, 'data' => system_health_snapshot()]);
    }
    if ($method !== 'POST') respond(['success' => false, 'message' => 'Method not allowed.'], 405);
    if ($action === 'repair-ai-schema') {
        if ((request_json()['confirm'] ?? false) !== true) {
            respond(['success' => false, 'message' => 'กรุณายืนยันการสร้างตารางก่อนดำเนินการ'], 422);
        }
        require_once __DIR__ . '/../app/schema-repair.php';
        try {
            $result = repair_missing_ai_tables(database_connection());
            respond(['success' => true, 'data' => $result, 'message' => $result['created']
                ? 'สร้างตารางเรียบร้อย: ' . implode(', ', $result['created'])
                : 'ตาราง AI ครบแล้ว ไม่มีการเปลี่ยนแปลงข้อมูล']);
        } catch (Throwable $error) {
            error_log('[SCHEMA REPAIR] failed code=' . $error->getCode());
            respond(['success' => false, 'error_code' => 'SCHEMA_REPAIR_FAILED', 'message' => 'สร้างตารางไม่ครบ กรุณาตรวจสิทธิ์ CREATE และความเข้ากันได้กับตาราง projects ตารางที่สร้างสำเร็จแล้วจะคงอยู่และลองใหม่ได้'], 503);
        }
    }
    if ($action === 'test-storage') {
        try {
            $result = system_health_storage_probe();
            respond(['success' => true, 'data' => $result, 'message' => 'ทดสอบ Storage สำเร็จ และลบไฟล์ชั่วคราวแล้ว']);
        } catch (Throwable $error) {
            error_log('[SYSTEM HEALTH ACTION] storage: ' . $error->getMessage());
            respond(['success' => false, 'message' => 'ทดสอบ Storage ไม่สำเร็จ กรุณาตรวจสอบการตั้งค่า'], 503);
        }
    }
    if ($action === 'test-email') {
        $config = env_config();
        $issue = mail_configuration_issue($config, mailer_transport());
        if ($issue) respond(['success' => false, 'message' => $issue['message'], 'error_code' => $issue['code']], 422);
        $recipient = trim((string) ($config['ADMIN_RECOVERY_EMAIL'] ?? ($_SESSION['app_user']['email'] ?? '')));
        if (!filter_var($recipient, FILTER_VALIDATE_EMAIL)) respond(['success' => false, 'message' => 'กรุณากำหนด ADMIN_RECOVERY_EMAIL เป็นอีเมลที่ถูกต้อง'], 422);
        try {
            $result = system_health_test_email($recipient);
            respond(['success' => true, 'data' => ['transport' => $result['transport'] ?? ''], 'message' => ($result['transport'] ?? '') === 'log'
                ? 'บันทึกอีเมลทดสอบลง Log แล้ว โหมดนี้ไม่ส่งอีเมลจริง'
                : 'ผู้ให้บริการรับอีเมลทดสอบแล้ว กรุณาตรวจกล่องจดหมายและ Spam ของผู้ดูแล']);
        } catch (Throwable $error) {
            $issue = mail_delivery_issue($error);
            error_log('[SYSTEM HEALTH ACTION] email: ' . $issue['code']);
            respond(['success' => false, 'message' => $issue['message'], 'error_code' => $issue['code']], 503);
        }
    }
    respond(['success' => false, 'message' => 'Unknown diagnostic action.'], 404);
}

if ($resource === 'dashboard') {
    if ($method !== 'GET') respond(['success' => false, 'message' => 'Method not allowed.'], 405);
    require_once __DIR__ . '/../app/admin-dashboard.php';
    header('Cache-Control: private, no-store');
    respond(['success' => true, 'data' => admin_dashboard_payload(database_connection())]);
}

if ($resource === 'projects' && ($method === 'DELETE' || in_array($action, ['deletion-cleanups', 'cleanup-delete'], true))) {
    require_once __DIR__ . '/../app/admin-project-delete.php';
    header('Cache-Control: private, no-store');
    try {
        $pdo = database_connection();
        if ($action === 'deletion-cleanups' && $method === 'GET') {
            respond(['success' => true, 'data' => admin_project_cleanup_jobs($pdo)]);
        }
        if ($action === 'cleanup-delete' && $method === 'POST') {
            $payload = request_json();
            if (($payload['confirm'] ?? false) !== true) respond(['success' => false, 'message' => 'กรุณายืนยันก่อนลบไฟล์'], 422);
            $result = admin_project_cleanup_files($pdo, (string) ($payload['job_id'] ?? ''));
            respond(['success' => true, 'data' => $result, 'message' => $result['pending'] ? 'ยังมีไฟล์ที่ลบไม่สำเร็จ สามารถลองใหม่ได้' : 'ลบไฟล์ที่ค้างเรียบร้อยแล้ว']);
        }
        if ($method !== 'DELETE' || $_SERVER['REQUEST_METHOD'] !== 'DELETE') respond(['success' => false, 'message' => 'Method not allowed'], 405);
        $id = is_string($_GET['id'] ?? null) ? $_GET['id'] : '';
        $payload = request_json();
        if ($id === '' || ($payload['confirm_id'] ?? null) !== $id) respond(['success' => false, 'message' => 'กรุณาพิมพ์รหัสโครงงานเพื่อยืนยันการลบ'], 422);
        $result = admin_project_delete($pdo, $id);
        $pending = 0;
        if ($result['cleanup_job']) {
            try { $pending = admin_project_cleanup_files($pdo, $result['cleanup_job'])['pending']; }
            catch (Throwable) { $pending = -1; }
        }
        respond(['success' => true, 'data' => $result + ['pending_files' => $pending], 'message' => $pending
            ? 'ลบโครงงานและข้อมูลแล้ว แต่ยังมีไฟล์รอลบ กรุณากดลองลบไฟล์ค้างอีกครั้ง'
            : 'ลบโครงงาน เอกสาร และประวัติที่เกี่ยวข้องแล้ว']);
    } catch (OutOfBoundsException $e) {
        respond(['success' => false, 'message' => $e->getMessage()], 404);
    } catch (InvalidArgumentException $e) {
        respond(['success' => false, 'message' => $e->getMessage()], 422);
    } catch (Throwable $e) {
        error_log('[PROJECT DELETE] failed code=' . $e->getCode());
        respond(['success' => false, 'message' => 'ดำเนินการไม่สำเร็จ กรุณาตรวจฐานข้อมูลและลองใหม่'], 503);
    }
}

$data = load_data();

if ($resource === 'timeline' && $method === 'GET') {
    $projectId = (string) ($_GET['project_id'] ?? '');
    if (!find_row($data['projects'] ?? [], $projectId)) respond(['success' => false, 'message' => 'Project not found'], 404);
    respond(['success' => true, 'data' => admin_timeline_rows(project_tracking_history($projectId))]);
}

if ($resource === 'students') {
    if ($method === 'GET') {
        $id = $_GET['id'] ?? '';
        if ($id) {
            $student = find_row($data['students'], $id);
            if (!$student) {
                respond(['success' => false, 'message' => 'Student not found'], 404);
            }
            $advisor = find_row($data['advisors'], $student['advisor_id'] ?? '');
            $project = find_row($data['projects'], $student['project_id'] ?? '');
            $files = array_values(array_filter($data['documents'], fn($row) => ($row['student_id'] ?? '') === $id));
            $tracking = !empty($project['id']) ? project_tracking_payload((string) $project['id'], $files) : derive_project_tracking([]);
            respond(['success' => true, 'data' => [
                'student' => $student,
                'advisor' => $advisor,
                'project' => $project,
                'timeline' => array_values(array_filter($data['approvals'], fn($row) => ($row['student_id'] ?? '') === $id)),
                'files' => $files,
                'tracking' => $tracking,
                'comments' => array_values(array_filter($data['comments'], fn($row) => ($row['student_id'] ?? '') === $id)),
                'approvals' => array_values(array_filter($data['approvals'], fn($row) => ($row['student_id'] ?? '') === $id)),
            ]]);
        }
        collection_response($data, 'students');
    }
    if ($method === 'POST') {
        $payload = request_json();
        unset($payload['_method']);
        unset($payload['advisor_id']);
        $payload['advisor_id'] = '';
        $payload['advisor_roles'] = [];
        if (!in_array($payload['faculty'] ?? '', app_faculties(), true)) {
            respond(['success' => false, 'message' => 'Please select a valid faculty.'], 422);
        }
        if (!in_array($payload['major'] ?? '', app_majors(), true)) {
            respond(['success' => false, 'message' => 'Please select a valid major.'], 422);
        }
        validate_student_identity($payload, $data['students'] ?? []);
        $payload['id'] = next_student_id($data['students']);
        $payload['photo'] = uploaded_student_photo($payload['id']) ?? 'assets/img/profile-student.svg';
        $payload['status'] = $payload['status'] ?? 'Active';
        if (!in_array($payload['status'], ['Active', 'Completed', 'Inactive'], true)) {
            respond(['success' => false, 'message' => 'สถานะนักศึกษาไม่ถูกต้อง'], 422);
        }
        sync_student_to_database($payload);
        $data['students'][] = $payload;
        save_data($data);
        respond(['success' => true, 'data' => $payload, 'message' => 'Student saved']);
    }
    if ($method === 'PUT') {
        $payload = request_json();
        unset($payload['_method']);
        unset($payload['advisor_id']);
        $studentId = (string) ($payload['id'] ?? '');
        if (isset($payload['status']) && !in_array($payload['status'], ['Active', 'Completed', 'Inactive'], true)) {
            respond(['success' => false, 'message' => 'สถานะนักศึกษาไม่ถูกต้อง'], 422);
        }
        if (isset($payload['faculty']) && !in_array($payload['faculty'], app_faculties(), true)) {
            respond(['success' => false, 'message' => 'Please select a valid faculty.'], 422);
        }
        if (isset($payload['major']) && !in_array($payload['major'], app_majors(), true)) {
            respond(['success' => false, 'message' => 'Please select a valid major.'], 422);
        }
        validate_student_identity($payload, $data['students'] ?? [], $studentId);
        foreach ($data['students'] as &$student) {
            if (($student['id'] ?? '') === ($payload['id'] ?? '')) {
                $uploadedPhoto = uploaded_student_photo((string) $student['id']);
                if ($uploadedPhoto !== null) $payload['photo'] = $uploadedPhoto;
                $academicProgramChanged = (isset($payload['faculty']) && $payload['faculty'] !== ($student['faculty'] ?? ''))
                    || (isset($payload['major']) && $payload['major'] !== ($student['major'] ?? ''));
                if ($academicProgramChanged) {
                    $student['advisor_id'] = '';
                    $student['advisor_roles'] = [];
                    foreach ($data['projects'] as &$project) {
                        if (($project['id'] ?? '') === ($student['project_id'] ?? '')) {
                            $project['advisor_id'] = '';
                            break;
                        }
                    }
                    unset($project);
                }
                $student = array_merge($student, $payload);
                sync_student_to_database($student);
                save_data($data);
                respond(['success' => true, 'data' => $student, 'message' => 'Student updated']);
            }
        }
        respond(['success' => false, 'message' => 'Student not found'], 404);
    }
    if ($method === 'DELETE') {
        $id = $_GET['id'] ?? '';
        if (!find_row($data['students'] ?? [], $id)) {
            respond(['success' => false, 'message' => 'Student not found'], 404);
        }
        foreach ($data['groups'] ?? [] as $group) {
            if (in_array($id, $group['member_ids'] ?? [], true)) {
                respond(['success' => false, 'message' => 'Remove the student from the project group before deleting the account.'], 409);
            }
        }
        $hasRelatedRecords = count(array_filter(
            $data['documents'] ?? [],
            static fn(array $document): bool => ($document['student_id'] ?? '') === $id
        )) > 0;
        if ($hasRelatedRecords) {
            respond(['success' => false, 'message' => 'This student has project documents and cannot be deleted.'], 409);
        }
        $data['students'] = array_values(array_filter($data['students'], fn($row) => ($row['id'] ?? '') !== $id));
        $pdo = database_connection();
        $pdo->beginTransaction();
        try {
            delete_student_from_database($id);
            save_data($data);
            $pdo->commit();
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $error;
        }
        respond(['success' => true, 'message' => 'Student deleted']);
    }
}

if ($resource === 'advisors') {
    if ($method === 'GET') {
        $advisors = admin_advisors_with_student_counts($data);
        respond(['success' => true, 'data' => array_values($advisors)]);
    }
    if ($method === 'POST') {
        $payload = request_json();
        $name = trim((string) ($payload['name'] ?? ''));
        $email = strtolower(trim((string) ($payload['email'] ?? '')));
        $password = (string) ($payload['password'] ?? '');
        $department = trim((string) ($payload['department'] ?? ''));
        $faculty = trim((string) ($payload['faculty'] ?? ''));
        if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)
            || !in_array($faculty, app_faculties(), true)
            || !in_array($department, app_majors(), true)) {
            respond(['success' => false, 'message' => 'Please enter a name, faculty, department, and valid email address.'], 422);
        }
        if (strlen($password) < 8) {
            respond(['success' => false, 'message' => 'Password must contain at least 8 characters.'], 422);
        }
        foreach ($data['advisors'] ?? [] as $advisor) {
            if (strtolower((string) ($advisor['email'] ?? '')) === $email) {
                respond(['success' => false, 'message' => 'This advisor email is already in use.'], 409);
            }
        }
        $payload['name'] = $name;
        $payload['email'] = $email;
        $payload['department'] = $department;
        $payload['faculty'] = $faculty;
        $payload['password_hash'] = secure_password_hash($password);
        unset($payload['password']);
        $payload['id'] = next_id($data['advisors'], 'ADV');
        $payload['students'] = 0;
        $payload['status'] = $payload['status'] ?? 'Active';
        $data['advisors'][] = $payload;
        save_data($data);
        $responseAdvisor = $payload;
        unset($responseAdvisor['password_hash']);
        respond(['success' => true, 'data' => $responseAdvisor, 'message' => 'Advisor account saved']);
    }
    if ($method === 'DELETE') {
        $id = (string) ($_GET['id'] ?? '');
        if (!find_row($data['advisors'] ?? [], $id)) {
            respond(['success' => false, 'message' => 'Advisor not found'], 404);
        }

        $data['advisors'] = array_values(array_filter(
            $data['advisors'] ?? [],
            static fn(array $advisor): bool => (string) ($advisor['id'] ?? '') !== $id
        ));
        foreach ($data['students'] ?? [] as &$student) {
            if ((string) ($student['advisor_id'] ?? '') === $id) $student['advisor_id'] = '';
            $student['advisor_roles'] = array_filter(
                $student['advisor_roles'] ?? [],
                static fn($advisorId): bool => (string) $advisorId !== $id
            );
        }
        unset($student);
        foreach ($data['projects'] ?? [] as &$project) {
            if ((string) ($project['advisor_id'] ?? '') === $id) $project['advisor_id'] = '';
        }
        unset($project);
        foreach ($data['groups'] ?? [] as &$group) {
            $group['advisor_roles'] = array_filter(
                $group['advisor_roles'] ?? [],
                static fn($advisorId): bool => (string) $advisorId !== $id
            );
        }
        unset($group);
        $data['advisor_invitations'] = array_values(array_filter(
            $data['advisor_invitations'] ?? [],
            static fn(array $row): bool => (string) ($row['advisor_id'] ?? '') !== $id
        ));
        $data['notifications'] = array_values(array_filter(
            $data['notifications'] ?? [],
            static fn(array $row): bool => (string) ($row['advisor_id'] ?? '') !== $id
        ));
        foreach ($data['messages'] ?? [] as &$message) {
            if ((string) ($message['advisor_id'] ?? '') === $id) $message['advisor_id'] = '';
        }
        unset($message);
        foreach ($data['comments'] ?? [] as &$comment) {
            if ((string) ($comment['author_id'] ?? '') === $id) $comment['author_id'] = '';
        }
        unset($comment);
        foreach ($data['approvals'] ?? [] as &$approval) {
            if ((string) ($approval['reviewer_id'] ?? '') === $id) $approval['reviewer_id'] = '';
        }
        unset($approval);

        $pdo = database_connection();
        $pdo->beginTransaction();
        try {
            delete_advisor_from_database($id);
            save_data($data);
            $pdo->commit();
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $error;
        }
        respond(['success' => true, 'message' => 'Advisor deleted']);
    }
}

if ($resource === 'projects') {
    if ($method === 'GET') {
        $index = ['students' => collection_rows_by_id($data['students']), 'advisors' => collection_rows_by_id($data['advisors']), 'documents' => []];
        foreach ($data['documents'] ?? [] as $document) {
            $index['documents'][(string) ($document['project_id'] ?? '')][] = $document;
        }
        $projects = array_map(fn($project) => enrich_project($project, $data, $index), $data['projects']);
        collection_response(['projects' => $projects], 'projects');
    }
    if ($method === 'POST') {
        $payload = request_json();
        if (($action === 'status') && !empty($payload['id'])) {
            $allowedStatuses = ['Pending', 'Draft', 'Review', 'Approved', 'Completed'];
            $requestedStatus = (string) ($payload['status'] ?? '');
            if (!in_array($requestedStatus, $allowedStatuses, true)) {
                respond(['success' => false, 'message' => 'Invalid project status'], 422);
            }
            foreach ($data['projects'] as &$project) {
                if (($project['id'] ?? '') === $payload['id']) {
                    if ($requestedStatus === 'Completed') {
                        $projectDetails = enrich_project($project, $data);
                        if (empty($projectDetails['complete_approved'])) {
                            respond([
                                'success' => false,
                                'message' => 'ยังตั้งเป็นเสร็จสมบูรณ์ไม่ได้: ต้องมีฉบับสมบูรณ์ที่ได้รับอนุมัติก่อน',
                            ], 409);
                        }
                    }
                    $project['status'] = $requestedStatus;
                    if ($requestedStatus === 'Completed') {
                        $project['progress'] = 100;
                    }
                    $project['updated_at'] = date('Y-m-d H:i:s');
                    save_data($data);
                    respond([
                        'success' => true,
                        'data' => $project,
                        'message' => $requestedStatus === 'Completed'
                            ? 'Project marked as completed'
                            : 'Project status updated',
                    ]);
                }
            }
            respond(['success' => false, 'message' => 'Project not found'], 404);
        }
        $payload['id'] = next_id($data['projects'], 'PRJ');
        $payload['code'] = '';
        $payload['updated_at'] = date('Y-m-d H:i:s');
        $data['projects'][] = $payload;
        save_data($data);
        respond(['success' => true, 'data' => $payload, 'message' => 'Project saved']);
    }
}

if ($resource === 'documents') {
    if ($method === 'GET') {
        $type = $_GET['type'] ?? '';
        $documents = $type ? array_values(array_filter($data['documents'], fn($row) => ($row['type'] ?? '') === $type)) : $data['documents'];
        if (($_GET['include_names'] ?? '') === '1') {
            $studentsById = collection_rows_by_id($data['students']);
            $projectsById = collection_rows_by_id($data['projects']);
            foreach ($documents as &$document) {
                $student = $studentsById[$document['student_id'] ?? ''] ?? [];
                $document['student_name'] = trim(($student['first_name'] ?? '') . ' ' . ($student['last_name'] ?? '')) ?: ($document['student_id'] ?? '');
                $document['project_title'] = ($projectsById[$document['project_id'] ?? '']['title'] ?? '') ?: ($document['project_id'] ?? '');
            }
            unset($document);
        }
        respond(['success' => true, 'data' => $documents]);
    }
    if ($method === 'DELETE') {
        $id = $_GET['id'] ?? '';
        $data['documents'] = array_values(array_filter($data['documents'], fn($row) => ($row['id'] ?? '') !== $id));
        save_data($data);
        respond(['success' => true, 'message' => 'Document deleted']);
    }
}

if ($resource === 'upload' && $method === 'POST') {
    $payload = request_json();
    $temporary = null;
    try {
        $context = admin_upload_context($data, $payload);
        $type = $context['type'];
        $blobPath = trim((string) ($payload['blob_pathname'] ?? ''), '/');
        if ($blobPath !== '') {
            if (storage_driver() !== 'vercel_blob') throw new InvalidArgumentException('Cloud uploads are not enabled');
            $filename = storage_accept_blob_reference($type, $blobPath);
            $stored = storage_materialize($type, $filename);
            $source = $stored['path'];
            $temporary = $stored['temporary'] ? $source : null;
            $originalName = basename((string) ($payload['original_name'] ?? ''));
        } else {
            $file = $_FILES['file'] ?? [];
            if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'] ?? '')) {
                throw new InvalidArgumentException('อัปโหลดไฟล์ไม่สำเร็จ กรุณาเลือกไฟล์ PDF อีกครั้ง');
            }
            $source = $file['tmp_name'];
            $originalName = basename($file['name']);
            $filename = bin2hex(random_bytes(24)) . '.pdf';
        }
        // Verify actual stored bytes, never trust client-reported size or MIME.
        $uploadedBytes = admin_validate_pdf($source, $originalName);
        if ($blobPath === '') storage_put_uploaded_file($source, $type, $filename, 'application/pdf');
    } catch (Throwable $exception) {
        if ($temporary && is_file($temporary)) @unlink($temporary);
        $invalid = $exception instanceof InvalidArgumentException;
        if (!$invalid) error_log('Admin document upload failed: ' . $exception->getMessage());
        respond(['success' => false, 'message' => $invalid ? $exception->getMessage() : 'ไม่สามารถตรวจสอบหรือบันทึกไฟล์ได้'], $invalid ? 422 : 500);
    }
    if ($temporary && is_file($temporary)) @unlink($temporary);
    $document = $context + [
        'id' => next_id($data['documents'], 'DOC'),
        'title' => trim((string) ($payload['title'] ?? '')) ?: ucfirst($type) . ' File',
        'filename' => $filename,
        'original_name' => $originalName,
        'size' => round($uploadedBytes / 1048576, 2) . ' MB',
        'status' => 'Review',
        'uploaded_at' => date('Y-m-d H:i:s'),
    ];
    $data['documents'][] = $document;
    save_data($data);
    respond(['success' => true, 'data' => $document, 'message' => 'File uploaded']);
}

if ($resource === 'notifications') {
    if ($method === 'GET') {
        $unread = count(array_filter($data['notifications'], fn($row) => empty($row['read'])));
        if (($_GET['summary'] ?? '') === '1') {
            respond(['success' => true, 'data' => [], 'unread' => $unread]);
        }
        respond(['success' => true, 'data' => $data['notifications'], 'unread' => $unread]);
    }
    if ($method === 'POST') {
        foreach ($data['notifications'] as &$notification) {
            $notification['read'] = true;
        }
        save_data($data);
        respond(['success' => true, 'message' => 'Notifications marked as read']);
    }
}

if ($resource === 'comments' && $method === 'POST') {
    $payload = request_json();
    $comment = [
        'id' => next_id($data['comments'], 'COM'),
        'student_id' => $payload['student_id'] ?? 'STU001',
        'author' => $payload['author'] ?? 'RMUTP Administrator',
        'message' => $payload['message'] ?? '',
        'created_at' => date('Y-m-d H:i:s'),
    ];
    $data['comments'][] = $comment;
    save_data($data);
    respond(['success' => true, 'data' => $comment, 'message' => 'Comment added']);
}

if ($resource === 'reports') {
    try {
        $from = (string) ($_GET['from'] ?? '');
        $to = (string) ($_GET['to'] ?? '');
        $reportProjects = admin_report_filter($data['projects'], 'updated_at', $from, $to);
        $reportDocuments = admin_report_filter($data['documents'], 'uploaded_at', $from, $to);
        $reportApprovals = admin_report_filter($data['approvals'], 'created_at', $from, $to);
    } catch (InvalidArgumentException $error) {
        respond(['success' => false, 'message' => $error->getMessage()], 422);
    }
    respond(['success' => true, 'data' => [
        'projects' => array_map(fn($project) => enrich_project($project, $data), $reportProjects),
        'documents' => $reportDocuments,
        'approvals' => $reportApprovals,
    ]]);
}

if ($resource === 'import' && $method === 'POST') {
    $rows = request_json()['rows'] ?? [];
    if (!is_array($rows) || $rows === [] || count($rows) > 500) {
        respond(['success' => false, 'message' => 'กรุณาเลือกไฟล์ที่มีข้อมูลนักศึกษา 1-500 รายการ'], 422);
    }

    $codes = [];
    $phones = [];
    $emails = [];
    foreach ($data['students'] ?? [] as $student) {
        $studentId = (string) ($student['id'] ?? '');
        $codes[trim((string) ($student['code'] ?? ''))] = $studentId;
        $normalizedPhone = normalize_student_phone((string) ($student['phone'] ?? ''));
        if ($normalizedPhone !== '') $phones[$normalizedPhone] = $studentId;
        $emails[strtolower(trim((string) ($student['email'] ?? '')))] = $studentId;
    }
    foreach (database_connection()->query('SELECT id, code, email, phone FROM students')->fetchAll() as $student) {
        $studentId = (string) ($student['id'] ?? '');
        $codeKey = trim((string) ($student['code'] ?? ''));
        $emailKey = strtolower(trim((string) ($student['email'] ?? '')));
        $phoneKey = normalize_student_phone((string) ($student['phone'] ?? ''));
        if ($codeKey !== '') $codes[$codeKey] = $studentId;
        if ($emailKey !== '') $emails[$emailKey] = $studentId;
        if ($phoneKey !== '') $phones[$phoneKey] = $studentId;
    }

    $errors = [];
    $newStudents = [];
    $skipped = 0;
    $nextStudentNumber = (int) substr(next_student_id($data['students'] ?? []), 3);
    foreach (array_values($rows) as $index => $row) {
        $rowNumber = $index + 1;
        if (!is_array($row)) {
            $errors[] = "รายการ {$rowNumber}: รูปแบบข้อมูลไม่ถูกต้อง";
            continue;
        }
        $code = trim((string) ($row['code'] ?? ''));
        $phone = normalize_student_phone((string) ($row['phone'] ?? ''));
        $email = strtolower(str_replace('-', '', $code) . '@rmutp.ac.th');
        $firstName = trim((string) ($row['first_name'] ?? ''));
        $lastName = trim((string) ($row['last_name'] ?? ''));
        $faculty = trim((string) ($row['faculty'] ?? ''));
        $major = trim((string) ($row['major'] ?? ''));
        $yearLevel = (int) ($row['year_level'] ?? 0);

        if (!preg_match('/^\d{12}-\d$/', $code)) {
            $errors[] = "รายการ {$rowNumber}: รหัสนักศึกษาไม่ถูกต้อง";
            continue;
        }
        $existingCodeId = (string) ($codes[$code] ?? '');
        $existingEmailId = (string) ($emails[$email] ?? '');
        if ($existingCodeId !== '' || $existingEmailId !== '') {
            if ($existingCodeId !== '' && $existingEmailId !== '' && $existingCodeId !== $existingEmailId) {
                $errors[] = "รายการ {$rowNumber}: รหัสและอีเมลตรงกับคนละบัญชี กรุณาตรวจสอบข้อมูลเดิม";
            } else {
                $skipped++;
            }
            continue;
        }
        if ($phone !== '' && !preg_match('/^\d{9,10}$/', $phone)) {
            $errors[] = "รายการ {$rowNumber}: เบอร์โทรต้องเป็นตัวเลข 9-10 หลัก หรือเว้นว่างไว้";
        } elseif ($phone !== '' && isset($phones[$phone])) {
            $errors[] = "รายการ {$rowNumber}: เบอร์โทร {$phone} มีอยู่ในระบบแล้ว";
        }
        if ($firstName === '' || $lastName === '') $errors[] = "รายการ {$rowNumber}: ชื่อหรือนามสกุลไม่ครบ";
        if (!in_array($faculty, app_faculties(), true)) $errors[] = "รายการ {$rowNumber}: คณะไม่ถูกต้อง";
        if (!in_array($major, app_majors(), true)) $errors[] = "รายการ {$rowNumber}: สาขาไม่ถูกต้อง";
        if ($yearLevel < 1 || $yearLevel > 20) $errors[] = "รายการ {$rowNumber}: ชั้นปีไม่ถูกต้อง";
        $newStudentId = 'STU' . str_pad((string) $nextStudentNumber++, 3, '0', STR_PAD_LEFT);
        $codes[$code] = $newStudentId;
        if ($phone !== '') $phones[$phone] = $newStudentId;
        $emails[$email] = $newStudentId;
        $newStudents[] = [
            'id' => $newStudentId,
            'code' => $code,
            'first_name' => $firstName,
            'last_name' => $lastName,
            'email' => $email,
            'phone' => $phone,
            'faculty' => $faculty,
            'major' => $major,
            'year_level' => $yearLevel,
            'advisor_id' => '',
            'advisor_roles' => [],
            'project_id' => '',
            'status' => in_array((string) ($row['status'] ?? ''), ['Active', 'Completed', 'Inactive'], true)
                ? (string) $row['status']
                : 'Active',
            'photo' => 'assets/img/profile-student.svg',
        ];
    }
    if ($errors) {
        respond([
            'success' => false,
            'message' => implode(' | ', array_slice($errors, 0, 5)),
            'errors' => $errors,
        ], 422);
    }

    if ($newStudents) {
        $data['students'] = array_merge($data['students'] ?? [], $newStudents);
        $pdo = database_connection();
        $pdo->beginTransaction();
        try {
            save_data($data);
            $pdo->commit();
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $error;
        }
    }
    $message = 'เพิ่มนักศึกษาใหม่ ' . count($newStudents) . ' รายการ';
    if ($skipped > 0) $message .= ' และข้ามรายชื่อเดิม ' . $skipped . ' รายการ';
    respond([
        'success' => true,
        'imported' => count($newStudents),
        'skipped' => $skipped,
        'message' => $message,
    ]);
}

if ($resource === 'export') {
    $kind = $_GET['kind'] ?? 'students';
    if ($kind === 'advisors') respond(['success' => true, 'data' => admin_advisors_with_student_counts($data)]);
    respond(['success' => true, 'data' => array_values($data[$kind] ?? [])]);
}

if ($resource === 'profile') {
    if ($method === 'GET') {
        respond(['success' => true, 'data' => $data['profile']]);
    }
    if ($method === 'POST') {
        $data['profile'] = array_merge($data['profile'], request_json());
        save_data($data);
        $_SESSION['app_user']['name'] = (string) ($data['profile']['name'] ?? 'ผู้ดูแล');
        respond(['success' => true, 'data' => $data['profile'], 'message' => 'Profile updated']);
    }
}

if ($resource === 'settings') {
    if ($method === 'GET') {
        respond(['success' => true, 'data' => $data['settings']]);
    }
    if ($method === 'POST') {
        require_once __DIR__ . '/../app/settings.php';
        try {
            $data['settings'] = validated_settings_update($data['settings'] ?? [], request_json());
        } catch (InvalidArgumentException $error) {
            respond(['success' => false, 'message' => $error->getMessage()], 422);
        }
        save_data($data);
        respond(['success' => true, 'data' => $data['settings'], 'message' => 'บันทึกการตั้งค่าระบบเรียบร้อยแล้ว']);
    }
}

respond(['success' => false, 'message' => 'API resource not found'], 404);
