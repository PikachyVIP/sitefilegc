<?php
declare(strict_types=1);

require_once __DIR__ . '/session.php';
require_once __DIR__ . '/db.php';

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

$is_personal_file = (bool) ($file['is_personal'] ?? false);
if ($is_personal_file && ((int) $file['uploader_id'] !== $user_id || (int) $file['folder_owner'] !== $user_id)) {
    http_response_code(403);
    exit('Нет доступа к файлу');
}

$can_download = (int) $file['uploader_id'] === $user_id;

if (!$can_download) {
    $stmt = $pdo->prepare('SELECT 1 FROM file_access WHERE file_id = ? AND user_id = ?');
    $stmt->execute([$file_id, $user_id]);
    $can_download = (bool) $stmt->fetch();
}

if (!$can_download && $user_tags !== []) {
    $placeholders = implode(',', array_fill(0, count($user_tags), '?'));
    $stmt = $pdo->prepare(
        "SELECT 1 FROM file_tags ft JOIN tags t ON ft.tag_id = t.id
          WHERE ft.file_id = ? AND t.name IN ({$placeholders})"
    );
    $stmt->execute(array_merge([$file_id], $user_tags));
    $can_download = (bool) $stmt->fetch();
}

if (!$can_download) {
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
