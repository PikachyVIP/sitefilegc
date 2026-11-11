<?php
declare(strict_types=1);

/**
 * GC Convert tab: the converter itself is a separate Python service rendered
 * in a same-origin iframe. converter_proxy.php supplies its session.
 */

require_once __DIR__ . '/layout.php';

$user = gc_require_login();

gc_head('Конвертер', 'converter');
?>
<div class="gc_wrap">
    <section class="gc_conv_head">
        <div>
            <div class="gc_hero_eyebrow">GC Convert</div>
            <h1 class="gc_h1">Конвертер</h1>
            <p class="gc_conv_lead">Изображения, документы, аудио и видео. Файл остаётся на нашем сервере: загрузите, выберите формат и скачайте результат.</p>
        </div>
    </section>

    <section class="gc_panel gc_conv_panel">
        <iframe
            class="gc_conv_frame"
            src="convert/embed"
            title="Конвертер файлов"
            allow="clipboard-write"
            loading="eager"
        ></iframe>
    </section>
</div>
<?php gc_foot(); ?>
