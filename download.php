<?php
session_start();
require_once 'db.php';

if (!isset($_SESSION['user'])) {
    header('Location: index.php');
    exit;
}

if (!isset($_GET['id'])) {
    header('Location: files.php');
    exit;
}

$file_id = (int)$_GET['id'];
$user_id = $_SESSION['user_id'];
$user_tags = $_SESSION['tags'] ?? [];

// Получаем информацию о файле
$stmt = $pdo->prepare('
    SELECT f.*, u.login AS uploader_login
    FROM files f
    JOIN users u ON f.uploader_id = u.id
    WHERE f.id = ?
');
$stmt->execute([$file_id]);
$file = $stmt->fetch();

if (!$file) {
    die('Файл не найден');
}

// Проверяем доступ
$can_download = false;

// Владелец
if ($file['uploader_id'] == $user_id) {
    $can_download = true;
}

// Доступ по логину
if (!$can_download) {
    $stmt = $pdo->prepare('SELECT 1 FROM file_access WHERE file_id = ? AND user_id = ?');
    $stmt->execute([$file_id, $user_id]);
    if ($stmt->fetch()) $can_download = true;
}

// Доступ по тегам
if (!$can_download && !empty($user_tags)) {
    $placeholders = implode(',', array_fill(0, count($user_tags), '?'));
    $stmt = $pdo->prepare("
        SELECT 1 
        FROM file_tags ft 
        JOIN tags t ON ft.tag_id = t.id 
        WHERE ft.file_id = ? AND t.name IN ($placeholders)
    ");
    $stmt->execute(array_merge([$file_id], $user_tags));
    if ($stmt->fetch()) $can_download = true;
}

if (!$can_download) {
    die('Нет доступа к файлу');
}

// Отдаём файл
$filepath = 'uploads/' . $file['filename'];

if (!file_exists($filepath)) {
    die('Файл не найден на сервере');
}

// Очищаем буфер вывода
if (ob_get_level()) ob_end_clean();

// Отправляем заголовки
header('Content-Description: File Transfer');
header('Content-Type: application/octet-stream');
header('Content-Disposition: attachment; filename="' . addcslashes($file['original_name'], '"\\') . '"');
header('Content-Length: ' . filesize($filepath));
header('Cache-Control: must-revalidate');
header('Pragma: public');
header('Expires: 0');

// Отдаём файл
readfile($filepath);
exit;
?>