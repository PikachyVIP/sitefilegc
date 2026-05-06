<?php
session_start();
require_once 'db.php';

// Проверка авторизации и прав
if (!isset($_SESSION['user'])) {
    http_response_code(403);
    echo json_encode(['error' => 'Не авторизован']);
    exit;
}

$admin_rights = $_SESSION['admin_rights'] ?? [];
if (!in_array('edit_any_file', $admin_rights)) {
    http_response_code(403);
    echo json_encode(['error' => 'Нет прав на редактирование файлов']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST['file_id'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Неверный запрос']);
    exit;
}

$file_id = (int)$_POST['file_id'];
$edit_tags = $_POST['edit_tags'] ?? [];
$edit_logins = trim($_POST['edit_logins'] ?? '');

try {
    $pdo->beginTransaction();
    
    // Удаляем старые связи с тегами
    $stmt = $pdo->prepare('DELETE FROM file_tags WHERE file_id = ?');
    $stmt->execute([$file_id]);
    
    // Добавляем новые теги
    if (!empty($edit_tags)) {
        $stmt_tag = $pdo->prepare('INSERT INTO file_tags (file_id, tag_id) VALUES (?, (SELECT id FROM tags WHERE name = ?))');
        foreach ($edit_tags as $tag) {
            $stmt_tag->execute([$file_id, $tag]);
        }
    }
    
    // Удаляем старые доступы по логинам
    $stmt = $pdo->prepare('DELETE FROM file_access WHERE file_id = ?');
    $stmt->execute([$file_id]);
    
    // Добавляем новые доступы по логинам
    if (!empty($edit_logins)) {
        $logins = array_map('trim', explode(',', $edit_logins));
        $stmt_access = $pdo->prepare('INSERT INTO file_access (file_id, user_id) VALUES (?, (SELECT id FROM users WHERE login = ?))');
        foreach ($logins as $login) {
            if ($login !== '') {
                $stmt_access->execute([$file_id, $login]);
            }
        }
    }
    
    $pdo->commit();
    echo json_encode(['success' => true]);
    
} catch (Exception $e) {
    $pdo->rollBack();
    http_response_code(500);
    echo json_encode(['error' => 'Ошибка базы данных']);
}