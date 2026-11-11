<?php
declare(strict_types=1);

/**
 * GC Convert — formats, jobs and storage.
 *
 * The converter runs on this server: PHP drives local command-line tools
 * (ffmpeg, LibreOffice, Ghostscript, poppler, tesseract, pandoc, Calibre) and
 * GD for raster images. Nothing is sent to a third-party service.
 *
 * This file is standalone: the CLI worker includes it without a session or the
 * database, so it must not call gc_* helpers from bootstrap.php.
 */

const GC_CONV_TTL = 86400;          // Finished jobs live one day.
const GC_CONV_MAX_LOG = 2097152;    // Keep the tail of a noisy tool log.
const GC_CONV_JOB_RE = '/^[a-f0-9]{24}$/';

/** Tools the converter can drive, resolved once per request. */
function gc_conv_tools(bool $refresh = false): array
{
    static $tools = null;
    if ($tools !== null && !$refresh) {
        return $tools;
    }

    $tools = [];
    foreach (['ffmpeg', 'soffice', 'gs', 'pdftoppm', 'pdftotext', 'pandoc', 'tesseract', 'ebook-convert'] as $name) {
        $tools[$name] = gc_conv_which($name);
    }
    if ($tools['soffice'] === null) {
        $tools['soffice'] = gc_conv_which('libreoffice');
    }

    return $tools;
}

function gc_conv_which(string $name): ?string
{
    if (!function_exists('exec')) {
        return null;
    }

    $windows = DIRECTORY_SEPARATOR === '\\';
    $command = ($windows ? 'where ' : 'command -v ')
        . escapeshellarg($name)
        . ' 2>' . ($windows ? 'NUL' : '/dev/null');
    $output = [];
    $code = 1;
    @exec($command, $output, $code);
    $path = trim((string) ($output[0] ?? ''));

    return $code === 0 && $path !== '' ? $path : null;
}

/** PHP CLI binary, not php-fpm: the worker must be started as a script. */
function gc_conv_php_binary(): string
{
    $configured = getenv('GC_CONVERT_PHP');
    if (is_string($configured) && $configured !== '' && is_file($configured)) {
        return $configured;
    }

    // On Windows PHP_BINARY is the real php.exe even under the built-in server
    // (and the path may contain non-ASCII folders where is_executable is flaky).
    if (DIRECTORY_SEPARATOR === '\\') {
        if (PHP_BINARY !== '' && is_file(PHP_BINARY)) {
            return PHP_BINARY;
        }
        $candidates = [PHP_BINDIR . '/php.exe', PHP_BINDIR . '/php'];
        foreach ($candidates as $candidate) {
            if (is_file($candidate)) {
                return $candidate;
            }
        }
    } else {
        $candidates = [PHP_BINDIR . '/php', '/usr/bin/php', '/usr/local/bin/php'];
        foreach ($candidates as $candidate) {
            if (is_executable($candidate)) {
                return $candidate;
            }
        }
        if (PHP_SAPI === 'cli' && PHP_BINARY !== '') {
            return PHP_BINARY;
        }
    }

    return 'php';
}

/**
 * Format catalog. Outputs are filtered by the tools actually present on this
 * host, so the page never offers a conversion that cannot run.
 */
function gc_conv_catalog(): array
{
    static $catalog = null;
    if ($catalog !== null) {
        return $catalog;
    }

    $tools = gc_conv_tools();
    $gd = function_exists('imagecreatetruecolor');

    $image = [];
    if ($gd) {
        $image = [
            'jpg'  => ['label' => 'JPG',  'quality' => 'image'],
            'png'  => ['label' => 'PNG',  'quality' => 'image'],
            'webp' => ['label' => 'WEBP', 'quality' => 'image'],
            'gif'  => ['label' => 'GIF',  'quality' => 'image'],
            'bmp'  => ['label' => 'BMP',  'quality' => 'image'],
        ];
        if (function_exists('imageavif')) {
            $image['avif'] = ['label' => 'AVIF', 'quality' => 'image'];
        }
        if ($tools['tesseract'] !== null) {
            $image['txt'] = ['label' => 'TXT (OCR)', 'run' => 'ocr'];
        }
    }

    $media = [];
    if ($tools['ffmpeg'] !== null) {
        $media = [
            'mp3'  => ['label' => 'MP3',  'quality' => 'media'],
            'wav'  => ['label' => 'WAV',  'quality' => 'media'],
            'ogg'  => ['label' => 'OGG',  'quality' => 'media'],
            'flac' => ['label' => 'FLAC', 'quality' => 'media'],
            'm4a'  => ['label' => 'M4A',  'quality' => 'media'],
            'opus' => ['label' => 'OPUS', 'quality' => 'media'],
            'aac'  => ['label' => 'AAC',  'quality' => 'media'],
        ];
    }

    $video = [];
    if ($tools['ffmpeg'] !== null) {
        $video = [
            'mp4'  => ['label' => 'MP4',  'quality' => 'media'],
            'webm' => ['label' => 'WEBM', 'quality' => 'media'],
            'mkv'  => ['label' => 'MKV',  'quality' => 'media'],
            'mov'  => ['label' => 'MOV',  'quality' => 'media'],
            'avi'  => ['label' => 'AVI',  'quality' => 'media'],
            'gif'  => ['label' => 'GIF',  'quality' => 'media'],
            'mp3'  => ['label' => 'MP3 (звук)', 'quality' => 'media'],
            'wav'  => ['label' => 'WAV (звук)', 'quality' => 'media'],
        ];
    }

    $docs = [];
    if ($tools['soffice'] !== null) {
        $docs = [
            'pdf'  => ['label' => 'PDF',  'filter' => 'pdf'],
            'docx' => ['label' => 'DOCX', 'filter' => 'docx:MS Word 2007 XML'],
            'odt'  => ['label' => 'ODT',  'filter' => 'odt'],
            'txt'  => ['label' => 'TXT',  'filter' => 'txt:Text'],
            'html' => ['label' => 'HTML', 'filter' => 'html:HTML (StarWriter)'],
            'rtf'  => ['label' => 'RTF',  'filter' => 'rtf'],
        ];
    }

    $sheets = [];
    if ($tools['soffice'] !== null) {
        $sheets = [
            'pdf'  => ['label' => 'PDF',  'filter' => 'pdf'],
            'xlsx' => ['label' => 'XLSX', 'filter' => 'xlsx:Calc MS Excel 2007 XML'],
            'ods'  => ['label' => 'ODS',  'filter' => 'ods'],
            'csv'  => ['label' => 'CSV',  'filter' => 'csv:Text - txt - csv (StarCalc)'],
        ];
    }

    $slides = [];
    if ($tools['soffice'] !== null) {
        $slides = [
            'pdf'  => ['label' => 'PDF',  'filter' => 'pdf'],
            'pptx' => ['label' => 'PPTX', 'filter' => 'pptx:Impress MS PowerPoint 2007 XML'],
            'odp'  => ['label' => 'ODP',  'filter' => 'odp'],
        ];
    }

    $pdf = [];
    if ($tools['pdftoppm'] !== null) {
        $pdf['png'] = ['label' => 'PNG (стр. 1)', 'quality' => 'pdf'];
        $pdf['jpg'] = ['label' => 'JPG (стр. 1)', 'quality' => 'pdf'];
    }
    if ($tools['pdftotext'] !== null) {
        $pdf['txt'] = ['label' => 'TXT'];
    }
    if ($tools['gs'] !== null) {
        $pdf['pdf'] = ['label' => 'PDF (сжать)', 'quality' => 'pdf'];
    }

    $ebook = [];
    if ($tools['ebook-convert'] !== null) {
        $ebook = [
            'epub' => ['label' => 'EPUB'],
            'mobi' => ['label' => 'MOBI'],
            'pdf'  => ['label' => 'PDF'],
            'txt'  => ['label' => 'TXT'],
        ];
    }

    $markdown = [];
    if ($tools['pandoc'] !== null) {
        $markdown = [
            'html' => ['label' => 'HTML'],
            'docx' => ['label' => 'DOCX'],
            'txt'  => ['label' => 'TXT'],
        ];
    }

    $catalog = [
        'image' => [
            'label'  => 'Изображения',
            'inputs' => array_fill_keys(['jpg', 'jpeg', 'png', 'webp', 'gif', 'bmp', 'avif'], true),
            'outputs' => $image,
            'default' => isset($image['webp']) ? 'webp' : 'jpg',
        ],
        'audio' => [
            'label'  => 'Аудио',
            'inputs' => array_fill_keys(['mp3', 'wav', 'ogg', 'oga', 'flac', 'm4a', 'aac', 'opus', 'wma'], true),
            'outputs' => $media,
            'default' => 'mp3',
        ],
        'video' => [
            'label'  => 'Видео',
            'inputs' => array_fill_keys(['mp4', 'webm', 'mkv', 'mov', 'avi', 'm4v', 'wmv', 'flv', 'mpg', 'mpeg', 'ts'], true),
            'outputs' => $video,
            'default' => 'mp4',
        ],
        'doc' => [
            'label'  => 'Документы',
            'inputs' => array_fill_keys(['doc', 'docx', 'odt', 'rtf', 'txt', 'html', 'htm'], true),
            'outputs' => $docs,
            'default' => 'pdf',
        ],
        'sheet' => [
            'label'  => 'Таблицы',
            'inputs' => array_fill_keys(['xls', 'xlsx', 'ods', 'csv'], true),
            'outputs' => $sheets,
            'default' => 'pdf',
        ],
        'slides' => [
            'label'  => 'Презентации',
            'inputs' => array_fill_keys(['ppt', 'pptx', 'odp'], true),
            'outputs' => $slides,
            'default' => 'pdf',
        ],
        'pdf' => [
            'label'  => 'PDF',
            'inputs' => ['pdf' => true],
            'outputs' => $pdf,
            'default' => 'png',
        ],
        'ebook' => [
            'label'  => 'Книги',
            'inputs' => array_fill_keys(['epub', 'mobi', 'azw3', 'fb2'], true),
            'outputs' => $ebook,
            'default' => 'epub',
        ],
        'markdown' => [
            'label'  => 'Markdown',
            'inputs' => array_fill_keys(['md', 'markdown'], true),
            'outputs' => $markdown,
            'default' => 'html',
        ],
    ];

    return $catalog;
}

/** @return array{group:string,kind:string,label:string,outputs:array,default:string}|null */
function gc_conv_group_for_ext(string $ext): ?array
{
    $ext = strtolower($ext);
    foreach (gc_conv_catalog() as $key => $group) {
        if (isset($group['inputs'][$ext]) && $group['outputs'] !== []) {
            return $group + ['group' => $key];
        }
    }

    return null;
}

/** Output list with quality choices, ready for JSON. */
function gc_conv_format_payload(string $ext): array
{
    $group = gc_conv_group_for_ext($ext);
    if ($group === null) {
        return [];
    }

    $formats = [];
    foreach ($group['outputs'] as $value => $spec) {
        $formats[] = [
            'value' => $value,
            'label' => $spec['label'] ?? strtoupper($value),
            'quality' => gc_conv_quality_choices($spec['quality'] ?? null),
        ];
    }

    return $formats;
}

function gc_conv_quality_choices(?string $type): array
{
    return match ($type) {
        'image' => [
            ['value' => 'low', 'label' => 'Низкое'],
            ['value' => 'medium', 'label' => 'Среднее'],
            ['value' => 'high', 'label' => 'Высокое'],
        ],
        'media' => [
            ['value' => 'low', 'label' => 'Экономно'],
            ['value' => 'medium', 'label' => 'Среднее'],
            ['value' => 'high', 'label' => 'Качество'],
        ],
        'pdf' => [
            ['value' => 'screen', 'label' => 'Экран'],
            ['value' => 'ebook', 'label' => 'Баланс'],
            ['value' => 'prepress', 'label' => 'Печать'],
        ],
        default => [],
    };
}

function gc_conv_default_format(string $ext): ?string
{
    $group = gc_conv_group_for_ext($ext);
    if ($group === null) {
        return null;
    }
    $default = $group['default'];
    if (isset($group['outputs'][$default])) {
        return $default;
    }
    $keys = array_keys($group['outputs']);

    return $keys[0] ?? null;
}

function gc_conv_output_name(string $original, string $format): string
{
    $base = pathinfo($original, PATHINFO_FILENAME);
    $base = preg_replace('/[^\pL\pN._ -]+/u', '_', $base) ?? 'file';
    $base = trim($base, " ._");
    if ($base === '') {
        $base = 'file';
    }
    if (mb_strlen($base) > 120) {
        $base = mb_substr($base, 0, 120);
    }

    return $base . '.' . $format;
}

function gc_conv_root(): string
{
    return __DIR__ . '/uploads/converter';
}

function gc_conv_user_root(int $userId): string
{
    return gc_conv_root() . '/' . $userId;
}

function gc_conv_job_dir(int $userId, string $jobId): string
{
    if (!preg_match(GC_CONV_JOB_RE, $jobId)) {
        throw new InvalidArgumentException('Некорректный идентификатор задачи.');
    }

    return gc_conv_user_root($userId) . '/' . $jobId;
}

/** mkdir + Apache deny file, same contract as gc_ensure_data_dir(). */
function gc_conv_ensure_dir(string $path): bool
{
    if (!is_dir($path) && !mkdir($path, 0755, true) && !is_dir($path)) {
        return false;
    }

    $deny = "<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n"
        . "<IfModule !mod_authz_core.c>\n    Order allow,deny\n    Deny from all\n</IfModule>\n";
    $htaccess = $path . '/.htaccess';
    if (!file_exists($htaccess)) {
        @file_put_contents($htaccess, $deny);
    }

    return true;
}

function gc_conv_safe_name(string $name): string
{
    $name = str_replace('\\', '/', $name);
    $name = basename($name);
    $name = preg_replace('/[\x00-\x1F\x7F]/u', '', $name) ?? '';
    $name = trim($name);
    if ($name === '' || $name === '.' || $name === '..') {
        return 'file';
    }
    if (strlen($name) > 200) {
        $extension = pathinfo($name, PATHINFO_EXTENSION);
        $base = substr($name, 0, 180);
        $name = $extension !== '' ? $base . '.' . substr($extension, 0, 16) : $base;
    }

    return $name;
}

function gc_conv_max_upload(): int
{
    $toBytes = static function (string $value): int {
        $value = trim($value);
        if ($value === '') {
            return 0;
        }
        $unit = strtolower(substr($value, -1));
        $number = (int) $value;
        return match ($unit) {
            'g' => $number * 1024 * 1024 * 1024,
            'm' => $number * 1024 * 1024,
            'k' => $number * 1024,
            default => $number,
        };
    };

    $limits = [defined('GC_MAX_UPLOAD_BYTES') ? (int) GC_MAX_UPLOAD_BYTES : 20 * 1024 * 1024 * 1024];
    $upload = $toBytes((string) ini_get('upload_max_filesize'));
    $post = $toBytes((string) ini_get('post_max_size'));
    if ($upload > 0) {
        $limits[] = $upload;
    }
    if ($post > 0) {
        $limits[] = $post;
    }

    return $limits === [] ? 64 * 1024 * 1024 : min($limits);
}

function gc_conv_job_load(string $dir): ?array
{
    $path = $dir . '/job.json';
    if (!is_file($path)) {
        return null;
    }
    $raw = @file_get_contents($path);
    if ($raw === false) {
        return null;
    }
    $job = json_decode($raw, true);

    return is_array($job) ? $job : null;
}

function gc_conv_job_save(string $dir, array $job): void
{
    $lock = @fopen($dir . '/job.lock', 'c');
    if ($lock !== false) {
        @flock($lock, LOCK_EX);
    }
    $job['updated'] = time();
    $tmp = $dir . '/job.json.tmp';
    $json = json_encode($job, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json !== false && @file_put_contents($tmp, $json, LOCK_EX) !== false) {
        @rename($tmp, $dir . '/job.json');
    }
    if ($lock !== false) {
        @flock($lock, LOCK_UN);
        @fclose($lock);
    }
}

function gc_conv_job_update(string $dir, array $patch): array
{
    $job = gc_conv_job_load($dir);
    if ($job === null) {
        throw new RuntimeException('Задача не найдена.');
    }
    foreach ($patch as $key => $value) {
        $job[$key] = $value;
    }
    gc_conv_job_save($dir, $job);

    return $job;
}

/** Create a job from one uploaded file. */
function gc_conv_job_create(int $userId, array $file): array
{
    $original = gc_conv_safe_name((string) ($file['name'] ?? 'file'));
    $ext = strtolower((string) pathinfo($original, PATHINFO_EXTENSION));
    if ($ext === '') {
        throw new RuntimeException('У файла нет расширения — формат не определить.');
    }

    $group = gc_conv_group_for_ext($ext);
    if ($group === null) {
        throw new RuntimeException('Формат «' . $ext . '» не поддерживается конвертером.');
    }

    $size = (int) ($file['size'] ?? 0);
    $max = gc_conv_max_upload();
    if ($size <= 0 || $size > $max) {
        throw new RuntimeException('Файл больше допустимого размера (' . gc_conv_human_size($max) . ').');
    }

    $tmp = (string) ($file['tmp_name'] ?? '');
    if ($tmp === '' || !is_uploaded_file($tmp)) {
        throw new RuntimeException('Файл не загрузился.');
    }

    $userRoot = gc_conv_user_root($userId);
    if (!gc_conv_ensure_dir($userRoot)) {
        throw new RuntimeException('Не удалось создать папку для задачи.');
    }

    $id = bin2hex(random_bytes(12));
    $dir = $userRoot . '/' . $id;
    if (!mkdir($dir, 0750, true) && !is_dir($dir)) {
        throw new RuntimeException('Не удалось создать папку для задачи.');
    }

    $inputName = 'input.' . $ext;
    if (!move_uploaded_file($tmp, $dir . '/' . $inputName)) {
        gc_conv_rmdir($dir);
        throw new RuntimeException('Не удалось сохранить загруженный файл.');
    }

    $job = [
        'id' => $id,
        'user_id' => $userId,
        'original' => $original,
        'input' => $inputName,
        'input_ext' => $ext,
        'kind' => $group['group'],
        'size' => $size,
        'status' => 'uploaded',
        'format' => null,
        'quality' => null,
        'output' => null,
        'output_name' => null,
        'output_size' => null,
        'error' => null,
        'created' => time(),
        'started' => null,
        'finished' => null,
    ];
    gc_conv_job_save($dir, $job);

    return $job;
}

function gc_conv_public_job(array $job): array
{
    return [
        'id' => (string) $job['id'],
        'name' => (string) $job['original'],
        'size' => (int) $job['size'],
        'ext' => (string) $job['input_ext'],
        'kind' => (string) $job['kind'],
        'status' => (string) $job['status'],
        'format' => $job['format'] ?? null,
        'quality' => $job['quality'] ?? null,
        'output_name' => $job['output_name'] ?? null,
        'output_size' => isset($job['output_size']) ? (int) $job['output_size'] : null,
        'error' => $job['error'] ?? null,
        'created' => (int) $job['created'],
        'finished' => isset($job['finished']) ? (int) $job['finished'] : null,
    ];
}

function gc_conv_history(int $userId, int $limit = 40): array
{
    gc_conv_cleanup($userId);

    $root = gc_conv_user_root($userId);
    if (!is_dir($root)) {
        return [];
    }

    $jobs = [];
    foreach (glob($root . '/*', GLOB_ONLYDIR) ?: [] as $dir) {
        if (!preg_match(GC_CONV_JOB_RE, basename($dir))) {
            continue;
        }
        $job = gc_conv_job_load($dir);
        if ($job !== null && (int) ($job['user_id'] ?? 0) === $userId) {
            $jobs[] = $job;
        }
    }
    usort($jobs, static fn(array $a, array $b): int => (int) $b['created'] <=> (int) $a['created']);

    return array_map('gc_conv_public_job', array_slice($jobs, 0, $limit));
}

/** Delete stale job folders for this user; cheap and idempotent. */
function gc_conv_cleanup(int $userId): void
{
    $root = gc_conv_user_root($userId);
    if (!is_dir($root)) {
        return;
    }
    $limit = time() - GC_CONV_TTL;
    foreach (glob($root . '/*', GLOB_ONLYDIR) ?: [] as $dir) {
        $mtime = @filemtime($dir);
        if ($mtime !== false && $mtime < $limit) {
            gc_conv_rmdir($dir);
        }
    }
}

function gc_conv_rmdir(string $dir): void
{
    if (!is_dir($dir) || is_link($dir)) {
        @unlink($dir);
        return;
    }

    try {
        $items = new FilesystemIterator($dir, FilesystemIterator::SKIP_DOTS);
        foreach ($items as $item) {
            $path = $item->getPathname();
            if ($item->isDir() && !$item->isLink()) {
                gc_conv_rmdir($path);
            } else {
                @unlink($path);
            }
        }
    } catch (Throwable) {
        // A concurrent worker may still hold a file; the TTL sweep retries later.
    }
    @rmdir($dir);
}

function gc_conv_human_size(int $bytes): string
{
    if ($bytes < 1024) {
        return $bytes . ' Б';
    }
    $units = ['КБ', 'МБ', 'ГБ', 'ТБ'];
    $value = $bytes / 1024;
    $index = 0;
    while ($value >= 1024 && $index < count($units) - 1) {
        $value /= 1024;
        $index++;
    }

    return number_format($value, $value >= 10 ? 0 : 1, '.', ' ') . ' ' . $units[$index];
}

function gc_conv_mime(string $ext): string
{
    return match (strtolower($ext)) {
        'jpg', 'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'webp' => 'image/webp',
        'avif' => 'image/avif',
        'gif' => 'image/gif',
        'bmp' => 'image/bmp',
        'txt' => 'text/plain; charset=utf-8',
        'pdf' => 'application/pdf',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'odt' => 'application/vnd.oasis.opendocument.text',
        'rtf' => 'application/rtf',
        'html' => 'text/html; charset=utf-8',
        'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'ods' => 'application/vnd.oasis.opendocument.spreadsheet',
        'csv' => 'text/csv; charset=utf-8',
        'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        'odp' => 'application/vnd.oasis.opendocument.presentation',
        'mp3' => 'audio/mpeg',
        'wav' => 'audio/wav',
        'ogg', 'opus' => 'audio/ogg',
        'flac' => 'audio/flac',
        'm4a' => 'audio/mp4',
        'aac' => 'audio/aac',
        'mp4' => 'video/mp4',
        'webm' => 'video/webm',
        'mkv' => 'video/x-matroska',
        'mov' => 'video/quicktime',
        'avi' => 'video/x-msvideo',
        'epub' => 'application/epub+zip',
        'mobi' => 'application/x-mobipocket-ebook',
        default => 'application/octet-stream',
    };
}

function gc_conv_log_tail(string $path, int $limit = 700): string
{
    if (!is_file($path)) {
        return '';
    }
    $size = @filesize($path);
    if ($size === false || $size === 0) {
        return '';
    }
    $read = min($size, 16384);
    $fh = @fopen($path, 'rb');
    if ($fh === false) {
        return '';
    }
    @fseek($fh, -$read, SEEK_END);
    $tail = (string) @fread($fh, $read);
    @fclose($fh);
    $tail = trim($tail);
    if (mb_strlen($tail) > $limit) {
        $tail = mb_substr($tail, -$limit);
    }

    return $tail;
}
