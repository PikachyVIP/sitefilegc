(function () {
    'use strict';

    function $(selector, root) {
        return (root || document).querySelector(selector);
    }

    function $$(selector, root) {
        return Array.prototype.slice.call((root || document).querySelectorAll(selector));
    }

    function formatTime(seconds) {
        if (!isFinite(seconds) || seconds < 0) return '0:00';
        var total = Math.floor(seconds);
        var hours = Math.floor(total / 3600);
        var minutes = Math.floor((total % 3600) / 60);
        var secs = total % 60;
        if (hours > 0) {
            return hours + ':' + String(minutes).padStart(2, '0') + ':' + String(secs).padStart(2, '0');
        }
        return minutes + ':' + String(secs).padStart(2, '0');
    }

    /* ---------- library picker ---------- */

    function initPicker() {
        var form = $('[data-hosting-form]');
        if (!form) return;

        var source = $('[data-hosting-source]', form);
        var action = $('[data-hosting-action]', form);
        var fileId = $('[data-hosting-file-id]', form);
        var fileInput = $('[data-hosting-file]', form);
        var drop = $('[data-hosting-drop]', form);
        var fileTitle = $('[data-hosting-file-title]', form);
        var selected = $('[data-hosting-selected]', form);
        var selectedName = $('[data-hosting-selected-name]', form);
        var dialog = $('#gcLibraryDialog');
        var search = $('[data-library-search]', dialog || document);
        var defaultTitle = fileTitle ? fileTitle.textContent : '';

        function useLibrary(id, name) {
            if (source) source.value = 'library';
            if (action) action.value = 'library';
            if (fileId) fileId.value = id;
            if (fileInput) fileInput.value = '';
            if (drop) drop.classList.remove('has-file');
            if (fileTitle) fileTitle.textContent = 'Файл выбран из хранилища';
            if (selected) selected.hidden = false;
            if (selectedName) selectedName.textContent = name;
        }

        function useUpload(file) {
            if (source) source.value = 'upload';
            if (action) action.value = 'upload';
            if (fileId) fileId.value = '';
            if (selected) selected.hidden = true;
            if (fileTitle) fileTitle.textContent = file ? file.name : defaultTitle;
            if (drop) drop.classList.toggle('has-file', !!file);
        }

        function openLibrary() {
            if (!dialog || typeof dialog.showModal !== 'function') return;
            dialog.showModal();
            if (search) {
                search.value = '';
                $$('[data-pick-library]', dialog).forEach(function (item) { item.hidden = false; });
                setTimeout(function () { search.focus(); }, 30);
            }
        }

        function closeLibrary() {
            if (dialog && dialog.open) dialog.close();
        }

        $$('[data-open-library]').forEach(function (button) {
            button.addEventListener('click', openLibrary);
        });
        $$('[data-close-library]').forEach(function (button) {
            button.addEventListener('click', closeLibrary);
        });
        if (dialog) {
            dialog.addEventListener('click', function (event) {
                if (event.target === dialog) closeLibrary();
            });
        }
        $$('[data-pick-library]').forEach(function (item) {
            item.addEventListener('click', function () {
                useLibrary(item.getAttribute('data-id') || '', item.getAttribute('data-name') || '');
                closeLibrary();
            });
        });

        var clearButton = $('[data-clear-library]', form);
        if (clearButton) {
            clearButton.addEventListener('click', function () {
                if (fileInput) fileInput.value = '';
                useUpload(null);
            });
        }

        if (fileInput) {
            fileInput.addEventListener('change', function () {
                useUpload(fileInput.files && fileInput.files[0] ? fileInput.files[0] : null);
            });
        }

        if (drop) {
            ['dragenter', 'dragover'].forEach(function (name) {
                drop.addEventListener(name, function (event) {
                    event.preventDefault();
                    drop.classList.add('is-drag');
                });
            });
            ['dragleave', 'drop'].forEach(function (name) {
                drop.addEventListener(name, function (event) {
                    event.preventDefault();
                    drop.classList.remove('is-drag');
                });
            });
            drop.addEventListener('drop', function (event) {
                if (!fileInput || !event.dataTransfer || !event.dataTransfer.files.length) return;
                var transfer = new DataTransfer();
                transfer.items.add(event.dataTransfer.files[0]);
                fileInput.files = transfer.files;
                fileInput.dispatchEvent(new Event('change', { bubbles: true }));
            });
        }

        if (search) {
            search.addEventListener('input', function () {
                var query = search.value.trim().toLowerCase();
                $$('[data-pick-library]', dialog).forEach(function (item) {
                    item.hidden = query !== '' && (item.textContent || '').toLowerCase().indexOf(query) === -1;
                });
            });
        }

        form.addEventListener('submit', function (event) {
            var library = source && source.value === 'library';
            if (library && (!fileId || !fileId.value)) {
                event.preventDefault();
                return;
            }
            if (!library && fileInput && (!fileInput.files || !fileInput.files.length)) {
                event.preventDefault();
                if (fileTitle) fileTitle.textContent = 'Выберите файл или возьмите его из хранилища';
                return;
            }
            var submit = $('.gc_hosting_submit', form);
            if (submit) {
                submit.disabled = true;
                submit.textContent = 'Публикую…';
            }
        });
    }

    /* ---------- custom video player ---------- */

    function initPlayer(root) {
        var video = $('[data-gc-video]', root);
        if (!video) return;

        var big = $('.gc_player_big', root);
        var toggles = $$('[data-gc-toggle]', root);
        var seek = $('[data-gc-seek]', root);
        var current = $('[data-gc-current]', root);
        var duration = $('[data-gc-duration]', root);
        var mute = $('[data-gc-mute]', root);
        var full = $('[data-gc-fullscreen]', root);
        var playIcon = $('[data-gc-play-icon]', root);
        var seeking = false;

        function isPlaying() {
            return !video.paused && !video.ended;
        }

        function paint() {
            var playing = isPlaying();
            root.classList.toggle('is-playing', playing);
            if (big) big.setAttribute('aria-label', playing ? 'Пауза' : 'Воспроизвести');
            if (playIcon) playIcon.classList.toggle('is-pause', playing);
        }

        function toggle() {
            if (video.paused || video.ended) {
                var promise = video.play();
                if (promise && promise.catch) promise.catch(function () {});
            } else {
                video.pause();
            }
        }

        toggles.forEach(function (button) {
            button.addEventListener('click', function (event) {
                event.preventDefault();
                event.stopPropagation();
                toggle();
            });
        });
        if (big) {
            big.addEventListener('click', function (event) {
                event.preventDefault();
                event.stopPropagation();
                toggle();
            });
        }
        video.addEventListener('click', toggle);
        video.addEventListener('play', paint);
        video.addEventListener('pause', paint);
        video.addEventListener('ended', paint);

        function readDuration() {
            if (duration) duration.textContent = formatTime(video.duration);
        }
        video.addEventListener('loadedmetadata', readDuration);
        if (video.readyState >= 1) readDuration();

        video.addEventListener('timeupdate', function () {
            if (current) current.textContent = formatTime(video.currentTime);
            if (!seeking && seek && video.duration > 0) {
                seek.value = String(Math.round((video.currentTime / video.duration) * 1000));
            }
        });
        if (seek) {
            seek.addEventListener('input', function () {
                seeking = true;
                if (video.duration > 0) {
                    video.currentTime = (Number(seek.value) / 1000) * video.duration;
                }
            });
            seek.addEventListener('change', function () {
                seeking = false;
            });
        }

        if (mute) {
            mute.addEventListener('click', function () {
                video.muted = !video.muted;
                mute.classList.toggle('is-muted', video.muted);
                mute.setAttribute('aria-label', video.muted ? 'Включить звук' : 'Без звука');
            });
        }

        function enterFullscreen() {
            var target = root;
            if (target.requestFullscreen) {
                target.requestFullscreen().catch(function () {});
            } else if (video.webkitEnterFullscreen) {
                video.webkitEnterFullscreen();
            }
        }
        if (full) {
            full.addEventListener('click', enterFullscreen);
        }
        video.addEventListener('dblclick', enterFullscreen);
    }

    function init() {
        initPicker();
        $$('[data-gc-player]').forEach(initPlayer);
        $$('[data-select-on-focus]').forEach(function (input) {
            input.addEventListener('focus', function () { input.select(); });
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
