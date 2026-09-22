<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/store.php';
require_once __DIR__ . '/../app/session.php';
require_once __DIR__ . '/../app/storage.php';
require_once __DIR__ . '/../app/runtime-read.php';
start_app_session();

$studentId = trim((string) ($_GET['id'] ?? ''));
$appUser = $_SESSION['app_user'] ?? [];
if (!in_array($appUser['role'] ?? '', ['admin', 'student'], true) && empty($_SESSION['advisor_user']['id'])) {
    http_response_code(403);
    exit('Access denied.');
}
// Persist session bookkeeping and release file-session locks before DB/storage I/O.
session_write_close();
$statement = database_connection()->prepare('SELECT id, photo FROM students WHERE id = :id LIMIT 1');
$statement->execute(['id' => $studentId]);
$student = $statement->fetch(PDO::FETCH_ASSOC);
if (!$student) {
    http_response_code(404);
    exit('Student not found.');
}

$allowed = false;
if (($appUser['role'] ?? '') === 'admin') {
    $allowed = true;
} elseif (($appUser['role'] ?? '') === 'student') {
    $viewerId = (string) ($appUser['id'] ?? '');
    $allowed = $viewerId === $studentId;
    if (!$allowed) {
        $data = runtime_read_collections(database_connection(), ['groups']);
        foreach ($data['groups'] ?? [] as $group) {
            $members = $group['member_ids'] ?? [];
            if (in_array($viewerId, $members, true) && in_array($studentId, $members, true)) {
                $allowed = true;
                break;
            }
        }
    }
} elseif (!empty($_SESSION['advisor_user']['id'])) {
    $data = runtime_read_collections(database_connection(), ['groups']);
    $advisorId = (string) $_SESSION['advisor_user']['id'];
    foreach ($data['groups'] ?? [] as $group) {
        if (in_array($studentId, $group['member_ids'] ?? [], true)
            && in_array($advisorId, array_values($group['advisor_roles'] ?? []), true)) {
            $allowed = true;
            break;
        }
    }
}

if (!$allowed) {
    http_response_code(403);
    exit('Access denied.');
}

$relativePath = str_replace('\\', '/', (string) ($student['photo'] ?? ''));
$isUploadedPhoto = str_starts_with($relativePath, 'uploads/student/');
$temporaryPhoto = false;
if ($isUploadedPhoto) {
    try {
        $storedPhoto = storage_materialize('student', basename($relativePath));
        $path = $storedPhoto['path'];
        $temporaryPhoto = $storedPhoto['temporary'];
        if ($temporaryPhoto) {
            register_shutdown_function(static function () use ($path): void {
                if (is_file($path)) @unlink($path);
            });
        }
    } catch (Throwable) {
        $path = false;
    }
} else {
    $path = realpath(__DIR__ . '/../assets/img/profile-student.svg');
}
if (!$path || !is_file($path)) {
    $path = realpath(__DIR__ . '/../assets/img/profile-student.svg');
    $temporaryPhoto = false;
}
if (!$path) {
    http_response_code(404);
    exit('Image not found.');
}

$mime = (new finfo(FILEINFO_MIME_TYPE))->file($path) ?: 'application/octet-stream';
$allowedMimes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/svg+xml'];
if (!in_array($mime, $allowedMimes, true)) {
    http_response_code(415);
    exit('Unsupported image type.');
}

header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($path));
header('Cache-Control: private, max-age=300');
header('X-Content-Type-Options: nosniff');
readfile($path);
if ($temporaryPhoto && is_file($path)) @unlink($path);
