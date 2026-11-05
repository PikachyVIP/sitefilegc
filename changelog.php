<?php
declare(strict_types=1);

require_once __DIR__ . '/layout.php';

$user = gc_require_login();

$statement = $pdo->query(
    'SELECT c.id, c.version, c.title, c.body, c.category, c.published_at, u.login AS author
       FROM changelog c
       LEFT JOIN users u ON u.id = c.published_by
      WHERE c.is_published = true
      ORDER BY c.published_at DESC, c.id DESC'
);
$entries = $statement->fetchAll();

$counts = [];
foreach ($entries as $entry) {
    $category = (string) $entry['category'];
    $counts[$category] = ($counts[$category] ?? 0) + 1;
}

$months = ['янв', 'фев', 'мар', 'апр', 'май', 'июн', 'июл', 'авг', 'сен', 'окт', 'ноя', 'дек'];

$bodyHtml = static function (string $body): string {
    $paragraphs = preg_split('/\R{2,}/', trim($body)) ?: [];
    $html = '';
    foreach ($paragraphs as $paragraph) {
        $paragraph = trim($paragraph);
        if ($paragraph === '') {
            continue;
        }
        $html .= '<p>' . nl2br(gc_h($paragraph), false) . '</p>';
    }

    return $html;
};

gc_head('Changelog');
?>
<div class="gc_wrap">
    <?php gc_flashes(); ?>

    <section class="gc_hero">
        <div>
            <div class="gc_hero_eyebrow">Журнал команды</div>
            <h1 class="gc_h1 gc_hero_title">Что нового</h1>
            <p class="gc_hero_lead">Журнал изменений сайта и приложения. Здесь появляются релизы, обновления хранилища и важные исправления — от свежих к старым.</p>
        </div>
        <div class="gc_hero_side">
            <div class="gc_hero_stat">
                <b><?= count($entries) ?></b>
                <span>записей</span>
            </div>
            <div class="gc_hero_stat">
                <b><?= $entries !== [] ? gc_h(date('d.m', strtotime((string) $entries[0]['published_at']))) : '—' ?></b>
                <span>обновлено</span>
            </div>
        </div>
    </section>

    <?php if ($entries === []) { ?>
    <div class="gc_empty">
        <span class="gc_ico gc_ico--news" aria-hidden="true"></span>
        <h3>Записей пока нет</h3>
        <p>Первое обновление появится здесь после публикации в админ-меню.</p>
    </div>
    <?php } else { ?>
    <div class="gc_changelog">
        <div>
            <div class="gc_filters" role="group" aria-label="Фильтр по категориям">
                <button class="gc_chip is-active" type="button" data-filter="all" aria-pressed="true">Все <b><?= count($entries) ?></b></button>
                <?php foreach (GC_CATEGORIES as $key => $label) {
                    if (($counts[$key] ?? 0) === 0) {
                        continue;
                    }
                    ?>
                <button class="gc_chip" type="button" data-filter="<?= gc_h($key) ?>" aria-pressed="false"><?= gc_h($label) ?> <b><?= (int) $counts[$key] ?></b></button>
                <?php } ?>
            </div>

            <ol class="gc_timeline">
                <?php foreach ($entries as $index => $entry) {
                    $timestamp = strtotime((string) $entry['published_at']);
                    $category = (string) $entry['category'];
                    $categoryClass = isset(GC_CATEGORIES[$category]) ? $category : 'site';
                    ?>
                <li class="gc_entry<?= $index === 0 ? ' is-latest' : '' ?>" data-cat="<?= gc_h($category) ?>">
                    <div class="gc_entry_date" aria-hidden="true">
                        <span class="gc_entry_day"><?= $timestamp !== false ? gc_h(date('d', $timestamp)) : '—' ?></span>
                        <span class="gc_entry_month"><?= $timestamp !== false ? gc_h($months[(int) date('n', $timestamp) - 1]) : '' ?></span>
                        <span class="gc_entry_year"><?= $timestamp !== false ? gc_h(date('Y', $timestamp)) : '' ?></span>
                    </div>
                    <article class="gc_card gc_card--glass gc_entry_card">
                        <div class="gc_entry_top">
                            <?php if ((string) $entry['version'] !== '') { ?>
                            <span class="gc_version">v<?= gc_h((string) $entry['version']) ?></span>
                            <?php } ?>
                            <span class="gc_cat gc_cat--<?= gc_h($categoryClass) ?>"><?= gc_h(gc_category_label($category)) ?></span>
                        </div>
                        <h2 class="gc_entry_title"><?= gc_h((string) $entry['title']) ?></h2>
                        <div class="gc_entry_body"><?= $bodyHtml((string) $entry['body']) ?></div>
                        <div class="gc_entry_foot">
                            <span><span class="gc_ico gc_ico--user" aria-hidden="true"></span><?= gc_h((string) ($entry['author'] ?? 'GC Team')) ?></span>
                            <span><span class="gc_ico gc_ico--clock" aria-hidden="true"></span><?= gc_h(gc_dt((string) $entry['published_at'])) ?></span>
                        </div>
                    </article>
                </li>
                <?php } ?>
            </ol>

            <div class="gc_empty" data-filter-empty hidden>
                <span class="gc_ico gc_ico--search" aria-hidden="true"></span>
                <h3>В этой категории пусто</h3>
                <p>Выберите другую категорию или покажите все записи.</p>
            </div>
        </div>

        <aside class="gc_aside">
            <div class="gc_card gc_aside_card">
                <h3>Разделы</h3>
                <p>Быстрый переход к рабочим местам сайта.</p>
                <nav class="gc_aside_nav" aria-label="Разделы сайта">
                    <a href="storage.php"><span class="gc_ico gc_ico--folder" aria-hidden="true"></span>Основное хранилище</a>
                    <a href="transfer.php"><span class="gc_ico gc_ico--swap" aria-hidden="true"></span>Временный трансфер</a>
                    <a href="info.php"><span class="gc_ico gc_ico--info" aria-hidden="true"></span>Инфо и приложение</a>
                    <a href="profile.php"><span class="gc_ico gc_ico--user" aria-hidden="true"></span>Профиль и доступы</a>
                </nav>
            </div>

            <div class="gc_card gc_aside_card">
                <h3>Статистика</h3>
                <div class="gc_aside_stats">
                    <div><b><?= count($entries) ?></b><span>записей</span></div>
                    <div><b><?= count($counts) ?></b><span>категорий</span></div>
                    <div><b><?= $entries !== [] ? gc_h(date('d.m.y', strtotime((string) $entries[0]['published_at']))) : '—' ?></b><span>последняя</span></div>
                </div>
            </div>

            <div class="gc_card gc_aside_card">
                <h3>Категории</h3>
                <div class="gc_legend">
                    <?php foreach (GC_CATEGORIES as $key => $label) { ?>
                    <div class="is-<?= gc_h($key) ?>"><i aria-hidden="true"></i><?= gc_h($label) ?></div>
                    <?php } ?>
                </div>
            </div>
        </aside>
    </div>
    <?php } ?>
</div>
<?php gc_foot(); ?>
