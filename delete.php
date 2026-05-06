<?php
session_start();
require_once 'db.php';

if (!isset($_SESSION['user'])) {
    header('Location: index.php');
    exit;
}

$user_id = $_SESSION['user_id'];
$user_tags = $_SESSION['tags'] ?? [];
$admin_rights = $_SESSION['admin_rights'] ?? [];

if (isset($_GET['id'])) {
    $file_id = (int) $_GET['id'];

    // Получаем информацию о файле
    $stmt = $pdo->prepare('SELECT * FROM files WHERE id = ?');
    $stmt->execute([$file_id]);
    $file = $stmt->fetch();

    if ($file) {
        $can_delete = false;
        // Владелец может удалить свой файл ВСЕГДА
        if ($file['uploader_id'] == $user_id)
            $can_delete = true;
        // С правом delete_any_file можно удалять чужие
        if (in_array('delete_any_file', $admin_rights))
            $can_delete = true;

        if ($can_delete) {
            // Удаляем физический файл
            $filepath = 'uploads/' . $file['filename'];
            if (file_exists($filepath))
                unlink($filepath);

            // Удаляем запись из БД (каскадно удалятся file_tags и file_access)
            $stmt = $pdo->prepare('DELETE FROM files WHERE id = ?');
            $stmt->execute([$file_id]);
        }
    }
}

header('Location: files.php?deleted=1');
exit;
?>