<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

$user = gc_require_login();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    gc_redirect('files.php');
}

gc_csrf_check();

$file_id = (int) ($_POST['id'] ?? 0);
$stmt = $pdo->prepare('SELECT id, filename, uploader_id FROM files WHERE id = ?');
$stmt->execute([$file_id]);
$file = $stmt->fetch();

if (is_array($file)) {
    $can_delete = (int) $file['uploader_id'] === (int) $user['id'] || gc_has_right('delete_any_file');
    if ($can_delete) {
        $uploadDir = realpath(__DIR__ . '/uploads');
        $filepath = realpath(__DIR__ . '/uploads/' . basename((string) $file['filename']));
        if ($uploadDir !== false && $filepath !== false && str_starts_with($filepath, $uploadDir . DIRECTORY_SEPARATOR)) {
            @unlink($filepath);
        }

        $pdo->prepare('DELETE FROM files WHERE id = ?')->execute([$file_id]);
    }
}

gc_redirect('files.php?deleted=1');
