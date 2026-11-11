<?php
declare(strict_types=1);

/**
 * GC Convert — job runner.
 *
 * Every conversion is one job folder:
 *   uploads/converter/<user>/<job>/input.<ext>
 *   uploads/converter/<user>/<job>/job.json
 *   uploads/converter/<user>/<job>/output.<ext>
 *
 * The web request writes job.json and starts converter_worker.php; the worker
 * reads the same file, runs one local tool and writes the result back.
 */

require_once __DIR__ . '/converter_lib.php';

/** Start a detached worker. Returns false when the host forbids exec(). */
function gc_conv_spawn(string $dir): bool
{
    if (!function_exists('exec')) {
        return false;
    }

    $php = gc_conv_php_binary();
    $script = __DIR__ . '/converter_worker.php';
    if (!is_file($script)) {
        return false;
    }

    if (DIRECTORY_SEPARATOR === '\\') {
        // `start` detaches; exec() returns as soon as cmd.exe has launched it.
        $command = 'start "" /B '
            . escapeshellarg($php) . ' '
            . escapeshellarg($script) . ' '
            . escapeshellarg($dir)
            . ' > NUL 2>&1';
    } else {
        $command = 'nohup '
            . escapeshellarg($php) . ' '
            . escapeshellarg($script) . ' '
            . escapeshellarg($dir)
            . ' > /dev/null 2>&1 &';
    }

    $output = [];
    $code = 1;
    @exec($command, $output, $code);

    return $code === 0;
}

function gc_conv_run_job(string $dir): ?array
{
    $job = gc_conv_job_load($dir);
    if ($job === null) {
        return null;
    }
    if (in_array($job['status'] ?? '', ['done', 'failed'], true)) {
        return $job;
    }

    $lock = @fopen($dir . '/worker.lock', 'c');
    if ($lock === false || !@flock($lock, LOCK_EX | LOCK_NB)) {
        return $job;
    }

    @set_time_limit(0);
    @ignore_user_abort(true);

    $job = gc_conv_job_update($dir, [
        'status' => 'running',
        'started' => time(),
        'error' => null,
    ]);

    $log = $dir . '/worker.log';
    @file_put_contents($log, "job " . $job['id'] . "\n" . date('c') . "\n");

    try {
        $result = gc_conv_execute($job, $dir, $log);
        $job = gc_conv_job_update($dir, [
            'status' => 'done',
            'output' => $result['output'],
            'output_name' => $result['output_name'],
            'output_size' => $result['output_size'],
            'finished' => time(),
            'error' => null,
        ]);
    } catch (Throwable $error) {
        $tail = gc_conv_log_tail($log);
        $message = $error->getMessage();
        if ($tail !== '') {
            $message .= "\n" . $tail;
        }
        $job = gc_conv_job_update($dir, [
            'status' => 'failed',
            'finished' => time(),
            'error' => mb_substr($message, 0, 1200),
        ]);
    } finally {
        @flock($lock, LOCK_UN);
        @fclose($lock);
    }

    return $job;
}

/** @return array{output:string,output_name:string,output_size:int} */
function gc_conv_execute(array $job, string $dir, string $log): array
{
    $ext = strtolower((string) $job['input_ext']);
    $group = gc_conv_group_for_ext($ext);
    if ($group === null) {
        throw new RuntimeException('Формат входа больше не поддерживается.');
    }

    $format = strtolower((string) ($job['format'] ?? ''));
    if (!isset($group['outputs'][$format])) {
        throw new RuntimeException('Формат результата не поддерживается для этого файла.');
    }

    $spec = $group['outputs'][$format];
    $run = $spec['run'] ?? $group['group'];
    $input = $dir . '/' . $job['input'];
    if (!is_file($input)) {
        throw new RuntimeException('Входной файл не найден.');
    }

    $output = $dir . '/output.' . $format;
    if (is_file($output)) {
        @unlink($output);
    }

    $quality = (string) ($job['quality'] ?? '');

    match ($run) {
        'image' => gc_conv_do_image($input, $ext, $output, $format, $quality, $log),
        'audio', 'video' => gc_conv_do_media($input, $output, $format, $quality, $log),
        'doc', 'sheet', 'slides' => gc_conv_do_office($input, $output, $format, (string) ($spec['filter'] ?? $format), $dir, $log),
        'pdf' => gc_conv_do_pdf($input, $output, $format, $quality, $dir, $log),
        'ocr' => gc_conv_do_ocr($input, $ext, $output, $dir, $log),
        'ebook' => gc_conv_do_ebook($input, $output, $log),
        'markdown' => gc_conv_do_pandoc($input, $output, $log),
        default => throw new RuntimeException('Неизвестный конвертер: ' . $run),
    };

    if (!is_file($output) || filesize($output) === 0) {
        throw new RuntimeException('Инструмент завершился без результата.');
    }

    return [
        'output' => 'output.' . $format,
        'output_name' => gc_conv_output_name((string) $job['original'], $format),
        'output_size' => (int) filesize($output),
    ];
}

/**
 * Run one command without a shell, stream its output to the job log and stop
 * it after $timeout seconds.
 *
 * @param list<string> $argv
 * @return array{0:int,1:string} exit code and captured output
 */
function gc_conv_command(array $argv, int $timeout, string $log, array $extraEnv = []): array
{
    if (!function_exists('proc_open')) {
        throw new RuntimeException('На сервере отключён proc_open — конвертер не может запускать инструменты.');
    }

    $logHandle = @fopen($log, 'ab');
    $logSize = 0;
    if ($logHandle !== false) {
        $logSize = (int) @filesize($log);
    }

    $baseEnv = getenv();
    if (!is_array($baseEnv)) {
        $baseEnv = [];
    }
    $env = $extraEnv === [] ? null : array_merge($baseEnv, $extraEnv);

    $pipes = [];
    $process = @proc_open($argv, [
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ], $pipes, null, $env);

    if (!is_resource($process)) {
        if ($logHandle !== false) {
            @fclose($logHandle);
        }
        throw new RuntimeException('Не удалось запустить инструмент: ' . ($argv[0] ?? '?'));
    }

    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);

    $started = microtime(true);
    $captured = '';
    $timedOut = false;
    $exitCode = null;

    while (true) {
        $status = proc_get_status($process);

        foreach ([1, 2] as $fd) {
            $chunk = stream_get_contents($pipes[$fd]);
            if ($chunk !== false && $chunk !== '') {
                $captured .= $chunk;
                if ($logHandle !== false && $logSize < GC_CONV_MAX_LOG) {
                    @fwrite($logHandle, $chunk);
                    $logSize += strlen($chunk);
                }
            }
        }

        if (!$status['running']) {
            $exitCode = (int) $status['exitcode'];
            break;
        }

        if (microtime(true) - $started > $timeout) {
            $timedOut = true;
            @proc_terminate($process, 9);
            break;
        }

        usleep(100000);
    }

    foreach ([1, 2] as $fd) {
        if (isset($pipes[$fd]) && is_resource($pipes[$fd])) {
            $chunk = stream_get_contents($pipes[$fd]);
            if ($chunk !== false && $chunk !== '') {
                $captured .= $chunk;
            }
            @fclose($pipes[$fd]);
        }
    }

    $closed = proc_close($process);
    if ($exitCode === null || $exitCode < 0) {
        $exitCode = (int) $closed;
    }

    if ($logHandle !== false) {
        @fwrite($logHandle, "\n" . date('c') . ' exit=' . $exitCode . ($timedOut ? ' timeout' : '') . "\n");
        @fclose($logHandle);
    }

    if ($timedOut) {
        throw new RuntimeException('Превышено время конвертации (' . $timeout . ' с).');
    }

    return [$exitCode, $captured];
}

function gc_conv_assert_ok(array $result, string $tool): void
{
    [$code, $output] = $result;
    if ($code !== 0) {
        $tail = trim($output);
        if (mb_strlen($tail) > 500) {
            $tail = mb_substr($tail, -500);
        }
        throw new RuntimeException($tool . ' завершился с кодом ' . $code . ($tail !== '' ? ': ' . $tail : '.'));
    }
}

function gc_conv_image_quality(string $quality): int
{
    return match ($quality) {
        'low' => 60,
        'high' => 92,
        default => 80,
    };
}

function gc_conv_media_crf(string $quality): int
{
    return match ($quality) {
        'low' => 28,
        'high' => 18,
        default => 23,
    };
}

function gc_conv_audio_bitrate(string $quality): string
{
    return match ($quality) {
        'low' => '128k',
        'high' => '320k',
        default => '192k',
    };
}

function gc_conv_do_image(string $input, string $inputExt, string $output, string $format, string $quality, string $log): void
{
    if (!function_exists('imagecreatetruecolor')) {
        throw new RuntimeException('PHP GD не установлен — изображения недоступны.');
    }

    $loader = match (strtolower($inputExt)) {
        'jpg', 'jpeg' => 'imagecreatefromjpeg',
        'png' => 'imagecreatefrompng',
        'webp' => 'imagecreatefromwebp',
        'gif' => 'imagecreatefromgif',
        'bmp' => 'imagecreatefrombmp',
        'avif' => 'imagecreatefromavif',
        default => null,
    };
    if ($loader === null || !function_exists($loader)) {
        throw new RuntimeException('Не удалось открыть это изображение: нет подходящего загрузчика GD.');
    }

    $src = @$loader($input);
    if (!$src instanceof GdImage) {
        throw new RuntimeException('Не удалось прочитать изображение.');
    }

    $width = imagesx($src);
    $height = imagesy($src);
    if ($width < 1 || $height < 1) {
        imagedestroy($src);
        throw new RuntimeException('Пустое изображение.');
    }

    $dst = imagecreatetruecolor($width, $height);
    if (!$dst instanceof GdImage) {
        imagedestroy($src);
        throw new RuntimeException('Не хватило памяти для изображения.');
    }

    $alpha = in_array($format, ['png', 'webp', 'avif', 'gif'], true);
    if ($alpha) {
        imagealphablending($dst, false);
        imagesavealpha($dst, true);
        $transparent = imagecolorallocatealpha($dst, 0, 0, 0, 127);
        imagefilledrectangle($dst, 0, 0, $width, $height, $transparent);
        imagealphablending($dst, true);
    } else {
        $white = imagecolorallocate($dst, 255, 255, 255);
        imagefilledrectangle($dst, 0, 0, $width, $height, $white);
    }
    imagecopyresampled($dst, $src, 0, 0, 0, 0, $width, $height, $width, $height);
    imagedestroy($src);

    $q = gc_conv_image_quality($quality);
    $ok = match ($format) {
        'jpg' => imagejpeg($dst, $output, $q),
        'png' => imagepng($dst, $output, 6),
        'webp' => imagewebp($dst, $output, $q),
        'avif' => imageavif($dst, $output, $q),
        'gif' => imagegif($dst, $output),
        'bmp' => imagebmp($dst, $output),
        default => false,
    };
    imagedestroy($dst);

    if (!$ok) {
        @unlink($output);
        throw new RuntimeException('GD не смог записать ' . strtoupper($format) . '.');
    }
    @file_put_contents($log, "image {$inputExt} -> {$format}, {$width}x{$height}\n", FILE_APPEND);
}

function gc_conv_do_media(string $input, string $output, string $format, string $quality, string $log): void
{
    $ffmpeg = gc_conv_tools()['ffmpeg'];
    if ($ffmpeg === null) {
        throw new RuntimeException('ffmpeg не установлен — аудио и видео недоступны.');
    }

    $crf = (string) gc_conv_media_crf($quality);
    $audioBitrate = gc_conv_audio_bitrate($quality);
    $args = [$ffmpeg, '-hide_banner', '-loglevel', 'error', '-y', '-i', $input, '-sn', '-dn'];

    switch ($format) {
        case 'mp3':
            $args = array_merge($args, ['-vn', '-c:a', 'libmp3lame', '-b:a', $audioBitrate]);
            break;
        case 'wav':
            $args = array_merge($args, ['-vn', '-c:a', 'pcm_s16le']);
            break;
        case 'ogg':
            $args = array_merge($args, ['-vn', '-c:a', 'libvorbis', '-q:a', $quality === 'high' ? '7' : ($quality === 'low' ? '3' : '5')]);
            break;
        case 'flac':
            $args = array_merge($args, ['-vn', '-c:a', 'flac']);
            break;
        case 'm4a':
        case 'aac':
            $args = array_merge($args, ['-vn', '-c:a', 'aac', '-b:a', $audioBitrate]);
            break;
        case 'opus':
            $args = array_merge($args, ['-vn', '-c:a', 'libopus', '-b:a', $quality === 'high' ? '192k' : ($quality === 'low' ? '96k' : '128k')]);
            break;
        case 'gif':
            $args = array_merge($args, [
                '-vf', 'fps=12,scale=960:-1:flags=lanczos,split[s0][s1];[s0]palettegen=max_colors=256[p];[s1][p]paletteuse=dither=bayer',
                '-loop', '0',
            ]);
            break;
        case 'mp4':
        case 'mov':
            $args = array_merge($args, [
                '-map', '0:v:0', '-map', '0:a:0?',
                '-vf', 'scale=trunc(iw/2)*2:trunc(ih/2)*2',
                '-c:v', 'libx264', '-preset', 'veryfast', '-crf', $crf,
                '-c:a', 'aac', '-b:a', '160k', '-movflags', '+faststart',
            ]);
            break;
        case 'mkv':
            $args = array_merge($args, [
                '-map', '0:v:0', '-map', '0:a:0?',
                '-vf', 'scale=trunc(iw/2)*2:trunc(ih/2)*2',
                '-c:v', 'libx264', '-preset', 'veryfast', '-crf', $crf,
                '-c:a', 'aac', '-b:a', '160k',
            ]);
            break;
        case 'avi':
            $args = array_merge($args, [
                '-map', '0:v:0', '-map', '0:a:0?',
                '-vf', 'scale=trunc(iw/2)*2:trunc(ih/2)*2',
                '-c:v', 'mpeg4', '-q:v', $quality === 'high' ? '3' : ($quality === 'low' ? '7' : '5'),
                '-c:a', 'libmp3lame', '-b:a', '160k',
            ]);
            break;
        case 'webm':
            $args = array_merge($args, [
                '-map', '0:v:0', '-map', '0:a:0?',
                '-c:v', 'libvpx-vp9', '-crf', $quality === 'high' ? '26' : ($quality === 'low' ? '36' : '32'),
                '-b:v', '0', '-deadline', 'good', '-cpu-used', '3',
                '-c:a', 'libopus', '-b:a', '96k',
            ]);
            break;
        default:
            throw new RuntimeException('Неизвестный медиаформат: ' . $format);
    }

    $args[] = $output;
    gc_conv_assert_ok(gc_conv_command($args, 3600, $log), 'ffmpeg');
}

function gc_conv_do_office(string $input, string $output, string $format, string $filter, string $dir, string $log): void
{
    $soffice = gc_conv_tools()['soffice'];
    if ($soffice === null) {
        throw new RuntimeException('LibreOffice не установлен — документы недоступны.');
    }

    $outdir = $dir . '/office';
    if (!is_dir($outdir) && !mkdir($outdir, 0750, true) && !is_dir($outdir)) {
        throw new RuntimeException('Не удалось создать папку LibreOffice.');
    }

    $args = [$soffice, '--headless', '--norestore', '--invisible', '--convert-to', $filter, '--outdir', $outdir, $input];
    $env = [
        'HOME' => $dir,
        'TMPDIR' => $dir,
        'TMP' => $dir,
        'TEMP' => $dir,
    ];
    gc_conv_assert_ok(gc_conv_command($args, 900, $log, $env), 'LibreOffice');

    $produced = null;
    foreach (glob($outdir . '/*') ?: [] as $candidate) {
        if (is_file($candidate) && strtolower((string) pathinfo($candidate, PATHINFO_EXTENSION)) === $format) {
            $produced = $candidate;
            break;
        }
    }
    if ($produced === null) {
        throw new RuntimeException('LibreOffice не создал ' . strtoupper($format) . '.');
    }
    if (!@rename($produced, $output)) {
        throw new RuntimeException('Не удалось переместить результат LibreOffice.');
    }
    gc_conv_rmdir($outdir);
}

function gc_conv_do_pdf(string $input, string $output, string $format, string $quality, string $dir, string $log): void
{
    $tools = gc_conv_tools();

    if ($format === 'pdf') {
        $gs = $tools['gs'];
        if ($gs === null) {
            throw new RuntimeException('Ghostscript не установлен.');
        }
        $preset = match ($quality) {
            'screen' => '/screen',
            'prepress' => '/prepress',
            default => '/ebook',
        };
        $args = [
            $gs, '-q', '-dNOPAUSE', '-dBATCH', '-dSAFER',
            '-sDEVICE=pdfwrite', '-dCompatibilityLevel=1.5',
            '-dPDFSETTINGS=' . $preset,
            '-sOutputFile=' . $output, $input,
        ];
        gc_conv_assert_ok(gc_conv_command($args, 900, $log), 'Ghostscript');

        return;
    }

    if ($format === 'txt') {
        $pdftotext = $tools['pdftotext'];
        if ($pdftotext === null) {
            throw new RuntimeException('pdftotext не установлен.');
        }
        $args = [$pdftotext, '-layout', '-enc', 'UTF-8', $input, $output];
        gc_conv_assert_ok(gc_conv_command($args, 300, $log), 'pdftotext');

        return;
    }

    if ($format === 'png' || $format === 'jpg') {
        $pdftoppm = $tools['pdftoppm'];
        if ($pdftoppm === null) {
            throw new RuntimeException('pdftoppm не установлен.');
        }
        $prefix = $dir . '/page';
        $args = [$pdftoppm, '-f', '1', '-l', '1', '-r', '150', $format === 'jpg' ? '-jpeg' : '-png', $input, $prefix];
        gc_conv_assert_ok(gc_conv_command($args, 600, $log), 'pdftoppm');

        $matches = glob($prefix . '*.' . $format) ?: [];
        if ($matches === []) {
            throw new RuntimeException('pdftoppm не создал изображение.');
        }
        sort($matches);
        if (!@rename($matches[0], $output)) {
            throw new RuntimeException('Не удалось переместить страницу PDF.');
        }

        return;
    }

    throw new RuntimeException('Неизвестное преобразование PDF.');
}

function gc_conv_tesseract_lang(): string
{
    static $lang = null;
    if ($lang !== null) {
        return $lang;
    }
    $tesseract = gc_conv_tools()['tesseract'];
    if ($tesseract === null) {
        return 'eng';
    }
    $output = [];
    $code = 1;
    @exec(escapeshellarg($tesseract) . ' --list-langs 2>&1', $output, $code);
    $joined = strtolower(implode("\n", $output));
    $lang = str_contains($joined, 'rus') ? 'rus+eng' : 'eng';

    return $lang;
}

function gc_conv_do_ocr(string $input, string $inputExt, string $output, string $dir, string $log): void
{
    $tools = gc_conv_tools();
    $tesseract = $tools['tesseract'];
    if ($tesseract === null) {
        throw new RuntimeException('tesseract не установлен — OCR недоступен.');
    }

    $source = $input;
    $tempPage = null;
    if (strtolower($inputExt) === 'pdf') {
        $pdftoppm = $tools['pdftoppm'];
        if ($pdftoppm === null) {
            throw new RuntimeException('Для OCR PDF нужен pdftoppm.');
        }
        $prefix = $dir . '/ocr-page';
        gc_conv_assert_ok(gc_conv_command([$pdftoppm, '-f', '1', '-l', '1', '-r', '200', '-png', $input, $prefix], 300, $log), 'pdftoppm');
        $matches = glob($prefix . '*.png') ?: [];
        if ($matches === []) {
            throw new RuntimeException('Не удалось получить страницу PDF для OCR.');
        }
        sort($matches);
        $source = $matches[0];
        $tempPage = $source;
    }

    $args = [$tesseract, $source, 'stdout', '-l', gc_conv_tesseract_lang()];
    [$code, $text] = gc_conv_command($args, 600, $log);
    if ($tempPage !== null) {
        @unlink($tempPage);
    }
    if ($code !== 0) {
        throw new RuntimeException('tesseract завершился с кодом ' . $code . '.');
    }
    if (trim($text) === '') {
        throw new RuntimeException('Текст на изображении не распознан.');
    }
    if (@file_put_contents($output, $text) === false) {
        throw new RuntimeException('Не удалось записать распознанный текст.');
    }
}

function gc_conv_do_ebook(string $input, string $output, string $log): void
{
    $ebook = gc_conv_tools()['ebook-convert'];
    if ($ebook === null) {
        throw new RuntimeException('Calibre (ebook-convert) не установлен.');
    }
    gc_conv_assert_ok(gc_conv_command([$ebook, $input, $output, '--quiet'], 1800, $log), 'ebook-convert');
}

function gc_conv_do_pandoc(string $input, string $output, string $log): void
{
    $pandoc = gc_conv_tools()['pandoc'];
    if ($pandoc === null) {
        throw new RuntimeException('pandoc не установлен.');
    }
    $ext = strtolower((string) pathinfo($output, PATHINFO_EXTENSION));
    $args = [$pandoc, $input, '-o', $output];
    if ($ext === 'html') {
        $args[] = '--standalone';
    }
    gc_conv_assert_ok(gc_conv_command($args, 300, $log), 'pandoc');
}
