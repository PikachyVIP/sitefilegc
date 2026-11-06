<?php
declare(strict_types=1);

require_once __DIR__ . '/layout.php';

gc_require_login();
if (!gc_has_right('view_logs')) {
    gc_flash('error', 'Журнал загрузок доступен только с правом «Журнал загрузок».');
    gc_redirect('admin_panel.php');
}

$logDir = __DIR__ . '/uploads/.logs';
$logPath = $logDir . '/upload-' . gmdate('Y-m-d') . '.log';
if (!is_file($logPath)) {
    $candidates = glob($logDir . '/upload-*.log') ?: [];
    rsort($candidates);
    $logPath = $candidates[0] ?? $logPath;
}

if (isset($_GET['download'])) {
    if (!is_file($logPath)) {
        http_response_code(404);
        header('Content-Type: text/plain; charset=utf-8');
        echo "Журнал пока пуст.\n";
        exit;
    }
    header('Content-Type: application/x-ndjson; charset=utf-8');
    header('Content-Disposition: attachment; filename="gc-upload-' . date('Y-m-d-H-i-s') . '.log"');
    header('Content-Length: ' . (string) filesize($logPath));
    header('X-Content-Type-Options: nosniff');
    readfile($logPath);
    exit;
}

/**
 * Read the newest slice without loading a potentially large log into memory.
 *
 * @return list<string>
 */
function gc_log_tail(string $path, int $maxBytes = 2 * 1024 * 1024, int $maxLines = 1000): array
{
    if (!is_file($path)) {
        return [];
    }
    $size = filesize($path);
    $handle = @fopen($path, 'rb');
    if ($handle === false) {
        return [];
    }
    $start = max(0, $size - $maxBytes);
    if ($start > 0) {
        fseek($handle, $start);
        fgets($handle); // discard the partial first line
    }
    $lines = [];
    while (($line = fgets($handle)) !== false) {
        $line = trim($line);
        if ($line !== '') {
            $lines[] = $line;
        }
    }
    fclose($handle);

    return array_slice($lines, -$maxLines);
}

$records = [];
foreach (gc_log_tail($logPath) as $line) {
    $record = json_decode($line, true);
    if (is_array($record)) {
        $records[] = $record;
    }
}
$records = array_reverse($records);

$eventFilter = trim((string) ($_GET['event'] ?? ''));
$search = trim((string) ($_GET['q'] ?? ''));
$events = [];
foreach ($records as $record) {
    $event = (string) ($record['event'] ?? '');
    if ($event !== '') {
        $events[$event] = ($events[$event] ?? 0) + 1;
    }
}
ksort($events);

$filtered = array_values(array_filter($records, static function (array $record) use ($eventFilter, $search): bool {
    if ($eventFilter !== '' && (string) ($record['event'] ?? '') !== $eventFilter) {
        return false;
    }
    if ($search !== '') {
        $haystack = json_encode($record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '';
        if (mb_stripos($haystack, $search) === false) {
            return false;
        }
    }

    return true;
}));

$limit = 300;
$shown = array_slice($filtered, 0, $limit);
$lastAt = $records !== [] ? (string) ($records[0]['ts'] ?? '') : '';
$errorCount = 0;
foreach ($records as $record) {
    $event = (string) ($record['event'] ?? '');
    if (str_contains($event, 'fail') || str_contains($event, 'error') || str_contains($event, 'fatal')) {
        $errorCount++;
    }
}

gc_head('Журнал загрузок', 'admin');
gc_flashes();
?>

<div class="gc_wrap">
    <section class="gc_hero">
        <div>
            <div class="gc_hero_eyebrow">Диагностика</div>
            <h1 class="gc_hero_title">Журнал загрузок<span class="gc_accent">.</span></h1>
            <p class="gc_hero_lead">
                Серверные события транспорта и сохранения файлов. Записи хранятся
                в <code>uploads/.logs/upload-ГГГГ-ММ-ДД.log</code>; страница читает последние 1000 строк.
            </p>
        </div>
        <div class="gc_hero_side">
            <div class="gc_hero_stat"><b><?= count($records) ?></b><span>событий</span></div>
            <div class="gc_hero_stat"><b><?= $errorCount ?></b><span>ошибок</span></div>
            <div class="gc_hero_stat"><b><?= $lastAt !== '' ? gc_h(date('H:i', strtotime($lastAt))) : '—' ?></b><span>последнее</span></div>
        </div>
    </section>

    <?php gc_admin_nav('logs'); ?>

    <section class="gc_panel">
        <div class="gc_panel_head">
            <h2>Последние события</h2>
            <p>
                Показано <?= count($shown) ?> из <?= count($filtered) ?> подходящих.
                Скачайте полный файл, если нужно разобрать конкретную загрузку.
            </p>
        </div>

        <form class="gc_logs_filter" method="get" action="upload_logs.php">
            <div class="gc_field">
                <label class="gc_label" for="event">Событие</label>
                <select class="gc_select" id="event" name="event">
                    <option value="">Все события</option>
                    <?php foreach ($events as $event => $count) { ?>
                    <option value="<?= gc_h($event) ?>"<?= $eventFilter === $event ? ' selected' : '' ?>><?= gc_h($event) ?> (<?= (int) $count ?>)</option>
                    <?php } ?>
                </select>
            </div>
            <div class="gc_field">
                <label class="gc_label" for="q">Поиск</label>
                <input class="gc_input" id="q" name="q" type="search" value="<?= gc_h($search) ?>" placeholder="имя файла, статус, request_id">
            </div>
            <div class="gc_logs_filter_actions">
                <button class="gc_btn gc_btn--primary gc_btn--sm" type="submit">Применить</button>
                <a class="gc_btn gc_btn--ghost gc_btn--sm" href="upload_logs.php">Сбросить</a>
                <a class="gc_btn gc_btn--outline gc_btn--sm" href="upload_logs.php?download=1">Скачать .log</a>
            </div>
        </form>

        <?php if ($shown === []) { ?>
        <p class="gc_muted">Событий пока нет. Они появятся после первой загрузки или открытия виджета передачи.</p>
        <?php } else { ?>
        <div class="gc_logs">
            <?php foreach ($shown as $record) {
                $event = (string) ($record['event'] ?? '');
                $context = is_array($record['context'] ?? null) ? $record['context'] : [];
                $name = (string) ($context['name'] ?? '');
                $status = $context['status'] ?? ($context['code'] ?? '');
                $request = (string) ($record['request_id'] ?? '');
                $isError = str_contains($event, 'fail') || str_contains($event, 'error') || str_contains($event, 'fatal');
                $summaryBits = [];
                if ($name !== '') $summaryBits[] = $name;
                if ($status !== '') $summaryBits[] = 'HTTP ' . $status;
                if (isset($context['attempt'])) $summaryBits[] = 'попытка ' . $context['attempt'];
                if (isset($context['offset'])) $summaryBits[] = 'offset ' . $context['offset'];
                if (isset($context['duration_ms'])) $summaryBits[] = $context['duration_ms'] . ' мс';
                if (isset($context['message']) && (string) $context['message'] !== '') $summaryBits[] = (string) $context['message'];
            ?>
            <details class="gc_log<?= $isError ? ' is-error' : '' ?>">
                <summary>
                    <span class="gc_log_time"><?= gc_h(date('d.m H:i:s', strtotime((string) ($record['ts'] ?? 'now')))) ?></span>
                    <span class="gc_log_event"><?= gc_h($event) ?></span>
                    <span class="gc_log_brief"><?= gc_h(implode(' · ', array_slice($summaryBits, 0, 3))) ?></span>
                    <span class="gc_log_user">#<?= (int) ($record['uid'] ?? 0) ?></span>
                </summary>
                <div class="gc_log_body">
                    <div class="gc_log_meta">
                        <span>request: <?= gc_h($request !== '' ? $request : '—') ?></span>
                        <span>ip: <?= gc_h((string) ($record['ip'] ?? '—')) ?></span>
                        <span><?= gc_h((string) ($record['method'] ?? '')) ?> <?= gc_h((string) ($record['uri'] ?? '')) ?></span>
                    </div>
                    <pre><?= gc_h(json_encode($context, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}') ?></pre>
                </div>
            </details>
            <?php } ?>
        </div>
        <?php } ?>
    </section>
</div>

<?php gc_footer(); ?>
