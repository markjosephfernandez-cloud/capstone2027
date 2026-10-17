<?= view('layouts/app_header', ['user' => $user, 'activePage' => $activePage, 'title' => $title]) ?>
<?php
$pagination = $pagination ?? ['current_page' => 1, 'total_pages' => 1, 'total_results' => count($files)];
$recentPageUrl = static fn (int $page): string => site_url('workspace/recent') . ($page > 1 ? '?page=' . $page : '');
?>
<style>
    .recent-preview { align-items: center; background: #f1f5ff; border: 0; border-radius: 9px; color: #4154f1; display: inline-flex; height: 52px; justify-content: center; overflow: hidden; padding: 0; width: 52px; }
    .recent-preview img { height: 52px; object-fit: cover; transition: transform .15s ease; width: 52px; }
    .recent-preview:hover img, .recent-preview:focus-visible img { transform: scale(1.08); }
    .recent-file-icon { align-items: center; background: #f5f7fb; border-radius: 9px; color: #667085; display: inline-flex; font-size: 1.5rem; height: 52px; justify-content: center; width: 52px; }
    #recentPreviewImage { height: 100%; max-height: 100%; object-fit: contain; width: 100%; visibility: hidden; }
    #recentPreviewModal .modal-content { max-height: calc(100vh - 2rem); overflow: hidden; }
    #recentPreviewModal .modal-body { height: min(65vh, 640px); min-height: 0; flex: 0 1 auto; display: flex; align-items: center; justify-content: center; }
    #recentPreviewModal .modal-header > div { min-width: 0; flex: 1; }
    #recentPreviewStatus { height: 3em; overflow-y: auto; }
    #recentPreviewCount { flex-shrink: 0; white-space: nowrap; }
</style>
<main id="main" class="main">
    <div class="pagetitle d-flex align-items-start justify-content-between flex-wrap gap-2">
        <div><h1>Recent Files</h1><nav><ol class="breadcrumb"><li class="breadcrumb-item"><a href="<?= esc(site_url('dashboard')) ?>">Dashboard</a></li><li class="breadcrumb-item active">Recent</li></ol></nav></div>
        <a class="btn btn-outline-primary btn-sm" href="<?= esc(site_url('workspace/file-management')) ?>"><i class="bi bi-folder2-open me-1"></i>File Management</a>
    </div>
    <section class="section"><div class="card"><div class="card-body">
        <div class="d-flex align-items-center justify-content-between flex-wrap gap-2"><div><h5 class="card-title mb-1">Recent media activity</h5><p class="small text-muted mb-0">Active media, ordered by the latest upload, download, restore, or update.</p></div><span class="badge bg-primary rounded-pill"><?= esc(number_format((int) $pagination['total_results'])) ?> files</span></div>
        <div class="table-responsive mt-3"><table class="table table-hover align-middle"><thead class="table-light"><tr><th>Preview</th><th>Media</th><th>Type</th><th>Activity</th><th>When</th><th>By</th></tr></thead><tbody>
            <?php foreach ($files as $file): ?>
                <?php $fileId = (int) $file['id']; $isImage = in_array(strtolower((string) ($file['filetype'] ?? '')), ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp'], true); ?>
                <tr>
                    <td><?php if ($isImage): ?><button class="recent-preview" type="button" data-bs-toggle="modal" data-bs-target="#recentPreviewModal" data-preview-src="<?= esc(site_url('workspace/file-management/' . $fileId . '/preview')) ?>" data-preview-name="<?= esc((string) $file['display_title']) ?>" aria-label="Preview <?= esc((string) $file['display_title']) ?>"><img src="<?= esc(site_url('workspace/file-management/' . $fileId . '/preview')) ?>" alt="Preview of <?= esc((string) $file['display_title']) ?>" loading="lazy"></button><?php else: ?><span class="recent-file-icon"><i class="bi bi-file-earmark"></i></span><?php endif; ?></td>
                    <td><strong><?= esc((string) $file['display_title']) ?></strong></td>
                    <td><span class="badge bg-light text-dark border text-uppercase"><?= esc((string) ($file['filetype'] ?: 'file')) ?></span></td>
                    <td><?= esc(ucwords(str_replace('_', ' ', (string) ($file['last_action'] ?: 'uploaded')))) ?></td>
                    <td class="text-nowrap"><?= esc(! empty($file['activity_at']) ? \App\Libraries\DisplayTime::format($file['activity_at'], 'M d, Y h:i A') : '—') ?></td>
                    <td><?= esc((string) ($file['uploader_name'] ?: 'Unknown')) ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if ($files === []): ?><tr><td colspan="6" class="text-center text-muted py-4">No active media files yet. Upload media from File Management to see it here.</td></tr><?php endif; ?>
        </tbody></table></div>
        <?= view('components/pagination', ['pagination' => $pagination, 'pageUrl' => $recentPageUrl, 'paginationLabel' => 'Recent file pages']) ?>
    </div></div></section>
</main>
<div class="modal fade" id="recentPreviewModal" tabindex="-1" aria-labelledby="recentPreviewTitle" aria-hidden="true"><div class="modal-dialog modal-xl modal-dialog-centered"><div class="modal-content"><div class="modal-header"><div class="overflow-hidden"><h5 class="modal-title text-truncate" id="recentPreviewTitle">Media preview</h5><div class="small text-muted" id="recentPreviewStatus" role="status"></div></div><span class="small text-muted ms-3 me-auto" id="recentPreviewCount" aria-live="polite"></span><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div><div class="modal-body text-center bg-light position-relative"><img id="recentPreviewImage" alt="Selected media preview">
    <button class="preview-gallery-arrow previous d-none" id="recentPreviewPrevious" type="button" aria-label="Previous image" title="Previous image"><i class="bi bi-chevron-left" aria-hidden="true"></i></button>
    <button class="preview-gallery-arrow next d-none" id="recentPreviewNext" type="button" aria-label="Next image" title="Next image"><i class="bi bi-chevron-right" aria-hidden="true"></i></button>
</div></div></div></div>
<script>
document.addEventListener('DOMContentLoaded', function () {
    var modal = document.getElementById('recentPreviewModal');
    if (!modal) return;
    var image = document.getElementById('recentPreviewImage');
    var title = document.getElementById('recentPreviewTitle');
    var counter = document.getElementById('recentPreviewCount');
    var status = document.getElementById('recentPreviewStatus');
    var previous = document.getElementById('recentPreviewPrevious');
    var next = document.getElementById('recentPreviewNext');
    var images = [...new Map([...document.querySelectorAll('[data-bs-target="#recentPreviewModal"][data-preview-src]')].map(function (button) {
        return [button.dataset.previewSrc, button];
    })).values()];
    var index = -1;
    var imageRequest = 0;
    var pendingImage = null;
    var cancelImageRequest = function () {
        imageRequest += 1;
        if (pendingImage) {
            pendingImage.onload = null;
            pendingImage.onerror = null;
            pendingImage = null;
        }
    };
    var updateDetails = function (trigger, requestedIndex) {
        title.textContent = trigger.dataset.previewName || 'Media preview';
        image.alt = title.textContent;
        counter.textContent = (requestedIndex + 1) + ' / ' + images.length;
    };
    var showImage = function (nextIndex) {
        if (!images.length) return;
        cancelImageRequest();
        index = (nextIndex + images.length) % images.length;
        var trigger = images[index];
        if (!image.getAttribute('src')) updateDetails(trigger, index);
        status.textContent = 'Loading image preview…';
        previous.classList.toggle('d-none', images.length < 2);
        next.classList.toggle('d-none', images.length < 2);
        var request = imageRequest;
        var requestedIndex = index;
        var loader = new Image();
        pendingImage = loader;
        var isCurrent = function () { return request === imageRequest && index >= 0 && modal.isConnected; };
        // Leave the displayed photo and its details intact until its replacement is ready.
        loader.onload = function () {
            if (!isCurrent()) return;
            pendingImage = null;
            image.src = loader.src;
            image.style.visibility = 'visible';
            updateDetails(trigger, requestedIndex);
            status.textContent = '';
        };
        loader.onerror = function () {
            if (!isCurrent()) return;
            pendingImage = null;
            image.style.visibility = 'hidden';
            image.removeAttribute('src');
            updateDetails(trigger, requestedIndex);
            status.textContent = 'Image preview could not be loaded. Try another image or reopen this preview to retry.';
        };
        loader.src = trigger.dataset.previewSrc;
    };
    modal.addEventListener('show.bs.modal', function (event) {
        if (!event.relatedTarget) return;
        var requested = images.findIndex(function (button) { return button.dataset.previewSrc === event.relatedTarget.dataset.previewSrc; });
        if (requested >= 0) showImage(requested);
    });
    previous.addEventListener('click', function () { if (index >= 0) showImage(index - 1); });
    next.addEventListener('click', function () { if (index >= 0) showImage(index + 1); });
    modal.addEventListener('keydown', function (event) {
        if (index < 0 || images.length < 2 || event.target?.closest('input, textarea, select, [contenteditable="true"]')) return;
        if (event.key === 'ArrowLeft' || event.key === 'ArrowRight') {
            event.preventDefault();
            showImage(index + (event.key === 'ArrowRight' ? 1 : -1));
        }
    });
    modal.addEventListener('hidden.bs.modal', function () {
        cancelImageRequest();
        index = -1;
        image.style.visibility = 'hidden';
        image.removeAttribute('src');
        status.textContent = '';
        counter.textContent = '';
    });
});
</script>
<?= view('layouts/app_footer') ?>
