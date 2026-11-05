<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

if (gc_user() !== null) {
    gc_redirect('changelog.php');
}

gc_redirect('login.php');
