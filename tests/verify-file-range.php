<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/file-range.php';

$size = 3 * 1024 * 1024 + 57;
$cases = [
    ['', 200, 0, $size],
    ['bytes=0-', 206, 0, $size],
    ['bytes=0-2097151', 206, 0, 2097152],
    ['bytes=1048576-', 206, 1048576, $size - 1048576],
    ['bytes=-1024', 206, $size - 1024, 1024],
    ['bytes=-9999999', 206, 0, $size],
    ['bytes=0-9999999', 206, 0, $size],
    ['bytes=0-0', 206, 0, 1],
    ['bytes=' . $size . '-', 416, 0, 0],
    ['bytes=10-5', 416, 0, 0],
    ['bytes=-0', 416, 0, 0],
    ['bytes=-', 200, 0, $size],
    ['bytes=0-5,10-15', 200, 0, $size],
    ['invalid bytes=0-5', 200, 0, $size],
];
foreach ($cases as [$header, $status, $start, $length]) {
    if (file_response_range($size, $header) !== compact('status', 'start', 'length')) {
        throw new RuntimeException('Incorrect PDF range response: ' . $header);
    }
}
if (file_response_range(0, '') !== ['status' => 200, 'start' => 0, 'length' => 0]) {
    throw new RuntimeException('Empty file response regression');
}
echo "FILE_RANGE_OK: full files over 1 MB, partial, suffix and invalid ranges\n";
