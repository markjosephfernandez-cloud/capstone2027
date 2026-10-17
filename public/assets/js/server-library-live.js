(function () {
    'use strict';
    function init() {
        if (window.greyhoundCatalogLiveCleanup) window.greyhoundCatalogLiveCleanup();
        var main = document.getElementById('main');
        var status = document.getElementById('catalogLiveStatus');
        if (!main || main.dataset.catalog !== '1' || !status) return;
        var page = window.location.href;
        var timer, controller, stopped = false;
        var regions = ['#catalogFolders', '#mediaListView tbody', '#mediaGridView', '#catalogPagination', '#catalogFolderCount', '#catalogFileCount', '#catalogProgress', '#catalogScanStatus'];
        function alive() { return !stopped && main.isConnected && window.location.href === page; }
        function busy() {
            return document.hidden || !!document.querySelector('.modal.show, .dropdown-menu.show, [data-media-select]:checked, form[aria-busy="true"]')
                || (document.activeElement && document.activeElement.matches('input, textarea, select, [contenteditable="true"]'));
        }
        function scanning() { return ['running', 'paused'].indexOf(main.dataset.catalogState) !== -1
            && (main.dataset.folderPending === '1' || (main.dataset.serverSearch === '1' && main.dataset.searchPending === '1')); }
        function message(text, loading) {
            status.replaceChildren();
            if (loading) {
                var spinner = document.createElement('span');
                spinner.className = 'spinner-border spinner-border-sm me-2';
                spinner.setAttribute('aria-hidden', 'true');
                status.appendChild(spinner);
            }
            status.appendChild(document.createTextNode(text));
        }
        function cleanup() {
            stopped = true;
            window.clearTimeout(timer);
            if (controller) controller.abort();
            window.removeEventListener('pagehide', cleanup);
            if (window.greyhoundCatalogLiveCleanup === cleanup) window.greyhoundCatalogLiveCleanup = null;
        }
        function comparableContent(node) {
            if (!node.cloneNode) return node.innerHTML;
            var copy = node.cloneNode(true);
            copy.querySelectorAll('img[data-server-thumbnail]').forEach(function (img) {
                img.removeAttribute('src');
                img.removeAttribute('data-thumbnail-state');
            });
            return copy.innerHTML;
        }
        async function poll() {
            if (!alive()) { cleanup(); return; }
            var delay = scanning() ? 5000 : 30000;
            if (busy()) { timer = window.setTimeout(poll, delay); return; }
            controller = new AbortController();
            var timeout = window.setTimeout(function () { controller.abort(); }, 10000);
            try {
                var url = new URL(page);
                url.searchParams.set('_catalog_poll', '1');
                url.searchParams.set('_catalog_version', main.dataset.catalogVersion || '');
                var response = await fetch(url.href, {credentials: 'same-origin', cache: 'no-store', signal: controller.signal});
                if (!response.ok || response.redirected) throw new Error('Catalog unavailable');
                if (response.status === 204) {
                    if (alive()) message(scanning() ? 'Indexing… New results appear automatically. You can keep browsing.' : 'Results are up to date with the catalog. Checking for new results automatically.', scanning());
                    return;
                }
                var html = await response.text();
                if (!alive() || busy()) return;
                var parsed = new DOMParser().parseFromString(html, 'text/html');
                var incoming = parsed.getElementById('main');
                if (!incoming || incoming.dataset.catalogValid !== '1') throw new Error('Catalog unavailable');
                // Leave controls, modals, selection state and scroll position in place.
                regions.forEach(function (selector) {
                    var current = main.querySelector(selector);
                    var next = incoming.querySelector(selector);
                    if (current && next && comparableContent(current) !== comparableContent(next)) current.innerHTML = next.innerHTML;
                });
                main.dataset.catalogVersion = incoming.dataset.catalogVersion;
                main.dataset.catalogState = incoming.dataset.catalogState;
                main.dataset.folderPending = incoming.dataset.folderPending;
                main.dataset.searchPending = incoming.dataset.searchPending;
                var pending = main.dataset.folderPending === '1' || (main.dataset.serverSearch === '1' && main.dataset.searchPending === '1');
                message(pending && scanning() ? 'Indexing… New results appear automatically. You can keep browsing.'
                    : pending ? 'Indexing is waiting or needs attention. Checking for updates automatically.'
                    : 'Results are up to date with the catalog. Checking for new results automatically.', pending && scanning());
            } catch (error) {
                if (alive()) message('Could not check for new results. Retrying automatically; your current results are still available.', false);
                delay = 30000;
            } finally {
                window.clearTimeout(timeout);
                controller = null;
                if (alive()) timer = window.setTimeout(poll, delay);
            }
        }
        window.greyhoundCatalogLiveCleanup = cleanup;
        window.addEventListener('pagehide', cleanup);
        message(scanning() ? 'Indexing… New results will appear automatically. You can keep browsing.' : 'Checking for new catalog results automatically.', scanning());
        timer = window.setTimeout(poll, 5000);
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
    else init();
}());
