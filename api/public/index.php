<?php
declare(strict_types=1);

require_once __DIR__ . '/../../app/store.php';
require_once __DIR__ . '/../../app/helpers.php';
require_once __DIR__ . '/../../app/public-catalog.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: public, max-age=30, stale-while-revalidate=60');
header('X-Content-Type-Options: nosniff');

if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'GET') {
    http_response_code(405);
    header('Allow: GET');
    echo json_encode(['success' => false, 'message' => 'Method not allowed.'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

try {
    $data = load_data();
    $catalog = public_completed_catalog($data, [
        'q' => trim((string) ($_GET['q'] ?? '')),
        'year' => trim((string) ($_GET['year'] ?? '')),
        'faculty' => trim((string) ($_GET['faculty'] ?? '')),
        'major' => trim((string) ($_GET['major'] ?? '')),
        'category' => trim((string) ($_GET['category'] ?? '')),
        'page' => max(1, (int) ($_GET['page'] ?? 1)),
    ]);
    echo json_encode(['success' => true, 'data' => $catalog], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
} catch (Throwable $error) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'ไม่สามารถโหลดคลังโครงงานได้ในขณะนี้'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
