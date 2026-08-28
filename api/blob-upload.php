<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/store.php';
require_once __DIR__ . '/../app/session.php';
require_once __DIR__ . '/../app/storage.php';
start_app_session();

header('Content-Type: application/json; charset=utf-8');

function blob_upload_response(array $payload, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    blob_upload_response(['message' => 'Method not allowed.'], 405);
}
require_csrf_token();

$user = $_SESSION['app_user'] ?? [];
if (($user['role'] ?? '') !== 'student' || empty($user['id'])) {
    blob_upload_response(['message' => 'Student authentication required.'], 401);
}
if (storage_driver() !== 'vercel_blob') {
    blob_upload_response(['message' => 'Direct Blob uploads are not enabled.'], 409);
}

$request = json_decode((string) file_get_contents('php://input'), true);
if (!is_array($request) || ($request['type'] ?? '') !== 'blob.generate-client-token') {
    blob_upload_response(['message' => 'Invalid Blob upload request.'], 422);
}

$payload = $request['payload'] ?? [];
$pathname = trim((string) ($payload['pathname'] ?? ''), '/');
$clientPayload = json_decode((string) ($payload['clientPayload'] ?? ''), true);
if (!is_array($clientPayload)) $clientPayload = [];

$kind = strtolower(trim((string) ($clientPayload['kind'] ?? '')));
$namespace = $kind === 'profile' ? 'student' : strtolower(trim((string) ($clientPayload['stage'] ?? '')));
$allowedNamespaces = $kind === 'profile' ? ['student'] : ['proposal', 'draft', 'complete'];
if (!in_array($namespace, $allowedNamespaces, true)) {
    blob_upload_response(['message' => 'Invalid upload destination.'], 422);
}

try {
    $filename = storage_safe_filename($pathname);
    if (!hash_equals(storage_blob_pathname($namespace, $filename), $pathname)) {
        throw new RuntimeException('Upload path is outside the project namespace.');
    }
} catch (Throwable $exception) {
    blob_upload_response(['message' => $exception->getMessage()], 422);
}

if ($kind === 'profile') {
    if (!preg_match('/\.(?:jpe?g|png|gif|webp)$/i', $filename)) {
        blob_upload_response(['message' => 'Image files only.'], 422);
    }
    $allowedContentTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
    $maximumSizeInBytes = 5 * 1024 * 1024;
} else {
    if (!str_ends_with(strtolower($filename), '.pdf')) {
        blob_upload_response(['message' => 'PDF files only.'], 422);
    }
    $allowedContentTypes = ['application/pdf'];
    $maximumSizeInBytes = 20 * 1024 * 1024;
}

$tokenPayload = [
    'pathname' => $pathname,
    'allowedContentTypes' => $allowedContentTypes,
    'maximumSizeInBytes' => $maximumSizeInBytes,
    'validUntil' => (time() + 3600) * 1000,
    'addRandomSuffix' => false,
    'allowOverwrite' => false,
];
$encodedPayload = base64_encode((string) json_encode($tokenPayload, JSON_UNESCAPED_SLASHES));
$signature = hash_hmac('sha256', $encodedPayload, storage_blob_token());
$signedToken = base64_encode($signature . '.' . $encodedPayload);
$clientToken = 'vercel_blob_client_' . storage_blob_store_id() . '_' . $signedToken;

blob_upload_response([
    'type' => 'blob.generate-client-token',
    'clientToken' => $clientToken,
]);
