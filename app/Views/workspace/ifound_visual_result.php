<?php $analysis = $analysis ?? null; ?>
<?php if (!$analysis): ?>
    <span class="small text-muted">Not scanned</span>
<?php else: ?>
    <details class="small" style="min-width:220px;max-width:360px">
        <summary>
            <?php if (($analysis['objects']['status'] ?? '') === 'unavailable' || ($analysis['expressions']['status'] ?? '') === 'unavailable'): ?><span class="badge bg-warning text-dark">Partial analysis</span><br><?php endif; ?>
            <?= count($analysis['objects']['items'] ?? []) ?> objects ·
            <?= count($analysis['expressions']['items'] ?? []) ?> expressions
        </summary>
        <div class="mt-2"><strong>Objects</strong>
            <?php if (($analysis['objects']['status'] ?? '') === 'not_requested'): ?><div class="text-muted">Not selected for scanning.</div>
            <?php elseif (($analysis['objects']['status'] ?? '') !== 'complete'): ?>
                <div class="text-warning">Model unavailable — retry after setup.</div>
            <?php elseif (empty($analysis['objects']['items'])): ?>
                <div class="text-muted">No objects detected above threshold.</div>
            <?php else: ?>
                <?php foreach ($analysis['objects']['items'] as $item): ?>
                    <div><?= esc($item['label']) ?> · <?= esc(number_format($item['confidence'], 1)) ?>%</div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
        <div class="mt-2"><strong>Facial-expression estimates</strong>
            <?php $expressionStatus = $analysis['expressions']['status'] ?? 'unavailable'; ?>
            <?php if ($expressionStatus !== 'complete'): ?>
                <div class="text-muted"><?= esc(['not_requested'=>'Not selected for scanning.', 'no_face'=>'No face detected.', 'disabled'=>'Expression analysis is disabled.', 'unavailable'=>'Model unavailable — retry after setup.'][$expressionStatus] ?? 'Unavailable') ?></div>
            <?php else: ?>
                <?php foreach ($analysis['expressions']['items'] as $index=>$item): ?>
                    <div>Face <?= $index + 1 ?>: <?= esc($item['label']) ?> · <?= esc(number_format($item['confidence'], 1)) ?>%</div>
                <?php endforeach; ?>
            <?php endif; ?>
            <div class="text-muted">Estimates from visible expressions, not a person's actual feelings.</div>
        </div>
        <div class="mt-2"><strong>Automatic tags</strong>
            <div class="d-flex flex-wrap gap-1 mt-1">
            <?php foreach ($analysis['auto_tags'] ?? [] as $tag): ?>
                <span class="badge bg-light text-dark border" title="<?= esc($tag['model'] . ' · ' . $tag['confidence'] . '%', 'attr') ?>"><?= esc($tag['label']) ?></span>
            <?php endforeach; ?>
            </div>
        </div>
        <?php if (($analysis['catalog_sync'] ?? '') === 'pending'): ?><div class="text-warning">Library tag sync pending; retry this scan.</div><?php endif; ?>
        <div class="text-muted mt-2">Scanned <?= esc(\App\Libraries\DisplayTime::format($analysis['analyzed_at'] ?? null)) ?> PHT</div>
    </details>
<?php endif; ?>
