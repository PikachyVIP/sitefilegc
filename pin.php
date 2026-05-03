<?php
session_start();
require_once 'db.php';

if (!isset($_SESSION['user'])) {
    header('Location: index.php');
    exit;
}

$user_tags = $_SESSION['tags'] ?? [];

$can_pin = in_array('Глава GC', $user_tags) || in_array('Команда GC', $user_tags);

if ($can_pin && isset($_GET['id'])) {
    $file_id = (int)$_GET['id'];
    
    // Переключаем закрепление
    $stmt = $pdo->prepare('UPDATE files SET is_pinned = NOT is_pinned WHERE id = ?');
    $stmt->execute([$file_id]);
}

header('Location: files.php?pinned=1');
exit;
?>