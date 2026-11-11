(() => {
    'use strict';

    const dataNode = document.getElementById('gcConvData');
    if (!dataNode) {
        return;
    }

    const cfg = JSON.parse(dataNode.textContent || '{}');
    const drop = document.getElementById('gcConvDrop');
    const input = document.getElementById('gcConvInput');
    const list = document.getElementById('gcConvList');
    const empty = document.getElementById('gcConvEmpty');
    const notice = document.getElementById('gcConvNotice');
    const startButton = document.getElementById('gcConvStart');
    const clearButton = document.getElementById('gcConvClear');
    const historyButton = document.getElementById('gcConvHistoryLoad');
    const historyList = document.getElementById('gcConvHistoryList');

    const rows = new Map();
    let uploading = 0;
    let polling = null;
    let noticeTimer = null;
    let dragDepth = 0;

    const statusLabels = {
        uploaded: 'Готов к конвертации',
        queued: 'В очереди',
        running: 'Конвертируется',
        done: 'Готово',
        failed: 'Ошибка',
    };

    const icons = {
        download: '<svg viewBox="0 0 24 24" width="17" height="17" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M12 4v12"></path><path d="m7 11 5 5 5-5"></path><path d="M4 20h16"></path></svg>',
        view: '<svg viewBox="0 0 24 24" width="17" height="17" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12Z"></path><circle cx="12" cy="12" r="3"></circle></svg>',
        trash: '<svg viewBox="0 0 24 24" width="17" height="17" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M4 7h16"></path><path d="M9 7V5h6v2"></path><path d="M6 7l1 13h10l1-13"></path></svg>',
    };

    function humanSize(bytes) {
        const value = Number(bytes) || 0;
        if (value < 1024) {
            return value + ' Б';
        }
        const units = ['КБ', 'МБ', 'ГБ', 'ТБ'];
        let size = value / 1024;
        let index = 0;
        while (size >= 1024 && index < units.length - 1) {
            size /= 1024;
            index += 1;
        }
        return (size >= 10 ? Math.round(size) : size.toFixed(1)).toString().replace('.', ',') + ' ' + units[index];
    }

    function showNotice(message, kind) {
        window.clearTimeout(noticeTimer);
        notice.textContent = message;
        notice.className = 'gc_conv_notice is-' + (kind || 'error');
        notice.hidden = false;
        if ((kind || 'error') === 'ok') {
            noticeTimer = window.setTimeout(() => {
                notice.hidden = true;
            }, 5000);
        }
    }

    function hideNotice() {
        notice.hidden = true;
    }

    function groupForExt(ext) {
        for (const [key, group] of Object.entries(cfg.catalog || {})) {
            if (group.inputs && Object.prototype.hasOwnProperty.call(group.inputs, ext)) {
                return { key, ...group };
            }
        }
        return null;
    }

    function formatsForExt(ext) {
        const group = groupForExt(ext);
        if (!group) {
            return [];
        }
        return Object.entries(group.outputs || {}).map(([value, spec]) => ({
            value,
            label: spec.label || value.toUpperCase(),
            quality: cfg.qualities && spec.quality ? (cfg.qualities[spec.quality] || []) : [],
        }));
    }

    async function api(action, options, query) {
        let url = cfg.api + '?action=' + encodeURIComponent(action);
        if (query) {
            const params = new URLSearchParams(query);
            if (params.toString() !== '') {
                url += '&' + params.toString();
            }
        }
        const response = await fetch(url, Object.assign({
            credentials: 'same-origin',
            headers: { 'Accept': 'application/json' },
        }, options || {}));
        let payload = null;
        try {
            payload = await response.json();
        } catch (error) {
            throw new Error('Сервер вернул неожиданный ответ (' + response.status + ').');
        }
        if (!response.ok) {
            throw new Error((payload && payload.error) || 'Ошибка ' + response.status);
        }
        return payload;
    }

    function post(action, fields) {
        const body = new URLSearchParams();
        body.set('csrf', cfg.csrf);
        Object.entries(fields || {}).forEach(([key, value]) => body.set(key, value));
        return api(action, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8' },
            body: body.toString(),
        });
    }

    function updateButtons() {
        const active = Array.from(rows.values()).filter((row) => ['queued', 'running'].includes(row.job.status));
        const convertible = Array.from(rows.values()).filter((row) => row.job.status !== 'queued' && row.job.status !== 'running');
        startButton.disabled = uploading > 0 || convertible.length === 0;
        clearButton.disabled = rows.size === 0 || active.length === rows.size;
    }

    function fillQuality(row) {
        const format = row.formats.find((item) => item.value === row.formatEl.value);
        const choices = (format && format.quality) || [];
        row.qualityEl.innerHTML = '';
        choices.forEach((choice) => {
            const option = document.createElement('option');
            option.value = choice.value;
            option.textContent = choice.label;
            row.qualityEl.appendChild(option);
        });
        if (choices.length > 0) {
            const preferred = choices.find((choice) => choice.value === 'medium' || choice.value === 'ebook');
            row.qualityEl.value = preferred ? preferred.value : choices[0].value;
        }
        row.qualityEl.closest('.gc_conv_select').hidden = choices.length === 0;
    }

    function setState(row, text, isSpinning) {
        row.stateEl.innerHTML = '';
        if (isSpinning) {
            const spinner = document.createElement('span');
            spinner.className = 'gc_conv_spin';
            row.stateEl.appendChild(spinner);
        }
        const label = document.createElement('span');
        label.textContent = text;
        row.stateEl.appendChild(label);
    }

    function buildActions(row) {
        const actions = row.actionsEl;
        actions.innerHTML = '';
        const job = row.job;

        if (job.status === 'done') {
            const view = document.createElement('a');
            view.className = 'gc_iconbtn';
            view.href = cfg.api + '?action=view&id=' + encodeURIComponent(job.id);
            view.target = '_blank';
            view.rel = 'noopener';
            view.title = 'Открыть';
            view.setAttribute('aria-label', 'Открыть результат');
            view.innerHTML = icons.view;
            actions.appendChild(view);

            const download = document.createElement('a');
            download.className = 'gc_iconbtn';
            download.href = cfg.api + '?action=download&id=' + encodeURIComponent(job.id);
            download.title = 'Скачать';
            download.setAttribute('aria-label', 'Скачать результат');
            download.innerHTML = icons.download;
            actions.appendChild(download);
        }

        if (job.status !== 'queued' && job.status !== 'running') {
            const remove = document.createElement('button');
            remove.type = 'button';
            remove.className = 'gc_iconbtn gc_iconbtn--danger';
            remove.title = 'Удалить';
            remove.setAttribute('aria-label', 'Удалить задачу');
            remove.innerHTML = icons.trash;
            remove.addEventListener('click', () => removeJob(row));
            actions.appendChild(remove);
        }
    }

    function renderRow(row) {
        const job = row.job;
        row.nameEl.textContent = job.name;
        row.metaEl.textContent = humanSize(job.size) + ' · ' + String(job.ext || '').toUpperCase();

        row.el.classList.toggle('is-done', job.status === 'done');
        row.el.classList.toggle('is-failed', job.status === 'failed');
        row.el.classList.toggle('is-busy', job.status === 'queued' || job.status === 'running');

        if (job.status === 'queued' || job.status === 'running' || job.status === 'failed') {
            row.formatEl.disabled = job.status !== 'failed';
            row.qualityEl.disabled = job.status !== 'failed';
        } else {
            row.formatEl.disabled = false;
            row.qualityEl.disabled = false;
        }

        const spinning = job.status === 'queued' || job.status === 'running';
        setState(row, statusLabels[job.status] || job.status, spinning);
        row.stateEl.className = 'gc_conv_state is-' + job.status;
        row.stateEl.title = job.error || '';
        if (job.status === 'failed' && job.error) {
            row.stateEl.title = job.error;
        }

        buildActions(row);
        updateButtons();
    }

    function createRow(job, formats) {
        const row = {
            job,
            formats: formats && formats.length ? formats : formatsForExt(job.ext),
            el: document.createElement('div'),
        };
        row.el.className = 'gc_conv_row';
        row.el.dataset.id = job.id;

        const file = document.createElement('div');
        file.className = 'gc_conv_file';
        row.nameEl = document.createElement('span');
        row.nameEl.className = 'gc_conv_name';
        row.metaEl = document.createElement('span');
        row.metaEl.className = 'gc_conv_meta';
        file.append(row.nameEl, row.metaEl);

        const formatWrap = document.createElement('label');
        formatWrap.className = 'gc_conv_select';
        const formatLabel = document.createElement('span');
        formatLabel.textContent = 'Формат';
        row.formatEl = document.createElement('select');
        row.formatEl.className = 'gc_input';
        row.formats.forEach((format) => {
            const option = document.createElement('option');
            option.value = format.value;
            option.textContent = format.label;
            row.formatEl.appendChild(option);
        });
        formatWrap.append(formatLabel, row.formatEl);

        const qualityWrap = document.createElement('label');
        qualityWrap.className = 'gc_conv_select';
        const qualityLabel = document.createElement('span');
        qualityLabel.textContent = 'Качество';
        row.qualityEl = document.createElement('select');
        row.qualityEl.className = 'gc_input';
        qualityWrap.append(qualityLabel, row.qualityEl);

        row.stateEl = document.createElement('div');
        row.stateEl.className = 'gc_conv_state';

        row.actionsEl = document.createElement('div');
        row.actionsEl.className = 'gc_conv_actions_cell';

        row.el.append(file, formatWrap, qualityWrap, row.stateEl, row.actionsEl);

        const group = groupForExt(job.ext);
        const preferred = group && group.default ? group.default : (row.formats[0] && row.formats[0].value);
        if (preferred && row.formats.some((format) => format.value === preferred)) {
            row.formatEl.value = preferred;
        }
        fillQuality(row);
        row.formatEl.addEventListener('change', () => fillQuality(row));

        list.appendChild(row.el);
        rows.set(job.id, row);
        empty.hidden = true;
        renderRow(row);

        return row;
    }

    function addUploadPlaceholder(file) {
        const el = document.createElement('div');
        el.className = 'gc_conv_row is-busy is-uploading';
        el.innerHTML = '<div class="gc_conv_file"><span class="gc_conv_name"></span><span class="gc_conv_meta"></span></div>'
            + '<div class="gc_conv_state"><span class="gc_conv_spin"></span><span>Загрузка</span></div>';
        el.querySelector('.gc_conv_name').textContent = file.name;
        el.querySelector('.gc_conv_meta').textContent = humanSize(file.size) + ' · загрузка';
        list.appendChild(el);
        empty.hidden = true;
        return el;
    }

    async function uploadFile(file) {
        const ext = String(file.name.split('.').pop() || '').toLowerCase();
        if (!groupForExt(ext)) {
            showNotice('Формат «' + (ext ? '.' + ext : 'без расширения') + '» не поддерживается.');
            return;
        }
        if (file.size > cfg.max) {
            showNotice('Файл «' + file.name + '» больше ' + humanSize(cfg.max) + '.');
            return;
        }

        const placeholder = addUploadPlaceholder(file);
        uploading += 1;
        updateButtons();

        try {
            const body = new FormData();
            body.append('csrf', cfg.csrf);
            body.append('file', file);
            const payload = await api('upload', { method: 'POST', body });
            placeholder.remove();
            createRow(payload.job, payload.formats);
            hideNotice();
        } catch (error) {
            placeholder.remove();
            showNotice(error.message || 'Не удалось загрузить файл.');
        } finally {
            uploading -= 1;
            updateButtons();
            updateEmpty();
        }
    }

    async function addFiles(files) {
        for (const file of Array.from(files)) {
            await uploadFile(file);
        }
    }

    function updateEmpty() {
        empty.hidden = rows.size > 0 || uploading > 0 || list.children.length > 0;
    }

    async function convertRow(row) {
        const format = row.formatEl.value;
        const quality = row.qualityEl.value || '';
        row.job = Object.assign({}, row.job, { status: 'queued', format, quality });
        renderRow(row);
        try {
            const payload = await post('convert', { id: row.job.id, format, quality });
            row.job = payload.job;
            renderRow(row);
            ensurePolling();
        } catch (error) {
            row.job = Object.assign({}, row.job, { status: 'failed', error: error.message });
            renderRow(row);
            showNotice(error.message || 'Не удалось запустить конвертацию.');
        }
    }

    async function convertAll() {
        const targets = Array.from(rows.values()).filter((row) => row.job.status !== 'queued' && row.job.status !== 'running');
        if (targets.length === 0) {
            return;
        }
        startButton.disabled = true;
        startButton.textContent = 'Запуск…';
        for (const row of targets) {
            await convertRow(row);
        }
        startButton.textContent = 'Конвертировать';
        updateButtons();
    }

    function ensurePolling() {
        if (polling !== null) {
            return;
        }
        polling = window.setInterval(tick, 1200);
        tick();
    }

    async function tick() {
        const active = Array.from(rows.values()).filter((row) => ['queued', 'running'].includes(row.job.status));
        if (active.length === 0) {
            window.clearInterval(polling);
            polling = null;
            updateButtons();
            return;
        }

        await Promise.all(active.map(async (row) => {
            try {
                const payload = await api('status', null, { id: row.job.id });
                row.job = payload.job;
                renderRow(row);
            } catch (error) {
                row.job = Object.assign({}, row.job, { status: 'failed', error: error.message });
                renderRow(row);
            }
        }));

        if (!Array.from(rows.values()).some((row) => ['queued', 'running'].includes(row.job.status))) {
            window.clearInterval(polling);
            polling = null;
        }
        updateButtons();
    }

    async function removeJob(row) {
        if (['queued', 'running'].includes(row.job.status)) {
            return;
        }
        if (!window.confirm('Удалить задачу «' + row.job.name + '»?')) {
            return;
        }
        try {
            await post('delete', { id: row.job.id });
            row.el.remove();
            rows.delete(row.job.id);
            updateEmpty();
            updateButtons();
        } catch (error) {
            showNotice(error.message || 'Не удалось удалить задачу.');
        }
    }

    function clearList() {
        rows.forEach((row) => {
            if (!['queued', 'running'].includes(row.job.status)) {
                row.el.remove();
                rows.delete(row.job.id);
            }
        });
        updateEmpty();
        updateButtons();
    }

    async function loadHistory() {
        historyButton.disabled = true;
        historyButton.textContent = 'Загрузка…';
        try {
            const payload = await api('history');
            historyList.innerHTML = '';
            if (!payload.jobs || payload.jobs.length === 0) {
                const none = document.createElement('p');
                none.className = 'gc_hint';
                none.textContent = 'Задач пока нет.';
                historyList.appendChild(none);
            } else {
                payload.jobs.forEach((job) => {
                    const el = document.createElement('div');
                    el.className = 'gc_conv_hist_row' + (job.status === 'failed' ? ' is-failed' : '');
                    const name = document.createElement('span');
                    name.className = 'gc_conv_name';
                    name.textContent = job.output_name || job.name;
                    const meta = document.createElement('span');
                    meta.className = 'gc_conv_meta';
                    meta.textContent = (job.status === 'done' ? humanSize(job.output_size) : statusLabels[job.status] || job.status)
                        + ' · ' + String(job.ext || '').toUpperCase();
                    const date = document.createElement('span');
                    date.className = 'gc_conv_meta';
                    date.textContent = new Date((job.finished || job.created) * 1000).toLocaleString('ru-RU');
                    const action = document.createElement('span');
                    if (job.status === 'done') {
                        const download = document.createElement('a');
                        download.className = 'gc_btn gc_btn--ghost gc_btn--sm';
                        download.href = cfg.api + '?action=download&id=' + encodeURIComponent(job.id);
                        download.textContent = 'Скачать';
                        action.appendChild(download);
                    }
                    el.append(name, meta, date, action);
                    historyList.appendChild(el);
                });
            }
            historyButton.textContent = 'Обновить';
        } catch (error) {
            showNotice(error.message || 'Не удалось загрузить историю.');
            historyButton.textContent = 'Повторить';
        } finally {
            historyButton.disabled = false;
        }
    }

    drop.addEventListener('click', (event) => {
        if (event.target === input) {
            return;
        }
        input.click();
    });

    drop.addEventListener('keydown', (event) => {
        if (event.key === 'Enter' || event.key === ' ') {
            event.preventDefault();
            input.click();
        }
    });

    input.addEventListener('change', () => {
        if (input.files && input.files.length) {
            addFiles(input.files);
        }
        input.value = '';
    });

    ['dragenter', 'dragover'].forEach((name) => {
        drop.addEventListener(name, (event) => {
            event.preventDefault();
            if (name === 'dragenter') {
                dragDepth += 1;
            }
            drop.classList.add('is-over');
        });
    });

    drop.addEventListener('dragleave', (event) => {
        event.preventDefault();
        dragDepth = Math.max(0, dragDepth - 1);
        if (dragDepth === 0) {
            drop.classList.remove('is-over');
        }
    });

    drop.addEventListener('drop', (event) => {
        event.preventDefault();
        dragDepth = 0;
        drop.classList.remove('is-over');
        if (event.dataTransfer && event.dataTransfer.files.length) {
            addFiles(event.dataTransfer.files);
        }
    });

    startButton.addEventListener('click', convertAll);
    clearButton.addEventListener('click', clearList);
    if (historyButton) {
        historyButton.addEventListener('click', loadHistory);
    }

    updateButtons();
    updateEmpty();
})();
