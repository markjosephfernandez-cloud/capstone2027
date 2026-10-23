<?php foreach ($serverItems as $item): ?>
<article class="trash-item" data-trash-item>
    <?php if ($canManageTrash): ?><label class="trash-select-wrap" title="Select <?= esc($item['name']) ?>"><input class="form-check-input trash-select" type="checkbox" data-trash-select data-trash-type="server" data-restore-destination="<?= !empty($item['original_unknown']) ? '1' : '0' ?>" data-trash-id="<?= esc($item['id']) ?>" aria-label="Select <?= esc($item['name']) ?>"></label><?php endif; ?>
    <?php if ($item['directory']): ?><a class="trash-preview trash-folder-preview" href="<?= esc(site_url('workspace/trash/server/' . rawurlencode($item['id']) . '/folder')) ?>" aria-label="Open deleted folder <?= esc($item['name']) ?>"><i class="bi bi-folder-fill"></i></a>
    <?php else: ?>
        <?php $extension = strtolower((string) $item['filetype']); $kind = in_array($extension, ['jpg','jpeg','png','gif','webp','bmp'], true) ? 'image' : (in_array($extension, ['mp4','mov','avi','mkv','webm','m4v'], true) ? 'video' : 'file'); ?>
        <?= view('workspace/trash_thumbnail', ['kind'=>$kind, 'mediaTitle'=>$item['name'], 'previewUrl'=>site_url('workspace/trash/server/' . rawurlencode($item['id']) . '/preview')]) ?>
    <?php endif; ?><span class="trash-type"><?= esc($item['directory'] ? 'Folder' : ($item['filetype'] ?: 'File')) ?></span>
    <div class="trash-content">
        <div class="trash-name" title="<?= esc($item['name']) ?>"><?= esc($item['name']) ?></div>
        <div class="trash-meta"><i class="bi bi-hdd-network me-1"></i>Server Library<?php if (!$item['directory']): ?> &middot; <?= esc(format_bytes($item['size'])) ?><?php else: ?> &middot; Folder size not calculated<?php endif; ?></div>
        <div class="trash-meta">Deleted by <?= esc($item['deleted_by_name'] ?? 'Unknown') ?></div>
        <div class="trash-meta"><?= $item['deleted_at'] === 'Unknown' ? 'Deletion date not recorded' : esc(\App\Libraries\DisplayTime::format($item['deleted_at'])) . ' PHT' ?></div>
        <?php if (isset($item['trash_days_remaining'])): ?>
            <div class="small mt-3 <?= (int) $item['trash_days_remaining'] <= 7 ? 'text-danger' : 'text-muted' ?>"><i class="bi bi-clock me-1" aria-hidden="true"></i><?= (int) $item['trash_days_remaining'] === 0 ? 'Eligible for retention cleanup' : 'Retention: ' . (int) $item['trash_days_remaining'] . ' day' . ((int) $item['trash_days_remaining'] === 1 ? '' : 's') ?></div>
            <div class="trash-meta text-break"><?= esc(\App\Libraries\DisplayTime::format($item['trash_expires_at'])) . ' PHT' ?></div>
        <?php endif; ?>
        <div class="trash-reason" title="<?= esc($item['path']) ?>"><i class="bi bi-folder2 me-1"></i><?= !empty($item['original_unknown']) ? 'Original location not recorded. Stored in TRASH/' : 'Original location: ' ?><?= esc($item['path']) ?></div>
        <div class="small mt-3 <?= !empty($item['deleting']) ? 'text-danger' : 'text-muted' ?>"><?= !empty($item['deleting']) ? 'Deletion was interrupted. Select Delete permanently to finish removing this item.' : (!empty($item['original_unknown']) ? 'Select this item to choose a restore destination.' : 'Restores to its original server folder.') ?></div>
    </div>
</article>
<?php endforeach; ?>
