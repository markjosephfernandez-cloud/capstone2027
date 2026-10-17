<div class="p-3 border-top" id="adminServerHealth" data-url="<?= esc(site_url('admin/control-center/server-health')) ?>">
    <h3 class="section-title mb-2"><i class="bi bi-hdd-network me-1" aria-hidden="true"></i>Server storage and catalog</h3>
    <div class="d-flex flex-wrap gap-2 mb-2">
        <span class="badge text-bg-secondary" data-health-connection>Checking connection…</span>
        <span class="badge text-bg-secondary" data-health-access>Access: Unverified</span>
    </div>
    <p class="small text-muted" data-health-help role="status">Checking the server share.</p>
    <dl class="small mb-2">
        <dt>Catalog</dt><dd data-health-catalog>Checking…</dd>
        <dt>Last completed scan (PHT)</dt><dd data-health-completed>Checking…</dd>
        <dt>Last saved progress (PHT)</dt><dd data-health-updated>Checking…</dd>
    </dl>
    <p class="small text-danger" data-health-error role="alert" hidden></p>
    <p class="small text-muted">Library results update automatically while indexing runs in the background.</p>
    <div class="d-flex flex-wrap gap-2">
        <button class="btn btn-sm btn-outline-secondary" type="button" data-health-refresh>Refresh connection</button>
        <button class="btn btn-sm btn-outline-primary" type="button" data-health-refresh>Refresh status</button>
    </div>
    <p class="small text-muted mt-2 mb-0" data-health-live role="status" aria-live="polite"></p>
</div>
<script>
document.addEventListener('DOMContentLoaded', function () {
    if (window.greyhoundAdminHealthCleanup) window.greyhoundAdminHealthCleanup();
    var panel = document.getElementById('adminServerHealth');
    if (!panel) return;
    var controller, timer, disposed = false;
    var buttons = panel.querySelectorAll('[data-health-refresh]');
    function field(name) { return panel.querySelector('[data-health-' + name + ']'); }
    function cleanup() {
        disposed = true; window.clearTimeout(timer);
        if (controller) controller.abort();
        window.removeEventListener('pagehide', cleanup);
    }
    async function refresh() {
        if (disposed || controller) return;
        if (!panel.isConnected) { cleanup(); return; }
        window.clearTimeout(timer);
        controller = new AbortController();
        var current = controller;
        var timeout = window.setTimeout(function () { current.abort(); }, 10000);
        buttons.forEach(function (button) { button.disabled = true; });
        field('live').textContent = 'Checking system health…';
        try {
            var response = await fetch(panel.dataset.url, { credentials: 'same-origin', cache: 'no-store', signal: current.signal, headers: { Accept: 'application/json' } });
            if (!response.ok || response.redirected) throw new Error('Health unavailable');
            var data = await response.json();
            if (typeof data.connected !== 'boolean' || !data.catalog) throw new Error('Health unavailable');
            if (disposed || !panel.isConnected) return;
            field('connection').textContent = data.connected ? 'Connected' : 'Disconnected';
            field('connection').className = 'badge text-bg-' + (data.connected ? 'success' : 'danger');
            field('access').textContent = 'Access: ' + (!data.connected ? 'Unavailable' : data.writable ? 'Read & write' : 'Read only');
            field('access').className = 'badge text-bg-' + (data.connected && data.writable ? 'success' : 'secondary');
            field('help').textContent = data.connected ? 'Files are read from and saved to the server share when write access is available.' : (data.connectionError || 'Connect the server share, then refresh.');
            field('catalog').textContent = data.catalog.status;
            field('completed').textContent = data.catalog.completed_display;
            field('updated').textContent = data.catalog.updated_display;
            field('error').textContent = data.catalog.last_error || '';
            field('error').hidden = !data.catalog.last_error;
            field('live').textContent = 'Status updated. Checks automatically every 30 seconds.';
        } catch (error) {
            if (!disposed) field('live').textContent = 'Could not refresh system health. Displayed values may be outdated. Try again.';
        } finally {
            window.clearTimeout(timeout); controller = null;
            if (!disposed && panel.isConnected) {
                buttons.forEach(function (button) { button.disabled = false; });
                timer = window.setTimeout(refresh, 30000);
            }
        }
    }
    buttons.forEach(function (button) { button.addEventListener('click', refresh); });
    window.addEventListener('pagehide', cleanup);
    window.greyhoundAdminHealthCleanup = cleanup;
    refresh();
});
</script>
