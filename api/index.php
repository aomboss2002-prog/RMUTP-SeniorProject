<?php
require_once __DIR__ . '/../app/store.php';
require_once __DIR__ . '/../app/session.php';
require_once __DIR__ . '/../app/storage.php';
require_once __DIR__ . '/../app/system-health.php';
start_app_session();

header('Content-Type: application/json; charset=utf-8');

$method = $_POST['_method'] ?? $_SERVER['REQUEST_METHOD'];
$resource = $_GET['resource'] ?? '';
$action = $_GET['action'] ?? '';
$data = load_data();

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

function enrich_project(array $project, array $data): array
{
    $student = find_row($data['students'], $project['student_id'] ?? '');
    $advisor = find_row($data['advisors'], $project['advisor_id'] ?? '');
    $project['student_name'] = trim(($student['first_name'] ?? '') . ' ' . ($student['last_name'] ?? ''));
    $project['advisor_name'] = $advisor['name'] ?? '';
    $projectId = (string) ($project['id'] ?? '');
    $documents = array_values(array_filter(
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

if ($resource === 'system-health') {
    if ($method === 'GET') {
        respond(['success' => true, 'data' => system_health_snapshot()]);
    }
    if ($method !== 'POST') respond(['success' => false, 'message' => 'Method not allowed.'], 405);
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
        $recipient = trim((string) ($config['ADMIN_RECOVERY_EMAIL'] ?? ($_SESSION['app_user']['email'] ?? '')));
        if (!filter_var($recipient, FILTER_VALIDATE_EMAIL)) respond(['success' => false, 'message' => 'กรุณากำหนด ADMIN_RECOVERY_EMAIL เป็นอีเมลที่ถูกต้อง'], 422);
        try {
            $result = system_health_test_email($recipient);
            respond(['success' => true, 'data' => ['transport' => $result['transport'] ?? ''], 'message' => 'ส่งอีเมลทดสอบแล้ว กรุณาตรวจสอบกล่องจดหมายของผู้ดูแล']);
        } catch (Throwable $error) {
            error_log('[SYSTEM HEALTH ACTION] email: ' . $error->getMessage());
            respond(['success' => false, 'message' => 'ส่งอีเมลทดสอบไม่สำเร็จ กรุณาตรวจสอบการตั้งค่าอีเมล'], 503);
        }
    }
    respond(['success' => false, 'message' => 'Unknown diagnostic action.'], 404);
}

if ($resource === 'dashboard') {
    $statuses = array_count_values(array_column($data['projects'], 'status'));
    $uploads = array_count_values(array_column($data['documents'], 'type'));
    $riskOverview = [
        'total' => 0,
        'latest_calculated_at' => null,
        'counts' => ['low' => 0, 'watch' => 0, 'high' => 0, 'critical' => 0],
    ];
    try {
        $riskRows = database_connection()->query(
            'SELECT LOWER(risk_level) AS risk_level, COUNT(*) AS total, MAX(calculated_at) AS latest_calculated_at
             FROM project_risk_scores
             GROUP BY LOWER(risk_level)'
        )->fetchAll();
        foreach ($riskRows as $riskRow) {
            $level = (string) ($riskRow['risk_level'] ?? '');
            if (!array_key_exists($level, $riskOverview['counts'])) {
                continue;
            }
            $count = (int) ($riskRow['total'] ?? 0);
            $riskOverview['counts'][$level] = $count;
            $riskOverview['total'] += $count;
            $calculatedAt = $riskRow['latest_calculated_at'] ?? null;
            if ($calculatedAt && (!$riskOverview['latest_calculated_at'] || $calculatedAt > $riskOverview['latest_calculated_at'])) {
                $riskOverview['latest_calculated_at'] = $calculatedAt;
            }
        }
    } catch (Throwable $exception) {
        // Keep the dashboard available while the optional risk-score migration is pending.
    }
    $dashboardStudents = array_map(static fn(array $student): array => [
        'id' => $student['id'] ?? '',
        'code' => $student['code'] ?? '',
        'first_name' => $student['first_name'] ?? '',
        'last_name' => $student['last_name'] ?? '',
        'major' => $student['major'] ?? '',
    ], $data['students']);
    $dashboardStudentsById = collection_rows_by_id($data['students']);
    $dashboardProjects = array_map(static function (array $project) use ($dashboardStudentsById): array {
        $student = $dashboardStudentsById[(string) ($project['student_id'] ?? '')] ?? [];
        return [
            'id' => $project['id'] ?? '',
            'code' => $project['code'] ?? '',
            'title' => $project['title'] ?? '',
            'student_name' => trim((string) ($student['first_name'] ?? '') . ' ' . (string) ($student['last_name'] ?? '')),
        ];
    }, $data['projects']);
    respond([
        'success' => true,
        'data' => [
            'summary' => [
                'students' => count($data['students']),
                'advisors' => count($data['advisors']),
                'projects' => count($data['projects']),
                'pending' => $statuses['Pending'] ?? 0,
            ],
            'project_status' => $statuses,
            'uploads' => $uploads,
            'risk_overview' => $riskOverview,
            'activities' => array_slice($data['activities'], 0, 5),
            'files' => array_slice($data['documents'], 0, 5),
            'notifications' => array_slice($data['notifications'], 0, 5),
            'approvals' => array_slice($data['approvals'], 0, 5),
            'students' => $dashboardStudents,
            'projects' => $dashboardProjects,
        ],
    ]);
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
        $payload['status'] = $payload['status'] ?? 'Pending';
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
        $advisors = array_map(static function (array $advisor): array {
            unset($advisor['password_hash']);
            return $advisor;
        }, $data['advisors'] ?? []);
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
        $projects = array_map(fn($project) => enrich_project($project, $data), $data['projects']);
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
    if (empty($_FILES['file'])) {
        respond(['success' => false, 'message' => 'No file uploaded'], 422);
    }
    $type = $_POST['type'] ?? 'proposal';
    if (!in_array($type, ['proposal', 'draft', 'complete'], true)) {
        respond(['success' => false, 'message' => 'Invalid document type'], 422);
    }
    if ($_FILES['file']['size'] > 20 * 1024 * 1024) {
        respond(['success' => false, 'message' => 'Maximum file size is 20 MB'], 422);
    }
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($_FILES['file']['tmp_name']);
    $extension = strtolower(pathinfo($_FILES['file']['name'], PATHINFO_EXTENSION));
    $signature = file_get_contents($_FILES['file']['tmp_name'], false, null, 0, 5);
    if ($extension !== 'pdf' || $mime !== 'application/pdf' || $signature !== '%PDF-') {
        respond(['success' => false, 'message' => 'Valid PDF files only'], 422);
    }
    $targetDir = __DIR__ . '/../uploads/' . $type;
    if (!is_dir($targetDir)) {
        mkdir($targetDir, 0775, true);
    }
    $originalName = basename($_FILES['file']['name']);
    // Capture the size before move/upload. On Vercel Blob there is no local
    // destination file after the upload, so filesize($target) is invalid.
    $uploadedBytes = max(0, (int) ($_FILES['file']['size'] ?? filesize($_FILES['file']['tmp_name'])));
    $target = $targetDir . '/' . bin2hex(random_bytes(24)) . '.pdf';
    if (storage_driver() === 'vercel_blob') {
        try {
            storage_put_uploaded_file($_FILES['file']['tmp_name'], $type, basename($target), 'application/pdf');
        } catch (Throwable $exception) {
            error_log('Document Blob upload failed: ' . $exception->getMessage());
            respond(['success' => false, 'message' => 'Could not save uploaded file'], 500);
        }
    } elseif (!move_uploaded_file($_FILES['file']['tmp_name'], $target)) {
        respond(['success' => false, 'message' => 'Could not save uploaded file'], 500);
    }
    $document = [
        'id' => next_id($data['documents'], 'DOC'),
        'project_id' => $_POST['project_id'] ?? 'PRJ001',
        'student_id' => $_POST['student_id'] ?? 'STU001',
        'type' => $type,
        'title' => $_POST['title'] ?? ucfirst($type) . ' File',
        'filename' => basename($target),
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
    respond(['success' => true, 'data' => [
        'students' => $data['students'],
        'projects' => array_map(fn($project) => enrich_project($project, $data), $data['projects']),
        'documents' => $data['documents'],
        'approvals' => $data['approvals'],
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
        $email = strtolower(str_replace('-', '', $code) . '@rmutp.com');
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
    respond(['success' => true, 'data' => array_values($data[$kind] ?? [])]);
}

if ($resource === 'profile') {
    if ($method === 'GET') {
        respond(['success' => true, 'data' => $data['profile']]);
    }
    if ($method === 'POST') {
        $data['profile'] = array_merge($data['profile'], request_json());
        save_data($data);
        respond(['success' => true, 'data' => $data['profile'], 'message' => 'Profile updated']);
    }
}

if ($resource === 'settings') {
    if ($method === 'GET') {
        respond(['success' => true, 'data' => $data['settings']]);
    }
    if ($method === 'POST') {
        $data['settings'] = array_merge($data['settings'], request_json());
        save_data($data);
        respond(['success' => true, 'data' => $data['settings'], 'message' => 'Settings saved']);
    }
}

respond(['success' => false, 'message' => 'API resource not found'], 404);
