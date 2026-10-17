<script>
(function () {
    var button = document.getElementById('ifoundAnalyzeSelected');
    var status = document.getElementById('ifoundAnalysisStatus');
    if (!button || !status) return;
    if (window.greyhoundIFoundAnalysisCleanup) window.greyhoundIFoundAnalysisCleanup();
    var timer, current, ids = [], stopped = false, started = 0;
    function cleanup() { stopped = true; clearTimeout(timer); if (current) current.abort(); }
    window.greyhoundIFoundAnalysisCleanup = cleanup;
    function release() { delete button.dataset.busy; button.disabled = !document.querySelector('[data-ifound-select]:checked'); }
    function refreshLink() {
        var link = document.createElement('a'); link.href = window.location.href; link.className = 'ms-2'; link.textContent = 'Refresh results'; status.appendChild(link);
    }
    async function request(url, options) {
        current = new AbortController(); var controller = current;
        var timeout = setTimeout(function () { controller.abort(); }, 15000);
        try {
            var response = await fetch(url, Object.assign({credentials:'same-origin',cache:'no-store',headers:{Accept:'application/json','X-Requested-With':'XMLHttpRequest'},signal:controller.signal},options));
            if (!response.ok || response.redirected) throw new Error('Could not complete the request. Refresh and try again.');
            return await response.json();
        } finally { clearTimeout(timeout); }
    }
    async function poll() {
        if (stopped || !button.isConnected) return;
        var url = new URL(<?= json_encode(site_url('workspace/ifound/analysis-status')) ?>, window.location.href);
        ids.forEach(function (id) { url.searchParams.append('file_ids[]', id); });
        try {
            var data = await request(url.toString());
            if (stopped || !button.isConnected) return;
            var complete = data.results.filter(function (row) { return row.status === 'complete'; }).length;
            var failed = data.results.filter(function (row) { return row.status === 'failed' || row.status === 'unavailable'; }).length;
            status.textContent = complete + ' of ' + ids.length + ' completed.' + (failed ? ' ' + failed + ' failed or unavailable. Check the AI service and server connection, then select them again to retry.' : '');
            if (complete + failed === ids.length) { release(); refreshLink(); return; }
            status.textContent += ' Waiting for background analysis; the worker checks every minute.';
            if (Date.now() - started > 600000) { status.textContent += ' Processing is taking longer than expected. You may refresh to check results.'; release(); refreshLink(); return; }
            timer = setTimeout(poll, 5000);
        } catch (error) {
            if (!stopped && button.isConnected) { status.textContent = 'Could not check progress. Queued analysis can continue in the background.'; release(); refreshLink(); }
        }
    }
    button.addEventListener('click', async function () {
        ids = Array.from(document.querySelectorAll('[data-ifound-item]')).filter(function (row) { return row.querySelector('[data-ifound-select]').checked; }).map(function (row) { return row.dataset.fileId; });
        if (!ids.length || button.dataset.busy) return;
        button.dataset.busy = '1'; button.disabled = true; clearTimeout(timer); status.textContent = 'Queueing selected images…';
        try {
            var token = await request(<?= json_encode(site_url('workspace/ifound/scan/token')) ?>);
            if (stopped || !button.isConnected) return;
            if (!token.csrfToken) throw new Error('Security token unavailable.');
            var body = new FormData(); body.append(token.csrfTokenName || <?= json_encode(csrf_token()) ?>, token.csrfToken);
            ids.forEach(function (id) { body.append('file_ids[]', id); });
            var data = await request(<?= json_encode(site_url('workspace/ifound/analyze-selected')) ?>, {method:'POST',body:body});
            if (stopped || !button.isConnected) return;
            ids = data.queued || [];
            status.textContent = ids.length + ' images queued. ' + data.skipped + ' skipped (already analyzed/reviewed, unavailable, or not images).' + (data.unavailable ? ' ' + data.unavailable + ' images are not linked to the server analysis queue.' : '');
            if (!ids.length) { release(); return; }
            status.textContent += ' The worker checks every minute. You can leave this page while it runs.';
            started = Date.now(); timer = setTimeout(poll, 5000);
        } catch (error) { if (!stopped && button.isConnected) { status.textContent = error.message; release(); } }
    });
})();
</script>
