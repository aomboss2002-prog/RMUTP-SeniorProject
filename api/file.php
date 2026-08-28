<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/store.php';
require_once __DIR__ . '/../app/session.php';
require_once __DIR__ . '/../app/pdf-watermark.php';
require_once __DIR__ . '/../app/public-catalog.php';
start_app_session();

$documentId = trim((string) ($_GET['id'] ?? ''));
$mode = strtolower(trim((string) ($_GET['mode'] ?? 'preview')));
$isPublicDownload = $mode === 'public-download';
$isDownload = $mode === 'download' || $isPublicDownload;
$data = load_data();
$document = find_row($data['documents'] ?? [], $documentId);
if (!$document) {
    http_response_code(404);
    exit('File not found.');
}

$allowed = false;
$appUser = $_SESSION['app_user'] ?? [];
if ($isPublicDownload) {
    // Public access is intentionally limited to an accepted Complete version.
    // Return 404 for every other document to avoid exposing workflow metadata.
    if (!public_is_complete_document($document)) {
        http_response_code(404);
        exit('File not found.');
    }
    $allowed = true;
} elseif (($appUser['role'] ?? '') === 'admin') {
    $allowed = true;
} elseif (($appUser['role'] ?? '') === 'student') {
    $studentId = (string) ($appUser['id'] ?? '');
    $group = null;
    foreach ($data['groups'] ?? [] as $candidateGroup) {
        if (in_array($studentId, $candidateGroup['member_ids'] ?? [], true)) {
            $group = $candidateGroup;
            break;
        }
    }
    $allowed = $group
        ? (($document['group_id'] ?? '') === ($group['id'] ?? ''))
        : (($document['student_id'] ?? '') === $studentId && empty($document['group_id']));
} elseif (!empty($_SESSION['advisor_user']['id'])) {
    $advisorId = (string) $_SESSION['advisor_user']['id'];
    $group = find_row($data['groups'] ?? [], (string) ($document['group_id'] ?? ''));
    $allowed = $group && in_array($advisorId, array_values($group['advisor_roles'] ?? []), true);
}

if (!$allowed) {
    http_response_code(403);
    exit('Access denied.');
}

$stage = basename((string) ($document['type'] ?? ''));
$filename = basename((string) ($document['filename'] ?? ''));
$base = realpath(__DIR__ . '/../uploads');
$path = realpath(__DIR__ . '/../uploads/' . $stage . '/' . $filename);
if (!$base || !$path || !str_starts_with($path, $base . DIRECTORY_SEPARATOR) || !is_file($path)) {
    http_response_code(404);
    exit('File not found.');
}

header('Content-Type: application/pdf');
$servePath = $path;
$temporaryPath = null;
$downloadFilename = $filename;
if ($isPublicDownload) {
    $project = find_row($data['projects'] ?? [], (string) ($document['project_id'] ?? ''));
    $publicCode = preg_replace('/[^A-Za-z0-9._-]+/', '-', (string) ($project['code'] ?? $documentId)) ?: $documentId;
    $downloadFilename = 'complete-' . trim($publicCode, '-') . '.pdf';
}
$isCompleteDownload = $isDownload
    && public_is_complete_document($document);

if ($isCompleteDownload) {
    $sessionUser = $appUser ?: ($_SESSION['advisor_user'] ?? []);
    $userName = pdf_ascii_text((string) ($sessionUser['name'] ?? 'Public visitor'));
    $userId = pdf_ascii_text((string) ($sessionUser['id'] ?? 'public'));
    if ($userName === '') $userName = 'Authenticated user';
    if ($userId === '') $userId = 'unknown';
    $downloadedAt = date('d/m/Y H:i:s');
    $watermark = "CONFIDENTIAL - {$userName} - User ID: {$userId} - {$downloadedAt} - Document ID: {$documentId}";
    $watermarkLogo = __DIR__ . '/../assets/img/watermark-logo.png';
    $temporaryPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'rmutp-watermark-' . bin2hex(random_bytes(12)) . '.pdf';
    if (create_watermarked_pdf_copy($path, $temporaryPath, $watermark, $watermarkLogo)) {
        $servePath = $temporaryPath;
        register_shutdown_function(static function () use ($temporaryPath): void {
            if (is_file($temporaryPath)) {
                unlink($temporaryPath);
            }
        });
    } else {
        if (is_file($temporaryPath)) {
            unlink($temporaryPath);
        }
        http_response_code(500);
        exit('Unable to prepare the protected download.');
    }

    audit_log('DOWNLOAD', 'document', $documentId, [
        'user_id' => $userId,
        'filename' => $filename,
        'download_time' => date('Y-m-d H:i:s'),
        'ip_address' => (string) ($_SERVER['REMOTE_ADDR'] ?? ''),
        'user_agent' => substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 500),
        'document_version' => 'complete',
        'public_download' => $isPublicDownload,
    ]);
}

header('Content-Length: ' . filesize($servePath));
header('Content-Disposition: ' . ($isDownload ? 'attachment' : 'inline') . '; filename="' . addcslashes($downloadFilename, '"\\') . '"');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');
readfile($servePath);
if ($temporaryPath && is_file($temporaryPath)) {
    unlink($temporaryPath);
}
