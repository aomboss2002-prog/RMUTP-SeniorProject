<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/app/store.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$delete = in_array('--delete', $argv, true);
$root = realpath(dirname(__DIR__) . '/uploads');
if ($root === false || !is_dir($root)) {
    throw new RuntimeException('Uploads directory is missing.');
}

$pdo = database_connection();
$references = ['proposal' => [], 'draft' => [], 'complete' => [], 'student' => []];
foreach ($pdo->query("SELECT type, filename FROM documents WHERE TRIM(filename) <> ''")->fetchAll() as $row) {
    $type = strtolower(trim((string) ($row['type'] ?? '')));
    if (!isset($references[$type])) continue;
    $filename = basename(str_replace('\\', '/', (string) $row['filename']));
    if ($filename !== '') $references[$type][$filename] = true;
}
foreach (['students', 'advisors'] as $table) {
    foreach ($pdo->query("SELECT photo FROM {$table} WHERE TRIM(COALESCE(photo, '')) <> ''")->fetchAll(PDO::FETCH_COLUMN) as $photo) {
        $filename = basename(str_replace('\\', '/', (string) $photo));
        if ($filename !== '') $references['student'][$filename] = true;
    }
}

$unused = [];
$bytes = 0;
foreach (array_keys($references) as $type) {
    $directory = realpath($root . DIRECTORY_SEPARATOR . $type);
    if ($directory === false || !str_starts_with($directory, $root . DIRECTORY_SEPARATOR)) continue;
    foreach (new DirectoryIterator($directory) as $file) {
        if (!$file->isFile() || $file->isLink() || $file->getFilename() === '.gitkeep') continue;
        if (isset($references[$type][$file->getFilename()])) continue;
        $path = $file->getRealPath();
        if ($path === false || !str_starts_with($path, $directory . DIRECTORY_SEPARATOR)) continue;
        $unused[] = str_replace('\\', '/', substr($path, strlen(dirname(__DIR__)) + 1));
        $bytes += $file->getSize();
        if ($delete && !unlink($path)) throw new RuntimeException("Could not delete {$path}");
    }
}

echo ($delete ? 'UNUSED_UPLOADS_DELETED' : 'UNUSED_UPLOADS_DRY_RUN')
    . ' files=' . count($unused)
    . ' size_mb=' . number_format($bytes / 1048576, 2) . PHP_EOL;
foreach ($unused as $path) echo $path . PHP_EOL;

