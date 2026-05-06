<?php
session_start();
require_once 'db.php';

// Проверка прав на доступ к админ-панели
if (!isset($_SESSION['user']) || !($_SESSION['is_admin'] ?? false)) {
    header('Location: index.php');
    exit;
}

$admin_rights = $_SESSION['admin_rights'] ?? [];
$message = '';
$error = '';

// === СОЗДАНИЕ ПОЛЬЗОВАТЕЛЯ (только с правом create_users) ===
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create_user' && in_array('create_users', $admin_rights)) {
    $new_login = trim($_POST['new_login'] ?? '');
    $new_password = trim($_POST['new_password'] ?? '');
    $new_tags = $_POST['new_tags'] ?? [];
    $new_rights = $_POST['new_rights'] ?? [];
    
    if ($new_login === '' || $new_password === '') {
        $error = 'Логин и пароль обязательны!';
    } else {
        $stmt = $pdo->prepare('SELECT id FROM users WHERE login = ?');
        $stmt->execute([$new_login]);
        if ($stmt->fetch()) {
            $error = 'Пользователь с таким логином уже существует!';
        } else {
            $pdo->beginTransaction();
            try {
                $password_hash = password_hash($new_password, PASSWORD_DEFAULT);
                $stmt = $pdo->prepare('INSERT INTO users (login, password) VALUES (?, ?)');
                $stmt->execute([$new_login, $password_hash]);
                $new_user_id = $pdo->lastInsertId();
                
                // Теги
                if (!empty($new_tags)) {
                    $stmt_tag = $pdo->prepare('INSERT INTO user_tags (user_id, tag_id) VALUES (?, (SELECT id FROM tags WHERE name = ?))');
                    foreach ($new_tags as $tag) {
                        $stmt_tag->execute([$new_user_id, $tag]);
                    }
                }
                
                // Права администратора
                if (!empty($new_rights)) {
                    $stmt_right = $pdo->prepare('INSERT INTO admin_rights (user_id, right_name) VALUES (?, ?)');
                    foreach ($new_rights as $right) {
                        $stmt_right->execute([$new_user_id, $right]);
                    }
                }
                
                $pdo->commit();
                $message = "✅ Пользователь «{$new_login}» создан!";
            } catch (Exception $e) {
                $pdo->rollBack();
                $error = 'Ошибка создания пользователя: ' . $e->getMessage();
            }
        }
    }
}

// === СОЗДАНИЕ ТЕГА (только с правом create_delete_tags) ===
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create_tag' && in_array('create_delete_tags', $admin_rights)) {
    $new_tag = trim($_POST['new_tag'] ?? '');
    
    if ($new_tag === '') {
        $error = 'Название тега обязательно!';
    } else {
        $stmt = $pdo->prepare('SELECT id FROM tags WHERE name = ?');
        $stmt->execute([$new_tag]);
        if ($stmt->fetch()) {
            $error = 'Тег с таким названием уже существует!';
        } else {
            $stmt = $pdo->prepare('INSERT INTO tags (name) VALUES (?)');
            $stmt->execute([$new_tag]);
            $message = "✅ Тег «{$new_tag}» создан!";
        }
    }
}

// === СОХРАНЕНИЕ ТЕГОВ ПОЛЬЗОВАТЕЛЕЙ (edit_user_tags) ===
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_tags') {
    if (!in_array('edit_user_tags', $admin_rights)) {
        $error = '❌ У вас нет прав на изменение тегов пользователей!';
    } else {
        $pdo->beginTransaction();
        try {
            $pdo->exec('DELETE FROM user_tags');
            if (isset($_POST['user_tags']) && is_array($_POST['user_tags'])) {
                $stmt = $pdo->prepare('INSERT INTO user_tags (user_id, tag_id) VALUES (?, (SELECT id FROM tags WHERE name = ?))');
                foreach ($_POST['user_tags'] as $user_id => $tags) {
                    if (is_array($tags)) {
                        foreach ($tags as $tag) {
                            $stmt->execute([(int)$user_id, $tag]);
                        }
                    }
                }
            }
            $pdo->commit();
            $message = '✅ Теги сохранены!';
        } catch (Exception $e) {
            $pdo->rollBack();
            $error = '❌ Ошибка сохранения тегов!';
        }
    }
}

// === СОХРАНЕНИЕ ПРАВ ПОЛЬЗОВАТЕЛЕЙ (manage_rights) ===
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_rights' && in_array('manage_rights', $admin_rights)) {
    $pdo->beginTransaction();
    try {
        $pdo->exec('DELETE FROM admin_rights');
        // Гарантируем, что админ всегда имеет все права
        $stmt_ensure = $pdo->prepare('INSERT IGNORE INTO admin_rights (user_id, right_name) VALUES (1, ?)');
        $all_rights_list = ['admin_panel', 'create_users', 'manage_rights', 'create_delete_tags', 'edit_user_tags', 'edit_any_file', 'view_any_file', 'delete_any_file', 'pin_files'];
        foreach ($all_rights_list as $r) {
            $stmt_ensure->execute([$r]);
        }
        
        if (isset($_POST['user_rights']) && is_array($_POST['user_rights'])) {
            $stmt = $pdo->prepare('INSERT INTO admin_rights (user_id, right_name) VALUES (?, ?)');
            foreach ($_POST['user_rights'] as $user_id => $rights) {
                if (is_array($rights) && (int)$user_id != 1) {
                    foreach ($rights as $right) {
                        $stmt->execute([(int)$user_id, $right]);
                    }
                }
            }
        }
        $pdo->commit();
        $message = '✅ Права сохранены!';
    } catch (Exception $e) {
        $pdo->rollBack();
        $error = '❌ Ошибка сохранения прав!';
    }
}

// === УДАЛЕНИЕ ПОЛЬЗОВАТЕЛЯ (create_users) ===
if (isset($_GET['delete_user']) && in_array('create_users', $admin_rights)) {
    $delete_id = (int)$_GET['delete_user'];
    if ($delete_id != 1) {
        $stmt = $pdo->prepare('DELETE FROM users WHERE id = ?');
        $stmt->execute([$delete_id]);
        $message = '✅ Пользователь удалён!';
    } else {
        $error = '❌ Нельзя удалить главного админа!';
    }
}

// === УДАЛЕНИЕ ТЕГА (create_delete_tags) ===
if (isset($_GET['delete_tag']) && in_array('create_delete_tags', $admin_rights)) {
    $delete_tag_id = (int)$_GET['delete_tag'];
    $stmt = $pdo->prepare('DELETE FROM tags WHERE id = ?');
    $stmt->execute([$delete_tag_id]);
    $message = '✅ Тег удалён!';
}

// === ПОЛУЧАЕМ ДАННЫЕ ===

$all_rights_list = ['admin_panel', 'create_users', 'manage_rights', 'create_delete_tags', 'edit_user_tags', 'edit_any_file', 'view_any_file', 'delete_any_file', 'pin_files'];
$rights_labels = [
    'admin_panel' => 'Админ меню',
    'create_users' => 'Создание пользователей',
    'manage_rights' => 'Выдача прав',
    'create_delete_tags' => 'Создание/удаление тегов',
    'edit_user_tags' => 'Изменять теги пользователям',
    'edit_any_file' => 'Изменять теги чужим файлам',
    'view_any_file' => 'Видеть недоступные файлы',
    'delete_any_file' => 'Удалять чужие файлы',
    'pin_files' => 'Прикреплять файлы'
];

// Все пользователи
$stmt = $pdo->query('SELECT id, login FROM users ORDER BY login');
$users = $stmt->fetchAll();

// Все теги
$stmt = $pdo->query('SELECT id, name FROM tags ORDER BY id');
$all_tags = $stmt->fetchAll();

// Теги пользователей
$user_tags_data = [];
foreach ($users as $user) {
    $stmt = $pdo->prepare('SELECT t.id, t.name FROM tags t JOIN user_tags ut ON t.id = ut.tag_id WHERE ut.user_id = ?');
    $stmt->execute([$user['id']]);
    $user_tags_data[$user['id']] = $stmt->fetchAll(PDO::FETCH_COLUMN, 1);
}

// Права пользователей
$user_rights_data = [];
foreach ($users as $user) {
    $stmt = $pdo->prepare('SELECT right_name FROM admin_rights WHERE user_id = ?');
    $stmt->execute([$user['id']]);
    $user_rights_data[$user['id']] = $stmt->fetchAll(PDO::FETCH_COLUMN);
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <link rel="icon" href="data:,">
    <title>Админ-панель</title>
    <link rel="stylesheet" href="style.css">
    <style>
        .rights-grid {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            margin-top: 10px;
        }
        .rights-grid label {
            display: flex;
            align-items: center;
            gap: 4px;
            cursor: pointer;
            padding: 5px 12px;
            background: #0d0d0d;
            border: 1px solid #333;
            border-radius: 15px;
            font-size: 12px;
            color: #ccc;
            transition: all 0.2s;
        }
        .rights-grid label:hover {
            border-color: #c9a84c;
        }
        .rights-grid label:has(input:checked) {
            color: #c9a84c;
            border-color: #c9a84c;
            background: #1a1a00;
        }
    </style>
</head>
<body class="admin-page">
    <div class="container">
        <header class="header">
            <h1>⚙ Админ-панель</h1>
            <a href="files.php" class="back-btn">← Назад к файлам</a>
        </header>
        
        <?php if ($message): ?>
            <div class="success-message"><?php echo $message; ?></div>
        <?php endif; ?>
        
        <?php if ($error): ?>
            <div class="error-message"><?php echo $error; ?></div>
        <?php endif; ?>
        
        <div class="admin-grid">
            
            <!-- ЛЕВАЯ КОЛОНКА -->
            <div class="admin-column">
                
                <?php if (in_array('create_users', $admin_rights)): ?>
                <div class="admin-block">
                    <h2>👤 Создать пользователя</h2>
                    <form method="POST" class="inline-form">
                        <input type="hidden" name="action" value="create_user">
                        <div class="form-row">
                            <input type="text" name="new_login" placeholder="Логин" required>
                            <input type="text" name="new_password" placeholder="Пароль" required>
                        </div>
                        <?php if (in_array('edit_user_tags', $admin_rights)): ?>
                        <div class="form-row">
                            <div class="admin-tags">
                                <?php foreach ($all_tags as $tag): ?>
                                    <label>
                                        <input type="checkbox" name="new_tags[]" value="<?php echo htmlspecialchars($tag['name']); ?>">
                                        <?php echo htmlspecialchars($tag['name']); ?>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <?php endif; ?>
                        <?php if (in_array('manage_rights', $admin_rights)): ?>
                        <div class="form-row">
                            <div class="rights-grid">
                                <?php foreach ($all_rights_list as $right): ?>
                                    <label>
                                        <input type="checkbox" name="new_rights[]" value="<?php echo $right; ?>">
                                        <?php echo $rights_labels[$right]; ?>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <?php endif; ?>
                        <button type="submit" class="action-btn green-btn">Создать пользователя</button>
                    </form>
                </div>
                <?php endif; ?>
                
                <?php if (in_array('create_delete_tags', $admin_rights)): ?>
                <div class="admin-block">
                    <h2>🏷 Создать тег</h2>
                    <form method="POST" class="inline-form">
                        <input type="hidden" name="action" value="create_tag">
                        <div class="form-row">
                            <input type="text" name="new_tag" placeholder="Название тега" required>
                            <button type="submit" class="action-btn gold-btn">Создать тег</button>
                        </div>
                    </form>
                </div>
                
                <div class="admin-block">
                    <h2>📋 Все теги (<?php echo count($all_tags); ?>)</h2>
                    <div class="tag-list">
                        <?php foreach ($all_tags as $tag): ?>
                            <span class="tag-item">
                                <?php echo htmlspecialchars($tag['name']); ?>
                                <a href="?delete_tag=<?php echo $tag['id']; ?>" 
                                   class="tag-delete" 
                                   onclick="return confirm('Удалить тег «<?php echo htmlspecialchars($tag['name']); ?>»?')">✕</a>
                            </span>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>
                
            </div>
            
            <!-- ПРАВАЯ КОЛОНКА -->
            <div class="admin-column">
                
                <?php if (in_array('edit_user_tags', $admin_rights)): ?>
                <div class="admin-block">
                    <h2>🔑 Теги пользователей</h2>
                    <form method="POST" onsubmit="return confirm('Сохранить изменения тегов пользователей?')">
                        <input type="hidden" name="action" value="save_tags">
                        
                        <?php foreach ($users as $user): ?>
                            <div class="user-edit-card">
                                <div class="user-card-header">
                                    <h3>
                                        👤 <?php echo htmlspecialchars($user['login']); ?>
                                        <?php if ($user['id'] == 1): ?>
                                            <span class="admin-mark">(админ)</span>
                                        <?php endif; ?>
                                    </h3>
                                    <?php if ($user['id'] != 1 && in_array('create_users', $admin_rights)): ?>
                                        <a href="?delete_user=<?php echo $user['id']; ?>" 
                                           class="delete-user-btn"
                                           onclick="return confirm('Удалить пользователя «<?php echo htmlspecialchars($user['login']); ?>»?')">Удалить</a>
                                    <?php endif; ?>
                                </div>
                                
                                <div class="admin-tags">
                                    <?php foreach ($all_tags as $tag): ?>
                                        <label>
                                            <input type="checkbox" 
                                                   name="user_tags[<?php echo $user['id']; ?>][]" 
                                                   value="<?php echo htmlspecialchars($tag['name']); ?>"
                                                   <?php echo in_array($tag['name'], $user_tags_data[$user['id']] ?? []) ? 'checked' : ''; ?>>
                                            <?php echo htmlspecialchars($tag['name']); ?>
                                        </label>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                        
                        <button type="submit" class="save-btn">💾 Сохранить теги</button>
                    </form>
                </div>
                <?php endif; ?>
                
                <?php if (in_array('manage_rights', $admin_rights)): ?>
                <div class="admin-block">
                    <h2>🛡 Права администраторов</h2>
                    <form method="POST">
                        <input type="hidden" name="action" value="save_rights">
                        
                        <?php foreach ($users as $user): ?>
                            <div class="user-edit-card">
                                <div class="user-card-header">
                                    <h3>
                                        👤 <?php echo htmlspecialchars($user['login']); ?>
                                        <?php if ($user['id'] == 1): ?>
                                            <span class="admin-mark">(все права)</span>
                                        <?php endif; ?>
                                    </h3>
                                </div>
                                
                                <?php if ($user['id'] != 1): ?>
                                <div class="rights-grid">
                                    <?php foreach ($all_rights_list as $right): ?>
                                        <label>
                                            <input type="checkbox" 
                                                   name="user_rights[<?php echo $user['id']; ?>][]" 
                                                   value="<?php echo $right; ?>"
                                                   <?php echo in_array($right, $user_rights_data[$user['id']] ?? []) ? 'checked' : ''; ?>>
                                            <?php echo $rights_labels[$right]; ?>
                                        </label>
                                    <?php endforeach; ?>
                                </div>
                                <?php else: ?>
                                    <p style="color: #666; font-size: 12px; padding: 5px;">Главный администратор всегда имеет полный доступ.</p>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                        
                        <button type="submit" class="save-btn">💾 Сохранить права</button>
                    </form>
                </div>
                <?php endif; ?>
                
            </div>
            
        </div>
    </div>
</body>
</html>