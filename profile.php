<?php
declare(strict_types=1);

require_once __DIR__ . '/layout.php';

$user = gc_require_login();

$tagStatement = $pdo->prepare(
    'SELECT t.name FROM tags t JOIN user_tags ut ON ut.tag_id = t.id WHERE ut.user_id = ? ORDER BY t.name'
);
$tagStatement->execute([$user['id']]);
$tags = array_values(array_map('strval', $tagStatement->fetchAll(PDO::FETCH_COLUMN)));

$rightStatement = $pdo->prepare('SELECT right_name FROM admin_rights WHERE user_id = ? ORDER BY right_name');
$rightStatement->execute([$user['id']]);
$rights = array_values(array_map('strval', $rightStatement->fetchAll(PDO::FETCH_COLUMN)));

$rightLabels = [
    'admin_panel' => 'Админ-меню',
    'view_any_file' => 'Просмотр всех файлов',
    'pin_files' => 'Закрепление файлов',
    'delete_any_file' => 'Удаление любых файлов',
    'edit_any_file' => 'Редактирование любых файлов',
];

$roleLabel = gc_is_admin() ? 'Администратор' : 'Участник команды';

gc_head('Профиль', 'profile');
?>
<div class="gc_wrap">
    <?php gc_flashes(); ?>

    <section class="gc_hero">
        <div>
            <div class="gc_hero_eyebrow">Аккаунт</div>
            <h1 class="gc_h1 gc_hero_title">Профиль</h1>
            <p class="gc_hero_lead">Аккаунт, теги видимости и права. Теги определяют, какие файлы основного хранилища вам видны; права — что разрешено делать с чужими файлами.</p>
        </div>
        <div class="gc_hero_side">
            <div class="gc_hero_stat">
                <b><?= count($tags) ?></b>
                <span>тегов</span>
            </div>
            <div class="gc_hero_stat">
                <b><?= count($rights) ?></b>
                <span>прав</span>
            </div>
        </div>
    </section>

    <div class="gc_profile_grid">
        <aside class="gc_card gc_card--glass gc_identity">
            <span class="gc_avatar gc_avatar--lg" aria-hidden="true"><?= gc_h(gc_initials($user['login'])) ?></span>
            <h2><?= gc_h($user['login']) ?></h2>
            <p><?= gc_h($roleLabel) ?></p>

            <div class="gc_identity_roles">
                <?php if ($tags === []) { ?>
                <span class="gc_tag">Без тегов</span>
                <?php } ?>
                <?php foreach ($tags as $index => $tag) {
                    $tones = ['accent', 'violet', 'info'];
                    ?>
                <span class="gc_tag gc_tag--<?= $tones[$index % count($tones)] ?>"><?= gc_h($tag) ?></span>
                <?php } ?>
            </div>

            <div class="gc_identity_foot">ID <?= (int) $user['id'] ?> · сессия 24 часа</div>
        </aside>

        <div>
            <div class="gc_card gc_card--glass">
                <div class="gc_section_head">
                    <div>
                        <h2 class="gc_section_title">Учётная запись</h2>
                        <p class="gc_section_note">Данные пришли из базы при входе. Изменения тегов и прав применяются после повторного входа.</p>
                    </div>
                </div>

                <dl class="gc_dl">
                    <div>
                        <dt>Логин</dt>
                        <dd><?= gc_h($user['login']) ?></dd>
                    </div>
                    <div>
                        <dt>Идентификатор</dt>
                        <dd><code><?= (int) $user['id'] ?></code></dd>
                    </div>
                    <div>
                        <dt>Роль</dt>
                        <dd><?= gc_h($roleLabel) ?><?= $user['id'] === 1 ? ' · главный администратор' : '' ?></dd>
                    </div>
                    <div>
                        <dt>Теги видимости</dt>
                        <dd>
                            <?php if ($tags === []) { ?>
                            <span class="gc_muted">Не назначены — видны только свои файлы и файлы с личным доступом.</span>
                            <?php } else { ?>
                            <?= gc_h(implode(', ', $tags)) ?>
                            <?php } ?>
                        </dd>
                    </div>
                    <div>
                        <dt>Права</dt>
                        <dd>
                            <?php if ($rights === []) { ?>
                            <span class="gc_muted">Базовые права участника.</span>
                            <?php } else { ?>
                            <?= gc_h(implode(', ', array_map(static fn (string $right): string => $rightLabels[$right] ?? $right, $rights))) ?>
                            <?php } ?>
                        </dd>
                    </div>
                </dl>
            </div>
        </div>
    </div>
</div>
<?php gc_foot(); ?>
