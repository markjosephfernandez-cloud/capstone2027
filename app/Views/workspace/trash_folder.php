<?= view('layouts/app_header', ['user' => $user, 'activePage' => $activePage, 'title' => $title]) ?>
<?php
$imageTypes = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp'];
$videoTypes = ['mp4', 'mov', 'avi', 'mkv', 'webm', 'm4v'];
$rootId = (int) $rootFolder['id'];
$days = (int) $rootFolder['trash_days_remaining'];
?>
<style>
    .trash-folder-workspace { --trash-primary: #315efb; --trash-ink: #1f2c46; --trash-muted: #6f7c93; }
    .trash-folder-workspace .folder-hero { align-items: center; background: linear-gradient(135deg, #f3f7ff, #fff); border: 1px solid #dfe7f7; border-radius: 17px; display: flex; flex-wrap: wrap; gap: 1rem; justify-content: space-between; padding: 1.2rem; }
    .trash-folder-workspace .folder-symbol { align-items: center; background: #e5edff; border-radius: 13px; color: #6884c4; display: flex; font-size: 1.5rem; height: 50px; justify-content: center; width: 50px; }
    .trash-folder-workspace .folder-hero h2 { color: var(--trash-ink); font-size: 1.25rem; font-weight: 750; margin: 0; }.trash-folder-workspace .folder-hero p { color: var(--trash-muted); font-size: .82rem; margin: .25rem 0 0; }
    .trash-folder-workspace .folder-actions { display: flex; flex-wrap: wrap; gap: .5rem; }.trash-folder-workspace .folder-actions form { margin: 0; }
    .trash-folder-workspace .deleted-breadcrumb { align-items: center; background: #fff; border: 1px solid #e4e9f2; border-radius: 12px; display: flex; flex-wrap: wrap; gap: .35rem; margin: 1rem 0; padding: .7rem .9rem; }
    .trash-folder-workspace .deleted-breadcrumb a { color: #50617f; font-size: .8rem; text-decoration: none; }.trash-folder-workspace .deleted-breadcrumb a:hover { color: var(--trash-primary); }.trash-folder-workspace .deleted-breadcrumb i { color: #a0a9ba; font-size: .7rem; }
    .trash-folder-workspace .content-card { border: 1px solid #e4e9f2; border-radius: 16px; box-shadow: none; overflow: hidden; }.trash-folder-workspace .content-header { align-items: center; background: #f8faff; border-bottom: 1px solid #e8edf5; display: flex; justify-content: space-between; padding: .85rem 1rem; }.trash-folder-workspace .content-header strong { color: #35435e; font-size: .88rem; }
    .trash-folder-workspace .item-grid { display: grid; gap: 1rem; grid-template-columns: repeat(auto-fill, minmax(min(210px, 100%), 1fr)); padding: 1rem; }
    .trash-folder-workspace .folder-hero > .d-flex { min-width: 0; max-width: 100%; }
    .trash-folder-workspace .folder-hero h2 { overflow-wrap: anywhere; }
    .trash-folder-workspace .folder-symbol { flex-shrink: 0; }
    .trash-folder-workspace .content-header { flex-wrap: wrap; gap: .5rem; }
    .trash-folder-workspace .item-card { background: #fff; border: 1px solid #e2e7f0; border-radius: 14px; color: inherit; display: flex; flex-direction: column; min-height: 255px; overflow: hidden; text-decoration: none; transition: box-shadow .15s, transform .15s; }.trash-folder-workspace .item-card:hover { box-shadow: 0 8px 20px rgba(45,59,92,.09); color: inherit; transform: translateY(-1px); }
    .trash-folder-workspace .item-preview { align-items: center; background: #eef2f7; border: 0; color: #71809a; display: flex; height: 145px; justify-content: center; overflow: hidden; padding: 0; width: 100%; }.trash-folder-workspace .item-preview img, .trash-folder-workspace .item-preview video { height: 100%; object-fit: cover; width: 100%; }.trash-folder-workspace .item-preview > i { font-size: 2.5rem; }.trash-folder-workspace .folder-preview { background: linear-gradient(180deg, #f3f7ff, #e7eeff); color: #6884c4; }.trash-folder-workspace .folder-preview > i { font-size: 3.5rem; }
    .trash-folder-workspace .item-body { display: flex; flex: 1; flex-direction: column; padding: .85rem; }.trash-folder-workspace .item-name { color: var(--trash-ink); font-size: .86rem; font-weight: 750; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }.trash-folder-workspace .item-meta { color: #7a879c; font-size: .73rem; margin-top: .3rem; }.trash-folder-workspace .item-type { background: #eef3ff; border-radius: 5px; color: #315fc2; display: inline-block; font-size: .63rem; font-weight: 700; margin-top: auto; padding: .2rem .4rem; text-transform: uppercase; width: fit-content; }
    .trash-folder-workspace .empty-folder { color: #75839a; grid-column: 1 / -1; padding: 4rem 1rem; text-align: center; }.trash-folder-workspace .empty-folder i { color: #a1b2d3; font-size: 3rem; }
    .trash-folder-preview-body { align-items: center; background: #101a31; display: flex; justify-content: center; min-height: 340px; overflow: hidden; padding: 0; }.trash-folder-preview-body img, .trash-folder-preview-body video { max-height: calc(100vh - 210px); max-width: 100%; object-fit: contain; }
    .trash-folder-workspace .deleted-folder-card { min-width: 0; }
    .trash-folder-workspace .deleted-folder-card:hover { transform: none; }
</style>
<main id="main" class="main trash-folder-workspace">
    <div class="pagetitle d-flex align-items-start justify-content-between flex-wrap gap-2"><div><h1>Trash</h1><nav><ol class="breadcrumb"><li class="breadcrumb-item"><a href="<?= esc(site_url('dashboard')) ?>">Dashboard</a></li><li class="breadcrumb-item"><a href="<?= esc(site_url('workspace/trash')) ?>">Trash</a></li><li class="breadcrumb-item active"><?= esc($folder['name']) ?></li></ol></nav></div><a class="btn btn-outline-secondary btn-sm" href="<?= esc(site_url('workspace/trash')) ?>"><i class="bi bi-arrow-left me-1"></i>Back to Trash</a></div>
    <section class="section">
        <?php if (! empty($success)): ?><div class="alert alert-success alert-dismissible fade show" role="alert"><?= esc($success) ?><button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button></div><?php endif; ?>
        <?php if (! empty($error)): ?><div class="alert alert-danger alert-dismissible fade show" role="alert"><?= esc($error) ?><button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button></div><?php endif; ?>
        <div class="folder-hero">
            <div class="d-flex align-items-center gap-3"><span class="folder-symbol"><i class="bi bi-folder2-open"></i></span><div><h2><?= esc($folder['name']) ?></h2><p><?= esc((string) $summary['file_count']) ?> file<?= (int) $summary['file_count'] === 1 ? '' : 's' ?> in this deleted folder tree &middot; <?= esc(format_bytes((int) $summary['size'])) ?> &middot; <?= $days ?> day<?= $days === 1 ? '' : 's' ?> remaining</p></div></div>
            <div class="folder-actions">
                <?php if ($canRestore): ?><form method="post" action="<?= esc(site_url('workspace/trash/bulk-restore')) ?>"><?= csrf_field() ?><input type="hidden" name="folder_ids[]" value="<?= $rootId ?>"><button class="btn btn-outline-success btn-sm" type="submit"><i class="bi bi-arrow-counterclockwise me-1"></i>Restore folder</button></form><?php endif; ?>
                <?php if ($canPermanentDelete): ?><form method="post" action="<?= esc(site_url('workspace/trash/bulk-delete')) ?>" data-confirm-kind="delete" data-confirm-message="Permanently delete this folder, all of its contents, and their physical files from computer storage?"><?= csrf_field() ?><input type="hidden" name="folder_ids[]" value="<?= $rootId ?>"><button class="btn btn-outline-danger btn-sm" type="submit"><i class="bi bi-trash3 me-1"></i>Delete folder</button></form><?php endif; ?>
            </div>
        </div>

        <nav class="deleted-breadcrumb" aria-label="Deleted folder path"><a href="<?= esc(site_url('workspace/trash')) ?>"><i class="bi bi-trash3 me-1"></i>Trash</a><?php foreach ($breadcrumbs as $crumb): ?><i class="bi bi-chevron-right"></i><a href="<?= esc(site_url('workspace/trash/folders/' . (int) $crumb['id'])) ?>"><?= esc($crumb['name']) ?></a><?php endforeach; ?></nav>

        <div class="card content-card">
            <div class="content-header"><strong>Folder contents</strong><span class="small text-muted"><?= count($children) ?> folder<?= count($children) === 1 ? '' : 's' ?> &middot; <?= count($files) ?> file<?= count($files) === 1 ? '' : 's' ?></span></div>
            <div class="item-grid">
                <?php foreach ($children as $child): ?><a class="item-card deleted-folder-card" href="<?= esc(site_url('workspace/trash/folders/' . (int) $child['id'])) ?>"><div class="item-preview folder-preview"><i class="bi bi-folder-fill"></i></div><div class="item-body"><div class="item-name"><?= esc($child['name']) ?></div><div class="item-meta">Deleted subfolder</div><span class="item-type">Folder</span></div></a><?php endforeach; ?>
                <?php foreach ($files as $file): ?><?php $filetype = strtolower((string) $file['filetype']); $kind = in_array($filetype, $imageTypes, true) ? 'image' : (in_array($filetype, $videoTypes, true) ? 'video' : 'file'); $mediaTitle = \App\Libraries\MediaTitle::forFile($file); $previewUrl = site_url('workspace/trash/' . (int) $file['id'] . '/preview'); ?>
                    <article class="item-card"><?php if ($kind !== 'file'): ?><button class="item-preview" type="button" data-bs-toggle="modal" data-bs-target="#trashFolderPreviewModal" data-preview-kind="<?= esc($kind) ?>" data-preview-src="<?= esc($previewUrl) ?>" data-preview-name="<?= esc($mediaTitle, 'attr') ?>" aria-label="Preview <?= esc($mediaTitle) ?>"><?php if ($kind === 'image'): ?><img src="<?= esc($previewUrl) ?>" alt="Preview of <?= esc($mediaTitle) ?>" loading="lazy"><?php else: ?><i class="bi bi-play-circle-fill"></i><?php endif; ?></button><?php else: ?><div class="item-preview"><i class="bi bi-file-earmark"></i></div><?php endif; ?><div class="item-body"><div class="item-name" title="<?= esc($mediaTitle) ?>"><?= esc($mediaTitle) ?></div><div class="item-meta"><?= esc(format_bytes((int) $file['size'])) ?></div><span class="item-type"><?= esc($filetype ?: 'file') ?></span></div></article>
                <?php endforeach; ?>
                <?php if ($children === [] && $files === []): ?><div class="empty-folder"><i class="bi bi-folder2"></i><h3 class="h6 mt-3">This folder is empty</h3><p class="small mb-0">There are no saved files or subfolders at this level.</p></div><?php endif; ?>
            </div>
        </div>
    </section>
</main>
<div class="modal fade" id="trashFolderPreviewModal" tabindex="-1" aria-labelledby="trashFolderPreviewTitle" aria-hidden="true"><div class="modal-dialog modal-xl modal-dialog-centered"><div class="modal-content"><div class="modal-header"><h5 class="modal-title text-truncate" id="trashFolderPreviewTitle">Trash preview</h5><button class="btn-close" type="button" data-bs-dismiss="modal" aria-label="Close"></button></div><div class="modal-body trash-folder-preview-body"><img class="d-none" id="trashFolderPreviewImage" src="" alt=""><video class="d-none" id="trashFolderPreviewVideo" src="" controls preload="metadata"></video></div></div></div></div>
<script>
document.addEventListener('DOMContentLoaded', function () {
    var modal = document.getElementById('trashFolderPreviewModal'); var image = document.getElementById('trashFolderPreviewImage'); var video = document.getElementById('trashFolderPreviewVideo'); if (!modal) return;
    modal.addEventListener('show.bs.modal', function (event) { var trigger = event.relatedTarget; var kind = trigger.dataset.previewKind; document.getElementById('trashFolderPreviewTitle').textContent = trigger.dataset.previewName || 'Trash preview'; image.classList.toggle('d-none', kind !== 'image'); video.classList.toggle('d-none', kind !== 'video'); if (kind === 'image') { image.src = trigger.dataset.previewSrc; image.alt = 'Preview of ' + (trigger.dataset.previewName || 'media'); } else { video.src = trigger.dataset.previewSrc; video.load(); } });
    modal.addEventListener('hidden.bs.modal', function () { image.src = ''; video.pause(); video.removeAttribute('src'); video.load(); });
});
</script>
<?= view('layouts/app_footer') ?>
