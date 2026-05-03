<?php
session_start();
require_once 'db.php';

if (!isset($_SESSION['user'])) {
    header('Location: index.php');
    exit;
}

$upload_dir = 'uploads/';
$all_tags = ['Глава GC', 'Команда GC', 'Програмист GC', 'Художник GC', 'Билдер GC', 'Игрок', 'Доверенный'];

if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);

if (!isset($_FILES['userfile']) || $_FILES['userfile']['error'] !== UPLOAD_ERR_OK) {
    header('Location: files.php?error=1');
    exit;
}

$tmp_name = $_FILES['userfile']['tmp_name'];
$original_name = $_FILES['userfile']['name'];

// Безопасное имя файла
$ext = pathinfo($original_name, PATHINFO_EXTENSION);
$safe_name = bin2hex(random_bytes(16)) . ($ext ? '.' . $ext : '');

$target = $upload_dir . $safe_name;

if (!move_uploaded_file($tmp_name, $target)) {
    header('Location: files.php?error=2');
    exit;
}

// Сохраняем файл в БД
$stmt = $pdo->prepare('INSERT INTO files (filename, original_name, uploader_id) VALUES (?, ?, ?)');
$stmt->execute([$safe_name, $original_name, $_SESSION['user_id']]);
$file_id = $pdo->lastInsertId();

// Сохраняем выбранные теги
if (isset($_POST['tags']) && is_array($_POST['tags'])) {
    $stmt_tag = $pdo->prepare('INSERT INTO file_tags (file_id, tag_id) VALUES (?, (SELECT id FROM tags WHERE name = ?))');
    foreach ($_POST['tags'] as $tag) {
        if (in_array($tag, $all_tags)) {
            $stmt_tag->execute([$file_id, $tag]);
        }
    }
}

// Сохраняем доступ по логинам
if (!empty($_POST['logins'])) {
    $logins = array_map('trim', explode(',', $_POST['logins']));
    $stmt_access = $pdo->prepare('INSERT INTO file_access (file_id, user_id) VALUES (?, (SELECT id FROM users WHERE login = ?))');
    foreach ($logins as $login) {
        if ($login !== '') {
            $stmt_access->execute([$file_id, $login]);
        }
    }
}

header('Location: files.php?uploaded=1');
exit;
?>