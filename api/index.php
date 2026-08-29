<?php
require_once __DIR__ . '/../app/store.php';
require_once __DIR__ . '/../app/session.php';
require_once __DIR__ . '/../app/storage.php';
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

function authenticate_user(array $payload, array &$data): ?array
{
    $email = trim((string) ($payload['email'] ?? ''));
    $password = trim((string) ($payload['password'] ?? ''));

    $config = env_config();
    if ($email === ($config['ADMIN_EMAIL'] ?? '')
        && ($config['ADMIN_PASSWORD'] ?? '') !== ''
        && hash_equals((string) $config['ADMIN_PASSWORD'], $password)) {
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

if ($resource === 'dashboard') {
    $statuses = array_count_values(array_column($data['projects'], 'status'));
    $uploads = array_count_values(array_column($data['documents'], 'type'));
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
            respond(['success' => true, 'data' => [
                'student' => $student,
                'advisor' => $advisor,
                'project' => $project,
                'timeline' => array_values(array_filter($data['approvals'], fn($row) => ($row['student_id'] ?? '') === $id)),
                'files' => array_values(array_filter($data['documents'], fn($row) => ($row['student_id'] ?? '') === $id)),
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
        if (isset($payload['faculty']) && !in_array($payload['faculty'], app_faculties(), true)) {
            respond(['success' => false, 'message' => 'Please select a valid faculty.'], 422);
        }
        if (isset($payload['major']) && !in_array($payload['major'], app_majors(), true)) {
            respond(['success' => false, 'message' => 'Please select a valid major.'], 422);
        }
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
        delete_student_from_database($id);
        $data['students'] = array_values(array_filter($data['students'], fn($row) => ($row['id'] ?? '') !== $id));
        save_data($data);
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
    respond(['success' => true, 'imported' => count($rows), 'message' => count($rows) . ' rows imported']);
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
