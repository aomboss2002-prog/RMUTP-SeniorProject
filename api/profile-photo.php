<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/store.php';
require_once __DIR__ . '/../app/session.php';
start_app_session();

$studentId = trim((string) ($_GET['id'] ?? ''));
$data = load_data();
$student = find_row($data['students'] ?? [], $studentId);
if (!$student) {
    http_response_code(404);
    exit('Student not found.');
}

$allowed = false;
$appUser = $_SESSION['app_user'] ?? [];
if (($appUser['role'] ?? '') === 'admin') {
    $allowed = true;
} elseif (($appUser['role'] ?? '') === 'student') {
    $viewerId = (string) ($appUser['id'] ?? '');
    $allowed = $viewerId === $studentId;
    if (!$allowed) {
        foreach ($data['groups'] ?? [] as $group) {
            $members = $group['member_ids'] ?? [];
            if (in_array($viewerId, $members, true) && in_array($studentId, $members, true)) {
                $allowed = true;
                break;
            }
        }
    }
} elseif (!empty($_SESSION['advisor_user']['id'])) {
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
$candidate = $isUploadedPhoto
    ? __DIR__ . '/../uploads/student/' . basename($relativePath)
    : __DIR__ . '/../assets/img/profile-student.svg';
$path = realpath($candidate);
$expectedBase = realpath($isUploadedPhoto ? __DIR__ . '/../uploads/student' : __DIR__ . '/../assets/img');

if (!$path || !$expectedBase || !str_starts_with($path, $expectedBase . DIRECTORY_SEPARATOR) || !is_file($path)) {
    $path = realpath(__DIR__ . '/../assets/img/profile-student.svg');
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
