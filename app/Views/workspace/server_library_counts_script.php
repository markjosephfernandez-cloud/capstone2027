<script>
document.addEventListener('DOMContentLoaded', function () {
    var root = document.getElementById('main');
    // Global results can contain hundreds of unrelated folders. Opening a
    // folder loads its counts; do not rescan all matches after every search slice.
    if (root.dataset && (root.dataset.serverSearch === '1' || root.dataset.catalog === '1')) {
        root.querySelectorAll('[data-server-count-path]').forEach(function (card) {
            card.querySelectorAll('.folder-card-meta, .folder-list-cell:not(.folder-list-modified)').forEach(function (node) { node.textContent = 'Open folder to view contents'; });
        });
        return;
    }
    var pending = [], active = new Set(), stopped = false;
    var observer;
    function stop() {
        stopped = true;
        pending = [];
        if (observer) observer.disconnect();
        active.forEach(function (controller) { controller.abort(); });
        window.removeEventListener('pagehide', stop);
        if (window.greyhoundServerLibraryBeforeNavigate === stop) window.greyhoundServerLibraryBeforeNavigate = null;
    }
    async function next() {
        if (stopped || !root.isConnected || active.size >= 2 || !pending.length) return;
        var card = pending.shift(), controller = new AbortController();
        active.add(controller);
        var timeout = window.setTimeout(function () { controller.abort(); }, 12000);
        try {
            var url = new URL(<?= json_encode(site_url('workspace/server-library/counts')) ?>, window.location.href);
            url.searchParams.set('folder', card.dataset.serverCountPath);
            var response = await fetch(url, {credentials:'same-origin', signal:controller.signal, headers:{Accept:'application/json'}});
            if (!response.ok) throw new Error('Unavailable');
            var counts = (await response.json()).counts;
            if (stopped || !card.isConnected) return;
            var label = counts.files + ' file' + (counts.files === 1 ? '' : 's') + ' · ' + counts.folders + ' folder' + (counts.folders === 1 ? '' : 's');
            card.querySelectorAll('.folder-card-meta, .folder-list-cell:not(.folder-list-modified)').forEach(function (node) { node.textContent = label; });
        } catch (error) {
            if (!stopped && card.isConnected) card.querySelectorAll('.folder-card-meta, .folder-list-cell:not(.folder-list-modified)').forEach(function (node) { node.textContent = 'Contents unavailable'; });
        } finally { window.clearTimeout(timeout); active.delete(controller); next(); }
    }
    var cards = root.querySelectorAll('[data-server-count-path]');
    cards.forEach(function (card) { card.querySelectorAll('.folder-card-meta, .folder-list-cell:not(.folder-list-modified)').forEach(function (node) { node.textContent = 'Loading contents…'; }); });
    if (window.IntersectionObserver) {
        observer = new IntersectionObserver(function (entries) {
            entries.forEach(function (entry) { if (entry.isIntersecting) { observer.unobserve(entry.target); pending.push(entry.target); } });
            next(); next();
        }, {rootMargin:'200px'});
        cards.forEach(function (card) { observer.observe(card); });
    } else { pending = Array.from(cards); next(); next(); }
    window.greyhoundServerLibraryBeforeNavigate = stop;
    window.addEventListener('pagehide', stop);
});
</script>
