<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

$user = gc_require_login();
$uid = (int) $user['id'];
$fileId = isset($_GET['id']) ? (int) $_GET['id'] : 0;

if ($fileId <= 0) {
    http_response_code(404);
    exit('Файл не найден');
}

$stmt = $pdo->prepare(
    'SELECT f.id, f.filename, f.original_name, f.mime_type, f.file_size, f.uploader_id,
            COALESCE(fo.is_personal, FALSE) AS is_personal, fo.created_by AS folder_owner
       FROM files f
       LEFT JOIN folders fo ON fo.id = f.folder_id
      WHERE f.id = ?'
);
$stmt->execute([$fileId]);
$file = $stmt->fetch();
if (!is_array($file)) {
    http_response_code(404);
    exit('Файл не найден');
}

if (!gc_file_readable($pdo, $file, $uid)) {
    http_response_code(403);
    exit('Нет доступа к файлу');
}

$kind = gc_preview_kind((string) $file['mime_type'], (string) $file['original_name']);
if ($kind === '') {
    http_response_code(415);
    exit('Этот формат нельзя открыть для просмотра');
}

$uploadDir = realpath(__DIR__ . '/uploads');
$path = realpath(__DIR__ . '/uploads/' . basename((string) $file['filename']));
if ($uploadDir === false || $path === false || !str_starts_with($path, $uploadDir . DIRECTORY_SEPARATOR) || !is_file($path)) {
    http_response_code(404);
    exit('Файл не найден на сервере');
}

$size = filesize($path);
if ($size === false) {
    http_response_code(404);
    exit('Файл не найден на сервере');
}

$storedMime = strtolower(trim(explode(';', (string) $file['mime_type'])[0]));
$ext = strtolower(pathinfo((string) $file['original_name'], PATHINFO_EXTENSION));
$imageTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/bmp'];
$videoTypes = ['video/mp4', 'video/webm', 'video/ogg'];
$extTypes = [
    'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png',
    'gif' => 'image/gif', 'webp' => 'image/webp', 'bmp' => 'image/bmp',
    'mp4' => 'video/mp4', 'webm' => 'video/webm', 'ogv' => 'video/ogg', 'ogg' => 'video/ogg',
];

$contentType = 'text/plain; charset=utf-8';
if ($kind === 'image') {
    $contentType = in_array($storedMime, $imageTypes, true) ? $storedMime : ($extTypes[$ext] ?? '');
} elseif ($kind === 'video') {
    $contentType = in_array($storedMime, $videoTypes, true) ? $storedMime : ($extTypes[$ext] ?? '');
}
if ($contentType === '') {
    http_response_code(415);
    exit('Этот формат нельзя открыть для просмотра');
}

$name = preg_replace('/[\r\n"]/', '_', basename((string) $file['original_name'])) ?: 'file';

if (ob_get_level()) {
    ob_end_clean();
}

header('X-Content-Type-Options: nosniff');
header('Content-Security-Policy: default-src \'none\'; sandbox');
header('Cross-Origin-Resource-Policy: same-origin');
header('Cache-Control: private, no-store');
header('Pragma: no-cache');
header('Expires: 0');
header('Content-Disposition: inline; filename="' . addcslashes($name, '"\\') . '"; filename*=UTF-8\'\'' . rawurlencode($name));
header('Content-Type: ' . $contentType);
header('Accept-Ranges: bytes');

$start = 0;
$length = $size;
$status = 200;
$maxTextBytes = 2 * 1024 * 1024;
$truncated = false;

if ($kind === 'text') {
    if ($size > $maxTextBytes) {
        $length = $maxTextBytes;
        $truncated = true;
    }
} elseif (isset($_SERVER['HTTP_RANGE']) && is_string($_SERVER['HTTP_RANGE'])) {
    if (preg_match('/^bytes=(\d*)-(\d*)$/', trim($_SERVER['HTTP_RANGE']), $matches)) {
        if ($matches[1] === '' && $matches[2] !== '') {
            $suffix = (int) $matches[2];
            if ($suffix > 0) {
                $start = max(0, $size - $suffix);
                $length = $size - $start;
                $status = 206;
            }
        } else {
            $start = (int) $matches[1];
            $end = $matches[2] !== '' ? (int) $matches[2] : $size - 1;
            if ($start <= $end && $start < $size) {
                $end = min($end, $size - 1);
                $length = $end - $start + 1;
                $status = 206;
            } else {
                http_response_code(416);
                header('Content-Range: bytes */' . $size);
                exit;
            }
        }
    }
}

if ($status === 206) {
    header('Content-Range: bytes ' . $start . '-' . ($start + $length - 1) . '/' . $size);
}
if ($truncated) {
    header('X-GC-Preview-Truncated: 1');
}
header('Content-Length: ' . $length);
http_response_code($status);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'HEAD') {
    exit;
}

$handle = fopen($path, 'rb');
if ($handle === false) {
    exit;
}
if ($start > 0) {
    fseek($handle, $start);
}

$remaining = $length;
while ($remaining > 0 && !feof($handle)) {
    $chunk = fread($handle, min(65536, $remaining));
    if ($chunk === false || $chunk === '') {
        break;
    }
    echo $chunk;
    $remaining -= strlen($chunk);
}
fclose($handle);
