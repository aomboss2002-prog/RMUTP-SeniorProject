<?php
declare(strict_types=1);

// Single Vercel Function entry point. Static assets are excluded by vercel.json.
$root = dirname(__DIR__);
$_SERVER['DOCUMENT_ROOT'] = $root;
$_SERVER['HTTPS'] = 'on';

$requestPath = rawurldecode((string) (parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/'));
$requestPath = '/' . ltrim(str_replace('\\', '/', $requestPath), '/');
if (str_contains($requestPath, "\0") || preg_match('#(?:^|/)\.\.(?:/|$)#', $requestPath)) {
    http_response_code(400);
    exit('Invalid request path.');
}

$relative = trim($requestPath, '/');
$candidate = $relative === '' ? $root . '/index.php' : $root . '/' . $relative;
if (is_dir($candidate)) $candidate .= '/index.php';
if (!str_ends_with(strtolower($candidate), '.php') && is_file($candidate . '.php')) $candidate .= '.php';

$realRoot = realpath($root);
$realCandidate = realpath($candidate);
if ($realRoot && $realCandidate && str_starts_with($realCandidate, $realRoot . DIRECTORY_SEPARATOR)
    && str_ends_with(strtolower($realCandidate), '.php')) {
    require $realCandidate;
    exit;
}

if (str_starts_with($requestPath, '/api/')) {
    header('Content-Type: application/json; charset=utf-8');
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => 'API endpoint not found.']);
    exit;
}

http_response_code(404);
require $root . '/404.php';
