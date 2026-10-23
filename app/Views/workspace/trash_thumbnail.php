<?php $previewClass = $previewClass ?? 'trash-preview'; ?>
<?php if ($kind === 'image' || $kind === 'video'): ?>
<button class="<?= esc($previewClass) ?> trash-media-preview" type="button"
    data-bs-toggle="modal" data-bs-target="#<?= esc($modalId ?? 'trashPreviewModal') ?>"
    data-trash-preview data-preview-kind="<?= esc($kind) ?>" data-preview-src="<?= esc($previewUrl) ?>"
    data-preview-name="<?= esc($mediaTitle, 'attr') ?>" aria-label="Preview <?= esc($mediaTitle) ?>">
    <span class="trash-preview-status" role="status"><i class="bi <?= $kind === 'video' ? 'bi-play-circle' : 'bi-image' ?>"></i><span>Loading preview…</span></span>
    <img data-trash-thumbnail data-src="<?= esc($previewUrl . (str_contains($previewUrl, '?') ? '&' : '?') . 'thumbnail=1') ?>"
        alt="<?= esc($mediaTitle) ?>" width="400" height="250" loading="eager" decoding="async" hidden>
    <?php if ($kind === 'video'): ?><span class="trash-video-mark"><i class="bi bi-play-fill"></i> Video</span><?php endif; ?>
</button>
<?php else: ?>
<div class="<?= esc($previewClass) ?>"><span class="trash-preview-status"><i class="bi bi-file-earmark"></i><span>No preview for this file type</span></span></div>
<?php endif; ?>
