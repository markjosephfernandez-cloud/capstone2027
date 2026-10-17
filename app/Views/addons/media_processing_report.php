<?php if (! empty($report['available'])): ?>
<div class="card" id="media-processing-report" style="scroll-margin-top:90px"><div class="card-body">
    <h2 class="card-title">Media Processing Status</h2>
    <p class="small text-muted">Active registered library files, newest uploads first. Check AI processing and passport availability. “Not queued” means no linked server analysis job.</p>
    <div class="table-responsive"><table class="table align-middle small">
        <thead><tr><th scope="col">File</th><th scope="col">Department / uploader</th><th scope="col">Uploaded (PHT)</th><th scope="col">AI processing</th><th scope="col">Classification</th><th scope="col">Passport</th></tr></thead>
        <tbody><?php foreach ($report['rows'] as $row): ?>
            <tr><td><?= esc($row['filename']) ?><div class="text-muted">#<?= (int) $row['file_id'] ?></div></td>
                <td><?= esc($row['department']) ?><div class="text-muted"><?= esc($row['uploader_name']) ?></div></td>
                <td><?= esc(\App\Libraries\DisplayTime::format($row['uploaded_at'], 'M d, Y h:i A')) ?></td>
                <td><?= esc(ucfirst(str_replace('_', ' ', $row['analysis_status']))) ?></td>
                <td><?= esc($row['classification']) ?></td>
                <td><?= (int) $row['has_passport'] === 1 ? ((int) $row['needs_review'] === 1 ? 'Available · Needs review' : 'Available') : 'Missing' ?></td></tr>
        <?php endforeach; ?>
        <?php if ($report['rows'] === []): ?><tr><td colspan="6" class="text-muted">No active library files.</td></tr><?php endif; ?>
        </tbody>
    </table></div>
    <nav class="d-flex align-items-center gap-3" aria-label="Media processing report pages">
        <span class="small text-muted"><?= (int) $report['total'] ?> files · Page <?= (int) $report['page'] ?> of <?= (int) $report['pages'] ?></span>
        <?php foreach (['Previous' => $report['page'] - 1, 'Next' => $report['page'] + 1] as $label => $page): ?>
            <?php if ($page >= 1 && $page <= $report['pages']): ?>
                <a class="btn btn-outline-primary btn-sm" href="<?= esc(site_url('workspace/passports') . '?' . http_build_query(['processing_page' => $page, 'q' => $searchQuery ?? '', 'page' => $pagination['current_page'] ?? 1]) . '#media-processing-report', 'attr') ?>"><?= esc($label) ?></a>
            <?php endif; ?>
        <?php endforeach; ?>
    </nav>
</div></div>
<?php endif; ?>
