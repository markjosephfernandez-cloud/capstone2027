<script>
document.addEventListener('DOMContentLoaded', function () {
    var failed = window.greyhoundTrashPreviewFailures || (window.greyhoundTrashPreviewFailures = new Set());
    var remember = function (url) { if (failed.size >= 200) failed.delete(failed.values().next().value); failed.add(url); };
    var thumbnails = document.querySelectorAll('[data-trash-thumbnail]');
    var unavailable = function (image) {
        image.hidden = true;
        var button = image.closest('[data-trash-preview]');
        var status = button.querySelector('.trash-preview-status');
        status.hidden = false;
        status.querySelector('span').textContent = 'Preview unavailable';
        button.disabled = true;
        remember(image.dataset.src);
    };
    var load = function (image) {
        if (image.dataset.requested) return;
        image.dataset.requested = '1';
        if (failed.has(image.dataset.src)) { unavailable(image); return; }
        image.addEventListener('load', function () {
            image.hidden = false;
            image.closest('[data-trash-preview]').querySelector('.trash-preview-status').hidden = true;
        }, {once: true});
        image.addEventListener('error', function () { unavailable(image); }, {once: true});
        // The visible card's observer owns lazy loading. Native lazy loading
        // can defer this hidden image forever while it waits for its load event.
        image.loading = 'eager';
        image.src = image.dataset.src;
    };
    var observer = typeof IntersectionObserver === 'function' ? new IntersectionObserver(function (entries) {
        entries.forEach(function (entry) { if (entry.isIntersecting) { observer.unobserve(entry.target); load(entry.target.querySelector('[data-trash-thumbnail]')); } });
    }, {rootMargin: '200px'}) : null;
    thumbnails.forEach(function (image) { if (observer) observer.observe(image.closest('[data-trash-preview]')); else load(image); });

    var prefix = document.getElementById('trashPreviewModal') ? 'trashPreview' : 'trashFolderPreview';
    var modal = document.getElementById(prefix + 'Modal');
    if (!modal) return;
    var image = document.getElementById(prefix + 'Image');
    var video = document.getElementById(prefix + 'Video');
    var body = modal.querySelector('.modal-body');
    var status = document.createElement('div');
    status.className = 'trash-modal-status';
    status.setAttribute('role', 'status');
    body.appendChild(status);
    var active = null;
    function hideMedia() { image.classList.add('d-none'); video.classList.add('d-none'); }
    function failedPreview() {
        if (!active) return;
        hideMedia();
        status.textContent = 'Preview unavailable. The file may be missing or the server may be disconnected.';
        remember(active);
    }
    image.addEventListener('error', failedPreview);
    video.addEventListener('error', failedPreview);
    image.addEventListener('load', function () {
        if (!active) return;
        status.textContent = '';
        image.classList.remove('d-none');
    });
    video.addEventListener('loadeddata', function () {
        if (!active) return;
        status.textContent = '';
        video.classList.remove('d-none');
    });
    modal.addEventListener('show.bs.modal', function (event) {
        var trigger = event.relatedTarget;
        if (!trigger || trigger.disabled) { event.preventDefault(); return; }
        hideMedia();
        active = trigger.dataset.previewSrc;
        document.getElementById(prefix + 'Title').textContent = trigger.dataset.previewName || 'Trash preview';
        if (failed.has(active)) { failedPreview(); return; }
        status.textContent = 'Loading preview…';
        if (trigger.dataset.previewKind === 'video') { video.src = active; video.load(); }
        else { image.alt = trigger.dataset.previewName || 'Media preview'; image.src = active; }
    });
    modal.addEventListener('hidden.bs.modal', function () {
        active = null;
        image.removeAttribute('src');
        video.pause();
        video.removeAttribute('src');
        video.load();
        hideMedia();
        status.textContent = '';
    });
});
</script>
