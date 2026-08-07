<?= view('layouts/app_header', ['user' => $user, 'activePage' => $activePage, 'title' => $title]) ?>
<?php $previewableImageTypes = ['jpg', 'jpeg', 'png', 'gif', 'webp']; ?>
<main id="main" class="main">
    <div class="pagetitle d-flex align-items-start justify-content-between flex-wrap gap-2">
        <div>
            <h1>iFound AI</h1>
            <nav>
                <ol class="breadcrumb">
                    <li class="breadcrumb-item"><a href="<?= esc(site_url('dashboard')) ?>">Dashboard</a></li>
                    <li class="breadcrumb-item active">iFound</li>
                </ol>
            </nav>
        </div>
        <a class="btn btn-outline-primary btn-sm" href="<?= esc(site_url('workspace/file-management')) ?>">
            <i class="bi bi-folder2-open me-1"></i> File Management
        </a>
    </div>
    <section class="section">
        <div class="card">
            <div class="card-body">
                <h5 class="card-title">University Event Search</h5>
                <p class="text-muted">Search file metadata and event classifications for Dal-uy, Kasadyaan, EXPO, and other university media.</p>
                <form class="row g-2 mb-3" method="get">
                    <div class="col-md-9">
                        <label class="visually-hidden" for="ifoundSearch">Search event, file, tag, or classification</label>
                        <input class="form-control" id="ifoundSearch" name="q" value="<?= esc($term) ?>" placeholder="e.g. Kasadyaan, EXPO, person, graduation">
                    </div>
                    <div class="col-md-3 d-grid">
                        <button class="btn btn-primary" type="submit"><i class="bi bi-search me-1"></i> Search</button>
                    </div>
                </form>
                <div class="table-responsive">
                    <table class="table table-hover align-middle">
                        <thead class="table-light">
                            <tr>
                                <th>File</th>
                                <th>Detected event</th>
                                <th>Confidence</th>
                                <th>Classification</th>
                                <th>Review</th>
                                <th class="text-end">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($results as $result): ?>
                                <?php $isPreviewableImage = in_array(strtolower((string) $result['filetype']), $previewableImageTypes, true); ?>
                                <tr>
                                    <td><?= esc(\App\Libraries\MediaTitle::forFile($result)) ?></td>
                                    <td><?= esc($result['event_name'] ?: 'Not classified') ?></td>
                                    <td><?= $result['confidence_score'] === null ? '-' : esc(number_format((float) $result['confidence_score'], 2)) . '%' ?></td>
                                    <td><?= esc($result['classification'] ?: '-') ?></td>
                                    <td>
                                        <?php if ((int) ($result['needs_review'] ?? 1) === 1): ?>
                                            <span class="badge bg-warning text-dark">Needs review</span>
                                        <?php else: ?>
                                            <span class="badge bg-success">Ready</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-end text-nowrap">
                                        <?php if ($isPreviewableImage): ?>
                                            <a class="btn btn-outline-secondary btn-sm" href="<?= esc(site_url('workspace/file-management/' . (int) $result['id'] . '/preview')) ?>" target="_blank" rel="noopener" aria-label="Preview <?= esc(\App\Libraries\MediaTitle::forFile($result)) ?>">
                                                <i class="bi bi-eye me-1"></i> Preview
                                            </a>
                                        <?php endif; ?>
                                        <a class="btn btn-outline-primary btn-sm" href="<?= esc(site_url('workspace/file-management/' . (int) $result['id'] . '/download')) ?>" aria-label="Download <?= esc(\App\Libraries\MediaTitle::forFile($result)) ?>">
                                            <i class="bi bi-download me-1"></i> Download
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            <?php if ($results === []): ?>
                                <tr><td colspan="6" class="text-center text-muted py-4">No AI-indexed media found.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </section>
</main>
<?= view('layouts/app_footer') ?>
