<?= view('layouts/app_header', ['user' => $user, 'activePage' => $activePage, 'title' => $title]) ?>
<?php
$previewableImageTypes = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
$eventOptions = array_keys((new \Config\GreyhoundAi())->eventKeywords);
$pagination = $pagination ?? ['current_page' => 1, 'total_pages' => 1, 'total_results' => count($results)];
$aiResult = $aiResult ?? '';
$aiResultOptions = ['' => 'All AI results', 'needs_review' => 'Needs review', 'different' => 'Different', 'dominant' => 'Dominant', 'reviewed' => 'Reviewed', 'not_applicable' => 'Not applicable'];
$ifoundPageUrl = static function (int $page) use ($term, $aiResult): string {
    $parameters = array_filter(['q' => $term, 'ai_result' => $aiResult, 'page' => $page > 1 ? $page : null], static fn ($value): bool => $value !== null && $value !== '');
    return site_url('workspace/ifound') . ($parameters === [] ? '' : '?' . http_build_query($parameters));
};
?>
<style>
    .ifound-result-filter .dropdown-toggle { align-items: center; background-color: #fff; display: flex; justify-content: space-between; text-align: left; width: 100%; }
    .ifound-result-filter .dropdown-menu { border: 1px solid #e3e8f2; border-radius: 10px; box-shadow: 0 8px 24px rgba(30, 48, 80, .12); min-width: 100%; padding: .35rem; }
    .ifound-result-filter .dropdown-item { align-items: center; border-radius: 6px; display: flex; gap: .65rem; padding: .55rem .7rem; }
    .ifound-result-filter .dropdown-item.active { background: #edf2ff; color: #3159b7; font-weight: 600; }
    .ifound-result-filter .dropdown-item:hover, .ifound-result-filter .dropdown-item:focus { background: #f0f4ff; color: #3159b7; }
    .ifound-result-filter .result-check { width: 1rem; }
    .ifound-toolbar { align-items: center; background: #f8faff; border: 1px solid #e3e8f2; border-radius: 12px; display: flex; flex-wrap: wrap; gap: .65rem; justify-content: space-between; margin-bottom: 1rem; padding: .75rem 1rem; }
    .ifound-selection-summary { align-items: center; display: flex; flex-wrap: wrap; gap: .65rem; }
    .ifound-selected-count { background: #e9efff; border-radius: 999px; color: #3159b7; font-size: .78rem; font-weight: 700; padding: .4rem .7rem; }
    .ifound-preview { align-items: center; background: #f1f5ff; border: 0; border-radius: 9px; color: #4154f1; display: inline-flex; height: 52px; justify-content: center; overflow: hidden; padding: 0; width: 52px; }
    .ifound-preview img { height: 52px; object-fit: cover; transition: transform .15s ease; width: 52px; }
    .ifound-preview:hover img, .ifound-preview:focus-visible img { transform: scale(1.08); }
    .ifound-video-thumbnail { position: relative; }
    .ifound-video-thumbnail .ifound-video-badge { position: absolute; right: 2px; bottom: 2px; border-radius: 3px; padding: 0 3px; background: rgba(0, 0, 0, .7); color: #fff; font-size: .75rem; }
    .ifound-preview-cell { align-items: center; display: flex; gap: .65rem; }
    .ifound-review-dialog { max-width: min(1480px, calc(100vw - 2rem)); }
    .ifound-review-content { border: 0; border-radius: 18px; box-shadow: 0 24px 70px rgba(16, 24, 40, .22); max-height: calc(100vh - 2rem); overflow: hidden; }
    .ifound-review-header { align-items: center; display: flex; gap: 1rem; padding: 1.4rem 1.75rem; }
    .ifound-review-heading { min-width: 0; }
    .ifound-review-heading .modal-title { font-size: 1.6rem; font-weight: 750; }
    .ifound-review-pager { align-items: center; display: flex; justify-content: center; margin-top: 1rem; }
    .ifound-review-pager .btn { border-radius: 0; min-width: 44px; }
    .ifound-review-pager .btn:first-child { border-radius: 8px 0 0 8px; }
    .ifound-review-pager .btn:last-child { border-radius: 0 8px 8px 0; }
    .ifound-review-pager .btn + .btn { margin-left: -1px; }
    .ifound-close { align-items: center; background: transparent; border: 0; border-radius: 50%; color: #111827; display: inline-flex; flex: 0 0 auto; font-size: 1.55rem; height: 44px; justify-content: center; margin-left: auto; transition: background .15s ease, transform .15s ease; width: 44px; }
    .ifound-close:hover, .ifound-close:focus-visible { background: #eef2f7; outline: 0; transform: rotate(90deg); }
    .ifound-review-body { display: grid; grid-template-columns: minmax(0, 1fr) minmax(0, 1.05fr); min-height: 0; overflow-y: auto; padding: 0; }
    .ifound-review-images { border-right: 1px solid #e5e9f0; overflow-y: auto; padding: 1.5rem 1.75rem; }
    .ifound-review-images h6 { font-size: 1rem; font-weight: 700; margin-bottom: 1rem; }
    .ifound-review-image-card { border: 2px solid transparent; border-radius: 14px; margin-bottom: 1rem; overflow: hidden; position: relative; transition: border-color .15s ease, box-shadow .15s ease; }
    .ifound-review-image-card.is-current { border-color: #2457f5; box-shadow: 0 0 0 3px rgba(36, 87, 245, .12); }
    .ifound-review-image-card img { aspect-ratio: 16 / 7; display: block; object-fit: contain; width: 100%; background: #f1f5ff; }
    .ifound-image-number { background: #2457f5; border-radius: 0 9px 0 0; bottom: 0; color: #fff; font-size: .8rem; font-weight: 700; left: 0; padding: .35rem .65rem; position: absolute; }
    .ifound-remove-image { align-items: center; background: rgba(255,255,255,.94); border: 1px solid #d5dbe6; border-radius: 50%; box-shadow: 0 3px 10px rgba(15,23,42,.18); color: #111827; display: inline-flex; font-size: 1.25rem; height: 38px; justify-content: center; position: absolute; right: .65rem; top: .65rem; transition: background .15s ease, color .15s ease, transform .15s ease; width: 38px; }
    .ifound-remove-image:hover, .ifound-remove-image:focus-visible { background: #dc3545; color: #fff; outline: 0; transform: scale(1.06); }
    .ifound-review-editor { display: flex; flex-direction: column; min-width: 0; }
    .ifound-review-fields { flex: 1; overflow-y: auto; padding: 2.2rem 2.4rem; }
    .ifound-ai-suggestion { background: #fbfcff; border: 1px solid #dfe4ed; border-radius: 12px; margin-bottom: 1.8rem; padding: 1.25rem 1.4rem; }
    .ifound-ai-label { color: #7b879d; font-size: .8rem; font-weight: 650; }
    .ifound-ai-value { color: #111827; font-size: 1.25rem; font-weight: 750; margin-top: .7rem; }
    .ifound-ai-confidence { color: #175df5; font-size: 1.05rem; font-weight: 650; margin-top: .2rem; }
    .ifound-review-footer { border-top: 1px solid #e5e9f0; display: flex; flex-wrap: wrap; gap: .75rem; justify-content: flex-end; padding: 1.25rem 2rem; }
    .ifound-existing-tags { display: flex; flex-wrap: wrap; gap: .35rem; margin-top: .5rem; }
    .ifound-existing-tags .badge { background: #e9efff; color: #344b80; white-space: normal; overflow-wrap: anywhere; text-align: left; }
    .ifound-existing-tags button { border: 0; background: transparent; color: inherit; padding: 0 .15rem 0 .4rem; }
    @media (max-width: 991.98px) { .ifound-review-body { grid-template-columns: 1fr; } .ifound-review-images { border-bottom: 1px solid #e5e9f0; border-right: 0; max-height: 360px; } }
    @media (max-width: 575.98px) {
        .ifound-review-header, .ifound-review-images, .ifound-review-fields, .ifound-review-footer { padding: 1rem; }
        .ifound-review-heading .modal-title { font-size: 1.15rem; }
        .ifound-review-pager { flex-wrap: wrap; row-gap: .5rem; }
        .ifound-ai-suggestion { padding: 1rem; }
    }
    .ifound-scan-location { min-width: 0; overflow-wrap: anywhere; }
</style>
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
    <?php if (!empty($reviewSuccess)): ?><div class="alert alert-success" role="status"><?= esc($reviewSuccess) ?></div><?php endif; ?>
    <?php if (!empty($reviewError)): ?><div class="alert alert-danger" role="alert"><?= esc($reviewError) ?></div><?php endif; ?>
    <section class="section">
        <div class="card mb-3">
            <div class="card-body">
                <div class="d-flex align-items-start justify-content-between flex-wrap gap-3">
                    <div>
                        <h5 class="card-title pb-1">Server File Scanner</h5>
                        <p class="text-muted mb-1">Choose images from your server folders and scan them with iFound AI.</p>
                        <div class="small text-muted ifound-scan-location">
                            Inbox: <code><?= esc($scanSource) ?></code><br>
                            <?php if (! empty($scannerEndpoint)): ?>Network source: <code><?= esc($scannerEndpoint) ?></code><br><?php endif; ?>
                            Destination: <strong><?= esc($scanDestination) ?></strong>
                        </div>
                    </div>
                    <form id="ifoundScanForm" method="post" action="<?= esc(site_url('workspace/ifound/scan/start')) ?>" data-step-url="<?= esc(site_url('workspace/ifound/scan/step')) ?>" data-token-url="<?= esc(site_url('workspace/ifound/scan/token')) ?>" data-ajax="false">
                        <?= csrf_field() ?>
                        <button class="btn btn-primary" id="ifoundScanButton" type="button" data-bs-toggle="modal" data-bs-target="#ifoundScanPicker">
                            <i class="bi bi-search me-1"></i> Scan Files
                        </button>
                    </form>
                </div>
                <div class="mt-3 d-none" id="ifoundScanProgress" aria-label="iFound scan progress">
                    <div class="d-flex align-items-center justify-content-between mb-2"><strong><i class="bi bi-search me-2"></i>iFound scan</strong><div><button id="ifoundScanCollapse" type="button" class="btn btn-sm btn-light" aria-label="Minimize scan progress" aria-expanded="true"><i class="bi bi-chevron-down"></i></button><button id="ifoundScanDismiss" type="button" class="btn btn-sm btn-light" aria-label="Dismiss scan progress" hidden>×</button></div></div>
                    <div data-scan-detail>
                    <div class="d-flex justify-content-between gap-2 small mb-1">
                        <span id="ifoundScanLabel" role="status" aria-live="polite">Preparing server scan...</span>
                        <span class="text-nowrap" id="ifoundScanCount">0 of 0</span>
                    </div>
                    <div class="progress" id="ifoundScanProgressTrack" style="height: 12px;">
                        <div class="progress-bar progress-bar-striped progress-bar-animated" id="ifoundScanProgressBar" role="progressbar" style="width: 0%" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0"></div>
                    </div>
                    <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mt-2">
                        <span class="small text-muted" id="ifoundScanSummary">Waiting to begin.</span>
                        <a class="btn btn-outline-primary btn-sm d-none" id="ifoundScannedFolderLink" href="#">
                            <i class="bi bi-folder2-open me-1"></i> View Scanned Folder
                        </a>
                    </div>
                    <div id="ifoundScanFileList"></div>
                    <a id="ifoundScanResults" class="btn btn-outline-primary btn-sm mt-2 d-none" href="<?= esc(site_url('workspace/ifound')) ?>">View iFound results</a>
                    </div>
                    <div class="alert alert-danger py-2 px-3 mt-2 mb-0 d-none" id="ifoundScanErrors" role="alert" data-auto-dismiss="hide"></div>
                </div>
            </div>
        </div>
        <div class="card">
            <div class="card-body">
                <h5 class="card-title">University Media Search</h5>
                <p class="text-muted">Search filenames, objects, facial-expression labels, tags, and event classifications. Press Enter or click Search to see results.</p>
                <div class="alert alert-light border small mb-3">
                    <strong>How images are grouped</strong>
                    <div class="d-flex flex-wrap gap-3 mt-2">
                        <span><span class="badge bg-warning text-dark me-1">Needs review</span> Below <?= (int) ($reviewThreshold ?? 80) ?>% confidence</span>
                        <span><span class="badge bg-primary me-1">Different</span> At least <?= (int) ($reviewThreshold ?? 80) ?>% and predicts another event</span>
                        <span><span class="badge bg-success me-1">Dominant</span> At least <?= (int) ($reviewThreshold ?? 80) ?>% and matches the folder's dominant event</span>
                    </div>
                    <div class="text-muted mt-2">The dominant event is the most common confident AI prediction among images in the same folder. Ties, missing predictions, and groups without a dominant event need review.</div>
                    <div class="text-muted mt-1">After you save a review, the AI result is marked Reviewed. The final classification, original AI prediction, and confidence stay visible for reference.</div>
                </div>
                <form class="row g-2 mb-3" method="get" action="<?= esc(site_url('workspace/ifound')) ?>" data-ajax="true" data-ajax-preserve-scroll>
                    <div class="col-md-6">
                        <label class="visually-hidden" for="ifoundSearch">Search filename, event, tag, or classification</label>
                        <div class="search-reset-field" data-search-reset>
                            <input class="form-control" type="search" id="ifoundSearch" data-search-suggestions="<?= esc(site_url('workspace/search-suggestions/ifound')) ?>" name="q" value="<?= esc($term) ?>" placeholder="Search filename, event, tag, or classification">
                            <a class="search-reset-clear" data-search-clear data-ajax-preserve-scroll href="<?= esc(site_url('workspace/ifound')) ?>" aria-label="Clear search and show all files" title="Clear search" <?= $term === '' ? 'hidden' : '' ?>>&times;</a>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <input type="hidden" name="ai_result" value="<?= esc($aiResult) ?>">
                        <div class="dropdown ifound-result-filter">
                            <button class="form-control dropdown-toggle" id="ifoundAiResult" type="button" data-bs-toggle="dropdown" aria-expanded="false" aria-label="<?= esc('AI result: ' . ($aiResultOptions[$aiResult] ?? 'All AI results')) ?>">
                                <?= esc($aiResultOptions[$aiResult] ?? 'All AI results') ?>
                            </button>
                            <ul class="dropdown-menu" aria-labelledby="ifoundAiResult">
                                <?php foreach ($aiResultOptions as $value => $label): ?>
                                    <li><a class="dropdown-item<?= $aiResult === $value ? ' active' : '' ?>" href="<?= esc(site_url('workspace/ifound') . '?' . http_build_query(['q' => $term, 'ai_result' => $value])) ?>" data-ajax-preserve-scroll <?= $aiResult === $value ? 'aria-current="true"' : '' ?>><i class="bi <?= $aiResult === $value ? 'bi-check2' : '' ?> result-check" aria-hidden="true"></i><?= esc($label) ?></a></li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    </div>
                    <div class="col-md-3 d-grid">
                        <button class="btn btn-primary" type="submit"><i class="bi bi-search me-1"></i> Search</button>
                    </div>
                </form>
                <div class="ifound-toolbar">
                    <div class="ifound-selection-summary">
                        <button class="btn btn-outline-primary btn-sm" id="ifoundSelectAll" type="button">Select all</button>
                        <span class="ifound-selected-count d-none" id="ifoundSelectedCount" aria-live="polite">0 selected</span>
                    </div>
                    <div class="d-flex align-items-center gap-2 ms-auto">
                        <button class="btn btn-primary btn-sm d-none" id="ifoundAnalyzeSelected" type="button" disabled><i class="bi bi-search me-1"></i>Analyze images</button>
                        <button class="btn btn-primary btn-sm d-none" id="ifoundReviewSelected" type="button" data-bs-toggle="modal" data-bs-target="#ifoundReviewModal" disabled>
                            <i class="bi bi-check2-square me-1"></i>Review images
                        </button>
                    </div>
                </div>
                <p class="small text-muted">Select images to detect objects, estimate facial expressions, and add tags. Event reviews are preserved.</p>
                <div id="ifoundVisualStatus" class="small my-2" role="status" aria-live="polite"></div>
                <div id="ifoundAnalysisStatus" class="small my-2" role="status" aria-live="polite"></div>
                <div class="table-responsive">
                    <table class="table table-hover align-middle">
                        <thead class="table-light">
                            <tr>
                                <th>Preview</th>
                                <th>File</th>
                                <th>AI prediction</th>
                                <th>Confidence</th>
                                <th>Final classification</th>
                                <th>Folder dominant event</th>
                                <th>AI result</th>
                                <th>Objects &amp; expressions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($results as $result): ?>
                                <?php $isPreviewableImage = in_array(strtolower((string) $result['filetype']), $previewableImageTypes, true); $isVideo = \App\Libraries\MediaTagPolicy::isVideo($result); $mediaTitle = \App\Libraries\MediaTitle::forFile($result); ?>
                                <tr data-ifound-item data-file-id="<?= (int) $result['id'] ?>" data-file-name="<?= esc($mediaTitle, 'attr') ?>" data-tags="<?= esc(\App\Libraries\MediaTagPolicy::clean((string) ($result['tags'] ?? ''), $result), 'attr') ?>" data-event-name="<?= esc((string) ($result['ai_prediction'] ?? ''), 'attr') ?>" data-final-event-name="<?= esc((string) ($result['event_name'] ?? ''), 'attr') ?>" data-confidence="<?= $result['confidence_score'] === null ? 'No confidence score' : esc(number_format((float) $result['confidence_score'], 2) . '%', 'attr') ?>" data-preview-src="<?= $isPreviewableImage ? esc(site_url('workspace/file-management/' . (int) $result['id'] . '/preview'), 'attr') : '' ?>">
                                    <td>
                                        <div class="ifound-preview-cell">
                                            <input class="form-check-input" type="checkbox" data-ifound-select aria-label="Select <?= esc($mediaTitle) ?>" <?= $isPreviewableImage ? '' : 'disabled' ?>>
                                            <?php if ($isPreviewableImage): ?>
                                                <button class="ifound-preview" type="button" data-bs-toggle="modal" data-bs-target="#ifoundImagePreviewModal" aria-label="Preview <?= esc($mediaTitle) ?>"><img src="<?= esc(site_url('workspace/file-management/' . (int) $result['id'] . '/preview') . '?thumbnail=1') ?>" alt="Preview of <?= esc($mediaTitle) ?>" loading="lazy" decoding="async" onerror="this.hidden = true; this.nextElementSibling.hidden = false; this.parentElement.title = 'Image unavailable on storage';"><i class="bi bi-image fs-4" aria-hidden="true" hidden></i></button>
                                            <?php elseif ($isVideo): ?>
                                                <span class="ifound-preview ifound-video-thumbnail" title="<?= esc('Video: ' . $mediaTitle, 'attr') ?>"><img src="<?= esc(site_url('workspace/file-management/' . (int) $result['id'] . '/preview') . '?thumbnail=1') ?>" alt="Video thumbnail of <?= esc($mediaTitle) ?>" loading="lazy" decoding="async" onerror="this.hidden = true;"><i class="bi bi-camera-video-fill ifound-video-badge" aria-hidden="true"></i></span>
                                            <?php else: ?>
                                                <span class="ifound-preview"><i class="bi bi-file-earmark fs-4"></i></span>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <td><?= esc($mediaTitle) ?><div class="small text-muted"><?= esc($result['folder_name'] ?? 'Root library') ?></div></td>
                                    <td><?= esc($result['ai_prediction'] ?? 'Not classified') ?></td>
                                    <td><?= $result['confidence_score'] === null ? '-' : esc(number_format((float) $result['confidence_score'], 2)) . '%' ?></td>
                                    <td><?= esc($result['classification'] ?: '-') ?></td>
                                    <td><?= esc($result['dominant_event'] ?? (! empty($result['dominant_tied']) ? 'Tied — needs review' : 'Not established')) ?></td>
                                    <td>
                                        <?php if (($result['ai_result'] ?? '') === 'dominant'): ?>
                                            <span class="badge bg-success">Dominant</span>
                                        <?php elseif (($result['ai_result'] ?? '') === 'different'): ?>
                                            <span class="badge bg-primary">Different</span>
                                        <?php elseif (($result['ai_result'] ?? '') === 'reviewed'): ?>
                                            <span class="badge bg-success">Reviewed</span>
                                        <?php elseif (($result['ai_result'] ?? '') === 'not_applicable'): ?>
                                            <span class="text-muted">Images only</span>
                                        <?php else: ?>
                                            <span class="badge bg-warning text-dark">Needs review</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?= view('workspace/ifound_visual_result', ['analysis'=>$visualResults[$result['id']] ?? null]) ?></td>
                                </tr>
                            <?php endforeach; ?>
                            <?php if ($results === []): ?>
                                <tr><td colspan="8" class="text-center text-muted py-4">No AI-indexed media found.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                <?= view('components/pagination', ['pagination' => $pagination, 'pageUrl' => $ifoundPageUrl, 'paginationLabel' => 'iFound result pages']) ?>
            </div>
        </div>
    </section>
</main>
<div class="modal fade" id="ifoundReviewModal" tabindex="-1" aria-labelledby="ifoundReviewModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered ifound-review-dialog">
        <form class="modal-content ifound-review-content" method="post" id="ifoundReviewForm" action="<?= esc(site_url('workspace/ifound/review')) ?>">
            <?= csrf_field() ?>
            <input type="hidden" name="return_q" value="<?= esc($term) ?>">
            <div id="ifoundReviewFileInputs"></div>
            <div class="modal-header ifound-review-header">
                <div class="ifound-review-heading">
                    <h5 class="modal-title" id="ifoundReviewModalLabel">Review AI Classification</h5>
                    <p class="text-muted mb-0 text-truncate" id="ifoundReviewFile"></p>
                </div>
                <button class="ifound-close" type="button" data-bs-dismiss="modal" aria-label="Close review"><i class="bi bi-x-lg"></i></button>
            </div>
            <div class="modal-body ifound-review-body">
                <section class="ifound-review-images" aria-labelledby="ifoundSelectedImagesTitle">
                    <h6 id="ifoundSelectedImagesTitle">Selected images</h6>
                    <p class="small text-muted">Select an image to view its tags and add details.</p>
                    <div id="ifoundReviewImages"></div>
                    <div class="ifound-review-pager" aria-label="Selected image pages">
                        <button class="btn btn-outline-secondary" id="ifoundPrevious" type="button"><i class="bi bi-chevron-left me-1"></i>Previous</button>
                        <button class="btn btn-primary" id="ifoundPageNumber" type="button" disabled>1 / 1</button>
                        <button class="btn btn-outline-primary" id="ifoundNext" type="button">Next<i class="bi bi-chevron-right ms-1"></i></button>
                    </div>
                </section>
                <section class="ifound-review-editor">
                    <div class="ifound-review-fields">
                        <div class="ifound-ai-suggestion">
                            <div class="ifound-ai-label"><i class="bi bi-stars me-1"></i>AI suggestion</div>
                            <div class="ifound-ai-value" id="ifoundReviewSuggestion">Not classified</div>
                            <div class="ifound-ai-confidence" id="ifoundReviewConfidence"></div>
                        </div>
                        <div class="mb-4">
                            <label class="form-label fw-semibold" for="ifoundReviewEvent">Final classification</label>
                            <select class="form-select form-select-lg" id="ifoundReviewEvent" name="event_name">
                                <option value="">Unclassified university media</option>
                                <?php foreach ($eventOptions as $eventOption): ?>
                                    <option value="<?= esc($eventOption) ?>"><?= esc($eventOption) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <div class="form-text">This classification will be applied to every selected image.</div>
                        </div>
                        <div class="ifound-review-tags mb-4">
                            <label class="form-label" for="ifoundReviewTags">Tags</label>
                            <div class="small text-muted mb-2 text-break" id="ifoundTagImageName"></div>
                            <input class="form-control" id="ifoundReviewTags" type="text" maxlength="2000" placeholder="Enter a tag and press Enter" aria-describedby="ifoundTagsHelp" autocomplete="off">
                            <div class="ifound-existing-tags" id="ifoundExistingTags"></div>
                            <div class="form-text mt-3" id="ifoundTagsHelp">Press Enter or comma to add. Tags are trimmed, limited to 50 characters, and duplicates are ignored.</div>
                            <div class="form-text">Add up to 25 tags to this image. Changes are saved with your review.</div>
                        </div>
                        <div>
                            <label class="form-label fw-semibold" for="ifoundReviewNote">Review note <span class="text-muted fw-normal">(optional)</span></label>
                            <textarea class="form-control" id="ifoundReviewNote" name="review_note" rows="3" maxlength="500" placeholder="Why was this classification approved or corrected?"></textarea>
                        </div>
                    </div>
                    <div id="ifoundReviewStatus" class="mx-3" role="alert" hidden></div>
                    <div class="ifound-review-footer">
                        <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary" id="ifoundSaveReview"><i class="bi bi-check2 me-1"></i>Save review</button>
                    </div>
                </section>
            </div>
        </form>
    </div>
</div>
<div class="modal fade" id="ifoundImagePreviewModal" tabindex="-1" aria-labelledby="ifoundImagePreviewTitle" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-centered"><div class="modal-content"><div class="modal-header"><h5 class="modal-title" id="ifoundImagePreviewTitle">Image preview</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div><div class="modal-body bg-dark p-0 text-center"><img id="ifoundImagePreview" src="" alt="" style="max-height:calc(100vh - 190px);max-width:100%;object-fit:contain"></div></div></div>
</div>
<?= view('workspace/ifound_scan_picker') ?>
<script>
(function initIFoundScan() {
    var form = document.getElementById('ifoundScanForm');
    if (!form || !window.fetch || !window.FormData) { return; }

    var button = document.getElementById('ifoundScanButton');
    if (window.greyhoundIFoundScanBusy) { button.disabled = true; window.greyhoundIFoundScanRebind = function () { button.disabled = false; initIFoundScan(); }; return; }
    var progress = document.getElementById('ifoundScanProgress');
    var progressBar = document.getElementById('ifoundScanProgressBar');
    var progressTrack = document.getElementById('ifoundScanProgressTrack');
    var label = document.getElementById('ifoundScanLabel');
    var count = document.getElementById('ifoundScanCount');
    var summary = document.getElementById('ifoundScanSummary');
    var errors = document.getElementById('ifoundScanErrors');
    var folderLink = document.getElementById('ifoundScannedFolderLink');
    var picker = document.getElementById('ifoundScanPicker');
    var rows = new Map(), analysisIds = [], busy = false;
    var collapse = document.getElementById('ifoundScanCollapse');
    var dismiss = document.getElementById('ifoundScanDismiss');
    var resultsLink = document.getElementById('ifoundScanResults');
    collapse.onclick = function () { var small = progress.classList.toggle('is-collapsed'); collapse.setAttribute('aria-expanded', String(!small)); collapse.setAttribute('aria-label', small ? 'Expand scan progress' : 'Minimize scan progress'); };
    dismiss.onclick = function () { progress.classList.add('d-none'); };
    var csrfInput = form.querySelector('input[name="<?= esc(csrf_token()) ?>"]');
    var csrfToken = csrfInput ? csrfInput.value : '';

    var refreshToken = async function () {
        var separator = form.dataset.tokenUrl.indexOf('?') === -1 ? '?' : '&';
        var response = await fetch(form.dataset.tokenUrl + separator + '_=' + Date.now(), {
            method: 'GET',
            credentials: 'same-origin',
            cache: 'no-store',
            headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
        });
        var payload = await response.json();
        if (!response.ok || !payload.ok || !payload.csrfToken) {
            throw new Error(payload.message || 'The security token could not be refreshed.');
        }
        csrfToken = payload.csrfToken;
        if (csrfInput) {
            if (payload.csrfTokenName) { csrfInput.name = payload.csrfTokenName; }
            csrfInput.value = csrfToken;
        }
    };

    var send = async function (url, values, mayRetry) {
        var data = new FormData();
        Object.keys(values || {}).forEach(function (key) { if (Array.isArray(values[key])) values[key].forEach(function (value) { data.append(key + '[]', value); }); else data.append(key, values[key]); });
        if (csrfInput) { data.append(csrfInput.name, csrfToken); }
        var response = await fetch(url, {
            method: 'POST',
            body: data,
            credentials: 'same-origin',
            headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
        });
        var payload;
        try {
            payload = await response.json();
        } catch (parseError) {
            throw new Error('The server returned an unreadable scan response.');
        }
        if (payload.csrfToken && csrfInput) {
            csrfToken = payload.csrfToken;
            csrfInput.value = csrfToken;
        }
        if (response.status === 403 && !payload.csrfToken && mayRetry !== false) {
            await refreshToken();
            return send(url, values, false);
        }
        if (!response.ok || !payload.ok) {
            throw new Error(payload.message || 'The server scan could not continue.');
        }
        return payload;
    };

    var render = function (payload) {
        var percent = Math.max(0, Math.min(100, Math.floor((payload.processed - analysisIds.length) / Math.max(1, payload.total) * 100)));
        progressBar.style.width = percent + '%';
        progressBar.setAttribute('aria-valuenow', String(percent));
        label.textContent = payload.message || (payload.complete ? 'Server scan complete.' : 'Scanning server files...');
        count.textContent = payload.processed + ' of ' + payload.total;
        summary.textContent = payload.imported + ' scanned, ' + payload.skipped + ' already scanned, ' + payload.failed + ' failed';
        if (payload.errors && payload.errors.length) {
            errors.textContent = payload.errors.slice(-3).join(' ');
            errors.classList.remove('d-none');
            if (window.GreyhoundNotifications) { window.GreyhoundNotifications.schedule(errors); }
        }
        if (payload.complete && !analysisIds.length) {
            progressBar.classList.remove('progress-bar-animated');
            progressBar.classList.toggle('bg-danger', payload.failed > 0 && payload.imported === 0);
            progressTrack.classList.add('d-none');
            label.textContent = payload.total === 0 ? payload.message : (payload.failed ? 'Scan finished with errors.' : 'Server scan complete.');
            button.disabled = false;
            button.innerHTML = '<i class="bi bi-arrow-repeat me-1"></i> Scan Again';
            if (payload.destinationFolderId) {
                folderLink.href = <?= json_encode(site_url('workspace/file-management?folder=')) ?> + payload.destinationFolderId;
                folderLink.classList.remove('d-none');
            }
        }
    };

    form.addEventListener('submit', async function (event) {
        event.preventDefault();
        if (busy || window.greyhoundIFoundScanBusy || !picker.scanSelection || !picker.scanSelection.length) return;
        busy = window.greyhoundIFoundScanBusy = true;
        var selection = picker.scanSelection.slice();
        var previousJobId = '';
        bootstrap.Modal.getInstance(picker).hide();
        dismiss.hidden = true; resultsLink.classList.add('d-none');
        rows.clear(); analysisIds = [];
        document.getElementById('ifoundScanFileList').replaceChildren();
        selection.forEach(function (file) {
            var row = document.createElement('div'), name = document.createElement('span'), state = document.createElement('span');
            name.textContent = file.name; state.textContent = 'Waiting'; row.append(name, state);
            document.getElementById('ifoundScanFileList').appendChild(row); rows.set(file.path, {state:state});
        });
        window.greyhoundIFoundScanBeforeReplace = function (body) {
            var incoming = body.querySelector('#ifoundScanProgress');
            if (incoming) incoming.remove();
            body.appendChild(progress);
        };
        button.disabled = true;
        button.innerHTML = '<span class="spinner-border spinner-border-sm me-1" aria-hidden="true"></span> Scanning';
        progress.classList.remove('d-none', 'is-collapsed');
        collapse.setAttribute('aria-expanded', 'true');
        progressTrack.classList.remove('d-none');
        progressBar.classList.remove('bg-danger');
        progressBar.classList.add('progress-bar-animated');
        progressBar.style.width = '0%';
        errors.classList.add('d-none');
        folderLink.classList.add('d-none');
        label.textContent = 'Starting selected scan…';
        summary.textContent = 'Preparing the managed destination folder.';

        try {
            var batchSize = Math.max(1, Math.min(100, Number(picker.dataset.batchSize) || 10));
            var totals = {imported:0, skipped:0, failed:0}, payload;
            for (var offset = 0; offset < selection.length; offset += batchSize) {
                var batch = selection.slice(offset, offset + batchSize);
                await refreshToken();
                payload = await send(form.action, {file_paths: batch.map(function (file) { return file.path; }), previous_job_id: previousJobId});
                var renderBatch = function () {
                    render(Object.assign({}, payload, {total:selection.length, processed:offset + payload.processed,
                        imported:totals.imported + payload.imported, skipped:totals.skipped + payload.skipped,
                        failed:totals.failed + payload.failed, complete:payload.complete && offset + batch.length >= selection.length}));
                };
                renderBatch();
                while (!payload.complete) {
                    var currentFile = batch[payload.processed];
                    if (currentFile) rows.get(currentFile.path).state.textContent = 'Scanning…';
                    payload = await send(form.dataset.stepUrl, { job_id: payload.jobId });
                    var item = rows.get(payload.filePath);
                    if (item) {
                        item.state.textContent = payload.analysisQueued ? 'Queued for AI' : ({imported:'Complete', skipped:'Already scanned', failed:'Failed'}[payload.fileStatus] || 'Failed');
                        item.id = payload.fileId;
                    }
                    if (payload.analysisQueued) analysisIds.push(payload.fileId);
                    renderBatch();
                }
                previousJobId = payload.jobId;
                totals.imported += payload.imported; totals.skipped += payload.skipped; totals.failed += payload.failed;
            }
            payload.failed = totals.failed;
            if (analysisIds.length) {
                var finished = false;
                while (!finished) {
                    label.textContent = 'AI analysis in progress…';
                    summary.textContent = 'The AI worker checks every minute. You can keep using this page.';
                    var data = {results:[]}, done = 0, failed = 0;
                    for (var statusOffset = 0; statusOffset < analysisIds.length; statusOffset += 100) {
                        var url = new URL(<?= json_encode(site_url('workspace/ifound/analysis-status')) ?>, window.location.href);
                        analysisIds.slice(statusOffset, statusOffset + 100).forEach(function (id) { url.searchParams.append('file_ids[]', id); });
                        var response = await fetch(url, {credentials:'same-origin',cache:'no-store',headers:{Accept:'application/json','X-Requested-With':'XMLHttpRequest'}});
                        if (!response.ok || response.redirected) throw new Error('Cannot check AI progress. Queued files may continue processing; view iFound results to check.');
                        var statusData = await response.json(); data.results.push.apply(data.results, statusData.results);
                    }
                    data.results.forEach(function (result) {
                        var terminal = ['complete','failed','unavailable'].includes(result.status);
                        if (terminal) done++;
                        if (['failed','unavailable'].includes(result.status)) failed++;
                        rows.forEach(function (row) { if (Number(row.id) === Number(result.file_id)) row.state.textContent = ({complete:'Complete',failed:'Failed',unavailable:'Unavailable',retrying:'Retrying',pending:'Queued for AI',processing:'Analyzing…'}[result.status] || 'Analyzing…'); });
                    });
                    var completed = selection.length - analysisIds.length + done;
                    var percent = Math.floor(completed / selection.length * 100);
                    progressTrack.classList.remove('d-none'); progressBar.style.width = percent + '%'; progressBar.setAttribute('aria-valuenow', String(percent));
                    count.textContent = completed + ' of ' + selection.length;
                    finished = done === analysisIds.length;
                    if (finished) { label.textContent = failed || payload.failed ? 'Scan finished with errors.' : 'Scan complete.'; summary.textContent = (failed + payload.failed) + ' failed. Results are ready in iFound.'; }
                    else await new Promise(function (resolve) { setTimeout(resolve, 5000); });
                }
            }
            progressBar.classList.remove('progress-bar-animated');
            resultsLink.classList.remove('d-none');
            // Refresh results without interrupting a review or a new selection.
            if (document.getElementById('ifoundScanForm') && !document.querySelector('.modal.show') && !document.querySelector('[data-ifound-select]:checked') && window.GreyhoundAjax) {
                window.GreyhoundAjax.refresh({ifIdle:true, preserveScroll:true}).catch(function () {});
            }
        } catch (error) {
            progressBar.classList.remove('progress-bar-animated');
            progressBar.classList.add('bg-danger');
            label.textContent = 'Server scan stopped.';
            errors.textContent = error.message || 'The server scan could not be completed.';
            errors.classList.remove('d-none');
            if (window.GreyhoundNotifications) { window.GreyhoundNotifications.schedule(errors); }
            button.disabled = false;
            button.innerHTML = '<i class="bi bi-arrow-repeat me-1"></i> Try Again';
            resultsLink.classList.remove('d-none');
        } finally {
            busy = window.greyhoundIFoundScanBusy = false; dismiss.hidden = false;
            button.disabled = false;
            button.innerHTML = '<i class="bi bi-search me-1"></i> Scan Files';
            if (window.greyhoundIFoundScanRebind) { var rebind = window.greyhoundIFoundScanRebind; window.greyhoundIFoundScanRebind = null; rebind(); }
        }
    });
})();

(function () {
    var rows = Array.prototype.slice.call(document.querySelectorAll('[data-ifound-item]')).filter(function (row) {
        return !row.querySelector('[data-ifound-select]').disabled;
    });
    var selectAllButton = document.getElementById('ifoundSelectAll');
    var countBadge = document.getElementById('ifoundSelectedCount');
    var reviewButton = document.getElementById('ifoundReviewSelected');
    var reviewModal = document.getElementById('ifoundReviewModal');
    var reviewImages = document.getElementById('ifoundReviewImages');
    var fileInputs = document.getElementById('ifoundReviewFileInputs');
    var eventSelect = document.getElementById('ifoundReviewEvent');
    var tagInput = document.getElementById('ifoundReviewTags');
    var existingTags = document.getElementById('ifoundExistingTags');
    var tagInputsByFile = {};
    var addedTagsByFile = {};
    var pendingTagsByFile = {};
    var currentIndex = 0;
    var modalItems = [];

    var splitTags = function (value) {
        return String(value || '').split(',').map(function (tag) { return tag.trim().replace(/\s+/g, ' '); }).filter(Boolean);
    };
    var tagValidationMessage = function (value) {
        var tags = splitTags(value);
        if (Array.from(value).length > 2000 || new Set(tags.map(function (tag) { return tag.toLowerCase(); })).size > 25) return 'Add no more than 25 new tags to this image.';
        if (tags.some(function (tag) { return Array.from(tag).length > 50; })) return 'Each tag may contain no more than 50 characters.';
        return '';
    };
    var mergedNewTags = function (current, pending) {
        var seen = new Set(splitTags(current.dataset.tags).map(function (tag) { return tag.toLowerCase(); }));
        return addedTagsByFile[current.dataset.fileId].concat(splitTags(pending)).filter(function (tag) {
            var key = tag.toLowerCase();
            if (seen.has(key)) return false;
            seen.add(key);
            return true;
        });
    };
    var renderTags = function () {
        var current = modalItems[currentIndex];
        existingTags.replaceChildren();
        var appendTag = function (tag, removable) {
            var badge = document.createElement('span');
            badge.className = 'badge';
            badge.textContent = tag;
            if (removable) {
                var remove = document.createElement('button');
                remove.type = 'button';
                remove.textContent = '×';
                remove.setAttribute('aria-label', 'Remove new tag ' + tag);
                remove.addEventListener('click', function () {
                    addedTagsByFile[current.dataset.fileId] = addedTagsByFile[current.dataset.fileId].filter(function (item) { return item !== tag; });
                    saveTagDraft();
                    renderTags();
                });
                badge.appendChild(remove);
            } else {
                badge.title = 'Existing tag';
            }
            existingTags.appendChild(badge);
        };
        splitTags(current.dataset.tags).forEach(function (tag) { appendTag(tag, false); });
        addedTagsByFile[current.dataset.fileId].forEach(function (tag) { appendTag(tag, true); });
    };
    var saveTagDraft = function () {
        var current = modalItems[currentIndex];
        if (!current) return;
        pendingTagsByFile[current.dataset.fileId] = tagInput.value;
        var value = mergedNewTags(current, tagInput.value).join(', ');
        tagInputsByFile[current.dataset.fileId].value = value;
        tagInput.setCustomValidity(tagValidationMessage(value));
    };
    var commitTags = function () {
        saveTagDraft();
        if (tagInput.validationMessage) { tagInput.reportValidity(); return; }
        var current = modalItems[currentIndex];
        if (!current) return;
        addedTagsByFile[current.dataset.fileId] = mergedNewTags(current, tagInput.value);
        tagInput.value = '';
        saveTagDraft();
        renderTags();
    };
    tagInput.addEventListener('input', function () {
        saveTagDraft();
        if (tagInput.value.includes(',')) commitTags();
    });
    tagInput.addEventListener('keydown', function (event) {
        if (!event.isComposing && (event.key === 'Enter' || event.key === ',')) {
            event.preventDefault();
            commitTags();
        }
    });

    var selectedRows = function () {
        return rows.filter(function (row) { return row.querySelector('[data-ifound-select]').checked; });
    };

    var syncSelection = function () {
        var selected = selectedRows();
        var allSelected = rows.length > 0 && selected.length === rows.length;
        selectAllButton.textContent = allSelected ? 'Unselect all' : 'Select all';
        countBadge.textContent = selected.length + ' selected';
        countBadge.classList.toggle('d-none', selected.length === 0);
        reviewButton.disabled = selected.length === 0;
        reviewButton.classList.toggle('d-none', selected.length === 0);
        var analyzeButton = document.getElementById('ifoundAnalyzeSelected');
        if (analyzeButton) {
            analyzeButton.classList.toggle('d-none', selected.length === 0);
            analyzeButton.disabled = selected.length === 0 || !!analyzeButton.dataset.busy;
        }
    };

    selectAllButton.addEventListener('click', function () {
        var shouldSelect = selectedRows().length !== rows.length;
        rows.forEach(function (row) { row.querySelector('[data-ifound-select]').checked = shouldSelect; });
        syncSelection();
    });
    rows.forEach(function (row) {
        row.querySelector('[data-ifound-select]').addEventListener('change', syncSelection);
    });

    var pageSize = 3;

    var updateCurrent = function () {
        if (modalItems.length === 0) {
            bootstrap.Modal.getInstance(reviewModal)?.hide();
            syncSelection();
            return;
        }
        currentIndex = Math.max(0, Math.min(currentIndex, modalItems.length - 1));
        var current = modalItems[currentIndex];
        var currentPage = Math.floor(currentIndex / pageSize);
        var pageCount = Math.ceil(modalItems.length / pageSize);
        document.getElementById('ifoundReviewFile').textContent = current.dataset.fileName;
        document.getElementById('ifoundReviewSuggestion').textContent = current.dataset.eventName || 'Not classified';
        document.getElementById('ifoundReviewConfidence').textContent = current.dataset.confidence || 'No confidence score';
        document.getElementById('ifoundTagImageName').textContent = 'Image ' + (currentIndex + 1) + ' of ' + modalItems.length + ' · ' + current.dataset.fileName;
        tagInput.value = pendingTagsByFile[current.dataset.fileId];
        tagInput.setCustomValidity(tagValidationMessage(tagInputsByFile[current.dataset.fileId].value));
        renderTags();
        document.getElementById('ifoundPageNumber').textContent = (currentPage + 1) + ' / ' + pageCount;
        document.getElementById('ifoundPrevious').disabled = currentPage === 0;
        document.getElementById('ifoundNext').disabled = currentPage === pageCount - 1;
        reviewImages.querySelectorAll('[data-review-image-id]').forEach(function (card) {
            card.classList.toggle('is-current', card.dataset.reviewImageId === current.dataset.fileId);
        });
    };

    var renderImagePage = function () {
        reviewImages.replaceChildren();
        if (modalItems.length === 0) {
            updateCurrent();
            return;
        }
        currentIndex = Math.max(0, Math.min(currentIndex, modalItems.length - 1));
        var pageStart = Math.floor(currentIndex / pageSize) * pageSize;
        modalItems.slice(pageStart, pageStart + pageSize).forEach(function (row, pageIndex) {
            var itemIndex = pageStart + pageIndex;
            var card = document.createElement('article');
            card.className = 'ifound-review-image-card';
            card.dataset.reviewImageId = row.dataset.fileId;
            card.tabIndex = 0;
            card.setAttribute('aria-label', 'Select ' + row.dataset.fileName + ' to edit tags');
            card.innerHTML = '<img alt="" loading="lazy"><span class="ifound-image-number"></span><button class="ifound-remove-image" type="button"><i class="bi bi-x-lg"></i></button>';
            card.querySelector('img').src = row.dataset.previewSrc;
            card.querySelector('img').alt = 'Preview of ' + row.dataset.fileName;
            card.querySelector('.ifound-image-number').textContent = itemIndex + 1;
            card.querySelector('.ifound-remove-image').setAttribute('aria-label', 'Remove ' + row.dataset.fileName + ' from review');
            card.addEventListener('click', function (event) {
                if (event.target.closest('.ifound-remove-image')) { return; }
                currentIndex = modalItems.indexOf(row);
                updateCurrent();
            });
            card.addEventListener('keydown', function (event) {
                if (event.target === card && (event.key === 'Enter' || event.key === ' ')) {
                    event.preventDefault();
                    currentIndex = modalItems.indexOf(row);
                    updateCurrent();
                }
            });
            card.querySelector('.ifound-remove-image').addEventListener('click', function () {
                row.querySelector('[data-ifound-select]').checked = false;
                modalItems = modalItems.filter(function (item) { return item !== row; });
                tagInputsByFile[row.dataset.fileId].remove();
                delete tagInputsByFile[row.dataset.fileId];
                delete addedTagsByFile[row.dataset.fileId];
                delete pendingTagsByFile[row.dataset.fileId];
                Array.prototype.slice.call(fileInputs.querySelectorAll('input[name="file_ids[]"]')).forEach(function (input) {
                    if (input.value === row.dataset.fileId) { input.remove(); }
                });
                currentIndex = Math.min(currentIndex, Math.max(0, modalItems.length - 1));
                renderImagePage();
                syncSelection();
            });
            reviewImages.appendChild(card);
        });
        updateCurrent();
    };

    var buildReview = function () {
        modalItems = selectedRows();
        currentIndex = 0;
        fileInputs.replaceChildren();
        tagInputsByFile = {};
        addedTagsByFile = {};
        pendingTagsByFile = {};
        modalItems.forEach(function (row) {
            var input = document.createElement('input');
            input.type = 'hidden';
            input.name = 'file_ids[]';
            input.value = row.dataset.fileId;
            fileInputs.appendChild(input);
            var tags = document.createElement('input');
            tags.type = 'hidden';
            tags.name = 'review_tags[' + row.dataset.fileId + ']';
            tags.value = '';
            tagInputsByFile[row.dataset.fileId] = tags;
            addedTagsByFile[row.dataset.fileId] = [];
            pendingTagsByFile[row.dataset.fileId] = '';
            fileInputs.appendChild(tags);
        });
        var firstEvent = modalItems[0]?.dataset.finalEventName ?? modalItems[0]?.dataset.eventName ?? '';
        eventSelect.value = Array.prototype.some.call(eventSelect.options, function (option) { return option.value === firstEvent; }) ? firstEvent : '';
        document.getElementById('ifoundReviewNote').value = '';
        renderImagePage();
    };

    reviewModal.addEventListener('show.bs.modal', buildReview);
    document.getElementById('ifoundReviewForm').addEventListener('submit', function (event) {
        saveTagDraft();
        var invalidIndex = modalItems.findIndex(function (row) { return tagValidationMessage(tagInputsByFile[row.dataset.fileId].value) !== ''; });
        if (invalidIndex !== -1) {
            event.preventDefault();
            currentIndex = invalidIndex;
            renderImagePage();
            tagInput.reportValidity();
        }
    });
    document.getElementById('ifoundPrevious').addEventListener('click', function () { currentIndex -= pageSize; renderImagePage(); });
    document.getElementById('ifoundNext').addEventListener('click', function () { currentIndex += pageSize; renderImagePage(); });

    document.querySelectorAll('.ifound-preview').forEach(function (button) {
        if (button.tagName !== 'BUTTON') { return; }
        button.addEventListener('click', function () {
            var row = button.closest('[data-ifound-item]');
            document.getElementById('ifoundImagePreviewTitle').textContent = row.dataset.fileName;
            var image = document.getElementById('ifoundImagePreview');
            image.src = row.dataset.previewSrc;
            image.alt = 'Preview of ' + row.dataset.fileName;
        });
    });
    document.getElementById('ifoundImagePreviewModal').addEventListener('hidden.bs.modal', function () {
        document.getElementById('ifoundImagePreview').src = '';
    });
    syncSelection();
})();
</script>
<?= view('workspace/ifound_review_submit_script') ?>
<?= view('workspace/ifound_analysis_script') ?>
<?= view('workspace/ifound_visual_script') ?>
<?= view('layouts/app_footer') ?>
