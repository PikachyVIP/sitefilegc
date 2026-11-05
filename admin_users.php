<?php
declare(strict_types=1);

require_once __DIR__ . '/layout.php';

$user = gc_require_login();

$canCreate = gc_has_right('create_users');
$canRights = gc_has_right('manage_rights');
$canUserTags = gc_has_right('edit_user_tags');
$canTagCrud = gc_has_right('create_delete_tags');
$isAdmin = gc_is_admin();

/** Rights a non-admin manager must never grant to himself or to another admin. */
function gc_admin_user_rights(PDO $pdo, int $userId): array
{
    $stmt = $pdo->prepare('SELECT right_name FROM admin_rights WHERE user_id = ?');
    $stmt->execute([$userId]);

    return array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN));
}

function gc_admin_user_tag_ids(PDO $pdo, int $userId): array
{
    $stmt = $pdo->prepare('SELECT tag_id FROM user_tags WHERE user_id = ?');
    $stmt->execute([$userId]);

    return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
}

if (!$canCreate && !$canRights && !$canUserTags && !$canTagCrud) {
    gc_flash('error', 'У вас нет прав на управление пользователями.');
    gc_redirect('admin_panel.php');
}

/** Keep only ids that really exist, so a forged POST cannot invent a row. */
function gc_admin_ids(array $values): array
{
    $ids = [];
    foreach ($values as $value) {
        $id = (int) $value;
        if ($id > 0) {
            $ids[$id] = $id;
        }
    }

    return array_values($ids);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    gc_csrf_check();
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'tag_create' && $canTagCrud) {
        $name = trim((string) ($_POST['tag_name'] ?? ''));
        if (gc_strlen($name) < 2 || gc_strlen($name) > 40) {
            gc_flash('error', 'Название тега должно быть от 2 до 40 символов.');
        } else {
            $stmt = $pdo->prepare('SELECT 1 FROM tags WHERE lower(name) = lower(?)');
            $stmt->execute([$name]);
            if ($stmt->fetchColumn()) {
                gc_flash('error', 'Такой тег уже есть.');
            } else {
                $stmt = $pdo->prepare('INSERT INTO tags (name) VALUES (?)');
                $stmt->execute([$name]);
                gc_flash('ok', 'Тег «' . $name . '» создан.');
            }
        }
        gc_redirect('admin_users.php');
    }

    if ($action === 'tag_delete' && $canTagCrud) {
        $tagId = (int) ($_POST['tag_id'] ?? 0);
        $stmt = $pdo->prepare('SELECT name FROM tags WHERE id = ?');
        $stmt->execute([$tagId]);
        $tagName = $stmt->fetchColumn();
        if ($tagId <= 0 || $tagName === false) {
            gc_flash('error', 'Тег не найден.');
        } else {
            $stmt = $pdo->prepare(
                'SELECT (SELECT count(*) FROM user_tags WHERE tag_id = ?)
                      + (SELECT count(*) FROM file_tags WHERE tag_id = ?)'
            );
            $stmt->execute([$tagId, $tagId]);
            if ((int) $stmt->fetchColumn() > 0) {
                gc_flash('error', 'Тег «' . $tagName . '» ещё назначен пользователям или файлам.');
            } else {
                $stmt = $pdo->prepare('DELETE FROM tags WHERE id = ?');
                $stmt->execute([$tagId]);
                gc_flash('ok', 'Тег «' . $tagName . '» удалён.');
            }
        }
        gc_redirect('admin_users.php');
    }

    if ($action === 'user_create' && $canCreate) {
        $login = trim((string) ($_POST['login'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');
        $tags = $canUserTags ? gc_admin_ids((array) ($_POST['tags'] ?? [])) : [];
        $rights = $canRights ? array_values(array_intersect(
            array_keys(GC_RIGHTS),
            array_map('strval', (array) ($_POST['rights'] ?? []))
        )) : [];
        if (!$isAdmin) {
            $rights = array_values(array_diff($rights, ['admin_panel']));
        }

        if (!preg_match('/^[A-Za-z0-9_.-]{3,32}$/', $login)) {
            gc_flash('error', 'Логин: 3–32 символа, латиница, цифры, точка, дефис или подчёркивание.');
        } elseif (gc_strlen($password) < 8) {
            gc_flash('error', 'Пароль должен быть не короче 8 символов.');
        } else {
            $stmt = $pdo->prepare('SELECT 1 FROM users WHERE lower(login) = lower(?)');
            $stmt->execute([$login]);
            if ($stmt->fetchColumn()) {
                gc_flash('error', 'Пользователь с таким логином уже существует.');
            } else {
                try {
                    $pdo->beginTransaction();
                    $stmt = $pdo->prepare('INSERT INTO users (login, password) VALUES (?, ?) RETURNING id');
                    $stmt->execute([$login, password_hash($password, PASSWORD_DEFAULT)]);
                    $newId = (int) $stmt->fetchColumn();

                    $insertTag = $pdo->prepare('INSERT INTO user_tags (user_id, tag_id) VALUES (?, ?) ON CONFLICT DO NOTHING');
                    foreach ($tags as $tagId) {
                        $insertTag->execute([$newId, $tagId]);
                    }
                    $insertRight = $pdo->prepare('INSERT INTO admin_rights (user_id, right_name) VALUES (?, ?) ON CONFLICT DO NOTHING');
                    foreach ($rights as $right) {
                        $insertRight->execute([$newId, $right]);
                    }
                    $pdo->commit();
                    gc_flash('ok', 'Пользователь «' . $login . '» создан.');
                    gc_redirect('admin_users.php?user=' . $newId);
                } catch (Throwable $e) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    gc_flash('error', 'Не удалось создать пользователя.');
                }
            }
        }
        gc_redirect('admin_users.php');
    }

    if ($action === 'user_save') {
        $targetId = (int) ($_POST['user_id'] ?? 0);
        $stmt = $pdo->prepare('SELECT id, login FROM users WHERE id = ?');
        $stmt->execute([$targetId]);
        $target = $stmt->fetch();
        if ($target === false) {
            gc_flash('error', 'Пользователь не найден.');
            gc_redirect('admin_users.php');
        }

        $newTags = $canUserTags ? gc_admin_ids((array) ($_POST['tags'] ?? [])) : null;
        $newRights = null;
        if ($canRights && $targetId !== 1) {
            $newRights = array_values(array_intersect(
                array_keys(GC_RIGHTS),
                array_map('strval', (array) ($_POST['rights'] ?? []))
            ));
        }

        // A manager may not promote himself or touch an existing admin. He can
        // still edit a non-admin peer's rights: manage_rights is delegated
        // power over accounts, recorded as debt/decision, not a self-service
        // route to admin_panel.
        if (!$isAdmin && $targetId !== 1) {
            $targetRights = gc_admin_user_rights($pdo, $targetId);
            if (in_array('admin_panel', $targetRights, true)) {
                gc_flash('error', 'Менять права администратора может только администратор.');
                gc_redirect('admin_users.php?user=' . $targetId);
            }
            if ($newRights !== null) {
                if ($targetId === (int) $user['id']) {
                    $newRights = array_values(array_intersect($newRights, $targetRights));
                } else {
                    $newRights = array_values(array_diff($newRights, ['admin_panel']));
                }
            }
            if ($newTags !== null && $targetId === (int) $user['id']) {
                $newTags = array_values(array_intersect($newTags, gc_admin_user_tag_ids($pdo, $targetId)));
            }
        }

        if ($newTags === null && $newRights === null) {
            gc_flash('error', 'Недостаточно прав для изменения этого пользователя.');
            gc_redirect('admin_users.php?user=' . $targetId);
        }

        try {
            $pdo->beginTransaction();
            if ($newTags !== null) {
                $pdo->prepare('DELETE FROM user_tags WHERE user_id = ?')->execute([$targetId]);
                $insertTag = $pdo->prepare('INSERT INTO user_tags (user_id, tag_id) VALUES (?, ?) ON CONFLICT DO NOTHING');
                foreach ($newTags as $tagId) {
                    $insertTag->execute([$targetId, $tagId]);
                }
            }
            if ($newRights !== null) {
                $pdo->prepare('DELETE FROM admin_rights WHERE user_id = ?')->execute([$targetId]);
                $insertRight = $pdo->prepare('INSERT INTO admin_rights (user_id, right_name) VALUES (?, ?) ON CONFLICT DO NOTHING');
                foreach ($newRights as $right) {
                    $insertRight->execute([$targetId, $right]);
                }
            }
            $pdo->commit();
            if ($targetId === (int) $user['id']) {
                gc_refresh_session_rights($pdo, $targetId);
            }
            gc_flash('ok', 'Доступы пользователя «' . $target['login'] . '» сохранены.');
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            gc_flash('error', 'Не удалось сохранить доступы.');
        }
        gc_redirect('admin_users.php?user=' . $targetId);
    }

    if ($action === 'user_password' && $canCreate) {
        $targetId = (int) ($_POST['user_id'] ?? 0);
        $password = (string) ($_POST['password'] ?? '');
        $targetRights = $targetId > 0 ? gc_admin_user_rights($pdo, $targetId) : [];
        if ($targetId === 1 && (int) $user['id'] !== 1) {
            gc_flash('error', 'Пароль суперадмина может менять только он сам.');
        } elseif (!$isAdmin && $targetId !== (int) $user['id']) {
            gc_flash('error', 'Пароль другого пользователя может менять только администратор.');
        } elseif (gc_strlen($password) < 8) {
            gc_flash('error', 'Пароль должен быть не короче 8 символов.');
        } else {
            $stmt = $pdo->prepare('UPDATE users SET password = ? WHERE id = ?');
            $stmt->execute([password_hash($password, PASSWORD_DEFAULT), $targetId]);
            gc_flash('ok', 'Пароль обновлён.');
        }
        gc_redirect('admin_users.php?user=' . $targetId);
    }

    if ($action === 'user_delete' && $canCreate) {
        $targetId = (int) ($_POST['user_id'] ?? 0);
        $targetRights = $targetId > 0 ? gc_admin_user_rights($pdo, $targetId) : [];
        if ($targetId === 1 || $targetId === (int) $user['id']) {
            gc_flash('error', 'Нельзя удалить суперадмина или себя.');
        } elseif (!$isAdmin && in_array('admin_panel', $targetRights, true)) {
            gc_flash('error', 'Удалить администратора может только администратор.');
        } else {
            $stmt = $pdo->prepare(
                'SELECT (SELECT count(*) FROM files WHERE uploader_id = ?)
                      + (SELECT count(*) FROM transfers WHERE uploader_id = ?)'
            );
            $stmt->execute([$targetId, $targetId]);
            if ((int) $stmt->fetchColumn() > 0) {
                gc_flash('error', 'У пользователя есть файлы. Сначала передайте или удалите их.');
            } else {
                $stmt = $pdo->prepare('DELETE FROM users WHERE id = ?');
                $stmt->execute([$targetId]);
                gc_flash('ok', 'Пользователь удалён.');
            }
        }
        gc_redirect('admin_users.php');
    }

    gc_flash('error', 'Неизвестное действие.');
    gc_redirect('admin_users.php');
}

$users = $pdo->query(
    'SELECT u.id, u.login,
            (SELECT count(*) FROM files f WHERE f.uploader_id = u.id) AS files_count,
            (SELECT count(*) FROM admin_rights ar WHERE ar.user_id = u.id) AS rights_count
     FROM users u ORDER BY u.id'
)->fetchAll();

$tags = $pdo->query(
    'SELECT t.id, t.name,
            (SELECT count(*) FROM user_tags ut WHERE ut.tag_id = t.id) AS users_count,
            (SELECT count(*) FROM file_tags ft WHERE ft.tag_id = t.id) AS files_count
     FROM tags t ORDER BY t.name'
)->fetchAll();

$userTags = [];
$userRights = [];
foreach ($users as $row) {
    $uid = (int) $row['id'];
    $stmt = $pdo->prepare('SELECT tag_id FROM user_tags WHERE user_id = ?');
    $stmt->execute([$uid]);
    $userTags[$uid] = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));

    $stmt = $pdo->prepare('SELECT right_name FROM admin_rights WHERE user_id = ? ORDER BY right_name');
    $stmt->execute([$uid]);
    $userRights[$uid] = $stmt->fetchAll(PDO::FETCH_COLUMN);
}

$selectedId = isset($_GET['user']) ? (int) $_GET['user'] : 0;
$selected = null;
foreach ($users as $row) {
    if ((int) $row['id'] === $selectedId) {
        $selected = $row;
        break;
    }
}

$tagNames = [];
foreach ($tags as $tag) {
    $tagNames[(int) $tag['id']] = (string) $tag['name'];
}

gc_head('Пользователи', 'admin');
gc_flashes();
?>

<div class="gc_wrap">
    <section class="gc_hero">
        <div>
            <div class="gc_hero_eyebrow">Пользователи и права</div>
            <h1 class="gc_hero_title">Пользователи<span class="gc_accent">.</span></h1>
            <p class="gc_hero_lead">
                Учётные записи, теги и права. Тег открывает файлы, право — действие
                в админ-меню; суперадмин с ID 1 имеет всё и не редактируется.
            </p>
        </div>
        <div class="gc_hero_side">
            <div class="gc_hero_stat"><b><?= count($users) ?></b><span>пользователей</span></div>
            <div class="gc_hero_stat"><b><?= count($tags) ?></b><span>тегов</span></div>
        </div>
    </section>

    <?php gc_admin_nav('users'); ?>

    <?php if ($selected !== null) {
        $sid = (int) $selected['id'];
        $isSelf = $sid === (int) $user['id'];
        $isSuper = $sid === 1;
        ?>
    <section class="gc_section">
        <div class="gc_card gc_admin_editor">
            <div class="gc_panel_head">
                <div>
                    <span class="gc_hero_eyebrow">Права пользователя</span>
                    <h2><?= gc_h((string) $selected['login']) ?><?= $isSuper ? ' · суперадмин' : '' ?></h2>
                    <p>ID <?= $sid ?> · <?= (int) $selected['files_count'] ?> файлов · <?= (int) $selected['rights_count'] ?> прав</p>
                </div>
                <a class="gc_btn gc_btn--ghost gc_btn--sm" href="admin_users.php">Закрыть</a>
            </div>

            <?php if ($canUserTags || ($canRights && !$isSuper)) { ?>
            <form method="post" class="gc_admin_form">
                <?= gc_csrf_field() ?>
                <input type="hidden" name="action" value="user_save">
                <input type="hidden" name="user_id" value="<?= $sid ?>">

                <div class="gc_form_grid">
                    <?php if ($canUserTags) { ?>
                    <div class="gc_field">
                        <span class="gc_label">Теги доступа</span>
                        <?php if ($tags === []) { ?>
                        <p class="gc_hint">Тегов пока нет.</p>
                        <?php } else { ?>
                        <div class="gc_checks gc_checks--wide">
                            <?php foreach ($tags as $tag) { ?>
                            <label class="gc_check">
                                <input type="checkbox" name="tags[]" value="<?= (int) $tag['id'] ?>"
                                    <?= in_array((int) $tag['id'], $userTags[$sid] ?? [], true) ? 'checked' : '' ?>>
                                <span><?= gc_h((string) $tag['name']) ?></span>
                            </label>
                            <?php } ?>
                        </div>
                        <?php } ?>
                    </div>
                    <?php } ?>

                    <?php if ($canRights && !$isSuper) { ?>
                    <div class="gc_field">
                        <span class="gc_label">Права</span>
                        <div class="gc_checks gc_checks--wide">
                            <?php foreach (GC_RIGHTS as $right => $label) { ?>
                            <label class="gc_check">
                                <input type="checkbox" name="rights[]" value="<?= gc_h($right) ?>"
                                    <?= in_array($right, $userRights[$sid] ?? [], true) ? 'checked' : '' ?>>
                                <span><?= gc_h($label) ?></span>
                            </label>
                            <?php } ?>
                        </div>
                    </div>
                    <?php } ?>
                </div>

                <?php if ($isSuper) { ?>
                <p class="gc_hint">Права суперадмина всегда полные; их нельзя снять через эту форму.</p>
                <?php } ?>

                <div class="gc_btn-row">
                    <button class="gc_btn gc_btn--primary" type="submit">Сохранить доступы</button>
                    <?php if ($isSelf && $canRights) { ?>
                    <span class="gc_hint">Свои права применяются сразу после сохранения.</span>
                    <?php } ?>
                </div>
            </form>
            <?php } ?>

            <div class="gc_admin_split">
                <?php if ($canCreate) { ?>
                <form method="post" class="gc_admin_mini">
                    <?= gc_csrf_field() ?>
                    <input type="hidden" name="action" value="user_password">
                    <input type="hidden" name="user_id" value="<?= $sid ?>">
                    <span class="gc_label">Сбросить пароль</span>
                    <div class="gc_input_wrap">
                        <input class="gc_input" type="password" name="password" minlength="8" required autocomplete="new-password" placeholder="Новый пароль, минимум 8 символов">
                    </div>
                    <button class="gc_btn gc_btn--outline gc_btn--sm" type="submit">Обновить пароль</button>
                </form>
                <?php } ?>

                <?php if ($canCreate && !$isSuper && !$isSelf) { ?>
                <form method="post" class="gc_admin_mini" data-confirm="Удалить пользователя «<?= gc_h((string) $selected['login']) ?>»?">
                    <?= gc_csrf_field() ?>
                    <input type="hidden" name="action" value="user_delete">
                    <input type="hidden" name="user_id" value="<?= $sid ?>">
                    <span class="gc_label">Опасная зона</span>
                    <p class="gc_hint">Удаление разрешено только для пользователя без файлов.</p>
                    <button class="gc_btn gc_btn--ghost gc_btn--sm gc_text-danger" type="submit">Удалить пользователя</button>
                </form>
                <?php } ?>
            </div>
        </div>
    </section>
    <?php } ?>

    <?php if ($canCreate) { ?>
    <details class="gc_upload_panel gc_admin_create"<?= $selected === null && $users === [] ? ' open' : '' ?>>
        <summary>
            <span class="gc_ico gc_ico--plus" aria-hidden="true"></span>
            <span>Создать пользователя</span>
            <span class="gc_ico gc_ico--chevron" aria-hidden="true"></span>
        </summary>
        <form method="post" class="gc_admin_form">
            <?= gc_csrf_field() ?>
            <input type="hidden" name="action" value="user_create">
            <div class="gc_form_grid">
                <div class="gc_field">
                    <label class="gc_label" for="new-login">Логин</label>
                    <input class="gc_input" id="new-login" name="login" minlength="3" maxlength="32" required autocomplete="off" placeholder="например, artist_gc">
                </div>
                <div class="gc_field">
                    <label class="gc_label" for="new-password">Пароль</label>
                    <input class="gc_input" id="new-password" type="password" name="password" minlength="8" required autocomplete="new-password" placeholder="минимум 8 символов">
                </div>
            </div>

            <?php if ($canUserTags && $tags !== []) { ?>
            <div class="gc_field">
                <span class="gc_label">Теги доступа</span>
                <div class="gc_checks">
                    <?php foreach ($tags as $tag) { ?>
                    <label class="gc_check">
                        <input type="checkbox" name="tags[]" value="<?= (int) $tag['id'] ?>">
                        <span><?= gc_h((string) $tag['name']) ?></span>
                    </label>
                    <?php } ?>
                </div>
            </div>
            <?php } ?>

            <?php if ($canRights) { ?>
            <div class="gc_field">
                <span class="gc_label">Права</span>
                <div class="gc_checks">
                    <?php foreach (GC_RIGHTS as $right => $label) { ?>
                    <label class="gc_check">
                        <input type="checkbox" name="rights[]" value="<?= gc_h($right) ?>">
                        <span><?= gc_h($label) ?></span>
                    </label>
                    <?php } ?>
                </div>
            </div>
            <?php } ?>

            <div class="gc_btn-row">
                <button class="gc_btn gc_btn--primary" type="submit">Создать пользователя</button>
            </div>
        </form>
    </details>
    <?php } ?>

    <section class="gc_section">
        <div class="gc_section_head">
            <h2 class="gc_section_title">Все учётные записи</h2>
            <span class="gc_section_note"><?= count($users) ?> всего</span>
        </div>
        <div class="gc_card gc_admin_list">
            <?php foreach ($users as $row) {
                $uid = (int) $row['id'];
                $rowTags = $userTags[$uid] ?? [];
                ?>
            <div class="gc_userrow<?= $uid === $selectedId ? ' is-active' : '' ?>">
                <span class="gc_avatar" aria-hidden="true"><?= gc_h(gc_initials((string) $row['login'])) ?></span>
                <div class="gc_userrow_main">
                    <div class="gc_userrow_name">
                        <?= gc_h((string) $row['login']) ?>
                        <?php if ($uid === 1) { ?><span class="gc_tag gc_tag--accent gc_tag--xs">super</span><?php } ?>
                        <?php if ($uid === (int) $user['id']) { ?><span class="gc_tag gc_tag--info gc_tag--xs">вы</span><?php } ?>
                    </div>
                    <div class="gc_userrow_meta">
                        ID <?= $uid ?>
                        <span class="gc_footer_dot">·</span>
                        <?= (int) $row['files_count'] ?> файлов
                        <span class="gc_footer_dot">·</span>
                        <?= (int) $row['rights_count'] ?> прав
                    </div>
                    <div class="gc_userrow_tags">
                        <?php if ($rowTags === []) { ?>
                        <span class="gc_dim">без тегов</span>
                        <?php } else { ?>
                        <?php foreach ($rowTags as $tagId) { ?>
                        <span class="gc_tag gc_tag--xs"><?= gc_h($tagNames[$tagId] ?? ('#' . $tagId)) ?></span>
                        <?php } ?>
                        <?php } ?>
                    </div>
                </div>
                <a class="gc_btn gc_btn--ghost gc_btn--sm" href="admin_users.php?user=<?= $uid ?>">Изменить</a>
            </div>
            <?php } ?>
        </div>
    </section>

    <?php if ($canTagCrud) { ?>
    <section class="gc_section">
        <div class="gc_section_head">
            <h2 class="gc_section_title">Теги</h2>
            <span class="gc_section_note">видны в профиле и хранилище</span>
        </div>
        <div class="gc_card">
            <div class="gc_taglist">
                <?php foreach ($tags as $tag) { ?>
                <div class="gc_taglist_row">
                    <span class="gc_tag"><?= gc_h((string) $tag['name']) ?></span>
                    <span class="gc_taglist_meta"><?= (int) $tag['users_count'] ?> польз. · <?= (int) $tag['files_count'] ?> файлов</span>
                    <form method="post" data-confirm="Удалить тег «<?= gc_h((string) $tag['name']) ?>»?">
                        <?= gc_csrf_field() ?>
                        <input type="hidden" name="action" value="tag_delete">
                        <input type="hidden" name="tag_id" value="<?= (int) $tag['id'] ?>">
                        <button class="gc_iconbtn gc_iconbtn--danger" type="submit" aria-label="Удалить тег" title="Удалить тег">
                            <span class="gc_ico gc_ico--delete" aria-hidden="true"></span>
                        </button>
                    </form>
                </div>
                <?php } ?>
            </div>
            <form method="post" class="gc_admin_tagform">
                <?= gc_csrf_field() ?>
                <input type="hidden" name="action" value="tag_create">
                <div class="gc_input_wrap">
                    <input class="gc_input" name="tag_name" minlength="2" maxlength="40" required placeholder="Новый тег, например «Модератор GC»">
                </div>
                <button class="gc_btn gc_btn--outline" type="submit">Создать тег</button>
            </form>
        </div>
    </section>
    <?php } ?>
</div>

<?php gc_foot(); ?>
