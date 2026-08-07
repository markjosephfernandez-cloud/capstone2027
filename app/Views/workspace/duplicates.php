<?= view('layouts/app_header', ['user' => $user, 'activePage' => $activePage, 'title' => $title]) ?>
<main id="main" class="main">
    <div class="pagetitle"><h1>Duplicate Queue</h1><nav><ol class="breadcrumb"><li class="breadcrumb-item"><a href="<?= esc(site_url('workspace/file-management')) ?>">File Management</a></li><li class="breadcrumb-item active">Duplicate Queue</li></ol></nav></div>
    <section class="section">
        <div class="card"><div class="card-body pt-3">
            <div class="d-flex align-items-center justify-content-between mb-3"><div><h2 class="h5 mb-1">Duplicates (<?= count($duplicates) ?>)</h2><p class="text-muted small mb-0">Uploaded copies are isolated here; originals are never overwritten.</p></div><a class="btn btn-outline-primary btn-sm" href="<?= esc(site_url('workspace/file-management')) ?>">Back to files</a></div>
            <div class="table-responsive"><table class="table align-middle"><thead><tr><th>Uploaded copy</th><th>Original match</th><th>Uploaded by</th><th>Date</th><th>Status</th></tr></thead><tbody>
            <?php foreach ($duplicates as $duplicate): ?><tr><td><strong><?= esc($duplicate['display_title'] ?: 'Duplicate media upload') ?></strong><div class="small text-muted"><?= esc(format_bytes((int) $duplicate['size'])) ?></div></td><td><?= esc($duplicate['original_display_title'] ?: 'Media #' . $duplicate['original_file_id']) ?></td><td><?= esc($duplicate['uploader_name'] ?: 'Unknown user') ?></td><td><?= esc(date('M j, Y g:i A', strtotime((string) $duplicate['created_at']))) ?></td><td><span class="badge bg-warning-subtle text-warning-emphasis"><?= esc(ucfirst((string) $duplicate['status'])) ?></span></td></tr><?php endforeach; ?>
            <?php if ($duplicates === []): ?><tr><td colspan="5" class="text-center text-muted py-5">No duplicate uploads are waiting for review.</td></tr><?php endif; ?>
            </tbody></table></div>
        </div></div>
    </section>
</main>
<?= view('layouts/app_footer') ?>
