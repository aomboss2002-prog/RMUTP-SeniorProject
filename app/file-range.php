<?php
declare(strict_types=1);

/** Select a single byte range; ignore unsupported or malformed range syntax. */
function file_response_range(int $size, string $range): array
{
    $full = ['status' => 200, 'start' => 0, 'length' => $size];
    if (!preg_match('/^bytes=(\d*)-(\d*)$/D', trim($range), $match)
        || ($match[1] === '' && $match[2] === '')) return $full;

    $start = $match[1] === '' ? max(0, $size - (int) $match[2]) : (int) $match[1];
    $end = $match[1] === '' || $match[2] === '' ? $size - 1 : min((int) $match[2], $size - 1);
    if ($start >= $size || $end < $start) {
        return ['status' => 416, 'start' => 0, 'length' => 0];
    }
    return ['status' => 206, 'start' => $start, 'length' => $end - $start + 1];
}
