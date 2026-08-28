<?php
declare(strict_types=1);

if (empty($entryPage)) {
    http_response_code(500);
    exit('Page route is not configured.');
}
require_once __DIR__ . '/../controllers/PageController.php';
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');
(new PageController())->render($entryPage);
