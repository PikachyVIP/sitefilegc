<?php
/**
 * Публичная выдача файла релиза приложения (MSI) для автообновления.
 *
 * GET-параметр: id — идентификатор записи в app_releases.
 */

require_once 'db.php';

$id = (int) ($_GET['id'] ?? 0);

if ($id <= 0) {
    http_response_code(400);
    die('Не указан релиз');
}

$stmt = $pdo->prepare('SELECT id, filename, original_name, file_size FROM app_releases WHERE id = ? AND is_active');
$stmt->execute([$id]);
$release = $stmt->fetch();

if (!$release) {
    http_response_code(404);
    die('Релиз не найден');
}

$updates_dir = realpath(__DIR__ . '/updates');
$filepath = $updates_dir !== false ? realpath($updates_dir . DIRECTORY_SEPARATOR . basename((string) $release['filename'])) : false;

if ($filepath === false || !is_file($filepath) || strpos($filepath, $updates_dir . DIRECTORY_SEPARATOR) !== 0) {
    http_response_code(404);
    die('Файл релиза отсутствует на сервере');
}

// Считаем скачивания (до отдачи файла, чтобы обрыв соединения не терял статистику).
$pdo->prepare('UPDATE app_releases SET downloads = downloads + 1 WHERE id = ?')->execute([$id]);

if (ob_get_level()) {
    ob_end_clean();
}

$downloadName = preg_replace('/[\r\n"]/', '_', basename((string) $release['original_name'])) ?: 'release';
header('X-Content-Type-Options: nosniff');
header('Content-Description: File Transfer');
header('Content-Type: application/octet-stream');
header('Content-Disposition: attachment; filename="' . addcslashes($downloadName, '"\\') . '"; filename*=UTF-8\'\'' . rawurlencode($downloadName));
header('Content-Length: ' . filesize($filepath));
header('Cache-Control: no-store');
header('Pragma: public');

readfile($filepath);
exit;
