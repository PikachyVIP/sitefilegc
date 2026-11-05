<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

if (!isset($_SESSION['user'])) {
    header('Location: index.php');
    exit;
}

$file_id = (int) ($_GET['id'] ?? 0);
$user_id = (int) $_SESSION['user_id'];
$user_tags = $_SESSION['tags'] ?? [];

$stmt = $pdo->prepare(
    'SELECT f.*, u.login AS uploader_login, COALESCE(fo.is_personal, FALSE) AS is_personal,
            fo.created_by AS folder_owner
       FROM files f JOIN users u ON f.uploader_id = u.id
       LEFT JOIN folders fo ON fo.id = f.folder_id
      WHERE f.id = ?'
);
$stmt->execute([$file_id]);
$file = $stmt->fetch();
if (!is_array($file)) {
    http_response_code(404);
    exit('Файл не найден');
}

if (!gc_file_readable($pdo, $file, $user_id)) {
    http_response_code(403);
    exit('Нет доступа к файлу');
}

$uploadDir = realpath(__DIR__ . '/uploads');
$filepath = realpath(__DIR__ . '/uploads/' . basename((string) $file['filename']));
if ($uploadDir === false || $filepath === false || !str_starts_with($filepath, $uploadDir . DIRECTORY_SEPARATOR) || !is_file($filepath)) {
    http_response_code(404);
    exit('Файл не найден на сервере');
}

if (ob_get_level()) {
    ob_end_clean();
}

$downloadName = preg_replace('/[\r\n"]/', '_', basename((string) $file['original_name'])) ?: 'file';
header('X-Content-Type-Options: nosniff');
header('Content-Description: File Transfer');
header('Content-Type: application/octet-stream');
header('Content-Disposition: attachment; filename="' . addcslashes($downloadName, '"\\') . '"; filename*=UTF-8\'\'' . rawurlencode($downloadName));
header('Content-Length: ' . filesize($filepath));
header('Cache-Control: no-store');
header('Pragma: no-cache');
header('Expires: 0');

readfile($filepath);
