<?= view('layouts/app_header', ['user' => $user, 'activePage' => $activePage, 'title' => $title]) ?>
<style>
.downloads-page .download-thumb{align-items:center;background:#eef3fb;border-radius:8px;display:flex;height:54px;justify-content:center;overflow:hidden;width:72px}.downloads-page .download-thumb img{height:100%;object-fit:cover;width:100%}
</style>
<main id="main" class="main downloads-page">
    <div class="pagetitle">
        <h1>Downloads</h1>
        <nav><ol class="breadcrumb"><li class="breadcrumb-item"><a href="<?= esc(site_url('dashboard')) ?>">Dashboard</a></li><li class="breadcrumb-item active">Downloads</li></ol></nav>
    </div>
    <section class="section">
        <div class="card">
            <div class="card-body pt-3">
                <div class="table-responsive">
                    <table class="table align-middle">
                        <thead><tr><th>Media</th><th>Assigned</th><th class="text-end">Action</th></tr></thead>
                        <tbody>
                        <?php foreach ($files as $file): ?>
                            <?php
                                $fileId = (int) $file['id'];
                                $mime = (string) ($file['mime_type'] ?? '');
                                $type = strtolower((string) ($file['filetype'] ?? ''));
                                $isImage = str_starts_with($mime, 'image/') || in_array($type, ['jpg', 'jpeg', 'png', 'gif', 'webp'], true);
                                $title = \App\Libraries\MediaTitle::forFile($file);
                            ?>
                            <tr>
                                <td>
                                    <div class="d-flex align-items-center gap-3">
                                        <span class="download-thumb"><?php if ($isImage): ?><img src="<?= esc(site_url('workspace/file-management/' . $fileId . '/preview')) ?>" alt=""><?php else: ?><i class="bi bi-file-earmark"></i><?php endif; ?></span>
                                        <span><strong class="d-block"><?= esc($title) ?></strong><span class="small text-muted"><?= esc((string) ($file['filename'] ?? 'Media file')) ?></span></span>
                                    </div>
                                </td>
                                <td><?= esc(! empty($file['assigned_at']) ? date('M j, Y g:i A', strtotime((string) $file['assigned_at'])) : '-') ?></td>
                                <td class="text-end"><a class="btn btn-primary btn-sm" href="<?= esc(site_url('workspace/file-management/' . $fileId . '/download')) ?>"><i class="bi bi-download me-1"></i>Download</a></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if ($files === []): ?><tr><td colspan="3" class="text-center text-muted py-5">No downloadable authorized files yet.</td></tr><?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </section>
</main>
<?= view('layouts/app_footer') ?>
