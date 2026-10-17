<script>
(function () {
    const button = document.getElementById('ifoundVisualAnalyze');
    const status = document.getElementById('ifoundVisualStatus');
    if (!button || !status) return;
    if (window.greyhoundVisualBusy) { button.disabled = true; status.textContent = 'An image scan is already running. Refresh after it finishes.'; return; }
    button.addEventListener('click', async function () {
        const rows = Array.from(document.querySelectorAll('[data-ifound-item]')).filter(row => row.querySelector('[data-ifound-select]:checked'));
        if (!rows.length || window.greyhoundVisualBusy) return;
        window.greyhoundVisualBusy = true; button.dataset.busy = '1'; button.disabled = true;
        let completed = 0, partial = 0, failed = 0;
        const errors = [];
        async function request(url, options = {}) {
            const controller = new AbortController();
            const timer = setTimeout(() => controller.abort(), 190000);
            try {
                const response = await fetch(url, {...options, credentials: 'same-origin', cache: 'no-store', signal: controller.signal,
                    headers: {'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest'}});
                if (response.redirected) throw new Error('Your session expired. Sign in and retry.');
                const data = await response.json().catch(() => null);
                if (!response.ok) throw new Error(data?.message || 'The server could not finish the scan. Please retry.');
                if (!data) throw new Error('The server returned an invalid response. Please retry.');
                return data;
            } finally { clearTimeout(timer); }
        }
        try {
            for (const row of rows) {
                if (!button.isConnected) break;
                status.textContent = `Scanning ${completed + failed + 1} of ${rows.length}: ${row.dataset.fileName}. First model startup can take a minute.`;
                try {
                    const token = await request(<?= json_encode(site_url('workspace/ifound/scan/token')) ?>);
                    const form = new FormData();
                    if (!token.csrfToken) throw new Error('Security token unavailable');
                    form.append(token.csrfTokenName || <?= json_encode(csrf_token()) ?>, token.csrfToken);
                    form.append('file_id', row.dataset.fileId);
                    const data = await request(<?= json_encode(site_url('workspace/ifound/visual-analysis')) ?>, {method: 'POST', body: form});
                    completed++;
                    if (data.result.objects.status === 'unavailable' || data.result.expressions.status === 'unavailable' || data.result.duplicates.status === 'partial' || data.result.catalog_sync === 'pending') partial++;
                } catch (error) { failed++; errors.push(row.dataset.fileName + ': ' + (error.name === 'AbortError' ? 'Scan timed out. Wait before retrying.' : error.message)); }
            }
            status.textContent = `${completed} images scanned${partial ? ` (${partial} with incomplete analysis or duplicate indexing)` : ''}; ${failed} failed. Refresh to see detections, duplicate candidates, and tags. You can select images again to retry.`;
            if (errors.length) status.textContent += ' ' + errors.join(' ');
            const link = document.createElement('a'); link.href = window.location.href; link.textContent = 'Refresh results'; link.className = 'ms-2'; status.appendChild(link);
        } finally {
            window.greyhoundVisualBusy = false; delete button.dataset.busy;
            button.disabled = !document.querySelector('[data-ifound-select]:checked');
        }
    });
})();
</script>
