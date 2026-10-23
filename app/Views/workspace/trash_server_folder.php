<?= view('layouts/app_header', ['user'=>$user, 'activePage'=>'trash', 'title'=>'Deleted server folder']) ?>
<?= view('workspace/trash_styles') ?>
<style>
.trash-folder-workspace .item-grid { display:grid; }
.trash-folder-workspace .item-card { border:1px solid #dce1e8; border-radius:14px; overflow:hidden; min-width:0; background:white; }
.trash-folder-workspace .item-preview { display:flex; align-items:center; justify-content:center; background:#edf0f4; color:#0d6efd; width:100%; border:0; }
.trash-folder-workspace .item-preview > i { font-size:4rem; }
.trash-folder-workspace .item-body { padding:1rem; }
.trash-folder-workspace .item-name { overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
</style>
<?php
$url = static fn (string $path): string => site_url('workspace/trash/server/' . rawurlencode($trashId) . '/folder') . ($path === '' ? '' : '?' . http_build_query(['child'=>$path]));
$parent = dirname($child);
$parent = $parent === '.' ? '' : $parent;
?>
<main id="main" class="main trash-folder-workspace">
    <div class="pagetitle"><h1>Deleted server folder</h1></div>
    <div class="card p-3">
        <nav aria-label="Trash folder location"><a href="<?= esc(site_url('workspace/trash')) ?>">Trash</a> / <a href="<?= esc($url('')) ?>"><?= esc(basename($record['path'])) ?></a> / <span class="text-break"><?= esc($child) ?></span></nav>
        <p class="mt-3 mb-1">Server Library · Deleted <?= esc(\App\Libraries\DisplayTime::format($record['deleted_at'] ?? null, 'M d, Y g:i A', 'date unavailable')) ?></p>
        <p class="small text-muted">Preview only. Restore or permanently delete the complete folder from its card on the Trash page.</p>
        <?php if ($child !== ''): ?><a href="<?= esc($url($parent)) ?>">Back to parent folder</a><?php endif; ?>
    </div>
    <div class="item-grid">
    <?php foreach ($items as $item): ?>
        <article class="item-card">
        <?php if ($item['directory']): ?>
            <a class="item-preview" href="<?= esc($url($item['child'])) ?>" aria-label="Open <?= esc($item['name']) ?>"><i class="bi bi-folder-fill"></i></a>
        <?php else: ?>
            <?php $kind = in_array($item['filetype'], ['jpg','jpeg','png','gif','webp','bmp'], true) ? 'image' : (in_array($item['filetype'], ['mp4','mov','webm','avi','mkv','m4v'], true) ? 'video' : 'file'); ?>
            <?= view('workspace/trash_thumbnail', ['kind'=>$kind, 'mediaTitle'=>$item['name'], 'previewClass'=>'item-preview', 'previewUrl'=>site_url('workspace/trash/server/' . rawurlencode($trashId) . '/preview') . '?' . http_build_query(['child'=>$item['child']])]) ?>
        <?php endif; ?>
        <div class="item-body"><div class="item-name" title="<?= esc($item['name']) ?>"><?= esc($item['name']) ?></div><div class="item-meta"><?= esc($item['directory'] ? 'Folder' : strtoupper($item['filetype']) . ' · ' . format_bytes($item['size'])) ?></div></div>
        </article>
    <?php endforeach; ?>
    <?php if (!$items): ?><p class="text-muted p-4">This folder has no available media or subfolders.</p><?php endif; ?>
    </div>
</main>
<div class="modal fade" id="trashPreviewModal" tabindex="-1" aria-labelledby="trashPreviewTitle" aria-hidden="true"><div class="modal-dialog modal-xl modal-dialog-centered"><div class="modal-content"><div class="modal-header"><h2 class="modal-title h5 text-truncate" id="trashPreviewTitle">Trash preview</h2><button class="btn-close" type="button" data-bs-dismiss="modal" aria-label="Close"></button></div><div class="modal-body trash-preview-body"><img class="d-none" id="trashPreviewImage" alt="" style="max-width:100%;max-height:70vh"><video class="d-none" id="trashPreviewVideo" controls preload="metadata" style="max-width:100%;max-height:70vh"></video></div></div></div></div>
<?= view('workspace/trash_preview_script') ?>
<?= view('layouts/app_footer') ?>
