<?= view('layouts/app_header', ['user' => $user, 'activePage' => $activePage, 'title' => $title]) ?>
<style>
    .recent-preview { align-items: center; background: #f1f5ff; border: 0; border-radius: 9px; color: #4154f1; display: inline-flex; height: 52px; justify-content: center; overflow: hidden; padding: 0; width: 52px; }
    .recent-preview img { height: 52px; object-fit: cover; transition: transform .15s ease; width: 52px; }
    .recent-preview:hover img, .recent-preview:focus-visible img { transform: scale(1.08); }
    .recent-file-icon { align-items: center; background: #f5f7fb; border-radius: 9px; color: #667085; display: inline-flex; font-size: 1.5rem; height: 52px; justify-content: center; width: 52px; }
    #recentPreviewImage { max-height: min(70vh, 720px); object-fit: contain; width: 100%; }
</style>
<main id="main" class="main">
    <div class="pagetitle d-flex align-items-start justify-content-between flex-wrap gap-2">
        <div><h1>Recent Files</h1><nav><ol class="breadcrumb"><li class="breadcrumb-item"><a href="<?= esc(site_url('dashboard')) ?>">Dashboard</a></li><li class="breadcrumb-item active">Recent</li></ol></nav></div>
        <a class="btn btn-outline-primary btn-sm" href="<?= esc(site_url('workspace/file-management')) ?>"><i class="bi bi-folder2-open me-1"></i>File Management</a>
    </div>
    <section class="section"><div class="card"><div class="card-body">
        <div class="d-flex align-items-center justify-content-between flex-wrap gap-2"><div><h5 class="card-title mb-1">Recent media activity</h5><p class="small text-muted mb-0">Active media, ordered by the latest upload, download, restore, or update.</p></div><span class="badge bg-primary rounded-pill"><?= esc(number_format(count($files))) ?> files</span></div>
        <div class="table-responsive mt-3"><table class="table table-hover align-middle"><thead class="table-light"><tr><th>Preview</th><th>Media</th><th>Type</th><th>Activity</th><th>When</th><th>By</th><th class="text-end">Actions</th></tr></thead><tbody>
            <?php foreach ($files as $file): ?>
                <?php $fileId = (int) $file['id']; $isImage = in_array(strtolower((string) ($file['filetype'] ?? '')), ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp'], true); ?>
                <tr>
                    <td><?php if ($isImage): ?><button class="recent-preview" type="button" data-bs-toggle="modal" data-bs-target="#recentPreviewModal" data-preview-src="<?= esc(site_url('workspace/file-management/' . $fileId . '/preview')) ?>" data-preview-name="<?= esc((string) $file['display_title']) ?>" data-download-url="<?= esc(site_url('workspace/file-management/' . $fileId . '/download')) ?>" aria-label="Preview <?= esc((string) $file['display_title']) ?>"><img src="<?= esc(site_url('workspace/file-management/' . $fileId . '/preview')) ?>" alt="Preview of <?= esc((string) $file['display_title']) ?>" loading="lazy"></button><?php else: ?><span class="recent-file-icon"><i class="bi bi-file-earmark"></i></span><?php endif; ?></td>
                    <td><strong><?= esc((string) $file['display_title']) ?></strong><?php if (! empty($file['tags'])): ?><div class="small text-muted text-truncate" style="max-width: 260px" title="<?= esc((string) $file['tags']) ?>"><?= esc((string) $file['tags']) ?></div><?php endif; ?></td>
                    <td><span class="badge bg-light text-dark border text-uppercase"><?= esc((string) ($file['filetype'] ?: 'file')) ?></span></td>
                    <td><?= esc(ucwords(str_replace('_', ' ', (string) ($file['last_action'] ?: 'uploaded')))) ?></td>
                    <td class="text-nowrap"><?= esc(! empty($file['activity_at']) ? date('M d, Y h:i A', strtotime((string) $file['activity_at'])) : '—') ?></td>
                    <td><?= esc((string) ($file['uploader_name'] ?: 'Unknown')) ?></td>
                    <td class="text-end text-nowrap"><?php if ($isImage): ?><button class="btn btn-outline-secondary btn-sm" type="button" data-bs-toggle="modal" data-bs-target="#recentPreviewModal" data-preview-src="<?= esc(site_url('workspace/file-management/' . $fileId . '/preview')) ?>" data-preview-name="<?= esc((string) $file['display_title']) ?>" data-download-url="<?= esc(site_url('workspace/file-management/' . $fileId . '/download')) ?>"><i class="bi bi-eye me-1"></i>Preview</button><?php endif; ?><a class="btn btn-outline-primary btn-sm" href="<?= esc(site_url('workspace/file-management/' . $fileId . '/download')) ?>"><i class="bi bi-download me-1"></i>Download</a></td>
                </tr>
            <?php endforeach; ?>
            <?php if ($files === []): ?><tr><td colspan="7" class="text-center text-muted py-4">No active media files yet. Upload media from File Management to see it here.</td></tr><?php endif; ?>
        </tbody></table></div>
    </div></div></section>
</main>
<div class="modal fade" id="recentPreviewModal" tabindex="-1" aria-labelledby="recentPreviewTitle" aria-hidden="true"><div class="modal-dialog modal-xl modal-dialog-centered"><div class="modal-content"><div class="modal-header"><h5 class="modal-title text-truncate" id="recentPreviewTitle">Media preview</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div><div class="modal-body text-center bg-light"><img id="recentPreviewImage" src="" alt="Selected media preview"></div><div class="modal-footer"><a id="recentPreviewDownload" class="btn btn-primary" href="#"><i class="bi bi-download me-1"></i>Download</a><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button></div></div></div></div>
<script>
document.addEventListener('DOMContentLoaded', function () {
    var modal = document.getElementById('recentPreviewModal');
    if (!modal) return;
    modal.addEventListener('show.bs.modal', function (event) {
        var trigger = event.relatedTarget;
        document.getElementById('recentPreviewTitle').textContent = trigger.getAttribute('data-preview-name') || 'Media preview';
        document.getElementById('recentPreviewImage').src = trigger.getAttribute('data-preview-src') || '';
        document.getElementById('recentPreviewDownload').href = trigger.getAttribute('data-download-url') || '#';
    });
    modal.addEventListener('hidden.bs.modal', function () { document.getElementById('recentPreviewImage').src = ''; });
});
</script>
<?= view('layouts/app_footer') ?>
