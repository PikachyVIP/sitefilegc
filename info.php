<?php
declare(strict_types=1);

require_once __DIR__ . '/layout.php';

$user = gc_require_login();

gc_head('Инфо', 'info');
?>
<div class="gc_wrap">
    <?php gc_flashes(); ?>

    <section class="gc_hero">
        <div>
            <div class="gc_hero_eyebrow">О проекте</div>
            <h1 class="gc_h1 gc_hero_title">О проекте</h1>
            <p class="gc_hero_lead">GC Files — закрытое хранилище команды. Один вход, общее основное хранилище с папками и отдельный временный трансфер для файлов, которые не должны остаться навсегда.</p>
        </div>
        <div class="gc_hero_side">
            <div class="gc_hero_stat">
                <b>v<?= gc_h(GC_APP_VERSION) ?></b>
                <span>версия сайта</span>
            </div>
            <div class="gc_hero_stat">
                <b>GC</b>
                <span>закрытый контур</span>
            </div>
        </div>
    </section>

    <section class="gc_info_grid" aria-label="Как устроен сайт">
        <article class="gc_card gc_card--glass gc_feature">
            <div class="gc_feature_ico"><span class="gc_ico gc_ico--shield" aria-hidden="true"></span></div>
            <h3>Доступ по тегам</h3>
            <p>Файл видит загрузивший, пользователи с совпадающим тегом и те, кому выдан личный доступ. Право просмотра без права скачивания не открывает файл.</p>
            <span class="gc_tag gc_tag--accent">Закрытый сайт команды</span>
        </article>

        <article class="gc_card gc_card--glass gc_feature">
            <div class="gc_feature_ico"><span class="gc_ico gc_ico--folder" aria-hidden="true"></span></div>
            <h3>Основное хранилище</h3>
            <p>Папки складываются в дерево, файлы сортируются по имени, размеру, типу и дате. Каждый файл получает теги видимости и личные доступы.</p>
            <span class="gc_tag gc_tag--violet">Папки и сортировка</span>
        </article>

        <article class="gc_card gc_card--glass gc_feature">
            <div class="gc_feature_ico"><span class="gc_ico gc_ico--swap" aria-hidden="true"></span></div>
            <h3>Временный трансфер</h3>
            <p>Файл живёт час, сутки или неделю. Ссылку можно открыть без входа, а после истечения срока файл удаляется с диска автоматически.</p>
            <span class="gc_tag gc_tag--info">Ссылки с TTL</span>
        </article>
    </section>
</div>
<?php gc_foot(); ?>
