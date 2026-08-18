<?= view('layouts/app_header', ['user' => $user, 'activePage' => $activePage, 'title' => $title]) ?>
<?php
$previewableImageTypes = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
$eventOptions = array_keys((new \Config\GreyhoundAi())->eventKeywords);
?>
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
                <form class="row g-2 mb-3" method="get" data-ajax-live-search>
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
                                        <?php if ((int) ($result['needs_review'] ?? 1) === 1): ?>
                                            <button
                                                class="btn btn-outline-warning btn-sm ifound-review-button"
                                                type="button"
                                                data-bs-toggle="modal"
                                                data-bs-target="#ifoundReviewModal"
                                                data-file-id="<?= (int) $result['id'] ?>"
                                                data-file-name="<?= esc(\App\Libraries\MediaTitle::forFile($result), 'attr') ?>"
                                                data-event-name="<?= esc((string) ($result['event_name'] ?? ''), 'attr') ?>"
                                                data-confidence="<?= $result['confidence_score'] === null ? 'No confidence score' : esc(number_format((float) $result['confidence_score'], 2) . '%', 'attr') ?>">
                                                <i class="bi bi-check2-square me-1"></i> Review
                                            </button>
                                        <?php endif; ?>
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
<div class="modal fade" id="ifoundReviewModal" tabindex="-1" aria-labelledby="ifoundReviewModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form class="modal-content" method="post" id="ifoundReviewForm">
            <?= csrf_field() ?>
            <input type="hidden" name="return_q" value="<?= esc($term) ?>">
            <div class="modal-header">
                <div>
                    <h5 class="modal-title" id="ifoundReviewModalLabel">Review AI Classification</h5>
                    <p class="small text-muted mb-0" id="ifoundReviewFile"></p>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="rounded border bg-light p-3 mb-3">
                    <div class="small text-muted">AI suggestion</div>
                    <div class="fw-semibold" id="ifoundReviewSuggestion">Not classified</div>
                    <div class="small text-muted mt-1" id="ifoundReviewConfidence"></div>
                </div>
                <div class="mb-3">
                    <label class="form-label" for="ifoundReviewEvent">Final classification</label>
                    <select class="form-select" id="ifoundReviewEvent" name="event_name">
                        <option value="">Unclassified university media</option>
                        <?php foreach ($eventOptions as $eventOption): ?>
                            <option value="<?= esc($eventOption) ?>"><?= esc($eventOption) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="form-label" for="ifoundReviewNote">Review note <span class="text-muted">(optional)</span></label>
                    <textarea class="form-control" id="ifoundReviewNote" name="review_note" rows="3" maxlength="500" placeholder="Why was this classification approved or corrected?"></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-primary"><i class="bi bi-check2 me-1"></i> Save review</button>
            </div>
        </form>
    </div>
</div>
<script>
document.querySelectorAll('.ifound-review-button').forEach(function (button) {
    button.addEventListener('click', function () {
        var form = document.getElementById('ifoundReviewForm');
        var eventSelect = document.getElementById('ifoundReviewEvent');
        var eventName = button.dataset.eventName || '';
        form.action = <?= json_encode(site_url('workspace/ifound')) ?> + '/' + button.dataset.fileId + '/review';
        document.getElementById('ifoundReviewFile').textContent = button.dataset.fileName;
        document.getElementById('ifoundReviewSuggestion').textContent = eventName || 'Not classified';
        document.getElementById('ifoundReviewConfidence').textContent = button.dataset.confidence;
        eventSelect.value = Array.prototype.some.call(eventSelect.options, function (option) {
            return option.value === eventName;
        }) ? eventName : '';
        document.getElementById('ifoundReviewNote').value = '';
    });
});
</script>
<?= view('layouts/app_footer') ?>
