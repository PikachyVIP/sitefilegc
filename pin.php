<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

gc_require_login();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    gc_redirect('files.php');
}

gc_csrf_check();

$file_id = (int) ($_POST['id'] ?? 0);
if ($file_id > 0 && gc_has_right('pin_files')) {
    $pdo->prepare('UPDATE files SET is_pinned = NOT is_pinned WHERE id = ?')->execute([$file_id]);
    gc_redirect('files.php?pinned=1');
}

gc_redirect('files.php?nopin=1');
