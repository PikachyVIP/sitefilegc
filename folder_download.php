<?php
declare(strict_types=1);

require_once __DIR__ . '/layout.php';

$user = gc_require_login();
$uid = (int) $user['id'];
$superadmin = gc_is_superadmin();
$folderId = isset($_GET['folder']) ? (int) $_GET['folder'] : 0;

if ($folderId <= 0) {
    gc_flash('error', 'Выберите папку для скачивания.');
    gc_redirect('storage.php');
}

$folders = $pdo->query('SELECT id, name, parent_id, created_by, is_personal FROM folders ORDER BY id')->fetchAll();
$folderMap = [];
$children = [];
foreach ($folders as $row) {
    $id = (int) $row['id'];
    $parent = $row['parent_id'] !== null ? (int) $row['parent_id'] : 0;
    $folderMap[$id] = [
        'id' => $id,
        'name' => (string) $row['name'],
        'parent_id' => $parent,
        'created_by' => (int) $row['created_by'],
        'is_personal' => gc_bool_value($row['is_personal']),
    ];
    $children[$parent][] = $id;
}

if (!isset($folderMap[$folderId])) {
    http_response_code(404);
    gc_head('Папка не найдена', '', ['curtain' => false]);
    echo '<div class="gc_wrap"><div class="gc_empty"><span class="gc_ico gc_ico--folder"></span><h3>Папка не найдена</h3><p>Возможно, её уже удалили.</p><a class="gc_btn gc_btn--outline" href="storage.php">В хранилище</a></div></div>';
    gc_foot();
    exit;
}

$folderTags = [];
foreach ($pdo->query('SELECT ft.folder_id, t.name FROM folder_tags ft JOIN tags t ON t.id = ft.tag_id ORDER BY t.name')->fetchAll() as $row) {
    $folderTags[(int) $row['folder_id']][] = (string) $row['name'];
}

$userTagSet = [];
if (isset($user['tags']) && is_array($user['tags'])) {
    foreach ($user['tags'] as $tagName) {
        $userTagSet[(string) $tagName] = true;
    }
}
$viewAll = gc_has_right('view_any_file');

$canSeeFolder = static function (int $id) use ($folderMap, $folderTags, $userTagSet, $viewAll, $uid, $superadmin): bool {
    if (!isset($folderMap[$id])) {
        return false;
    }
    if (!empty($folderMap[$id]['is_personal'])) {
        return $superadmin || (int) $folderMap[$id]['created_by'] === $uid;
    }
    if ((int) $folderMap[$id]['created_by'] === $uid || $viewAll) {
        return true;
    }
    $tags = $folderTags[$id] ?? [];
    if ($tags === []) {
        return true;
    }
    foreach ($tags as $tagName) {
        if (isset($userTagSet[$tagName])) {
            return true;
        }
    }
    return false;
};

$canSeePath = static function (int $id) use ($folderMap, $canSeeFolder): bool {
    $seen = [];
    $cursor = $id;
    while ($cursor > 0 && isset($folderMap[$cursor]) && !isset($seen[$cursor])) {
        if (!$canSeeFolder($cursor)) {
            return false;
        }
        $seen[$cursor] = true;
        $cursor = (int) $folderMap[$cursor]['parent_id'];
    }
    return true;
};

if (!$canSeePath($folderId)) {
    gc_flash('error', 'Эта папка недоступна.');
    gc_redirect('storage.php');
}

$scopeFolders = [];
$walk = static function (int $id) use (&$walk, $children, $canSeePath, &$scopeFolders): void {
    if (!$canSeePath($id)) {
        return;
    }
    $scopeFolders[$id] = true;
    foreach ($children[$id] ?? [] as $childId) {
        $walk((int) $childId);
    }
};
$walk($folderId);

$ids = array_keys($scopeFolders);
$in = implode(',', array_fill(0, count($ids), '?'));
$personalDownload = !empty($folderMap[$folderId]['is_personal']);
if ($personalDownload && $superadmin) {
    $statement = $pdo->prepare(
        'SELECT DISTINCT f.id, f.filename, f.original_name, f.folder_id FROM files f'
        . ' WHERE f.folder_id IN (' . $in . ')'
        . ' ORDER BY f.original_name'
    );
    $statement->execute($ids);
} elseif ($personalDownload) {
    $statement = $pdo->prepare(
        'SELECT DISTINCT f.id, f.filename, f.original_name, f.folder_id FROM files f'
        . ' WHERE f.folder_id IN (' . $in . ') AND f.uploader_id = ?'
        . ' ORDER BY f.original_name'
    );
    $statement->execute(array_merge($ids, [$uid]));
} else {
    $statement = $pdo->prepare(
        'SELECT DISTINCT f.id, f.filename, f.original_name, f.folder_id FROM files f'
        . ' WHERE f.folder_id IN (' . $in . ')'
        . ' AND (f.uploader_id = ?'
        . ' OR EXISTS (SELECT 1 FROM file_access fa WHERE fa.file_id = f.id AND fa.user_id = ?)'
        . ' OR EXISTS (SELECT 1 FROM file_tags ft JOIN user_tags ut ON ut.tag_id = ft.tag_id'
        . ' WHERE ft.file_id = f.id AND ut.user_id = ?))'
        . ' ORDER BY f.original_name'
    );
    $statement->execute(array_merge($ids, [$uid, $uid, $uid]));
}
$files = $statement->fetchAll();

if ($files === []) {
    gc_flash('error', 'В этой папке нет файлов, которые вы можете скачать.');
    gc_redirect(gc_storage_link($folderId));
}

$rootName = trim((string) $folderMap[$folderId]['name']);
if ($rootName === '') {
    $rootName = 'folder';
}
$zipName = preg_replace('/[^\p{L}\p{N} ._()\-]+/u', '_', $rootName) ?: 'folder';
$zipName = trim($zipName, " ._") ?: 'folder';

$entryName = static function (string $name): string {
    $name = preg_replace('/[\\\\\/\x00-\x1F\x7F]+/u', '_', $name) ?? '';
    $name = trim($name);
    if ($name === '' || $name === '.' || $name === '..') {
        return 'file';
    }
    return $name;
};

$relativeFolders = static function (int $id) use ($folderMap, $folderId): array {
    $parts = [];
    $cursor = $id;
    $seen = [];
    while ($cursor > 0 && isset($folderMap[$cursor]) && $cursor !== $folderId && !isset($seen[$cursor])) {
        $seen[$cursor] = true;
        array_unshift($parts, $folderMap[$cursor]['name']);
        $cursor = $folderMap[$cursor]['parent_id'];
    }
    return $parts;
};

$zipDosTime = static function (int $timestamp): array {
    $date = getdate($timestamp);
    $dosDate = (($date['year'] - 1980) << 9) | ($date['mon'] << 5) | $date['mday'];
    $dosTime = ($date['hours'] << 11) | ($date['minutes'] << 5) | intdiv($date['seconds'], 2);
    return [$dosTime, $dosDate];
};

$central = [];
$offset = 0;
$usedNames = [];

header('Content-Type: application/zip');
header("Content-Disposition: attachment; filename=\"folder.zip\"; filename*=UTF-8''" . rawurlencode($zipName . '.zip'));
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');
set_time_limit(0);
session_write_close();

$uploadsRoot = realpath(__DIR__ . '/uploads');
if ($uploadsRoot === false) {
    exit;
}

foreach ($files as $file) {
    $path = realpath($uploadsRoot . '/' . (string) $file['filename']);
    if ($path === false || !is_file($path) || !str_starts_with($path, $uploadsRoot . DIRECTORY_SEPARATOR)) {
        continue;
    }

    $parts = $relativeFolders((int) $file['folder_id']);
    $name = $entryName((string) $file['original_name']);
    $baseName = implode('/', array_map($entryName, $parts));
    if ($baseName !== '') {
        $name = $baseName . '/' . $name;
    }
    $name = $entryName($rootName) . '/' . $name;

    $candidate = $name;
    $counter = 2;
    while (isset($usedNames[$candidate])) {
        $dot = strrpos($name, '.');
        $candidate = $dot === false
            ? $name . ' (' . $counter . ')'
            : substr($name, 0, $dot) . ' (' . $counter . ')' . substr($name, $dot);
        $counter++;
    }
    $usedNames[$candidate] = true;
    $name = $candidate;

    $mtime = @filemtime($path) ?: time();
    [$dosTime, $dosDate] = $zipDosTime($mtime);
    $nameLength = strlen($name);
    $handle = fopen($path, 'rb');
    if ($handle === false) {
        continue;
    }
    $localOffset = $offset;
    $local = pack('VvvvvvVVVvv', 0x04034b50, 20, 0x0800, 8, $dosTime, $dosDate, 0, 0, 0, $nameLength, 0) . $name;
    echo $local;
    $offset += strlen($local);

    $hash = hash_init('crc32b');
    $deflate = deflate_init(ZLIB_ENCODING_RAW, ['level' => 6]);
    $compressedSize = 0;
    $uncompressedSize = 0;
    while (!feof($handle)) {
        $chunk = fread($handle, 1048576);
        if ($chunk === false || $chunk === '') {
            break;
        }
        $uncompressedSize += strlen($chunk);
        hash_update($hash, $chunk);
        $out = deflate_add($deflate, $chunk, ZLIB_NO_FLUSH);
        $compressedSize += strlen($out);
        echo $out;
    }
    fclose($handle);
    $tail = deflate_add($deflate, '', ZLIB_FINISH);
    $compressedSize += strlen($tail);
    echo $tail;
    $crc = hexdec(hash_final($hash));
    $descriptor = pack('VVVV', 0x08074b50, $crc, $compressedSize, $uncompressedSize);
    echo $descriptor;
    $offset += $compressedSize + strlen($descriptor);

    $central[] = pack(
        'VvvvvvvVVVvvvvvVV',
        0x02014b50,
        20,
        20,
        0x0800,
        8,
        $dosTime,
        $dosDate,
        $crc,
        $compressedSize,
        $uncompressedSize,
        $nameLength,
        0,
        0,
        0,
        0,
        0,
        $localOffset
    ) . $name;
}

$centralData = implode('', $central);
$centralOffset = $offset;
echo $centralData;
echo pack('VvvvvVVv', 0x06054b50, 0, 0, count($central), count($central), strlen($centralData), $centralOffset, 0);
exit;
