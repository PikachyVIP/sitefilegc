/* GC Files — small progressive enhancements. No dependencies, no inline code. */
(function () {
    'use strict';

    var $ = function (sel, root) { return (root || document).querySelector(sel); };
    var $$ = function (sel, root) { return Array.prototype.slice.call((root || document).querySelectorAll(sel)); };

    /* ---------- curtain ---------- */

    function initCurtain() {
        var curtain = $('#gcCurtain');
        var trigger = $('#gcTrigger');
        if (!curtain || !trigger) return;

        var closeTimer = null;

        function setOpen(open) {
            curtain.classList.toggle('is-open', open);
            trigger.classList.toggle('is-open', open);
            trigger.setAttribute('aria-expanded', open ? 'true' : 'false');
            curtain.inert = !open;
        }

        curtain.inert = !curtain.classList.contains('is-open');

        function open() {
            if (closeTimer) { clearTimeout(closeTimer); closeTimer = null; }
            setOpen(true);
        }

        function scheduleClose() {
            if (closeTimer) clearTimeout(closeTimer);
            closeTimer = setTimeout(function () { setOpen(false); }, 320);
        }

        trigger.addEventListener('mouseenter', open);
        trigger.addEventListener('focus', open);
        trigger.addEventListener('click', function (event) {
            event.preventDefault();
            setOpen(!curtain.classList.contains('is-open'));
        });

        curtain.addEventListener('mouseenter', open);
        curtain.addEventListener('mouseleave', scheduleClose);
        trigger.addEventListener('mouseleave', scheduleClose);

        curtain.addEventListener('focusin', open);
        curtain.addEventListener('focusout', function (event) {
            if (!curtain.contains(event.relatedTarget)) scheduleClose();
        });

        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape' && curtain.classList.contains('is-open')) {
                setOpen(false);
                trigger.focus({ preventScroll: true });
            }
        });

        document.addEventListener('pointerdown', function (event) {
            if (!curtain.classList.contains('is-open')) return;
            if (curtain.contains(event.target) || trigger.contains(event.target)) return;
            setOpen(false);
        });
    }

    /* ---------- copy to clipboard ---------- */

    function copyText(text) {
        if (navigator.clipboard && window.isSecureContext) {
            return navigator.clipboard.writeText(text);
        }
        return new Promise(function (resolve, reject) {
            var area = document.createElement('textarea');
            area.value = text;
            area.setAttribute('readonly', '');
            area.style.position = 'fixed';
            area.style.top = '-1000px';
            document.body.appendChild(area);
            area.select();
            try {
                var ok = document.execCommand('copy');
                document.body.removeChild(area);
                ok ? resolve() : reject(new Error('copy failed'));
            } catch (error) {
                document.body.removeChild(area);
                reject(error);
            }
        });
    }

    function initCopy() {
        $$('[data-copy]').forEach(function (button) {
            button.addEventListener('click', function () {
                var value = button.getAttribute('data-copy-value') || '';
                if (!value && button.dataset.copyTarget) {
                    var source = $(button.dataset.copyTarget);
                    if (source) value = source.value || source.textContent || '';
                }
                if (!value) return;

                var label = button.querySelector('[data-copy-label]');
                var previous = label ? label.textContent : '';
                copyText(value).then(function () {
                    button.classList.add('is-copied');
                    if (label) label.textContent = 'Скопировано';
                    setTimeout(function () {
                        button.classList.remove('is-copied');
                        if (label) label.textContent = previous;
                    }, 1600);
                }).catch(function () {
                    if (label) label.textContent = 'Не вышло';
                    setTimeout(function () { if (label) label.textContent = previous; }, 1600);
                });
            });
        });
    }

    /* ---------- password visibility ---------- */

    function initPasswordToggle() {
        $$('[data-password-toggle]').forEach(function (button) {
            button.addEventListener('click', function () {
                var input = $(button.dataset.passwordToggle);
                if (!input) return;
                var show = input.type === 'password';
                input.type = show ? 'text' : 'password';
                button.setAttribute('aria-label', show ? 'Скрыть пароль' : 'Показать пароль');
                var icon = button.querySelector('.gc_ico');
                if (icon) {
                    icon.classList.toggle('gc_ico--eye', !show);
                    icon.classList.toggle('gc_ico--eye-off', show);
                }
            });
        });
    }

    /* ---------- drop zone ---------- */

    function formatSize(bytes) {
        if (!bytes && bytes !== 0) return '';
        if (bytes < 1024) return bytes + ' Б';
        var units = ['КБ', 'МБ', 'ГБ', 'ТБ'];
        var value = bytes / 1024;
        var index = 0;
        while (value >= 1024 && index < units.length - 1) { value /= 1024; index++; }
        return (value >= 10 ? Math.round(value) : value.toFixed(1).replace('.', ',')) + ' ' + units[index];
    }

    function initDrops() {
        $$('.gc_drop').forEach(function (drop) {
            var form = drop.closest('form');
            var input = drop.querySelector('input[type="file"]');
            var dirInput = form ? form.querySelector('[data-folder-input]') : null;
            var nameEl = drop.querySelector('[data-drop-name]');
            var dirNameEl = form ? form.querySelector('[data-dir-name]') : null;
            var hint = drop.querySelector('[data-drop-hint]');
            if (!input) return;

            function plural(count, forms) {
                var n = Math.abs(count) % 100;
                var n1 = n % 10;
                if (n > 10 && n < 20) return forms[2];
                if (n1 > 1 && n1 < 5) return forms[1];
                if (n1 === 1) return forms[0];
                return forms[2];
            }

            function filesOf(target) {
                return target && target.files ? Array.prototype.slice.call(target.files) : [];
            }

            function pathsOf(target) {
                var files = filesOf(target);
                if (target && target._gcPaths && target._gcPaths.length === files.length) {
                    return target._gcPaths;
                }
                return files.map(function (file) { return file.webkitRelativePath || file.name; });
            }

            function setFiles(target, items) {
                if (!target) return;
                try {
                    var transfer = new DataTransfer();
                    items.forEach(function (item) { transfer.items.add(item.file); });
                    target.files = transfer.files;
                } catch (error) {
                    try {
                        target.files = items.map(function (item) { return item.file; });
                    } catch (ignored) {
                        return;
                    }
                }
                target._gcPaths = items.map(function (item) { return item.path; });
            }

            function readEntries(reader) {
                return new Promise(function (resolve, reject) {
                    var all = [];
                    function next() {
                        reader.readEntries(function (entries) {
                            if (!entries.length) {
                                resolve(all);
                                return;
                            }
                            all = all.concat(Array.prototype.slice.call(entries));
                            next();
                        }, reject);
                    }
                    next();
                });
            }

            function walkEntry(entry, prefix) {
                if (!entry) return Promise.resolve([]);
                if (entry.isFile) {
                    return new Promise(function (resolve, reject) {
                        entry.file(function (file) {
                            resolve([{ file: file, path: prefix + file.name }]);
                        }, reject);
                    });
                }
                if (entry.isDirectory) {
                    return readEntries(entry.createReader()).then(function (entries) {
                        return Promise.all(entries.map(function (child) {
                            return walkEntry(child, prefix + entry.name + '/');
                        }));
                    }).then(function (groups) {
                        return groups.reduce(function (all, group) { return all.concat(group); }, []);
                    });
                }
                return Promise.resolve([]);
            }

            function render() {
                var files = filesOf(input);
                var dirFiles = filesOf(dirInput);
                var all = files.concat(dirFiles);
                var total = 0;
                all.forEach(function (file) { total += file.size; });
                drop.classList.toggle('has-file', all.length > 0);
                if (nameEl) {
                    if (all.length === 0) {
                        nameEl.textContent = '';
                    } else if (all.length === 1) {
                        nameEl.textContent = all[0].name + ' · ' + formatSize(all[0].size);
                    } else {
                        nameEl.textContent = all.length + ' ' + plural(all.length, ['файл', 'файла', 'файлов']) + ' · ' + formatSize(total);
                    }
                }
                if (dirNameEl) {
                    dirNameEl.textContent = dirFiles.length
                        ? 'В папке: ' + dirFiles.length + ' ' + plural(dirFiles.length, ['файл', 'файла', 'файлов'])
                        : '';
                }
                if (hint) hint.textContent = all.length ? 'Нажмите, чтобы выбрать другие файлы' : 'Можно несколько · до 20 ГБ каждый';
            }

            input.addEventListener('change', function () {
                input._gcPaths = filesOf(input).map(function (file) {
                    return file.webkitRelativePath || file.name;
                });
                render();
            });
            if (dirInput) {
                dirInput.addEventListener('change', function () {
                    dirInput._gcPaths = filesOf(dirInput).map(function (file) {
                        return file.webkitRelativePath || file.name;
                    });
                    render();
                });
            }

            ['dragenter', 'dragover'].forEach(function (type) {
                drop.addEventListener(type, function (event) {
                    event.preventDefault();
                    drop.classList.add('is-drag');
                });
            });
            ['dragleave', 'dragend'].forEach(function (type) {
                drop.addEventListener(type, function () { drop.classList.remove('is-drag'); });
            });
            drop.addEventListener('drop', function (event) {
                event.preventDefault();
                drop.classList.remove('is-drag');
                var transfer = event.dataTransfer;
                if (!transfer) return;
                var entries = [];
                if (transfer.items && transfer.items.length) {
                    Array.prototype.forEach.call(transfer.items, function (item) {
                        var entry = item.webkitGetAsEntry && item.webkitGetAsEntry();
                        if (entry) entries.push(entry);
                    });
                }
                if (!entries.length) {
                    var dropped = Array.prototype.slice.call(transfer.files || []);
                    if (dropped.length) {
                        setFiles(input, dropped.map(function (file) {
                            return { file: file, path: file.name };
                        }));
                        render();
                    }
                    return;
                }
                Promise.all(entries.map(function (entry) { return walkEntry(entry, ''); })).then(function (groups) {
                    var items = groups.reduce(function (all, group) { return all.concat(group); }, []);
                    if (!items.length) return;
                    var hasFolder = items.some(function (item) { return item.path.indexOf('/') !== -1; });
                    setFiles(hasFolder && dirInput ? dirInput : input, items);
                    render();
                });
            });
        });
    }

    /* ---------- file tree ---------- */

    function initTree() {
        $$('.gc_tree_toggle').forEach(function (button) {
            var target = document.getElementById(button.getAttribute('aria-controls'));
            if (!target) return;
            button.addEventListener('click', function () {
                var open = button.getAttribute('aria-expanded') === 'true';
                button.setAttribute('aria-expanded', open ? 'false' : 'true');
                target.hidden = open;
            });
        });
    }

    /* ---------- changelog filter ---------- */

    function initFilters() {
        var chips = $$('[data-filter]');
        var entries = $$('[data-cat]');
        if (!chips.length || !entries.length) return;
        var empty = $('[data-filter-empty]');

        chips.forEach(function (chip) {
            chip.addEventListener('click', function () {
                var filter = chip.getAttribute('data-filter');
                chips.forEach(function (other) {
                    other.classList.toggle('is-active', other === chip);
                    other.setAttribute('aria-pressed', other === chip ? 'true' : 'false');
                });
                var shown = 0;
                entries.forEach(function (entry) {
                    var match = filter === 'all' || entry.getAttribute('data-cat') === filter;
                    entry.hidden = !match;
                    if (match) shown++;
                });
                if (empty) empty.hidden = shown !== 0;
            });
        });
    }

    /* ---------- storage search (client-side) ---------- */

    function initFileSearch() {
        var input = $('[data-file-search]');
        if (!input) return;
        var rows = $$('[data-file-name]');
        var empty = $('[data-file-empty]');
        var serverEmpty = empty && empty.hasAttribute('data-server-empty');

        function apply() {
            var term = input.value.trim().toLowerCase();
            var shown = 0;
            rows.forEach(function (row) {
                var haystack = (row.getAttribute('data-file-name') || '').toLowerCase();
                var match = !term || haystack.indexOf(term) !== -1;
                row.hidden = !match;
                if (match) shown++;
            });
            if (empty) {
                if (serverEmpty) return;
                empty.hidden = shown !== 0;
                var title = empty.querySelector('[data-empty-title]');
                if (title) {
                    title.textContent = term
                        ? 'Ничего не найдено'
                        : (title.getAttribute('data-empty-default') || 'Здесь пока пусто');
                }
            }
        }

        input.addEventListener('input', apply);
    }

    /* ---------- password / dangerous action confirm ---------- */

    function initConfirm() {
        $$('[data-confirm]').forEach(function (form) {
            form.addEventListener('submit', function (event) {
                if (!window.confirm(form.getAttribute('data-confirm'))) {
                    event.preventDefault();
                }
            });
        });
        $$('a[data-confirm]').forEach(function (link) {
            link.addEventListener('click', function (event) {
                if (!window.confirm(link.getAttribute('data-confirm'))) {
                    event.preventDefault();
                }
            });
        });
    }

    /* ---------- expiry countdown ---------- */

    function initCountdowns() {
        var nodes = $$('[data-expires]');
        if (!nodes.length) return;

        function plural(n, one, few, many) {
            var mod10 = n % 10;
            var mod100 = n % 100;
            if (mod10 === 1 && mod100 !== 11) return one;
            if (mod10 >= 2 && mod10 <= 4 && (mod100 < 10 || mod100 >= 20)) return few;
            return many;
        }

        function tick() {
            var now = Date.now();
            nodes.forEach(function (node) {
                var target = Date.parse(node.getAttribute('data-expires'));
                if (!target) return;
                var text = node.querySelector('[data-countdown]') || node;
                var diff = target - now;
                if (diff <= 0) {
                    text.textContent = 'истёк';
                    node.classList.add('is-dead');
                    return;
                }
                var minutes = Math.floor(diff / 60000);
                var hours = Math.floor(diff / 3600000);
                var days = Math.floor(diff / 86400000);
                var label;
                if (minutes < 60) {
                    label = minutes + ' ' + plural(minutes, 'минута', 'минуты', 'минут');
                } else if (hours < 24) {
                    label = hours + ' ' + plural(hours, 'час', 'часа', 'часов');
                } else {
                    label = days + ' ' + plural(days, 'день', 'дня', 'дней');
                }
                text.textContent = label;
                node.classList.toggle('is-soon', diff < 3600000);
            });
        }

        tick();
        setInterval(tick, 30000);
    }

    /* ---------- open details panels from toolbar buttons ---------- */

    function initDetailsOpeners() {
        $$('[data-open-details]').forEach(function (button) {
            button.addEventListener('click', function (event) {
                var id = button.getAttribute('data-open-details');
                var panel = id ? document.getElementById(id) : null;
                if (!panel) return;
                event.preventDefault();
                panel.open = true;
                panel.scrollIntoView({ behavior: 'smooth', block: 'center' });
                var field = panel.querySelector('input, select, textarea');
                if (field) {
                    window.setTimeout(function () { field.focus(); }, 180);
                }
            });
        });
    }

    /* ---------- drive grid / list toggle ---------- */

    function initViewToggle() {
        var main = $('.gc_storage_main[data-drive-view]');
        if (!main) return;
        var buttons = $$('[data-view-btn]');
        if (!buttons.length) return;

        function apply(view) {
            if (view !== 'grid' && view !== 'list') return;
            main.setAttribute('data-drive-view', view);
            buttons.forEach(function (button) {
                var active = button.getAttribute('data-view-btn') === view;
                button.classList.toggle('is-active', active);
                button.setAttribute('aria-pressed', active ? 'true' : 'false');
            });
            try {
                window.localStorage.setItem('gcDriveView', view);
            } catch (error) {
                /* storage may be unavailable in private contexts */
            }
        }

        buttons.forEach(function (button) {
            button.addEventListener('click', function () {
                apply(button.getAttribute('data-view-btn'));
            });
        });

        var saved = null;
        try {
            saved = window.localStorage.getItem('gcDriveView');
        } catch (error) {
            saved = null;
        }
        if (saved === 'grid' || saved === 'list') apply(saved);
    }

    /* ---------- drag a file onto a folder ---------- */

    function initFileDrag() {
        var form = document.getElementById('gcMoveForm');
        if (!form) return;
        var folders = $$('[data-drop-folder]');
        var cards = $$('[data-file-id][draggable="true"]');
        if (!folders.length || !cards.length) return;

        var draggedId = null;
        var idField = form.querySelector('[name="id"]');
        var targetField = form.querySelector('[name="target_folder"]');

        function clear() {
            draggedId = null;
            cards.forEach(function (card) { card.classList.remove('is-dragging'); });
            folders.forEach(function (folder) {
                folder.classList.remove('is-drop-ready');
                folder.classList.remove('is-drop-target');
            });
        }

        cards.forEach(function (card) {
            card.addEventListener('dragstart', function (event) {
                draggedId = card.getAttribute('data-file-id');
                card.classList.add('is-dragging');
                folders.forEach(function (folder) { folder.classList.add('is-drop-ready'); });
                if (event.dataTransfer) {
                    event.dataTransfer.effectAllowed = 'move';
                    try {
                        event.dataTransfer.setData('text/plain', draggedId);
                    } catch (error) {
                        /* some browsers restrict the payload */
                    }
                }
            });
            card.addEventListener('dragend', clear);
        });

        folders.forEach(function (folder) {
            folder.addEventListener('dragover', function (event) {
                if (draggedId === null) return;
                event.preventDefault();
                if (event.dataTransfer) event.dataTransfer.dropEffect = 'move';
                folder.classList.add('is-drop-target');
            });
            folder.addEventListener('dragleave', function (event) {
                if (event.relatedTarget && folder.contains(event.relatedTarget)) return;
                folder.classList.remove('is-drop-target');
            });
            folder.addEventListener('drop', function (event) {
                if (draggedId === null) return;
                event.preventDefault();
                var target = folder.getAttribute('data-drop-folder');
                if (!target || !idField || !targetField) return;
                idField.value = draggedId;
                targetField.value = target;
                clear();
                form.submit();
            });
        });
    }

    /* ---------- tag dialogs for folders and files ---------- */

    function initTagDialogs() {
        var folderDialog = document.getElementById('gcFolderTagsDialog');
        var fileDialog = document.getElementById('gcFileTagsDialog');
        var editDialog = document.getElementById('gcFolderEditDialog');
        var folderRemove = document.getElementById('gcFolderTagRemoveForm');
        var fileRemove = document.getElementById('gcFileTagRemoveForm');

        function tagList(button, attribute) {
            try {
                var parsed = JSON.parse(button.getAttribute(attribute) || '[]');
                return Array.isArray(parsed) ? parsed : [];
            } catch (error) {
                return [];
            }
        }

        function renderTags(container, tags, onRemove) {
            if (!container) return;
            container.innerHTML = '';
            if (!tags.length) {
                var empty = document.createElement('span');
                empty.className = 'gc_muted';
                empty.textContent = 'Тегов пока нет.';
                container.appendChild(empty);
                return;
            }
            tags.forEach(function (tag) {
                var chip = document.createElement('span');
                chip.className = 'gc_tag gc_tag--xs gc_modal_tag';
                var label = document.createElement('span');
                label.textContent = tag.name;
                chip.appendChild(label);
                var remove = document.createElement('button');
                remove.type = 'button';
                remove.className = 'gc_modal_tag_x';
                remove.textContent = '×';
                remove.setAttribute('aria-label', 'Убрать тег ' + tag.name);
                remove.addEventListener('click', function () { onRemove(tag.id); });
                chip.appendChild(remove);
                container.appendChild(chip);
            });
        }

        function closeOnBackdrop(dialog) {
            dialog.addEventListener('click', function (event) {
                if (event.target === dialog) dialog.close();
            });
            dialog.querySelectorAll('[data-close-dialog]').forEach(function (button) {
                button.addEventListener('click', function () { dialog.close(); });
            });
        }

        if (folderDialog && folderRemove) {
            closeOnBackdrop(folderDialog);
            $$('[data-folder-tags]').forEach(function (button) {
                button.addEventListener('click', function () {
                    var id = button.getAttribute('data-folder-tags');
                    var name = button.getAttribute('data-folder-name') || 'Папка';
                    var title = folderDialog.querySelector('[data-folder-title]');
                    if (title) title.textContent = 'Теги · ' + name;
                    folderDialog.querySelectorAll('[data-folder-id]').forEach(function (field) {
                        field.value = id;
                    });
                    renderTags(folderDialog.querySelector('[data-folder-current]'), tagList(button, 'data-folder-tag-list'), function (tagId) {
                        folderRemove.querySelector('[name="id"]').value = id;
                        folderRemove.querySelector('[name="tag_id"]').value = tagId;
                        folderRemove.submit();
                    });
                    folderDialog.showModal();
                });
            });
        }

        if (fileDialog && fileRemove) {
            closeOnBackdrop(fileDialog);
            $$('[data-file-tags]').forEach(function (button) {
                button.addEventListener('click', function () {
                    var id = button.getAttribute('data-file-tags');
                    var name = button.getAttribute('data-file-name') || 'Файл';
                    var title = fileDialog.querySelector('[data-file-title]');
                    if (title) title.textContent = 'Теги · ' + name;
                    fileDialog.querySelectorAll('[data-file-id]').forEach(function (field) {
                        field.value = id;
                    });
                    renderTags(fileDialog.querySelector('[data-file-current]'), tagList(button, 'data-file-tag-list'), function (tagId) {
                        fileRemove.querySelector('[name="id"]').value = id;
                        fileRemove.querySelector('[name="tag_id"]').value = tagId;
                        fileRemove.submit();
                    });
                    fileDialog.showModal();
                });
            });
        }

        if (editDialog) {
            closeOnBackdrop(editDialog);
            $$('[data-folder-edit]').forEach(function (button) {
                button.addEventListener('click', function () {
                    var id = button.getAttribute('data-folder-edit');
                    var name = button.getAttribute('data-folder-name') || '';
                    editDialog.querySelectorAll('[data-folder-id]').forEach(function (field) {
                        field.value = id;
                    });
                    var input = editDialog.querySelector('[data-folder-name-input]');
                    if (input) {
                        input.value = name;
                        window.setTimeout(function () { input.focus(); input.select(); }, 30);
                    }
                    editDialog.showModal();
                });
            });
        }
    }

    /* ---------- folder delete choice ---------- */

    function initFolderDeleteDialog() {
        var dialog = document.getElementById('gcFolderDeleteDialog');
        if (!dialog) return;
        var idInput = dialog.querySelector('[data-folder-delete-id]');
        var nameNode = dialog.querySelector('[data-folder-delete-name]');

        function closeDialog() {
            if (dialog.open) dialog.close();
        }

        dialog.addEventListener('click', function (event) {
            if (event.target === dialog) closeDialog();
        });
        dialog.querySelectorAll('[data-close-dialog]').forEach(function (button) {
            button.addEventListener('click', closeDialog);
        });
        $$('[data-folder-delete]').forEach(function (button) {
            button.addEventListener('click', function () {
                if (!idInput) return;
                idInput.value = button.getAttribute('data-folder-delete') || '';
                if (nameNode) nameNode.textContent = button.getAttribute('data-folder-delete-name') || 'Папка';
                dialog.showModal();
            });
        });
    }

    /* ---------- persistent upload queue ---------- */

    function initUploadManager() {
        var form = $('[data-upload-form]');
        var widget = $('[data-upload-widget]');
        if (!form || !widget || !window.FormData || !window.XMLHttpRequest) return;

        var input = form.querySelector('input[name="files[]"]');
        var dirInput = form.querySelector('[data-folder-input]');
        var csrfInput = form.querySelector('input[name="csrf"]');
        if (!input) return;

        var toggle = widget.querySelector('[data-upload-toggle]');
        var close = widget.querySelector('[data-upload-close]');
        var badge = widget.querySelector('[data-upload-badge]');
        var titleEl = widget.querySelector('[data-upload-title]');
        var currentEl = widget.querySelector('[data-upload-current]');
        var filePercentEl = widget.querySelector('[data-upload-file-percent]');
        var bar = widget.querySelector('[data-upload-widget-bar]');
        var fill = widget.querySelector('[data-upload-widget-fill]');
        var percentEl = widget.querySelector('[data-upload-widget-percent]');
        var sizeEl = widget.querySelector('[data-upload-widget-size]');
        var noteEl = widget.querySelector('[data-upload-widget-note]');
        var list = widget.querySelector('[data-upload-widget-list]');
        var retry = widget.querySelector('[data-upload-retry]');
        var refresh = widget.querySelector('[data-upload-refresh]');

        var DB_NAME = 'gc_upload_queue';
        var STORE = 'items';
        var CHUNK = 32 * 1024 * 1024;
        var MAX_FILE = 20 * 1024 * 1024 * 1024;
        var queue = [];
        var active = null;
        var processing = false;
        var committing = false;
        var db = null;
        var dbFailed = false;
        var doneTimer = 0;

        function uid() {
            if (window.crypto && window.crypto.randomUUID) return window.crypto.randomUUID().replace(/-/g, '');
            var out = '';
            for (var i = 0; i < 32; i++) out += Math.floor(Math.random() * 16).toString(16);
            return out;
        }

        function csrf() {
            return csrfInput ? csrfInput.value : '';
        }

        function syncCraft(payload) {
            if (!payload || typeof payload !== 'object') return null;
            return payload;
        }

        function parseJson(text) {
            try { return JSON.parse(text); } catch (error) { return null; }
        }

        function blobToBase64(text) {
            var bytes = new TextEncoder().encode(text);
            var binary = '';
            for (var i = 0; i < bytes.length; i++) binary += String.fromCharCode(bytes[i]);
            return btoa(binary);
        }

        function openDb() {
            return new Promise(function (resolve, reject) {
                if (!window.indexedDB) { reject(new Error('IndexedDB недоступен')); return; }
                var request = window.indexedDB.open(DB_NAME, 1);
                request.onupgradeneeded = function () {
                    if (!request.result.objectStoreNames.contains(STORE)) {
                        request.result.createObjectStore(STORE, { keyPath: 'id' });
                    }
                };
                request.onsuccess = function () { resolve(request.result); };
                request.onerror = function () { reject(request.error || new Error('IndexedDB недоступен')); };
            });
        }

        function dbAll() {
            if (!db) return Promise.resolve([]);
            return new Promise(function (resolve) {
                var tx = db.transaction(STORE, 'readonly');
                var request = tx.objectStore(STORE).getAll();
                request.onsuccess = function () { resolve(request.result || []); };
                request.onerror = function () { resolve([]); };
            });
        }

        function dbPut(item) {
            if (!db) return Promise.resolve();
            return new Promise(function (resolve) {
                try {
                    var tx = db.transaction(STORE, 'readwrite');
                    tx.objectStore(STORE).put(item);
                    tx.oncomplete = function () { resolve(); };
                    tx.onerror = function () { resolve(); };
                } catch (error) {
                    resolve();
                }
            });
        }

        function dbDelete(id) {
            if (!db) return Promise.resolve();
            return new Promise(function (resolve) {
                try {
                    var tx = db.transaction(STORE, 'readwrite');
                    tx.objectStore(STORE).delete(id);
                    tx.oncomplete = function () { resolve(); };
                    tx.onerror = function () { resolve(); };
                } catch (error) {
                    resolve();
                }
            });
        }

        function dbEmpty() {
            if (!db) return Promise.resolve();
            return new Promise(function (resolve) {
                try {
                    var tx = db.transaction(STORE, 'readwrite');
                    tx.objectStore(STORE).clear();
                    tx.oncomplete = function () { resolve(); };
                    tx.onerror = function () { resolve(); };
                } catch (error) {
                    resolve();
                }
            });
        }

        function setPanel(open) {
            widget.classList.toggle('is-open', open);
            if (toggle) toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
        }

        function setState(state) {
            widget.classList.remove('is-idle', 'is-error', 'is-done');
            if (state) widget.classList.add('is-' + state);
        }

        function itemsPending() {
            return queue.filter(function (item) {
                return item.state !== 'done' && item.state !== 'staged';
            });
        }

        function totalBytes() {
            return queue.reduce(function (sum, item) { return sum + (item.size || 0); }, 0);
        }

        function uploadedBytes() {
            return queue.reduce(function (sum, item) {
                if (item.state === 'done') return sum + item.size;
                return sum + Math.min(item.offset || 0, item.size || 0);
            }, 0);
        }

        function render() {
            var pending = itemsPending();
            var total = totalBytes();
            var loaded = uploadedBytes();
            var percent = total ? Math.min(100, Math.round(loaded / total * 100)) : 0;

            if (badge) {
                badge.textContent = String(pending.length);
                badge.hidden = pending.length === 0;
            }
            if (!queue.length) {
                widget.hidden = true;
                return;
            }
            widget.hidden = false;

            if (titleEl) {
                titleEl.textContent = pending.length
                    ? 'Осталось ' + pending.length
                    : 'Готово';
            }
            if (currentEl) {
                currentEl.textContent = active
                    ? active.name
                    : (pending.length ? 'Подготовка…' : 'Все файлы загружены');
            }
            if (filePercentEl) {
                var filePercent = active && active.size
                    ? Math.min(100, Math.round((active.offset || 0) / active.size * 100))
                    : (pending.length ? 0 : 100);
                filePercentEl.textContent = filePercent + '%';
            }
            if (fill) fill.style.width = percent + '%';
            if (bar) bar.setAttribute('aria-valuenow', String(percent));
            if (percentEl) percentEl.textContent = percent + '%';
            if (sizeEl) sizeEl.textContent = formatSize(loaded) + ' из ' + formatSize(total);

            if (noteEl) {
                if (widget.classList.contains('is-error')) {
                    noteEl.textContent = 'Загрузка остановилась. Можно повторить с места остановки.';
                } else if (!pending.length) {
                    noteEl.textContent = 'Файлы сохранены в хранилище.';
                } else {
                    noteEl.textContent = 'Можно свернуть: загрузка продолжится в фоне.';
                }
            }

            if (retry) retry.hidden = !widget.classList.contains('is-error');
            if (refresh) refresh.hidden = !widget.classList.contains('is-done');
            if (list) {
                list.innerHTML = '';
                queue.slice(-6).forEach(function (item) {
                    var li = document.createElement('li');
                    if (item.state === 'done' || item.state === 'staged') li.className = 'is-ok';
                    if (item.state === 'error') li.className = 'is-error';
                    var name = document.createElement('span');
                    name.className = 'gc_upload_item_name';
                    name.textContent = item.name;
                    var state = document.createElement('span');
                    state.className = 'gc_upload_item_state';
                    state.textContent = item.state === 'done' || item.state === 'staged'
                        ? 'готово'
                        : (item.state === 'error' ? 'ошибка' : (item.state === 'uploading' ? 'идёт' : 'в очереди'));
                    li.appendChild(name);
                    li.appendChild(state);
                    list.appendChild(li);
                });
            }
        }

        function save(item) {
            if (!item) return Promise.resolve();
            return dbPut(item);
        }

        function failItem(item, message) {
            item.state = 'error';
            item.error = message || 'Не удалось загрузить файл.';
            save(item);
            active = null;
            setState('error');
            render();
            if (noteEl) noteEl.textContent = item.error;
        }

        function getStatus(item) {
            return fetch('upload_chunk.php?id=' + encodeURIComponent(item.id), {
                method: 'GET',
                credentials: 'same-origin',
                headers: { 'X-GC-CSRF': csrf() }
            }).then(function (response) {
                if (response.status === 404) return null;
                return response.json().catch(function () { return null; });
            });
        }

        function sendChunk(item, blob) {
            return new Promise(function (resolve, reject) {
                var xhr = new XMLHttpRequest();
                xhr.open('POST', 'upload_chunk.php?id=' + encodeURIComponent(item.id), true);
                xhr.setRequestHeader('Content-Type', 'application/octet-stream');
                xhr.setRequestHeader('X-GC-CSRF', csrf());
                xhr.setRequestHeader('X-GC-OFFSET', String(item.offset));
                xhr.setRequestHeader('X-GC-TOTAL', String(item.size));
                xhr.setRequestHeader('X-GC-META', blobToBase64(JSON.stringify({
                    name: item.name,
                    path: item.path,
                    size: item.size
                })));

                xhr.upload.addEventListener('progress', function (event) {
                    if (!event.lengthComputable) return;
                    item.loaded = (item.offset || 0) + event.loaded;
                    if (active === item) {
                        if (filePercentEl) {
                            filePercentEl.textContent = Math.min(100, Math.round(item.loaded / item.size * 100)) + '%';
                        }
                        var total = totalBytes();
                        var loaded = uploadedBytes() - (item.offset || 0) + item.loaded;
                        var percent = total ? Math.min(100, Math.round(loaded / total * 100)) : 0;
                        if (fill) fill.style.width = percent + '%';
                        if (bar) bar.setAttribute('aria-valuenow', String(percent));
                        if (percentEl) percentEl.textContent = percent + '%';
                        if (sizeEl) sizeEl.textContent = formatSize(loaded) + ' из ' + formatSize(total);
                    }
                });

                xhr.addEventListener('load', function () {
                    var payload = parseJson(xhr.responseText);
                    if (xhr.status === 409 && payload && typeof payload.offset === 'number') {
                        resolve({ conflict: true, offset: payload.offset });
                        return;
                    }
                    if (xhr.status >= 200 && xhr.status < 300 && payload && payload.ok) {
                        resolve(payload);
                        return;
                    }
                    reject(new Error(payload && payload.error ? payload.error : 'Сервер отклонил часть файла (HTTP ' + xhr.status + ').'));
                });
                xhr.addEventListener('error', function () {
                    reject(new Error('Сеть недоступна. Загрузка продолжится после повтора.'));
                });
                xhr.send(blob);
            });
        }

        function uploadItem(item) {
            item.state = 'uploading';
            item.error = '';
            active = item;
            setState('uploading');
            render();
            return save(item).then(function () {
                return getStatus(item).catch(function () { return null; });
            }).then(function (status) {
                if (status && status.ok && status.done) {
                    item.state = 'staged';
                    item.stagedId = status.staged;
                    item.offset = item.size;
                    return save(item);
                }
                if (status && status.ok && typeof status.offset === 'number') {
                    item.offset = Math.max(item.offset || 0, status.offset);
                }
                return pump(item);
            }).catch(function (error) {
                failItem(item, error && error.message ? error.message : 'Не удалось загрузить файл.');
            });
        }

        function pump(item) {
            if (item.offset >= item.size) {
                return getStatus(item).then(function (status) {
                    if (status && status.ok && status.done) {
                        item.state = 'staged';
                        item.stagedId = status.staged;
                        item.offset = item.size;
                        save(item);
                        return;
                    }
                    throw new Error('Файл передан, но сервер не подтвердил его. Повторите загрузку.');
                });
            }
            var end = Math.min(item.size, item.offset + CHUNK);
            var blob = item.file.slice(item.offset, end);
            return sendChunk(item, blob).then(function (payload) {
                if (payload.conflict) {
                    item.offset = payload.offset;
                    save(item);
                    return pump(item);
                }
                if (payload.done) {
                    item.state = 'staged';
                    item.stagedId = payload.staged;
                    item.offset = item.size;
                    save(item);
                    return;
                }
                item.offset = typeof payload.offset === 'number' ? payload.offset : end;
                item.loaded = item.offset;
                save(item);
                return pump(item);
            });
        }

        function commitQueue() {
            if (committing) return;
            var staged = queue.filter(function (item) { return item.state === 'staged' && item.stagedId; });
            if (!staged.length) return;
            committing = true;
            var data = new FormData(form);
            staged.forEach(function (item) { data.append('staged_upload[]', item.stagedId); });
            data.append('ajax', '1');

            fetch(form.getAttribute('action') || window.location.href, {
                method: 'POST',
                body: data,
                credentials: 'same-origin',
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            }).then(function (response) {
                return response.json().catch(function () { return null; });
            }).then(function (payload) {
                if (!payload || !payload.ok) {
                    throw new Error(payload && payload.errors && payload.errors.length
                        ? payload.errors[0]
                        : 'Не удалось сохранить файлы в хранилище.');
                }
                return Promise.all(queue.map(function (item) { return dbDelete(item.id); })).then(function () {
                    queue = [];
                    setState('done');
                    render();
                    if (payload.redirect) {
                        window.setTimeout(function () { window.location.href = payload.redirect; }, 300);
                    }
                });
            }).catch(function (error) {
                committing = false;
                staged.forEach(function (item) {
                    item.state = 'staged';
                });
                setState('error');
                if (retry) retry.hidden = false;
                render();
                if (noteEl) noteEl.textContent = error.message || 'Файлы переданы, но не сохранены. Повторите.';
            });
        }

        function runQueue() {
            if (processing || committing) return;
            processing = true;
            var next = function () {
                var item = null;
                for (var i = 0; i < queue.length; i++) {
                    if (queue[i].state === 'queued') { item = queue[i]; break; }
                }
                if (!item) {
                    processing = false;
                    active = null;
                    var staged = queue.filter(function (entry) { return entry.state === 'staged'; });
                    if (staged.length && staged.length === queue.length) {
                        commitQueue();
                    } else {
                        render();
                    }
                    return;
                }
                uploadItem(item).then(function () {
                    if (item.state === 'error') {
                        processing = false;
                        return;
                    }
                    next();
                });
            };
            next();
        }

        function enqueue(files) {
            var now = Date.now();
            var added = 0;
            files.forEach(function (entry) {
                var file = entry.file;
                if (!file) return;
                if (file.size > MAX_FILE) {
                    window.alert('Файл «' + file.name + '» больше 20 ГБ.');
                    return;
                }
                queue.push({
                    id: uid(),
                    name: file.name,
                    path: entry.path || file.webkitRelativePath || file.name,
                    size: file.size,
                    offset: 0,
                    loaded: 0,
                    state: 'queued',
                    error: '',
                    stagedId: '',
                    createdAt: now + added,
                    file: file
                });
                added++;
            });
            if (!added) return;
            queue.slice(-added).forEach(function (item) { save(item); });
            widget.hidden = false;
            setState('uploading');
            render();
            runQueue();
        }

        function collectFiles() {
            var entries = [];
            var sources = [
                { input: input, field: 'paths' },
                { input: dirInput, field: 'dir_paths' }
            ];
            sources.forEach(function (source) {
                if (!source.input || !source.input.files) return;
                var files = Array.prototype.slice.call(source.input.files);
                var paths = source.input._gcPaths && source.input._gcPaths.length === files.length
                    ? source.input._gcPaths
                    : files.map(function (file) { return file.webkitRelativePath || file.name; });
                files.forEach(function (file, index) {
                    entries.push({ file: file, path: paths[index] || file.name });
                });
            });
            return entries;
        }

        form.addEventListener('submit', function (event) {
            var entries = collectFiles();
            if (!entries.length) return;
            event.preventDefault();
            if (!csrf()) {
                window.alert('Сессия устарела. Обновите страницу.');
                return;
            }
            input.value = '';
            if (dirInput) dirInput.value = '';
            input._gcPaths = [];
            if (dirInput) dirInput._gcPaths = [];
            enqueue(entries);
        });

        if (toggle) {
            toggle.addEventListener('click', function () {
                setPanel(!widget.classList.contains('is-open'));
            });
        }
        if (close) close.addEventListener('click', function () { setPanel(false); });
        if (retry) {
            retry.addEventListener('click', function () {
                queue.forEach(function (item) {
                    if (item.state === 'error') {
                        item.state = 'queued';
                        item.error = '';
                        save(item);
                    }
                });
                if (noteEl) noteEl.textContent = 'Продолжаем с места остановки…';
                setState('uploading');
                render();
                runQueue();
            });
        }
        if (refresh) {
            refresh.addEventListener('click', function (event) {
                event.preventDefault();
                window.location.reload();
            });
        }

        openDb().then(function (database) {
            db = database;
            return dbAll();
        }).catch(function () {
            dbFailed = true;
            return [];
        }).then(function (items) {
            queue = (items || []).filter(function (item) {
                return item && item.file && item.id;
            }).map(function (item) {
                if (item.state === 'uploading') item.state = 'queued';
                return item;
            });
            if (!queue.length) return;
            widget.hidden = false;
            setState('uploading');
            render();
            runQueue();
        });
    }

    /* ---------- read-only file preview ---------- */

    function initPreviewDialog() {
        var dialog = document.getElementById('gcPreviewDialog');
        if (!dialog) return;
        var body = dialog.querySelector('[data-preview-body]');
        var title = dialog.querySelector('[data-preview-title]');
        var note = dialog.querySelector('[data-preview-note]');
        var download = dialog.querySelector('[data-preview-download]');
        var close = dialog.querySelector('[data-preview-close]');
        var controller = null;

        function cleanup() {
            if (controller) {
                controller.abort();
                controller = null;
            }
            if (body) {
                $$('video', body).forEach(function (video) {
                    video.pause();
                    video.removeAttribute('src');
                    video.load();
                });
                body.innerHTML = '';
            }
        }

        function finish() {
            cleanup();
        }

        function closeDialog() {
            if (dialog.open) dialog.close();
        }

        function showMessage(text) {
            if (!body) return;
            body.innerHTML = '';
            var message = document.createElement('p');
            message.className = 'gc_muted';
            message.textContent = text;
            body.appendChild(message);
        }

        function openPreview(button) {
            var id = button.getAttribute('data-file-preview');
            var kind = button.getAttribute('data-preview-kind') || '';
            var name = button.getAttribute('data-file-name') || 'Файл';
            if (!id || !body) return;

            cleanup();
            if (title) title.textContent = name;
            if (note) note.textContent = '';
            if (download) download.setAttribute('href', button.getAttribute('data-preview-download') || ('download.php?id=' + id));
            var url = 'preview.php?id=' + encodeURIComponent(id);

            if (kind === 'image') {
                var image = document.createElement('img');
                image.className = 'gc_preview_image';
                image.alt = name;
                image.src = url;
                body.appendChild(image);
                if (note) note.textContent = 'Только просмотр';
            } else if (kind === 'video') {
                var video = document.createElement('video');
                video.className = 'gc_preview_video';
                video.controls = true;
                video.playsInline = true;
                video.preload = 'metadata';
                video.src = url;
                body.appendChild(video);
                if (note) note.textContent = 'Только просмотр';
            } else if (kind === 'text') {
                showMessage('Загрузка…');
                controller = new AbortController();
                fetch(url, { credentials: 'same-origin', signal: controller.signal })
                    .then(function (response) {
                        if (!response.ok) throw new Error('Не удалось открыть файл');
                        var truncated = response.headers.get('X-GC-Preview-Truncated') === '1';
                        return response.text().then(function (text) {
                            return { text: text, truncated: truncated };
                        });
                    })
                    .then(function (result) {
                        if (!body) return;
                        body.innerHTML = '';
                        var pre = document.createElement('pre');
                        pre.className = 'gc_preview_text';
                        pre.textContent = result.text;
                        body.appendChild(pre);
                        if (note) note.textContent = result.truncated ? 'Показаны первые 2 МБ' : 'Только просмотр';
                    })
                    .catch(function (error) {
                        if (error && error.name === 'AbortError') return;
                        showMessage('Не удалось открыть файл для просмотра.');
                    });
            } else {
                showMessage('Этот формат нельзя открыть для просмотра.');
            }

            dialog.showModal();
        }

        dialog.addEventListener('click', function (event) {
            if (event.target === dialog) closeDialog();
        });
        dialog.addEventListener('close', finish);
        if (close) close.addEventListener('click', closeDialog);
        $$('[data-file-preview]').forEach(function (button) {
            button.addEventListener('click', function () { openPreview(button); });
        });
    }

    /* ---------- boot ---------- */

    function initTreeScroll() {
        var scroller = document.querySelector('.gc_tree_scroll');
        var nav = document.querySelector('.gc_tree_nav');
        if (!scroller || !nav) return;
        var buttons = nav.querySelectorAll('[data-tree-nav]');

        function sync() {
            var overflow = scroller.scrollWidth > scroller.clientWidth + 1;
            nav.hidden = !overflow;
            if (!overflow) return;
            var max = scroller.scrollWidth - scroller.clientWidth;
            buttons[0].disabled = scroller.scrollLeft <= 1;
            buttons[1].disabled = scroller.scrollLeft >= max - 1;
        }

        buttons.forEach(function (button) {
            button.addEventListener('click', function () {
                var direction = Number(button.getAttribute('data-tree-nav')) || 1;
                scroller.scrollBy({ left: direction * 160, behavior: 'smooth' });
            });
        });
        scroller.addEventListener('scroll', sync, { passive: true });
        scroller.addEventListener('click', function () { setTimeout(sync, 0); });
        window.addEventListener('resize', sync);
        sync();
    }

    function boot() {
        initCurtain();
        initCopy();
        initPasswordToggle();
        initDrops();
        initTree();
        initTreeScroll();
        initFilters();
        initFileSearch();
        initConfirm();
        initCountdowns();
        initDetailsOpeners();
        initViewToggle();
        initFileDrag();
        initTagDialogs();
        initFolderDeleteDialog();
        initPreviewDialog();
        initUploadManager();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }
})();
