<?php
declare(strict_types=1);

function storage_config(): array
{
    return function_exists('env_config') ? env_config() : [];
}

function storage_driver(): string
{
    $config = storage_config();
    $driver = strtolower(trim((string) ($config['STORAGE_DRIVER'] ?? getenv('STORAGE_DRIVER') ?: 'local')));
    return in_array($driver, ['vercel_blob', 'blob'], true) ? 'vercel_blob' : 'local';
}

function storage_blob_token(): string
{
    $token = trim((string) (storage_config()['BLOB_READ_WRITE_TOKEN'] ?? getenv('BLOB_READ_WRITE_TOKEN') ?: ''));
    if ($token === '' || !str_starts_with($token, 'vercel_blob_rw_')) {
        throw new RuntimeException('BLOB_READ_WRITE_TOKEN is missing or invalid.');
    }
    return $token;
}

function storage_blob_store_id(): string
{
    $parts = explode('_', storage_blob_token());
    $storeId = trim((string) ($parts[3] ?? ''));
    if ($storeId === '') throw new RuntimeException('Unable to determine the Vercel Blob store ID.');
    return $storeId;
}

function storage_safe_namespace(string $namespace): string
{
    $namespace = strtolower(trim($namespace));
    if (!in_array($namespace, ['student', 'proposal', 'draft', 'complete'], true)) {
        throw new InvalidArgumentException('Invalid storage namespace.');
    }
    return $namespace;
}

function storage_safe_filename(string $filename): string
{
    $filename = basename(str_replace('\\', '/', trim($filename)));
    if ($filename === '' || !preg_match('/^[A-Za-z0-9._-]+$/', $filename)) {
        throw new InvalidArgumentException('Invalid storage filename.');
    }
    return $filename;
}

function storage_blob_prefix(): string
{
    $prefix = trim((string) (storage_config()['BLOB_PATH_PREFIX'] ?? 'rmutp'), '/ ');
    $prefix = preg_replace('#[^A-Za-z0-9/_-]+#', '-', $prefix) ?: 'rmutp';
    return trim($prefix, '/');
}

function storage_blob_pathname(string $namespace, string $filename): string
{
    return storage_blob_prefix() . '/' . storage_safe_namespace($namespace) . '/' . storage_safe_filename($filename);
}

function storage_blob_url(string $namespace, string $filename): string
{
    $pathname = storage_blob_pathname($namespace, $filename);
    $encoded = implode('/', array_map('rawurlencode', explode('/', $pathname)));
    return 'https://' . storage_blob_store_id() . '.private.blob.vercel-storage.com/' . $encoded;
}

function storage_curl(string $url, array $options = []): CurlHandle
{
    if (!function_exists('curl_init')) throw new RuntimeException('The PHP cURL extension is required for cloud storage.');
    $handle = curl_init($url);
    if ($handle === false) throw new RuntimeException('Unable to initialize cloud storage request.');
    curl_setopt_array($handle, $options + [
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 60,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 3,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . storage_blob_token()],
    ]);
    return $handle;
}

function storage_local_path(string $namespace, string $filename): string
{
    return dirname(__DIR__) . '/uploads/' . storage_safe_namespace($namespace) . '/' . storage_safe_filename($filename);
}

function storage_put_uploaded_file(string $temporaryFile, string $namespace, string $filename, string $contentType): string
{
    $namespace = storage_safe_namespace($namespace);
    $filename = storage_safe_filename($filename);
    if (!is_file($temporaryFile)) throw new RuntimeException('Uploaded temporary file is missing.');

    if (storage_driver() === 'local') {
        $target = storage_local_path($namespace, $filename);
        $directory = dirname($target);
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new RuntimeException('Unable to create the upload directory.');
        }
        if (!move_uploaded_file($temporaryFile, $target)) {
            throw new RuntimeException('Unable to save the uploaded file.');
        }
        return $filename;
    }

    $pathname = storage_blob_pathname($namespace, $filename);
    $url = 'https://vercel.com/api/blob/?pathname=' . rawurlencode($pathname);
    $stream = fopen($temporaryFile, 'rb');
    if ($stream === false) throw new RuntimeException('Unable to read the uploaded file.');
    $headers = [
        'Authorization: Bearer ' . storage_blob_token(),
        'x-api-version: 12',
        'x-vercel-blob-store-id: ' . storage_blob_store_id(),
        'x-vercel-blob-access: private',
        'x-add-random-suffix: 0',
        'x-allow-overwrite: 0',
        'x-content-type: ' . $contentType,
    ];
    $handle = storage_curl($url, [
        CURLOPT_UPLOAD => true,
        CURLOPT_INFILE => $stream,
        CURLOPT_INFILESIZE => filesize($temporaryFile),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => $headers,
    ]);
    $response = curl_exec($handle);
    $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
    $error = curl_error($handle);
    unset($handle);
    fclose($stream);
    if ($response === false || $status < 200 || $status >= 300) {
        throw new RuntimeException('Vercel Blob upload failed' . ($error !== '' ? ': ' . $error : " (HTTP {$status})"));
    }
    return $filename;
}

function storage_exists(string $namespace, string $filename): bool
{
    try {
        if (storage_driver() === 'local') return is_file(storage_local_path($namespace, $filename));
        $handle = storage_curl(storage_blob_url($namespace, $filename), [
            CURLOPT_NOBODY => true,
            CURLOPT_RETURNTRANSFER => true,
        ]);
        curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        unset($handle);
        return $status >= 200 && $status < 300;
    } catch (Throwable) {
        return false;
    }
}

function storage_accept_blob_reference(string $namespace, string $pathname): string
{
    $filename = storage_safe_filename($pathname);
    if (!hash_equals(storage_blob_pathname($namespace, $filename), trim($pathname, '/'))) {
        throw new RuntimeException('The uploaded Blob path is outside the allowed project namespace.');
    }
    if (!storage_exists($namespace, $filename)) throw new RuntimeException('The uploaded Blob could not be verified.');
    return $filename;
}

/** @return array{path:string,temporary:bool} */
function storage_materialize(string $namespace, string $filename): array
{
    if (storage_driver() === 'local') {
        $path = storage_local_path($namespace, $filename);
        if (!is_file($path)) throw new RuntimeException('Stored file not found.');
        return ['path' => $path, 'temporary' => false];
    }

    $extension = pathinfo(storage_safe_filename($filename), PATHINFO_EXTENSION);
    $temporary = tempnam(sys_get_temp_dir(), 'rmutp-storage-');
    if ($temporary === false) throw new RuntimeException('Unable to create a temporary download file.');
    if ($extension !== '') {
        $renamed = $temporary . '.' . $extension;
        if (rename($temporary, $renamed)) $temporary = $renamed;
    }
    $stream = fopen($temporary, 'wb');
    if ($stream === false) throw new RuntimeException('Unable to open a temporary download file.');
    $handle = storage_curl(storage_blob_url($namespace, $filename), [
        CURLOPT_FILE => $stream,
        CURLOPT_RETURNTRANSFER => false,
    ]);
    $result = curl_exec($handle);
    $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
    unset($handle);
    fclose($stream);
    if ($result === false || $status < 200 || $status >= 300) {
        @unlink($temporary);
        throw new RuntimeException("Unable to download the stored Blob (HTTP {$status}).");
    }
    return ['path' => $temporary, 'temporary' => true];
}

function storage_delete(string $namespace, string $filename): void
{
    if (storage_driver() === 'local') {
        $path = storage_local_path($namespace, $filename);
        if (is_file($path)) @unlink($path);
    }
    // Remote deletion is intentionally deferred. Document history may still
    // reference the Blob and Vercel lifecycle policies can remove old objects.
}
