<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/hosting_lib.php';

function gc_media_stream_fail(int $status, string $message): never
{
    http_response_code($status);
    header('Content-Type: text/plain; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'HEAD') {
        echo $message;
    }
    exit;
}

gc_media_ensure_schema($pdo);

$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
if ($method !== 'GET' && $method !== 'HEAD') {
    header('Allow: GET, HEAD');
    gc_media_stream_fail(405, 'Method not allowed');
}

$token = '';
$pathInfo = (string) ($_SERVER['PATH_INFO'] ?? '');
if ($pathInfo !== '' && preg_match('#^/([a-f0-9]{32})(?:/|$)#i', $pathInfo, $match) === 1) {
    $token = strtolower($match[1]);
} elseif (isset($_GET['t']) && is_string($_GET['t'])) {
    $token = strtolower(trim($_GET['t']));
} else {
    $requestPath = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
    $script = (string) ($_SERVER['SCRIPT_NAME'] ?? '/hosting_file.php');
    if (str_starts_with($requestPath, $script . '/')) {
        $rest = substr($requestPath, strlen($script) + 1);
        $candidate = explode('/', $rest)[0] ?? '';
        if (preg_match('/^[a-f0-9]{32}$/i', $candidate) === 1) {
            $token = strtolower($candidate);
        }
    }
}

if ($token === '') {
    gc_media_stream_fail(404, 'Not found');
}

$uploadDir = gc_media_upload_dir();
gc_media_purge($pdo, $uploadDir);

$row = gc_media_find($pdo, $token);
if (!$row || ($row['expires_at'] !== null && strtotime((string) $row['expires_at']) <= time())) {
    gc_media_stream_fail(404, 'Not found');
}

$path = gc_media_path((string) $row['filename']);
$mime = gc_media_mime((string) $row['mime_type'], (string) $row['original_name']);
if ($path === null || $mime === null) {
    gc_media_stream_fail(404, 'Not found');
}

$size = (int) filesize($path);
if ($size < 0) {
    gc_media_stream_fail(404, 'Not found');
}

$start = 0;
$end = $size > 0 ? $size - 1 : 0;
$partial = false;
$range = (string) ($_SERVER['HTTP_RANGE'] ?? '');
if ($range !== '' && preg_match('/^bytes=(\d*)-(\d*)$/', trim($range), $match) === 1) {
    $from = $match[1] === '' ? null : (int) $match[1];
    $to = $match[2] === '' ? null : (int) $match[2];
    if ($from === null && $to === null) {
        gc_media_stream_fail(416, 'Range not satisfiable');
    }
    if ($from === null) {
        $suffix = (int) $to;
        if ($suffix <= 0) {
            gc_media_stream_fail(416, 'Range not satisfiable');
        }
        $start = max(0, $size - $suffix);
        $end = $size - 1;
    } else {
        $start = $from;
        $end = $to === null ? $size - 1 : min($to, $size - 1);
    }
    if ($start > $end || $start >= $size) {
        header('Content-Range: bytes */' . $size);
        gc_media_stream_fail(416, 'Range not satisfiable');
    }
    $partial = true;
}

$length = $end - $start + 1;
http_response_code($partial ? 206 : 200);
header('Content-Type: ' . $mime);
header('Content-Length: ' . $length);
header('Accept-Ranges: bytes');
header('X-Content-Type-Options: nosniff');
header('Access-Control-Allow-Origin: *');
header('Cache-Control: public, max-age=300');
if ($partial) {
    header('Content-Range: bytes ' . $start . '-' . $end . '/' . $size);
}

$download = isset($_GET['download']) && $_GET['download'] === '1';
$fallbackName = preg_replace('/[^A-Za-z0-9._-]+/', '_', (string) $row['original_name']) ?: 'media';
header(
    'Content-Disposition: ' . ($download ? 'attachment' : 'inline')
    . '; filename="' . $fallbackName . '"'
    . "; filename*=UTF-8''" . rawurlencode((string) $row['original_name'])
);

session_write_close();

if ($method === 'HEAD' || $length === 0) {
    exit;
}

$handle = fopen($path, 'rb');
if ($handle === false) {
    gc_media_stream_fail(404, 'Not found');
}
fseek($handle, $start);
$remaining = $length;
while ($remaining > 0 && !feof($handle)) {
    $chunk = fread($handle, min(65536, $remaining));
    if ($chunk === false || $chunk === '') {
        break;
    }
    echo $chunk;
    $remaining -= strlen($chunk);
    if (connection_aborted()) {
        break;
    }
}
fclose($handle);
