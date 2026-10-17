(function () {
    function initialize() {
        var modal = document.getElementById('recentMediaModal');
        if (!modal || modal.dataset.initialized) return;
        modal.dataset.initialized = '1';
        var buttons = Array.from(document.querySelectorAll('[data-recent-media]'));
        var image = document.getElementById('recentMediaImage');
        var video = document.getElementById('recentMediaVideo');
        var status = document.getElementById('recentMediaStatus');
        var error = document.getElementById('recentMediaError');
        var index = -1;
        var generation = 0;
        var pending = null;
        function clear() {
            generation++;
            if (pending) { pending.onload = null; pending.onerror = null; pending = null; }
            video.onloadeddata = video.onerror = null;
            video.pause();
            video.removeAttribute('src');
            video.load();
            video.classList.add('d-none');
            image.removeAttribute('src');
            image.classList.add('d-none');
            error.classList.add('d-none');
            status.textContent = '';
        }
        function show(nextIndex) {
            if (!buttons.length) return;
            clear();
            index = (nextIndex + buttons.length) % buttons.length;
            var button = buttons[index];
            var request = generation;
            function current() { return request === generation && modal.isConnected; }
            function loaded() {
                if (!current()) return;
                status.textContent = (index + 1) + ' / ' + buttons.length;
                if (window.greyhoundRecordViewed) window.greyhoundRecordViewed(button);
            }
            function failed() {
                if (!current()) return;
                image.classList.add('d-none');
                video.classList.add('d-none');
                error.classList.remove('d-none');
                status.textContent = '';
            }
            document.getElementById('recentMediaTitle').textContent = button.dataset.previewName;
            document.getElementById('recentMediaDownload').href = button.dataset.downloadUrl;
            status.textContent = 'Loading preview…';
            if (button.dataset.previewKind === 'video') {
                video.onloadeddata = loaded;
                video.onerror = failed;
                video.classList.remove('d-none');
                video.src = button.dataset.previewSrc;
            } else {
                var loader = new Image();
                pending = loader;
                loader.onload = function () {
                    if (!current()) return;
                    pending = null;
                    image.src = loader.src;
                    image.alt = button.dataset.previewName;
                    image.classList.remove('d-none');
                    loaded();
                };
                loader.onerror = failed;
                loader.src = button.dataset.previewSrc;
            }
        }
        modal.addEventListener('show.bs.modal', function (event) {
            var selected = buttons.indexOf(event.relatedTarget);
            if (selected >= 0) show(selected);
        });
        modal.addEventListener('hidden.bs.modal', function () { clear(); index = -1; });
        ['Previous', 'Next'].forEach(function (direction) {
            var button = document.getElementById('recentMedia' + direction);
            button.disabled = buttons.length < 2;
            button.addEventListener('click', function () { show(index + (direction === 'Next' ? 1 : -1)); });
        });
        document.querySelectorAll('.recent-media-thumbnail img').forEach(function (thumbnail) {
            function fallback() { thumbnail.hidden = true; }
            thumbnail.addEventListener('error', fallback);
            if (thumbnail.complete && !thumbnail.naturalWidth) fallback();
        });
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', initialize);
    else initialize();
})();
