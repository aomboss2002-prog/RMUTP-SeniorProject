<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/store.php';
require_once __DIR__ . '/../app/session.php';
start_app_session();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if (!in_array(strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')), ['GET', 'HEAD'], true)) {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}

echo json_encode(['success' => true, 'token' => csrf_token()], JSON_UNESCAPED_SLASHES);
