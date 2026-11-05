<?php
require_once __DIR__ . '/session.php';

define('GC_JSON_ERRORS', true);

require_once 'db.php';

if (!isset($_SESSION['user'])) {
    http_response_code(403);
    echo json_encode(['error' => 'Не авторизован']);
    exit;
}

$admin_rights = $_SESSION['admin_rights'] ?? [];
$is_superadmin = (int) ($_SESSION['user_id'] ?? 0) === 1;
if (!$is_superadmin && !in_array('edit_any_file', $admin_rights, true)) {
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
$user_id = (int)$_SESSION['user_id'];

$stmt = $pdo->prepare(
    'SELECT f.uploader_id, COALESCE(fo.is_personal, FALSE) AS is_personal, fo.created_by AS folder_owner'
    . ' FROM files f LEFT JOIN folders fo ON fo.id = f.folder_id WHERE f.id = ?'
);
$stmt->execute([$file_id]);
$file_row = $stmt->fetch();
if (!$file_row || (!$is_superadmin && gc_bool_value($file_row['is_personal'])
    && ((int) $file_row['uploader_id'] !== $user_id || (int) $file_row['folder_owner'] !== $user_id))) {
    http_response_code(403);
    echo json_encode(['error' => 'Нет доступа']);
    exit;
}

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