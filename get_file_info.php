<?php
session_start();
require_once 'db.php';

if (!isset($_SESSION['user'])) {
    http_response_code(403);
    echo json_encode(['error' => 'Не авторизован']);
    exit;
}

$admin_rights = $_SESSION['admin_rights'] ?? [];
if (!in_array('edit_any_file', $admin_rights)) {
    http_response_code(403);
    echo json_encode(['error' => 'Нет прав']);
    exit;
}

if (!isset($_GET['id'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Не указан ID файла']);
    exit;
}

$file_id = (int)$_GET['id'];

// Получаем теги файла
$stmt = $pdo->prepare('SELECT t.name FROM tags t JOIN file_tags ft ON t.id = ft.tag_id WHERE ft.file_id = ?');
$stmt->execute([$file_id]);
$tags = $stmt->fetchAll(PDO::FETCH_COLUMN);

// Получаем логины, у которых есть доступ
$stmt = $pdo->prepare('SELECT u.login FROM users u JOIN file_access fa ON u.id = fa.user_id WHERE fa.file_id = ?');
$stmt->execute([$file_id]);
$logins = $stmt->fetchAll(PDO::FETCH_COLUMN);

header('Content-Type: application/json');
echo json_encode([
    'tags' => $tags,
    'logins' => $logins
]);