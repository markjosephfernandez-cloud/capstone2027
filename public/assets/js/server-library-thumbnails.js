(function () {
    'use strict';
    function init() {
        if (window.greyhoundThumbnailCleanup) window.greyhoundThumbnailCleanup();
        var main = document.querySelector('#main[data-catalog="1"]');
        if (!main) return;
        var queue = [], active = new Set(), seen = new WeakSet(), stopped = false;
        function next() {
            if (stopped || !main.isConnected) return;
            while (active.size < 2 && queue.length) {
                var img = queue.shift();
                if (!img.isConnected) continue;
                img.dataset.thumbnailState = 'loading';
                active.add(img);
                img.addEventListener('load', done, {once:true});
                img.addEventListener('error', done, {once:true});
                img.src = img.dataset.serverThumbnail;
            }
        }
        function done(event) {
            var img = event.target;
            img.dataset.thumbnailState = event.type === 'load' ? 'loaded' : 'error';
            img.removeEventListener('load', done);
            img.removeEventListener('error', done);
            active.delete(img);
            next();
        }
        var observer = typeof IntersectionObserver === 'undefined' ? null : new IntersectionObserver(function (entries) {
            entries.forEach(function (entry) {
                if (!entry.isIntersecting) return;
                observer.unobserve(entry.target);
                queue.push(entry.target);
            });
            next();
        }, {rootMargin:'150px'});
        function discover() {
            active.forEach(function (img) {
                if (img.isConnected) return;
                img.removeEventListener('load', done);
                img.removeEventListener('error', done);
                img.removeAttribute('src');
                active.delete(img);
            });
            main.querySelectorAll('img[data-server-thumbnail]').forEach(function (img) {
                if (seen.has(img)) return;
                seen.add(img);
                if (observer) observer.observe(img);
                else queue.push(img);
            });
            next();
        }
        var changes = new MutationObserver(discover);
        changes.observe(main, {childList:true, subtree:true});
        function cleanup() {
            stopped = true;
            changes.disconnect();
            if (observer) observer.disconnect();
            queue = [];
            active.forEach(function (img) {
                img.removeEventListener('load', done);
                img.removeEventListener('error', done);
                img.removeAttribute('src');
            });
            active.clear();
            window.removeEventListener('pagehide', cleanup);
            if (window.greyhoundThumbnailCleanup === cleanup) window.greyhoundThumbnailCleanup = null;
        }
        window.greyhoundThumbnailCleanup = cleanup;
        window.addEventListener('pagehide', cleanup);
        discover();
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
    else init();
}());
