<?php
$knownDate = !empty($record['deleted_at']) || !empty($record['last_action_at']);
$known = $knownDate && ($record['trash_expires_at'] ?? '-') !== '-';
$remaining = (int) ($record['trash_days_remaining'] ?? 0);
?>
<?php if ($known): ?>
<div class="retention-row"><strong><?= $remaining === 0 ? 'Eligible for retention cleanup' : $remaining . ' day' . ($remaining === 1 ? '' : 's') . ' remaining' ?></strong><span><?= esc(\App\Libraries\DisplayTime::format($record['trash_expires_at'])) ?> PHT</span></div>
<div class="retention-progress"><div class="h-100 <?= $remaining <= 7 ? 'bg-danger' : 'bg-primary' ?>" style="width: <?= max(0, min(100, (int) round($remaining / max(1, $retentionDays) * 100))) ?>%"></div></div>
<?php else: ?>
<div class="trash-meta mt-3">Deletion date unavailable · Retention date unknown</div>
<?php endif; ?>
