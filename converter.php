<?php
declare(strict_types=1);

/**
 * GC Convert tab — PHP page. The browser only talks to converter_api.php;
 * conversion runs locally through converter_engine.php (Python bridge or the
 * native CLI fallback).
 */

require_once __DIR__ . '/layout.php';
require_once __DIR__ . '/converter_lib.php';

$user = gc_require_login();
$catalog = gc_conv_catalog();

$full = gc_conv_full_data();
$formatCount = count($full['inputs']);
$pairCount = count($full['pairs']);
$accept = [];
foreach (array_keys($full['inputs']) as $ext) {
    if (!str_contains($ext, '/')) {
        $accept[] = '.' . $ext;
    }
}
foreach ($catalog as $group) {
    foreach (array_keys($group['inputs']) as $ext) {
        $accept[] = '.' . $ext;
    }
}
$accept = array_values(array_unique($accept));
sort($accept);

$payload = [
    'csrf' => gc_csrf_token(),
    'api' => 'converter_api.php',
    'max' => gc_conv_max_upload(),
    'catalog' => $catalog,
    'qualities' => [
        'image' => gc_conv_quality_choices('image'),
        'media' => gc_conv_quality_choices('media'),
        'pdf' => gc_conv_quality_choices('pdf'),
    ],
];
$json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);

gc_head('Конвертер', 'converter');
?>
<div class="gc_wrap gc_conv">
    <section class="gc_conv_head">
        <div>
            <div class="gc_hero_eyebrow">GC Convert</div>
            <h1 class="gc_h1">Конвертер</h1>
            <p class="gc_conv_lead">Изображения, документы, аудио и видео. Файл остаётся на нашем сервере: загрузите, выберите формат и скачайте результат.</p>
        </div>
        <div class="gc_conv_facts">
            <span><?= (int) $formatCount ?> форматов</span>
            <span><?= (int) $pairCount ?> пар конвертации</span>
            <span>до <?= gc_h(gc_conv_human_size(gc_conv_max_upload())) ?></span>
            <span>хранение 24 часа</span>
        </div>
    </section>

    <section class="gc_panel gc_conv_panel">
        <div class="gc_conv_body">
            <div class="gc_conv_drop" id="gcConvDrop" role="button" tabindex="0" aria-controls="gcConvInput">
                <span class="gc_conv_drop_icon" aria-hidden="true">
                    <svg viewBox="0 0 24 24" width="26" height="26" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M12 16V4"></path>
                        <path d="m7 9 5-5 5 5"></path>
                        <path d="M4 15v4a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-4"></path>
                    </svg>
                </span>
                <span class="gc_conv_drop_title">Перетащите файлы сюда</span>
                <span class="gc_conv_drop_hint">или нажмите, чтобы выбрать. Можно несколько сразу.</span>
                <input type="file" id="gcConvInput" multiple accept="<?= gc_h(implode(',', $accept)) ?>" hidden>
            </div>

            <p class="gc_conv_tools">Изображения, документы, таблицы, презентации, PDF, аудио, видео, архивы и книги.</p>

            <div class="gc_conv_notice" id="gcConvNotice" role="status" hidden></div>

            <div class="gc_conv_list" id="gcConvList"></div>

            <div class="gc_conv_empty" id="gcConvEmpty">
                <span class="gc_conv_empty_title">Пока пусто</span>
                <span>Добавьте файл — формат результата выбирается для каждого файла отдельно.</span>
            </div>

            <div class="gc_conv_actions">
                <span class="gc_hint">Обработка локальная, без внешних сервисов.</span>
                <div class="gc_conv_actions_buttons">
                    <button type="button" class="gc_btn gc_btn--ghost" id="gcConvClear" disabled>Очистить список</button>
                    <button type="button" class="gc_btn gc_btn--primary" id="gcConvStart" disabled>Конвертировать</button>
                </div>
            </div>
        </div>
    </section>

    <section class="gc_conv_history">
        <div class="gc_conv_history_head">
            <h2 class="gc_h3">Последние задачи</h2>
            <button type="button" class="gc_btn gc_btn--ghost gc_btn--sm" id="gcConvHistoryLoad">Показать</button>
        </div>
        <div class="gc_conv_history_list" id="gcConvHistoryList"></div>
    </section>
</div>

<noscript>
    <p class="gc_hint">Для работы конвертера нужен JavaScript.</p>
</noscript>

<script type="application/json" id="gcConvData"><?= $json ?></script>
<?php gc_foot(['scripts' => ['assets/js/converter.js']]); ?>
