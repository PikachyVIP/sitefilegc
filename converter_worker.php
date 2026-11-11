<?php
declare(strict_types=1);

/**
 * CLI worker: one job folder in, one output file out.
 *
 * Started detached by converter_engine.php::gc_conv_spawn(). It must never be
 * reachable over HTTP; .htaccess denies this file and the folder check below
 * rejects anything outside uploads/converter.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}

require_once __DIR__ . '/converter_engine.php';

$dir = isset($argv[1]) ? (string) $argv[1] : '';
$real = $dir !== '' ? realpath($dir) : false;
$root = realpath(gc_conv_root());

if ($real === false || $root === false) {
    exit(1);
}
if (!str_starts_with($real, $root . DIRECTORY_SEPARATOR)) {
    exit(1);
}

gc_conv_run_job($real);
