<?php
session_start();
require_once 'db.php';

if (!isset($_SESSION['user'])) {
    header('Location: index.php');
    exit;
}

$admin_rights = $_SESSION['admin_rights'] ?? [];
$can_pin = in_array('pin_files', $admin_rights);

if ($can_pin && isset($_GET['id'])) {
    $file_id = (int)$_GET['id'];
    
    // Переключаем закрепление
    $stmt = $pdo->prepare('UPDATE files SET is_pinned = NOT is_pinned WHERE id = ?');
    $stmt->execute([$file_id]);
}

header('Location: files.php?pinned=1');
exit;
?>