<?php
declare(strict_types=1);

require_once __DIR__ . '/layout.php';

$user = gc_require_login();

$apps = $pdo->query(
    'SELECT id, slug, name, description FROM apps WHERE is_active ORDER BY name, id'
)->fetchAll();

$releaseStmt = $pdo->prepare(
    'SELECT id, version, original_name, file_size, notes, downloads, published_at
     FROM app_releases
     WHERE app_id = ? AND channel = ? AND is_active
     ORDER BY published_at DESC, id DESC
     LIMIT 1'
);

$releases = [];
foreach ($apps as $app) {
    foreach (['stable', 'beta'] as $channel) {
        $releaseStmt->execute([(int) $app['id'], $channel]);
        $releases[(int) $app['id']][$channel] = $releaseStmt->fetch() ?: null;
    }
}

$canManage = gc_has_right('manage_updates');
$channelLabels = ['stable' => 'Stable', 'beta' => 'Beta'];

gc_head('Приложения', 'apps');
gc_flashes();
?>

<div class="gc_wrap">
    <section class="gc_hero">
        <div>
            <div class="gc_hero_eyebrow">Приложения команды</div>
            <h1 class="gc_hero_title">Обновления<span class="gc_accent">.</span></h1>
            <p class="gc_hero_lead">
                Сборки приложений GC, которые раздаёт этот сайт. Stable — для всех,
                Beta — для проверки перед выкладкой. Клиенты обновляются через
                <code>update_api.php</code>.
            </p>
        </div>
        <div class="gc_hero_side">
            <div class="gc_hero_stat"><b><?= count($apps) ?></b><span>приложений</span></div>
            <?php if ($canManage) { ?>
            <a class="gc_btn gc_btn--outline gc_btn--sm" href="admin_releases.php">
                <span class="gc_ico gc_ico--gear" aria-hidden="true"></span>
                Управлять
            </a>
            <?php } ?>
        </div>
    </section>

    <?php if ($apps === []) { ?>
    <div class="gc_empty">
        <span class="gc_ico gc_ico--grid" aria-hidden="true"></span>
        <h3>Приложений пока нет</h3>
        <p>Администратор добавит их в разделе «Приложения» админ-меню.</p>
    </div>
    <?php } else { ?>
    <div class="gc_app_grid">
        <?php foreach ($apps as $app) {
            $appId = (int) $app['id'];
            ?>
        <article class="gc_card gc_app_card">
            <div class="gc_app_head">
                <span class="gc_app_mark" aria-hidden="true"><span class="gc_ico gc_ico--grid"></span></span>
                <div class="gc_app_title">
                    <h2 class="gc_h3"><?= gc_h((string) $app['name']) ?></h2>
                    <span class="gc_app_slug"><?= gc_h((string) $app['slug']) ?></span>
                </div>
                <?php if ($canManage) { ?>
                <a class="gc_iconbtn" href="admin_releases.php?app=<?= $appId ?>" title="Редактировать приложение">
                    <span class="gc_ico gc_ico--gear" aria-hidden="true"></span>
                </a>
                <?php } ?>
            </div>

            <?php if (trim((string) $app['description']) !== '') { ?>
            <p class="gc_app_desc"><?= nl2br(gc_h((string) $app['description'])) ?></p>
            <?php } ?>

            <div class="gc_channels">
                <?php foreach (['stable', 'beta'] as $channel) {
                    $release = $releases[$appId][$channel] ?? null;
                    ?>
                <div class="gc_channel<?= $release === null ? ' is-empty' : '' ?>">
                    <div class="gc_channel_head">
                        <span class="gc_tag gc_tag--<?= $channel === 'stable' ? 'accent' : 'info' ?>"><?= gc_h($channelLabels[$channel]) ?></span>
                        <?php if ($release !== null) { ?>
                        <span class="gc_channel_version">v<?= gc_h((string) $release['version']) ?></span>
                        <?php } else { ?>
                        <span class="gc_channel_version">нет сборки</span>
                        <?php } ?>
                    </div>
                    <?php if ($release !== null) { ?>
                    <div class="gc_channel_meta">
                        <?= gc_h(gc_dt((string) $release['published_at'], 'd.m.Y')) ?>
                        <span class="gc_footer_dot">·</span>
                        <?= gc_h(gc_size((int) $release['file_size'])) ?>
                        <span class="gc_footer_dot">·</span>
                        <?= (int) $release['downloads'] ?> скачиваний
                    </div>
                    <div class="gc_channel_actions">
                        <a class="gc_btn gc_btn--sm gc_btn--outline" href="update_download.php?id=<?= (int) $release['id'] ?>">
                            <span class="gc_ico gc_ico--download" aria-hidden="true"></span>
                            Скачать
                        </a>
                    </div>
                    <?php } ?>
                </div>
                <?php } ?>
            </div>
        </article>
        <?php } ?>
    </div>

    <section class="gc_section">
        <div class="gc_card gc_api_note">
            <span class="gc_ico gc_ico--link" aria-hidden="true"></span>
            <div>
                <h2 class="gc_h3">Автообновление</h2>
                <p class="gc_lead">
                    Клиент запрашивает сборку по каналу и slug приложения. Без параметра
                    <code>app</code> API отвечает за приложение по умолчанию.
                </p>
                <div class="gc_api_url">
                    <code><?= gc_h(gc_base_url()) ?>/update_api.php?channel=stable&amp;app=<?= gc_h((string) ($apps[0]['slug'] ?? '')) ?></code>
                    <button class="gc_iconbtn" type="button" data-copy data-copy-target=".gc_api_url code" aria-label="Скопировать адрес API" title="Скопировать">
                        <span class="gc_ico gc_ico--copy" aria-hidden="true"></span>
                    </button>
                </div>
            </div>
        </div>
    </section>
    <?php } ?>
</div>

<?php gc_foot(); ?>
