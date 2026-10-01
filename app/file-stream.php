<?php
declare(strict_types=1);

/** Short-lived, single-file capability issued only after document authorization. */
function file_stream_ticket(string $pathname, string $filename, bool $download, string $secret, ?int $now = null): string
{
    $payload = rtrim(strtr(base64_encode(json_encode([
        'path' => $pathname, 'filename' => $filename, 'download' => $download,
        'exp' => ($now ?? time()) + 300,
    ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)), '+/', '-_'), '=');
    return $payload . '.' . hash_hmac('sha256', 'pdf-stream-v1:' . $payload, $secret);
}
