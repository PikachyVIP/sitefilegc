<?php
declare(strict_types=1);

define('GC_JSON_ERRORS', true);
require_once __DIR__ . '/bootstrap.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    echo json_encode(['error' => 'Метод не разрешён']);
    exit;
}

if (gc_user() === null) {
    http_response_code(403);
    echo json_encode(['error' => 'Не авторизован']);
    exit;
}
if (!gc_has_right('edit_any_file')) {
    http_response_code(403);
    echo json_encode(['error' => 'Нет прав на редактирование файлов']);
    exit;
}

gc_csrf_check();

$file_id = (int) ($_POST['file_id'] ?? 0);
$edit_tags = is_array($_POST['edit_tags'] ?? null) ? $_POST['edit_tags'] : [];
$edit_logins = is_string($_POST['edit_logins'] ?? null) ? trim($_POST['edit_logins']) : '';

$stmt = $pdo->prepare('SELECT 1 FROM files WHERE id = ?');
$stmt->execute([$file_id]);
if ($file_id <= 0 || !$stmt->fetchColumn()) {
    http_response_code(404);
    echo json_encode(['error' => 'Файл не найден']);
    exit;
}

try {
    $pdo->beginTransaction();

    $pdo->prepare('DELETE FROM file_tags WHERE file_id = ?')->execute([$file_id]);
    if ($edit_tags !== []) {
        $stmt_tag = $pdo->prepare(
            'INSERT INTO file_tags (file_id, tag_id)
             SELECT ?, id FROM tags WHERE name = ? ON CONFLICT DO NOTHING'
        );
        foreach ($edit_tags as $tag) {
            if (is_string($tag) && $tag !== '') {
                $stmt_tag->execute([$file_id, $tag]);
            }
        }
    }

    $pdo->prepare('DELETE FROM file_access WHERE file_id = ?')->execute([$file_id]);
    if ($edit_logins !== '') {
        $logins = array_map('trim', explode(',', $edit_logins));
        $stmt_access = $pdo->prepare(
            'INSERT INTO file_access (file_id, user_id)
             SELECT ?, id FROM users WHERE login = ? ON CONFLICT DO NOTHING'
        );
        foreach ($logins as $login) {
            if ($login !== '') {
                $stmt_access->execute([$file_id, $login]);
            }
        }
    }

    $pdo->commit();
    echo json_encode(['success' => true]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(500);
    echo json_encode(['error' => 'Ошибка базы данных']);
}
