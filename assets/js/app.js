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
            var input = drop.querySelector('input[type="file"]');
            var nameEl = drop.querySelector('[data-drop-name]');
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

            function render() {
                var files = input.files;
                var file = files && files[0];
                drop.classList.toggle('has-file', !!file);
                if (file) {
                    if (files.length > 1) {
                        var total = 0;
                        for (var i = 0; i < files.length; i++) total += files[i].size;
                        if (nameEl) nameEl.textContent = files.length + ' ' + plural(files.length, ['файл', 'файла', 'файлов']) + ' · ' + formatSize(total);
                    } else if (nameEl) {
                        nameEl.textContent = file.name + ' · ' + formatSize(file.size);
                    }
                    if (hint) hint.textContent = 'Нажмите, чтобы выбрать другие файлы';
                } else {
                    drop.classList.remove('has-file');
                    if (hint) hint.textContent = 'или нажмите, чтобы выбрать';
                }
            }

            input.addEventListener('change', render);

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
                if (event.dataTransfer && event.dataTransfer.files.length) {
                    input.files = event.dataTransfer.files;
                    render();
                }
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

    /* ---------- multi-file upload with progress ---------- */

    function initUploadProgress() {
        var form = $('[data-upload-form]');
        if (!form || !window.FormData || !window.XMLHttpRequest) return;
        var input = form.querySelector('input[type="file"]');
        if (!input) return;
        var progress = form.querySelector('[data-upload-progress]');
        var bar = form.querySelector('[data-upload-bar]');
        var percent = form.querySelector('[data-upload-percent]');
        var status = form.querySelector('[data-upload-status]');
        var busy = false;

        form.addEventListener('submit', function (event) {
            if (busy) {
                event.preventDefault();
                return;
            }
            if (!input.files || !input.files.length) return;
            event.preventDefault();
            busy = true;

            if (progress) progress.hidden = false;
            if (bar) bar.style.width = '0%';
            if (percent) percent.textContent = '0%';
            if (status) status.textContent = 'Загрузка…';

            var data = new FormData(form);
            data.append('ajax', '1');
            var xhr = new XMLHttpRequest();
            /* form.action is shadowed by the hidden input named "action". */
            xhr.open('POST', form.getAttribute('action') || window.location.href, true);
            xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');

            xhr.upload.addEventListener('progress', function (uploadEvent) {
                if (!uploadEvent.lengthComputable) return;
                var value = Math.round((uploadEvent.loaded / uploadEvent.total) * 100);
                if (bar) bar.style.width = value + '%';
                if (percent) percent.textContent = value + '%';
                if (status) status.textContent = value < 100 ? 'Загрузка…' : 'Обработка на сервере…';
            });

            xhr.addEventListener('load', function () {
                var payload = null;
                try {
                    payload = JSON.parse(xhr.responseText);
                } catch (error) {
                    payload = null;
                }
                if (xhr.status >= 200 && xhr.status < 300 && payload && payload.ok) {
                    if (bar) bar.style.width = '100%';
                    if (percent) percent.textContent = '100%';
                    if (status) status.textContent = payload.message || 'Готово';
                    window.location.href = payload.redirect || window.location.href;
                    return;
                }
                busy = false;
                if (bar) bar.style.width = '0%';
                if (percent) percent.textContent = '0%';
                if (status) {
                    status.textContent = payload && payload.errors && payload.errors.length
                        ? payload.errors[0]
                        : 'Не удалось загрузить файлы.';
                }
            });

            xhr.addEventListener('error', function () {
                busy = false;
                if (bar) bar.style.width = '0%';
                if (percent) percent.textContent = '0%';
                if (status) status.textContent = 'Сеть недоступна, попробуйте ещё раз.';
            });

            xhr.send(data);
        });
    }

    /* ---------- boot ---------- */

    function boot() {
        initCurtain();
        initCopy();
        initPasswordToggle();
        initDrops();
        initTree();
        initFilters();
        initFileSearch();
        initConfirm();
        initCountdowns();
        initDetailsOpeners();
        initViewToggle();
        initFileDrag();
        initTagDialogs();
        initUploadProgress();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }
})();
