(function () {
    const form = document.getElementById('passportScanForm');
    if (!form) return;
    const get = name => document.getElementById('passportScan' + name);
    // The active scan and its panel survive in-app page replacement.
    if (!window.GreyhoundPassportScan) {
        window.GreyhoundPassportScan = {
            busy: false, panel: null,
            beforeReplace(body) {
                if (!this.panel) return;
                const incoming = body.querySelector('#passportScanProgress');
                if (incoming) incoming.remove();
                body.appendChild(this.panel);
            }
        };
    }
    const session = window.GreyhoundPassportScan;
    get('Button').disabled = session.busy;
    get('Collapse').onclick = () => {
        const collapsed = get('Progress').classList.toggle('is-collapsed');
        get('Collapse').setAttribute('aria-expanded', String(!collapsed));
        get('Collapse').setAttribute('aria-label', collapsed ? 'Expand metadata progress' : 'Minimize metadata progress');
    };
    get('Dismiss').onclick = () => { get('Progress').classList.add('d-none'); };

    async function request(url, options = {}) {
        const controller = new AbortController();
        const timer = setTimeout(() => controller.abort(), 120000);
        try {
            const response = await fetch(url, {...options, credentials:'same-origin', cache:'no-store', signal:controller.signal, headers:{Accept:'application/json','X-Requested-With':'XMLHttpRequest'}});
            let data;
            try { data = await response.json(); } catch (_) { throw new Error('The server returned an unreadable response.'); }
            if (response.redirected || !response.ok || !data.ok) {
                const error = new Error(data.message || 'Metadata scan could not continue.');
                error.status = response.status;
                throw error;
            }
            return data;
        } finally { clearTimeout(timer); }
    }
    form.addEventListener('submit', async event => {
        event.preventDefault();
        const picker = get('Picker');
        if (session.busy || !picker.scanSelection || !picker.scanSelection.length) return;
        const files = picker.scanSelection.slice();
        session.busy = true;
        session.panel = get('Progress');
        const panel = session.panel, label = get('Label'), bar = get('Bar'), list = get('FileList'), summary = get('Summary'), dismiss = get('Dismiss'), results = get('Results');
        get('Button').disabled = true;
        bootstrap.Modal.getInstance(picker).hide();
        panel.classList.remove('d-none', 'is-collapsed');
        get('Collapse').setAttribute('aria-expanded', 'true');
        dismiss.hidden = true; results.classList.add('d-none');
        bar.style.width = '0%'; bar.setAttribute('aria-valuenow', '0'); bar.classList.remove('bg-danger');
        list.replaceChildren();
        summary.textContent = 'Reading each selected image’s metadata. Keep this tab open while scanning.';
        const rows = files.map(file => {
            const row = document.createElement('div'), name = document.createElement('span'), status = document.createElement('span');
            name.textContent = file.name; status.textContent = 'Waiting'; row.append(name, status); list.appendChild(row);
            return status;
        });
        let completed = 0, skipped = 0, failed = 0, processed = 0;
        try {
            for (let index = 0; index < files.length; index++) {
                label.textContent = 'Scanning ' + (index + 1) + ' of ' + files.length;
                rows[index].textContent = 'Reading metadata…';
                try {
                    // A fresh token also handles other forms used while this scan runs.
                    const token = await request(form.dataset.tokenUrl);
                    const body = new FormData(); body.append(token.csrfTokenName, token.csrfToken); body.append('file_path', files[index].path);
                    const data = await request(form.action, {method:'POST', body});
                    if (data.status === 'skipped') { skipped++; rows[index].textContent = 'Already exists'; }
                    else { completed++; rows[index].textContent = 'Complete'; }
                } catch (error) {
                    failed++; rows[index].textContent = 'Failed: ' + (error.name === 'AbortError' ? 'Request timed out; check passports before retrying.' : error.message);
                    summary.textContent = files[index].name + ': ' + (error.name === 'AbortError' ? 'Request timed out. Check passports before retrying.' : error.message);
                    if (error.status === 401 || error.status === 403) {
                        rows.slice(index + 1).forEach(row => { row.textContent = 'Not scanned'; });
                        throw error;
                    }
                }
                processed++;
                const percent = Math.round(processed / files.length * 100);
                bar.style.width = percent + '%'; bar.setAttribute('aria-valuenow', String(percent));
            }
            label.textContent = failed ? 'Metadata scan finished with errors.' : 'Metadata scan complete.';
            summary.textContent = completed + ' passports generated · ' + skipped + ' already exist · ' + failed + ' failed.' + (failed ? ' Select the failed images again to retry.' : '');
        } catch (error) {
            label.textContent = 'Metadata scan stopped.'; summary.textContent = error.message;
        } finally {
            session.busy = false; dismiss.hidden = false; results.classList.remove('d-none');
            bar.classList.toggle('bg-danger', failed > 0);
            if (get('Button')) get('Button').disabled = false;
        }
        // Refresh only the passport page, without closing a detail view or new selection.
        if (completed && document.getElementById('passportScanForm') && !document.querySelector('.modal.show') && window.GreyhoundAjax) {
            window.GreyhoundAjax.refresh({ifIdle:true, preserveScroll:true}).catch(() => {});
        }
    });
})();
