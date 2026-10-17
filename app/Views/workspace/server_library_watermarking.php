<?= view('layouts/app_header', [
    'user'       => $user,
    'activePage' => $activePage,
    'title'      => $title,
]) ?>

<?php
$positions = [
    'bottom_right' => 'Bottom right',
    'bottom_left'  => 'Bottom left',
    'top_right'    => 'Top right',
    'top_left'     => 'Top left',
    'center'       => 'Center',
];

$base = 'workspace/server-library/watermarking';
$canUploadLogos = in_array(\App\Libraries\RolePolicy::role($user), ['admin', 'super_admin'], true);
$pagination = $pagination ?? ['current_page' => 1, 'total_pages' => 1, 'total_results' => count($images), 'from' => $images === [] ? 0 : 1, 'to' => count($images)];
$currentPage = (int) $pagination['current_page'];
$totalPages = (int) $pagination['total_pages'];
$imagePageUrl = static function (int $page) use ($base, $folderId, $searchQuery, $type): string {
    $parameters = ['page' => $page];
    if ($folderId !== null) $parameters['folder'] = $folderId;
    if ($searchQuery !== '') $parameters['q'] = $searchQuery;
    if ($type !== '') $parameters['type'] = $type;
    return site_url($base) . '?' . http_build_query($parameters);
};
$pageNumbers = $totalPages <= 7
    ? range(1, $totalPages)
    : array_values(array_unique(array_filter([1, $currentPage - 1, $currentPage, $currentPage + 1, $totalPages], static fn (int $page): bool => $page >= 1 && $page <= $totalPages)));
?>

<style>
    .watermark-workspace {
        --wm-blue: #1670f8;
        --wm-border: #dfe5ef;
    }

    /* Introduction */
    .wm-intro {
        background: linear-gradient(135deg, #f4f7ff, #fff);
        border: 1px solid #e0e7f4;
        border-radius: 16px;
        padding: 1.2rem;
    }

    /* Cards */
    .wm-card {
        background: #fff;
        border: 1px solid var(--wm-border);
        border-radius: 12px;
        box-shadow: 0 2px 7px #25345512;
        height: 100%;
    }

    .wm-card-body {
        padding: 1.25rem;
    }

    /* Logo selection */
    .logo-grid {
        display: grid;
        gap: 0.65rem;
        grid-template-columns: repeat(auto-fill, minmax(min(105px, 100%), 1fr));
    }

    .logo-option input {
        position: absolute;
        opacity: 0;
    }

    .logo-add-tile {
        display: flex;
        align-items: center;
        justify-content: center;
        width: 100%;
        height: 105px;
        border: 2px dashed #b9c9ec;
        border-radius: 12px;
        background: #f8faff;
        color: #1670f8;
        font-size: 2rem;
        cursor: pointer;
    }

    .logo-add-tile:hover,
    .logo-add-tile:focus-visible {
        background: #edf3ff;
        border-color: #1670f8;
        outline: 2px solid #1670f8;
        outline-offset: 2px;
    }

    .logo-option label {
        align-items: center;
        border: 2px solid #e3e8f1;
        border-radius: 12px;
        cursor: pointer;
        display: flex;
        flex-direction: column;
        gap: 0.35rem;
        height: 105px;
        justify-content: center;
        padding: 0.5rem;
    }

    .logo-option input:checked + label {
        background: #f0f4ff;
        border-color: #4154f1;
    }

    .logo-option img {
        height: 55px;
        max-width: 80px;
        object-fit: contain;
    }

    .logo-option small {
        color: #4b5870;
        font-weight: 700;
        max-width: 100%;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
    }

    /* Preview */
    .preview-stage {
        align-items: center;
        background: #101a31;
        border-radius: 12px;
        display: flex;
        justify-content: center;
        min-height: 360px;
        overflow: hidden;
        position: relative;
    }

    .preview-canvas {
        display: inline-flex;
        flex: 0 1 auto;
        line-height: 0;
        max-height: calc(100vh - 190px);
        max-width: 100%;
        position: relative;
    }

    .preview-canvas > .preview-background {
        display: block;
        height: auto;
        max-height: calc(100vh - 190px);
        max-width: 100%;
        object-fit: contain;
        width: auto;
    }

    .image-preview-dialog {
        max-width: min(1100px, calc(100vw - 2rem));
    }

    .image-preview-dialog .modal-content {
        max-height: calc(100vh - 2rem);
        overflow: hidden;
    }

    .image-preview-dialog .modal-body {
        min-height: 0;
        overflow-y: auto;
    }

    /* Keep gallery navigation stationary without changing the watermark settings preview. */
    #imagePreviewModal .preview-stage { height: min(65vh, 640px, calc(100vh - 150px)); min-height: 0; }
    #imagePreviewModal .preview-canvas,
    #imagePreviewModal .preview-background { max-height: min(65vh, 640px, calc(100vh - 150px)); }
    #imagePreviewRaw { visibility: hidden; }
    #imagePreviewModal .modal-header > div { min-width: 0; flex: 1; }
    #imagePreviewTitle { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    #imagePreviewStatus { height: 3em; overflow-y: auto; }
    #imagePreviewCount { flex-shrink: 0; white-space: nowrap; }

    .image-preview-arrow {
        position: absolute;
        top: 50%;
        transform: translateY(-50%);
        z-index: 3;
        display: flex;
        align-items: center;
        justify-content: center;
        width: 44px;
        height: 44px;
        padding: 0;
        border: 1px solid rgba(255, 255, 255, 0.65);
        border-radius: 50%;
        color: #fff;
        background: rgba(15, 15, 16, 0.7);
        font-size: 1.25rem;
    }

    .image-preview-arrow:hover,
    .image-preview-arrow:focus-visible {
        color: #fff;
        background: rgba(15, 15, 16, 0.95);
        border-color: #fff;
        outline: 2px solid #fff;
        outline-offset: 2px;
    }

    .image-preview-arrow.previous { left: 12px; }
    .image-preview-arrow.next { right: 12px; }

    .preview-control-panel {
        background: rgba(16, 26, 49, 0.92);
        border: 1px solid rgba(255, 255, 255, 0.16);
        border-radius: 12px;
        bottom: 1rem;
        color: #fff;
        display: grid;
        gap: 0.75rem;
        grid-template-columns: minmax(150px, 1.15fr) minmax(130px, 1fr) minmax(130px, 1fr) auto;
        left: 1rem;
        padding: 0.8rem;
        position: absolute;
        right: 1rem;
        z-index: 5;
    }

    .preview-control-panel .form-label {
        color: #fff;
        font-size: 0.72rem;
        font-weight: 700;
        margin-bottom: 0.25rem;
    }

    .preview-control-panel output {
        color: #cbd5e8;
        font-size: 0.72rem;
    }

    .preview-watermark {
        max-height: 40%;
        max-width: 40%;
        object-fit: contain;
        position: absolute;
    }

    .preview-watermark.bottom_right {
        bottom: 16px;
        right: 16px;
    }

    .preview-watermark.bottom_left {
        bottom: 16px;
        left: 16px;
    }

    .preview-watermark.top_right {
        right: 16px;
        top: 16px;
    }

    .preview-watermark.top_left {
        left: 16px;
        top: 16px;
    }

    .preview-watermark.center {
        left: 50%;
        top: 50%;
        transform: translate(-50%, -50%);
    }

    #sampleLogo.bottom_right,
    #sampleLogo.bottom_left {
        bottom: 105px;
    }

    /* Image library */
    .mini-library {
        border: 1px solid var(--wm-border);
        border-radius: 12px;
        overflow: hidden;
    }

    .mini-library-head {
        padding: 1.15rem 1.25rem;
    }

    .filter-bar {
        background: #f8faff;
        border-bottom: 1px solid #e8edf5;
        border-top: 1px solid #e8edf5;
        padding: 1rem;
    }

    /* View switch */
    .view-switch {
        border: 1px solid #d7deed;
        border-radius: 999px;
        display: flex;
        padding: 2px;
    }

    .view-button {
        background: transparent;
        border: 0;
        border-radius: 999px;
        color: #53627c;
        height: 38px;
        width: 45px;
    }

    .view-button.is-active {
        background: var(--wm-blue);
        color: #fff;
    }

    /* Folders */
    .folder-location {
        background: #eef3ff;
        border-bottom: 1px solid #dfe7f7;
        color: #4a5e89;
        padding: 0.7rem 1rem;
    }

    .folder-path {
        align-items: center;
        display: flex;
        flex-wrap: wrap;
        gap: 0.35rem;
        min-width: 0;
    }

    .folder-path a {
        color: #315da8;
        font-weight: 600;
        text-decoration: none;
    }

    .folder-path a:hover {
        text-decoration: underline;
    }

    .folder-browser {
        padding: 1rem;
    }

    .watermark-workspace .folder-grid {
        align-items: start;
        display: grid;
        gap: 1.25rem;
        grid-template-columns: repeat(auto-fill, minmax(min(185px, 100%), 1fr));
        margin-top: 0.9rem;
    }

    .watermark-workspace .folder-grid:not(.is-list-view) > .folder-card {
        margin: 0;
        min-width: 0;
        width: 100%;
    }

    .folder-grid.is-list-view {
        --folder-list-columns: minmax(220px, 2fr) minmax(140px, 1fr) minmax(105px, .65fr);
        background: #fff;
        border: 1px solid #e0e4ea;
        border-radius: 12px;
        display: block;
        overflow-x: auto;
    }

    .folder-list-header,
    .folder-list-cell {
        display: none;
    }

    .folder-grid.is-list-view .folder-list-header {
        align-items: center;
        border-bottom: 1px solid #dadce0;
        color: #5f6368;
        display: grid;
        font-size: 0.75rem;
        font-weight: 600;
        gap: 12px;
        grid-template-columns: var(--folder-list-columns);
        height: 44px;
        padding: 0 16px;
    }

    .folder-card {
        background: #f0f4f9;
        border: 1px solid transparent;
        border-radius: 12px;
        height: 68px;
        overflow: hidden;
        transition: background .15s ease, border-color .15s ease, box-shadow .15s ease;
    }

    .folder-card:hover {
        background: #e8eef8;
        border-color: #d8e1ef;
        box-shadow: 0 2px 6px rgba(60, 64, 67, .10);
    }

    .folder-card-main {
        align-items: center;
        color: #34405b;
        display: flex;
        gap: 0.65rem;
        height: 100%;
        min-width: 0;
        padding: 0.85rem 0.9rem;
        text-decoration: none;
    }

    .watermark-workspace .folder-card-main { width: 100%; }
    .watermark-workspace .folder-name-cell { min-width: 0; width: 100%; }
    .watermark-workspace .folder-name-cell > .min-w-0 { display: block; min-width: 0; }

    .folder-card-icon {
        align-items: center;
        background: #edf2ff;
        border-radius: 10px;
        color: var(--wm-blue);
        display: inline-flex;
        flex: 0 0 auto;
        font-size: 1.3rem;
        height: 40px;
        justify-content: center;
        width: 40px;
    }

    .folder-card-name {
        font-size: 0.86rem;
        font-weight: 700;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
    }

    .folder-card-meta {
        color: #7d899f;
        font-size: 0.75rem;
        margin-top: 0.15rem;
    }

    .folder-grid.is-list-view .folder-card {
        background: #fff;
        border: 0;
        border-bottom: 1px solid #e8eaed;
        border-radius: 0;
        display: grid;
        gap: 12px;
        grid-template-columns: var(--folder-list-columns);
        height: 58px;
        margin: 0;
        min-height: 58px;
        min-width: 0;
        padding: 0;
        width: 100%;
    }

    .folder-grid.is-list-view .folder-card:last-child {
        border-bottom: 0;
    }

    .folder-grid.is-list-view .folder-card-main {
        display: grid;
        gap: 12px;
        grid-column: 1 / 4;
        grid-template-columns: var(--folder-list-columns);
        padding: 0 16px;
    }

    .folder-grid.is-list-view .folder-name-cell {
        align-items: center;
        display: flex;
        gap: 12px;
        min-width: 0;
    }

    .folder-grid.is-list-view .folder-card-icon {
        background: transparent;
        border-radius: 0;
        color: #5f6368;
        height: 28px;
        width: 28px;
    }

    .folder-grid.is-list-view .folder-card-meta {
        display: none;
    }

    .folder-grid.is-list-view .folder-list-cell {
        align-items: center;
        color: #5f6368;
        display: flex;
        font-size: 0.8rem;
        height: 100%;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
    }

    /* Selection bar */
    .selection-bar {
        align-items: center;
        border-bottom: 1px solid #e8edf5;
        border-top: 1px solid #e8edf5;
        display: flex;
        gap: 0.5rem;
        padding: 0.7rem 1rem;
    }

    .selection-count {
        background: #f1f4fa;
        border-radius: 99px;
        color: #44516e;
        font-size: 0.75rem;
        font-weight: 700;
        padding: 0.4rem 0.65rem;
    }

    .apply-logo-button {
        margin-left: auto;
    }

    /* Media grid */
    .media-grid {
        display: grid;
        gap: 1rem;
        grid-template-columns: repeat(auto-fill, minmax(min(205px, 100%), 1fr));
        padding: 1.25rem;
    }

    .media-card {
        background: #f0f4f9;
        border: 1px solid transparent;
        border-radius: 14px;
        display: flex;
        flex-direction: column;
        min-width: 0;
        overflow: visible;
        position: relative;
    }

    .media-card.is-selected {
        border-color: #315efb;
        box-shadow: 0 0 0 2px #315efb22;
    }

    .media-select-wrap {
        background: #fffffff2;
        border-radius: 7px;
        left: 0.6rem;
        padding: 0.25rem;
        position: absolute;
        top: 0.6rem;
        z-index: 3;
    }

    .media-thumb,
    .list-thumb {
        background: #fff;
        overflow: hidden;
        position: relative;
    }

    .media-thumb {
        border-radius: 10px;
        height: 160px;
        margin: 0.5rem;
        width: calc(100% - 1rem);
    }

    .media-thumb .raw,
    .list-thumb .raw {
        height: 100%;
        object-fit: cover;
        width: 100%;
    }

    /* Applied watermark */
    .item-logo {
        display: none;
        max-height: 40%;
        max-width: 40%;
        object-fit: contain;
        position: absolute;
    }

    .logo-applied .item-logo {
        display: block;
    }

    .item-logo.bottom_right {
        bottom: 9px;
        right: 9px;
    }

    .item-logo.bottom_left {
        bottom: 9px;
        left: 9px;
    }

    .item-logo.top_right {
        right: 9px;
        top: 9px;
    }

    .item-logo.top_left {
        left: 9px;
        top: 9px;
    }

    .item-logo.center {
        left: 50%;
        top: 50%;
        transform: translate(-50%, -50%);
    }

    /* Media information */
    .media-info {
        padding: 0.9rem;
    }

    .media-title {
        color: #34405b;
        font-size: 0.9rem;
        font-weight: 700;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
    }

    .media-meta {
        color: #7a869d;
        font-size: 0.69rem;
    }

    .file-type {
        background: #e7ecf4;
        border-radius: 99px;
        color: #59667e;
        font-size: 0.65rem;
        font-weight: 700;
        padding: 0.25rem 0.45rem;
    }

    /* Download actions */
    .download-actions {
        display: flex;
        gap: 0.3rem;
        margin-top: 0.55rem;
    }

    .download-actions form {
        flex: 1;
    }

    .download-actions .btn {
        font-size: 0.7rem;
        width: 100%;
    }

    /* List view */
    .media-table th {
        background: #f7f9fc;
        font-size: 0.68rem;
        text-transform: uppercase;
    }

    .media-table td {
        vertical-align: middle;
    }

    .list-thumb {
        border-radius: 7px;
        height: 45px;
        width: 58px;
    }

    .table-row.is-selected {
        background: #f2f6ff;
    }

    .applied-badge {
        background: #e6f6ec;
        border-radius: 99px;
        color: #168447;
        font-size: 0.65rem;
        font-weight: 700;
        padding: 0.25rem 0.45rem;
    }

    /* Empty state */
    .empty-state {
        color: #71809b;
        padding: 3rem;
        text-align: center;
    }

    /* Responsive */
    @media (max-width: 1199px) {
        .wm-card {
            height: auto;
        }

        #watermarkPreviewModal .preview-stage {
            flex-direction: column;
        }

        .preview-control-panel {
            grid-template-columns: repeat(2, minmax(0, 1fr));
            position: static;
            margin: 1rem;
            width: calc(100% - 2rem);
        }
    }

    @media (max-width: 767px) {
        .folder-grid.is-list-view {
            --folder-list-columns: minmax(0, 1fr) minmax(70px, .6fr);
        }

        .folder-grid.is-list-view .folder-list-header > :nth-child(2),
        .folder-grid.is-list-view .folder-card-main > :nth-child(2) {
            display: none;
        }

        .folder-grid.is-list-view .folder-card-main {
            grid-column: 1 / -1;
        }

        .folder-grid.is-list-view .folder-list-cell {
            white-space: normal;
        }

        .media-grid {
            grid-template-columns: repeat(
                auto-fill,
                minmax(min(150px, 100%), 1fr)
            );
        }

        .apply-logo-button {
            margin-left: 0;
            width: 100%;
        }

        .preview-control-panel {
            grid-template-columns: 1fr;
        }
    }
</style>

<main id="main" class="main watermark-workspace">

    <!-- Page Header -->
    <div class="pagetitle">
        <h1>Logo Watermarking</h1>

        <nav>
            <ol class="breadcrumb">
                <li class="breadcrumb-item">
                    <a href="<?= esc(site_url('dashboard')) ?>">
                        Dashboard
                    </a>
                </li>

                <li class="breadcrumb-item active">
                    Watermarking
                </li>
            </ol>
        </nav>
    </div>

    <section class="section">

        <!-- Alerts -->
        <?php if (!empty($success)): ?>
            <div class="alert alert-success" role="alert" data-auto-dismiss>
                <?= esc($success) ?>
            </div>
        <?php endif; ?>

        <?php if (!empty($error)): ?>
            <div class="alert alert-danger" role="alert" data-auto-dismiss>
                <?= esc($error) ?>
            </div>
        <?php endif; ?>

        <!-- Introduction -->
        <div class="wm-intro mb-4">
            <h2 class="h5 mb-1">
                Department logo downloads
            </h2>

            <p class="text-muted mb-0">
                Choose the default watermark, preview it, then select raw
                images for an on-demand watermarked download.
                Originals are never changed.
            </p>
        </div>

        <div class="row g-4">

            <!-- Watermark Settings -->
            <div class="col-xl-4">

                <div class="wm-card">
                    <div class="wm-card-body">

                        <h5>Default watermark</h5>

                        <p class="small text-muted">
                            Choose a logo, then configure its position and
                            appearance directly in the preview.
                        </p>

                        <form
                            id="defaultWatermarkForm"
                            onsubmit="return false"
                        >

                            <!-- Logo -->
                            <label class="form-label">
                                Default logo
                            </label>

                            <div class="logo-grid mb-3">

                                <?php foreach ($logos as $logo): ?>

                                    <div class="logo-option">

                                        <input
                                            type="radio"
                                            id="default_logo_<?= esc($logo['code']) ?>"
                                            name="logo_code"
                                            value="<?= esc($logo['code']) ?>"
                                            data-logo-url="<?= esc(
                                                site_url(
                                                    'workspace/watermarking/logo/' . $logo['code']
                                                )
                                            ) ?>"
                                            <?= $defaultLogoCode === $logo['code']
                                                ? 'checked'
                                                : '' ?>
                                            required
                                        >

                                        <label
                                            for="default_logo_<?= esc($logo['code']) ?>"
                                            title="<?= esc($logo['label']) ?>"
                                        >
                                            <img
                                                src="<?= esc(
                                                    site_url(
                                                        'workspace/watermarking/logo/' . $logo['code']
                                                    )
                                                ) ?>"
                                                alt="<?= esc($logo['label']) ?> logo"
                                            >

                                            <small>
                                                <?= esc($logo['name'] ?? $logo['code']) ?>
                                            </small>
                                        </label>

                                    </div>

                                <?php endforeach; ?>

                                <?php if ($canUploadLogos): ?>
                                    <button class="logo-add-tile" type="button" data-bs-toggle="modal" data-bs-target="#addWatermarkLogoModal" aria-label="Add logo" title="Add logo">
                                        <i class="bi bi-plus-lg" aria-hidden="true"></i>
                                    </button>
                                <?php endif; ?>

                            </div>

                            <input id="position" name="position" type="hidden" value="<?= esc((string) ($settings['position'] ?? 'bottom_right')) ?>">
                            <input id="opacity" name="opacity" type="hidden" value="<?= esc((string) ($settings['opacity'] ?? 65)) ?>">
                            <input id="resize_percent" name="resize_percent" type="hidden" value="<?= esc((string) ($settings['resize_percent'] ?? 100)) ?>">

                        </form>

                        <!-- Preview -->
                        <button
                            class="btn btn-primary w-100 mt-2"
                            type="button"
                            data-bs-toggle="modal"
                            data-bs-target="#watermarkPreviewModal"
                        >
                            <i class="bi bi-eye me-1"></i>
                            Configure preview
                        </button>

                        <p class="small text-muted mt-3 mb-0">
                            <i class="bi bi-shield-check me-1"></i>
                            Preview settings are used for the current download.
                            Original images remain unchanged.
                        </p>

                    </div>
                </div>

            </div>

            <!-- Server Library -->
            <div class="col-xl-8">

                <div class="wm-card">

                    <div class="mini-library">

                        <div class="mini-library-head">

                            <h5 class="mb-1">
                                Server Library
                            </h5>

                            <p class="small text-muted mb-0">
                                Browse server folders and select original images from Server Library.
                            </p>

                        </div>

                        <!-- Search & Filter -->
                        <form
                            class="filter-bar"
                            method="get"
                            action="<?= esc(site_url($base)) ?>"
                        >
                            <?php if ($folderId !== null): ?><input type="hidden" name="folder" value="<?= esc($folderId) ?>"><?php endif; ?>

                            <div class="row g-2">

                                <div class="col-lg-6">

                                    <div class="input-group">

                                        <span class="input-group-text">
                                            <i class="bi bi-search"></i>
                                        </span>

                                        <input
                                            class="form-control"
                                            name="q"
                                            value="<?= esc($searchQuery) ?>"
                                            placeholder="Search filename, folder, or tag" data-search-suggestions="<?= esc(site_url('workspace/server-library/suggestions')) ?>"
                                        >

                                    </div>

                                </div>

                                <div class="col-lg-3">

                                    <select
                                        class="form-select"
                                        name="type"
                                    >

                                        <option value="">
                                            All image types
                                        </option>

                                        <?php foreach (['jpg', 'jpeg', 'png', 'webp'] as $typeOption): ?>

                                            <option
                                                value="<?= $typeOption ?>"
                                                <?= $type === $typeOption
                                                    ? 'selected'
                                                    : '' ?>
                                            >
                                                <?= strtoupper($typeOption) ?>
                                            </option>

                                        <?php endforeach; ?>

                                    </select>

                                </div>

                                <div class="col-lg-3 d-flex gap-2">

                                    <button
                                        class="btn btn-primary flex-grow-1"
                                        type="submit"
                                    >
                                        <i class="bi bi-funnel"></i>
                                    </button>

                                    <div class="view-switch">

                                        <button
                                            class="view-button"
                                            type="button"
                                            data-view="list"
                                            aria-label="List view"
                                            aria-pressed="false"
                                            title="List view"
                                        >
                                            <i class="bi bi-list-ul"></i>
                                        </button>

                                        <button
                                            class="view-button is-active"
                                            type="button"
                                            data-view="grid"
                                            aria-label="Grid view"
                                            aria-pressed="true"
                                            title="Grid view"
                                        >
                                            <i class="bi bi-grid-3x3-gap-fill"></i>
                                        </button>

                                    </div>

                                </div>

                            </div>

                        </form>

                        <!-- Folder Location -->
                        <div class="folder-location d-flex align-items-center gap-2">
                            <nav class="folder-path" aria-label="Current library folder">
                                <i class="bi bi-folder2-open me-1"></i>
                                <a href="<?= esc(site_url($base)) ?>">Root Library</a>
                                <?php foreach (($folderBreadcrumbs ?? []) as $crumb): ?>
                                    <i class="bi bi-chevron-right small" aria-hidden="true"></i>
                                    <?php if ((string) $crumb['id'] === (string) ($folderId ?? '')): ?>
                                        <strong aria-current="page"><?= esc($crumb['name']) ?></strong>
                                    <?php else: ?>
                                        <a href="<?= esc(site_url($base . '?folder=' . rawurlencode($crumb['id']))) ?>"><?= esc($crumb['name']) ?></a>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </nav>

                        </div>

                        <!-- Folders -->
                        <?php if ($folders !== []): ?>

                            <div class="folder-browser">

                                <div class="small fw-bold mb-2">
                                    <i class="bi bi-folder2-open"></i>

                                    <?= $selectedFolder === null
                                        ? 'Folders'
                                        : 'Subfolders' ?>
                                </div>

                                <div
                                    class="folder-grid"
                                    id="folderGrid"
                                >
                                    <div class="folder-list-header" aria-hidden="true">
                                        <span>Folder name</span>
                                        <span>Date modified</span>
                                        <span>Contents</span>
                                    </div>

                                    <?php foreach ($folders as $folder): ?>

                                        <div class="folder-card">

                                            <a
                                                class="folder-card-main"
                                                href="<?= esc(
                                                    site_url(
                                                        $base . '?folder=' . rawurlencode($folder['id'])
                                                    )
                                                ) ?>"
                                            >

                                                <span class="folder-name-cell">
                                                    <span class="folder-card-icon"><i class="bi bi-folder-fill"></i></span>
                                                    <span class="min-w-0">
                                                        <span class="folder-card-name d-block"><?= esc($folder['name']) ?></span>
                                                        <span class="folder-card-meta">

                                                        <?= (int) $folder['file_count'] ?>

                                                        <?= (int) $folder['file_count'] === 1
                                                            ? 'image'
                                                            : 'images' ?>

                                                        ·

                                                        <?= (int) $folder['subfolder_count'] ?>

                                                        <?= (int) $folder['subfolder_count'] === 1
                                                            ? 'folder'
                                                            : 'folders' ?>

                                                        </span>
                                                    </span>
                                                </span>
                                                <span class="folder-list-cell"><?= esc(! empty($folder['created_at']) ? \App\Libraries\DisplayTime::format($folder['created_at'], 'M d, Y') : '-') ?></span>
                                                <span class="folder-list-cell"><?= (int) $folder['file_count'] ?> image<?= (int) $folder['file_count'] === 1 ? '' : 's' ?> &middot; <?= (int) $folder['subfolder_count'] ?> folder<?= (int) $folder['subfolder_count'] === 1 ? '' : 's' ?></span>

                                            </a>

                                        </div>

                                    <?php endforeach; ?>

                                </div>

                            </div>

                        <?php endif; ?>

                        <!-- Selection Bar -->
                        <form
                            class="selection-bar"
                            id="bulkWatermarkForm"
                            action="<?= esc(site_url($base . '/download')) ?>"
                            method="post"
                        >

                            <?= csrf_field() ?>

                            <span id="selectedFileInputs"></span>

                            <input
                                type="hidden"
                                name="logo_code"
                            >

                            <input
                                type="hidden"
                                name="position"
                            >

                            <input
                                type="hidden"
                                name="opacity"
                            >

                            <input
                                type="hidden"
                                name="resize_percent"
                            >

                            <button
                                class="btn btn-sm btn-outline-primary"
                                id="selectAll"
                                type="button"
                            >
                                <i class="bi bi-check2-square"></i>
                                Select all
                            </button>

                            <button
                                class="btn btn-sm btn-link text-secondary"
                                id="clearSelection"
                                type="button"
                            >
                                Clear
                            </button>

                            <span
                                class="selection-count"
                                id="selectionCount"
                            >
                                0 selected
                            </span>

                            <button
                                class="btn btn-sm btn-outline-primary apply-logo-button d-none"
                                id="applyLogo"
                                type="button"
                            >
                                <i class="bi bi-droplet-half"></i>
                                Apply logo
                            </button>

                            <button
                                class="btn btn-sm btn-primary d-none"
                                id="downloadSelected"
                                type="submit"
                            >
                                <i class="bi bi-download"></i>
                                Download
                            </button>

                        </form>

                        <!-- List View -->
                        <div
                            class="table-responsive d-none"
                            id="listView"
                        >

                            <table class="table media-table mb-0">

                                <thead>
                                    <tr>
                                        <th></th>
                                        <th>Image</th>
                                        <th>Type</th>
                                        <th>Size</th>
                                        <th>Uploaded</th>
                                    </tr>
                                </thead>

                                <tbody>

                                    <?php foreach ($images as $image): ?>

                                        <?php
                                        $id = (string) $image['id'];
                                        $title = \App\Libraries\MediaTitle::forFile($image);
                                        $previewUrl = site_url(
                                            'workspace/server-library/items/' . $id . '/preview'
                                        );
                                        ?>

                                        <tr
                                            class="table-row"
                                            data-item
                                            data-image-id="<?= $id ?>"
                                        >

                                            <td>
                                                <input
                                                    class="form-check-input image-select"
                                                    type="checkbox"
                                                    aria-label="Select <?= esc($title) ?>"
                                                >
                                            </td>

                                            <td>

                                                <button
                                                    class="btn p-0 border-0 bg-transparent d-flex align-items-center gap-2 text-start"
                                                    type="button"
                                                    data-image-preview
                                                    data-preview-src="<?= esc($previewUrl) ?>"
                                                    data-preview-title="<?= esc($title) ?>"
                                                >

                                                    <span class="list-thumb">

                                                        <img
                                                            class="raw"
                                                            src="<?= esc($previewUrl) ?>"
                                                            alt=""
                                                        >

                                                        <img
                                                            class="item-logo"
                                                            alt=""
                                                        >

                                                    </span>

                                                    <span>

                                                        <span class="media-title d-block">
                                                            <?= esc($title) ?>
                                                        </span>

                                                        <span
                                                            class="applied-badge d-none"
                                                            data-applied-badge
                                                        >
                                                            Logo applied
                                                        </span>

                                                    </span>

                                                </button>

                                            </td>

                                            <td>
                                                <span class="file-type">
                                                    <?= esc($image['filetype']) ?>
                                                </span>
                                            </td>

                                            <td class="small">
                                                <?= esc(
                                                    format_bytes((int) $image['size'])
                                                ) ?>
                                            </td>

                                            <td class="small">
                                                <?= esc(
                                                    $image['dateupload']
                                                        ? \App\Libraries\DisplayTime::format($image['dateupload'], 'M d, Y')
                                                        : '-'
                                                ) ?>
                                            </td>

                                        </tr>

                                    <?php endforeach; ?>

                                </tbody>

                            </table>

                        </div>

                        <!-- Grid View -->
                        <div
                            class="media-grid"
                            id="gridView"
                        >

                            <?php foreach ($images as $image): ?>

                                <?php
                                $id = (string) $image['id'];
                                $title = \App\Libraries\MediaTitle::forFile($image);
                                $previewUrl = site_url(
                                    'workspace/server-library/items/' . $id . '/preview'
                                );
                                ?>

                                <article
                                    class="media-card"
                                    data-item
                                    data-image-id="<?= $id ?>"
                                >

                                    <label class="media-select-wrap">

                                        <input
                                            class="form-check-input image-select"
                                            type="checkbox"
                                            aria-label="Select <?= esc($title) ?>"
                                        >

                                    </label>

                                    <button
                                        class="media-thumb border-0 p-0"
                                        type="button"
                                        data-image-preview
                                        data-preview-src="<?= esc($previewUrl) ?>"
                                        data-preview-title="<?= esc($title) ?>"
                                    >

                                        <img
                                            class="raw"
                                            src="<?= esc($previewUrl) ?>"
                                            alt="Preview of <?= esc($title) ?>"
                                            loading="lazy"
                                        >

                                        <img
                                            class="item-logo"
                                            alt="Watermark preview"
                                        >

                                    </button>

                                    <div class="media-info">

                                        <div
                                            class="d-flex justify-content-between align-items-center gap-1"
                                        >

                                            <div class="media-title">
                                                <?= esc($title) ?>
                                            </div>

                                            <span
                                                class="applied-badge d-none"
                                                data-applied-badge
                                            >
                                                Logo applied
                                            </span>

                                        </div>

                                        <div class="d-flex justify-content-between">

                                            <span class="file-type">
                                                <?= esc($image['filetype']) ?>
                                            </span>

                                            <span class="media-meta">
                                                <?= esc(
                                                    format_bytes((int) $image['size'])
                                                ) ?>
                                            </span>

                                        </div>

                                    </div>

                                </article>

                            <?php endforeach; ?>

                            <?php if ($images === []): ?>

                                <div class="empty-state">

                                    <i class="bi bi-images fs-2"></i>

                                    <p>
                                        No images found in this folder.
                                    </p>

                                </div>

                            <?php endif; ?>

                        </div>

                        <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 p-3 border-top">
                            <span class="text-muted small">Showing <?= esc(number_format((int) $pagination['from'])) ?>–<?= esc(number_format((int) $pagination['to'])) ?> of <?= esc(number_format((int) $pagination['total_results'])) ?> images</span>
                            <?php if ($totalPages > 1): ?>
                                <nav aria-label="Watermark image pages">
                                    <ul class="pagination pagination-sm flex-wrap mb-0">
                                        <li class="page-item <?= $currentPage === 1 ? 'disabled' : '' ?>">
                                            <?php if ($currentPage === 1): ?><span class="page-link" aria-disabled="true">Previous</span>
                                            <?php else: ?><a class="page-link" href="<?= esc($imagePageUrl($currentPage - 1)) ?>" rel="prev">Previous</a><?php endif; ?>
                                        </li>
                                        <?php $previousPage = 0; foreach ($pageNumbers as $pageNumber): ?>
                                            <?php if ($previousPage && $pageNumber > $previousPage + 1): ?><li class="page-item disabled"><span class="page-link">…</span></li><?php endif; ?>
                                            <li class="page-item <?= $pageNumber === $currentPage ? 'active' : '' ?>">
                                                <?php if ($pageNumber === $currentPage): ?><span class="page-link" aria-current="page"><?= $pageNumber ?></span>
                                                <?php else: ?><a class="page-link" href="<?= esc($imagePageUrl($pageNumber)) ?>" aria-label="Page <?= $pageNumber ?>"><?= $pageNumber ?></a><?php endif; ?>
                                            </li>
                                            <?php $previousPage = $pageNumber; ?>
                                        <?php endforeach; ?>
                                        <li class="page-item <?= $currentPage === $totalPages ? 'disabled' : '' ?>">
                                            <?php if ($currentPage === $totalPages): ?><span class="page-link" aria-disabled="true">Next</span>
                                            <?php else: ?><a class="page-link" href="<?= esc($imagePageUrl($currentPage + 1)) ?>" rel="next">Next</a><?php endif; ?>
                                        </li>
                                    </ul>
                                </nav>
                            <?php endif; ?>
                        </div>

                    </div>

                </div>

            </div>

        </div>

    </section>

</main>

<?php if ($canUploadLogos): ?>
<div class="modal fade" id="addWatermarkLogoModal" tabindex="-1" aria-labelledby="addWatermarkLogoTitle" aria-hidden="true" data-reopen="<?= isset($logoUploadName) ? 'true' : 'false' ?>">
    <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
        <form class="modal-content" action="<?= esc(site_url($base . '/logos')) ?>" method="post" enctype="multipart/form-data" data-no-ajax>
            <div class="modal-header">
                <h5 class="modal-title" id="addWatermarkLogoTitle">Add logo</h5>
                <button class="btn-close" type="button" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <?= csrf_field() ?>
                <?php foreach (['folder' => $folderId, 'q' => $searchQuery, 'type' => $type, 'page' => $currentPage] as $key => $value): ?>
                    <?php if ($value !== null && $value !== ''): ?><input type="hidden" name="<?= esc($key) ?>" value="<?= esc((string) $value) ?>"><?php endif; ?>
                <?php endforeach; ?>
                <?php if (isset($logoUploadName) && ! empty($error)): ?><div class="alert alert-danger" role="alert"><?= esc($error) ?></div><?php endif; ?>
                <label class="form-label" for="watermarkLogoName">Logo name</label>
                <input class="form-control mb-3" id="watermarkLogoName" name="logo_name" maxlength="60" value="<?= esc($logoUploadName ?? '') ?>" placeholder="e.g. Alumni Association" required>
                <label class="form-label" for="watermarkLogoFile">Logo image</label>
                <input class="form-control" id="watermarkLogoFile" name="logo_file" type="file" accept="image/png,image/jpeg,image/webp" aria-describedby="watermarkLogoHelp" required>
                <p class="form-text mb-0" id="watermarkLogoHelp">PNG, JPG or WebP, up to 5 MB and 2048 × 2048 pixels. Use a transparent PNG for a logo without a background.</p>
            </div>
            <div class="modal-footer">
                <button class="btn btn-outline-secondary" type="button" data-bs-dismiss="modal">Cancel</button>
                <button class="btn btn-primary" type="submit"><i class="bi bi-upload me-1"></i>Upload logo</button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<!-- Image Preview Modal -->
<div
    class="modal fade"
    id="imagePreviewModal"
    tabindex="-1"
    aria-labelledby="imagePreviewTitle"
>

    <div class="modal-dialog modal-xl modal-dialog-centered image-preview-dialog">

        <div class="modal-content">

            <div class="modal-header">

                <div>
                <h5
                    class="modal-title"
                    id="imagePreviewTitle"
                >
                    Image preview
                </h5>
                <div class="small text-muted" id="imagePreviewStatus" role="status"></div>
                </div>

                <span class="small text-muted ms-3 me-auto" id="imagePreviewCount" aria-live="polite"></span>

                <button
                    class="btn-close"
                    data-bs-dismiss="modal"
                ></button>

            </div>

            <div class="modal-body p-0">

                <div class="preview-stage">

                    <div class="preview-canvas">

                        <img
                            class="preview-background"
                            id="imagePreviewRaw"
                            alt="Selected image preview"
                        >

                        <img
                            class="preview-watermark"
                            id="imagePreviewLogo"
                            alt="Watermark preview"
                        >

                    </div>

                    <button class="image-preview-arrow previous d-none" id="imagePreviewPrevious" type="button" aria-label="Previous image" title="Previous image">
                        <i class="bi bi-chevron-left" aria-hidden="true"></i>
                    </button>
                    <button class="image-preview-arrow next d-none" id="imagePreviewNext" type="button" aria-label="Next image" title="Next image">
                        <i class="bi bi-chevron-right" aria-hidden="true"></i>
                    </button>

                </div>

            </div>

        </div>

    </div>

</div>

<!-- Watermark Settings Preview Modal -->
<div
    class="modal fade"
    id="watermarkPreviewModal"
    tabindex="-1"
>

    <div class="modal-dialog modal-xl modal-dialog-centered">

        <div class="modal-content">

            <div class="modal-header">

                <div>

                    <h5 class="modal-title">
                        Edit watermark preview
                    </h5>

                    <p class="small text-muted mb-0">
                        This sample is temporary and is not saved.
                    </p>

                </div>

                <button
                    class="btn-close"
                    data-bs-dismiss="modal"
                ></button>

            </div>

            <div class="modal-body p-0">

                        <div class="preview-stage rounded-0">

                            <div class="preview-canvas">

                                <img
                                    class="preview-background"
                                    src="<?= esc(
                                        base_url('assets/img/background-1.jpg')
                                    ) ?>"
                                    alt="Default preview image"
                                >

                                <img
                                    id="sampleLogo"
                                    class="preview-watermark"
                                    alt="Watermark preview"
                                >

                            </div>

                            <div class="preview-control-panel">
                                <div>
                                    <label class="form-label" for="previewPosition">Position</label>
                                    <select class="form-select form-select-sm" id="previewPosition">
                                        <?php foreach ($positions as $value => $label): ?>
                                            <option value="<?= esc($value) ?>"><?= esc($label) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div>
                                    <label class="form-label" for="previewOpacity">Opacity · <output id="previewOpacityValue"></output></label>
                                    <input class="form-range" id="previewOpacity" type="range" min="30" max="100">
                                </div>
                                <div>
                                    <label class="form-label" for="previewScale">Logo scale · <output id="previewScaleValue"></output></label>
                                    <input class="form-range" id="previewScale" type="range" min="10" max="200" step="1">
                                </div>
                                <button class="btn btn-primary align-self-end" id="usePreviewSettings" type="button" data-bs-dismiss="modal">
                                    Use settings
                                </button>
                            </div>
                        </div>

            </div>

        </div>

    </div>

</div>

<script>
document.addEventListener('DOMContentLoaded', function () {

    const addLogoModal = document.getElementById('addWatermarkLogoModal');
    if (addLogoModal) {
        addLogoModal.addEventListener('shown.bs.modal', () => {
            document.getElementById('watermarkLogoName').focus();
        });
        if (addLogoModal.dataset.reopen === 'true') {
            bootstrap.Modal.getOrCreateInstance(addLogoModal).show();
        }
    }

    /* -------------------------------------------------------
     * Elements
     * ----------------------------------------------------- */

    const defaultForm = document.getElementById(
        'defaultWatermarkForm'
    );

    const position = document.getElementById('position');
    const opacity = document.getElementById('opacity');
    const scale = document.getElementById('resize_percent');

    const bulkForm = document.getElementById(
        'bulkWatermarkForm'
    );

    const listView = document.getElementById('listView');
    const gridView = document.getElementById('gridView');
    const folderGrid = document.getElementById('folderGrid');

    const items = [
        ...document.querySelectorAll('[data-item]')
    ];

    const selectionCount =
        document.getElementById('selectionCount');

    const applyLogo =
        document.getElementById('applyLogo');

    const downloadSelected =
        document.getElementById('downloadSelected');

    const selectedFileInputs =
        document.getElementById('selectedFileInputs');


    /* -------------------------------------------------------
     * Watermark Settings
     * ----------------------------------------------------- */

    const selectedLogo = () => {
        return defaultForm.querySelector(
            '[name="logo_code"]:checked'
        );
    };

    const getSettings = () => {

        const logo = selectedLogo();

        return {
            logo: logo?.value || 'FU',
            url: logo?.dataset.logoUrl || '',
            position: position.value,
            opacity: Math.max(
                30,
                Math.min(100, Number(opacity.value) || 65)
            ),
            scale: Math.max(
                10,
                Math.min(200, Number(scale.value) || 100)
            ),
        };
    };


    /* -------------------------------------------------------
     * Bulk Form
     * ----------------------------------------------------- */

    const fillBulkSettings = () => {

        const settings = getSettings();

        bulkForm.querySelector(
            '[name="logo_code"]'
        ).value = settings.logo;

        bulkForm.querySelector(
            '[name="position"]'
        ).value = settings.position;

        bulkForm.querySelector(
            '[name="opacity"]'
        ).value = settings.opacity;

        bulkForm.querySelector(
            '[name="resize_percent"]'
        ).value = settings.scale;
    };


    fillBulkSettings();


    /* -------------------------------------------------------
     * View Switching
     * ----------------------------------------------------- */

    const viewButtons = document.querySelectorAll('[data-view]');

    const setMediaView = view => {
        const activeView = view === 'list' ? 'list' : 'grid';
        const isGrid = activeView === 'grid';

        listView.classList.toggle('d-none', isGrid);
        gridView.classList.toggle('d-none', !isGrid);
        folderGrid?.classList.toggle('is-list-view', !isGrid);

        viewButtons.forEach(button => {
            const isActive = button.dataset.view === activeView;
            button.classList.toggle('is-active', isActive);
            button.setAttribute('aria-pressed', isActive ? 'true' : 'false');
        });

        try {
            window.localStorage.setItem('greyhound-media-view', activeView);
        } catch (error) { }
    };

    viewButtons.forEach(button => {
        button.addEventListener('click', () => setMediaView(button.dataset.view));
    });

    let initialView = 'grid';
    try {
        const savedView = window.localStorage.getItem('greyhound-media-view');
        if (savedView === 'list' || savedView === 'grid') {
            initialView = savedView;
        }
    } catch (error) { }
    setMediaView(initialView);


    /* -------------------------------------------------------
     * Selection
     * ----------------------------------------------------- */

    const getSelectedIds = () => {

        return new Set(
            items
                .filter(item =>
                    item.querySelector(
                        '.image-select'
                    ).checked
                )
                .map(item => item.dataset.imageId)
        );

    };


    const syncSelection = () => {

        const selected = getSelectedIds();

        items.forEach(item => {

            const isSelected =
                selected.has(
                    item.dataset.imageId
                );

            item.querySelector(
                '.image-select'
            ).checked = isSelected;

            item.classList.toggle(
                'is-selected',
                isSelected
            );

        });


        selectedFileInputs.replaceChildren(
            ...[...selected].map(id => {

                const input =
                    document.createElement('input');

                input.type = 'hidden';
                input.name = 'file_ids[]';
                input.value = id;

                return input;

            })
        );


        selectionCount.textContent =
            `${selected.size} selected`;

        const allSelected =
            items.length > 0 &&
            items.every(item =>
                item.querySelector('.image-select').checked
            );

        document.getElementById('selectAll').innerHTML =
            '<i class="bi bi-check2-square"></i> ' +
            (allSelected ? 'Unselect all' : 'Select all');

        applyLogo.classList.toggle(
            'd-none',
            selected.size === 0
        );

        downloadSelected.classList.toggle(
            'd-none',
            selected.size === 0
        );

        downloadSelected.innerHTML =
            '<i class="bi bi-download"></i> Download';
    };


    /* Individual selection */

    items.forEach(item => {

        const checkbox =
            item.querySelector('.image-select');

        checkbox.addEventListener(
            'change',
            event => {

                items
                    .filter(other =>
                        other.dataset.imageId ===
                        item.dataset.imageId
                    )
                    .forEach(other => {

                        other.querySelector(
                            '.image-select'
                        ).checked =
                            event.target.checked;

                    });

                syncSelection();
            }
        );

    });


    /* Select all */

    document
        .getElementById('selectAll')
        .addEventListener('click', () => {

            const shouldSelect =
                !items.every(item =>
                    item.querySelector('.image-select').checked
                );

            items.forEach(item => {

                item.querySelector(
                    '.image-select'
                ).checked = shouldSelect;

            });

            syncSelection();
        });


    /* Clear selection */

    document
        .getElementById('clearSelection')
        .addEventListener('click', () => {

            items.forEach(item => {

                item.querySelector(
                    '.image-select'
                ).checked = false;

            });

            syncSelection();
        });


    /* -------------------------------------------------------
     * Apply Logo
     * ----------------------------------------------------- */

    applyLogo.addEventListener('click', () => {

        const settings = getSettings();
        const selected = getSelectedIds();

        if (selected.size === 0) {
            return;
        }

        fillBulkSettings();

        items.forEach(item => {

            if (
                !selected.has(
                    item.dataset.imageId
                )
            ) {
                return;
            }

            item.classList.add('logo-applied');

            item.querySelector(
                '[data-applied-badge]'
            ).classList.remove('d-none');

            const logo =
                item.querySelector('.item-logo');

            logo.src = settings.url;

            logo.className =
                `item-logo ${settings.position}`;

            logo.style.opacity =
                settings.opacity / 100;

            logo.style.maxWidth =
                `${40 * settings.scale / 100}%`;

            item.querySelector(
                '.image-select'
            ).checked = false;

        });

        syncSelection();
    });


    /* -------------------------------------------------------
     * Download
     * ----------------------------------------------------- */

    bulkForm.addEventListener(
        'submit',
        event => {

            if (getSelectedIds().size === 0) {
                event.preventDefault();
                return;
            }

            fillBulkSettings();
        }
    );


    /* -------------------------------------------------------
     * Image Preview Modal
     * ----------------------------------------------------- */

    const imageModalElement = document.getElementById('imagePreviewModal');
    const imageModal = new bootstrap.Modal(imageModalElement);
    const imageRaw = document.getElementById('imagePreviewRaw');
    const imageLogo = document.getElementById('imagePreviewLogo');
    const imageTitle = document.getElementById('imagePreviewTitle');
    const imageCount = document.getElementById('imagePreviewCount');
    const imageStatus = document.getElementById('imagePreviewStatus');
    const previousImage = document.getElementById('imagePreviewPrevious');
    const nextImage = document.getElementById('imagePreviewNext');
    const previewButtons = [...document.querySelectorAll('[data-image-preview]')];
    // Each image has both a list row and a grid card; include it only once.
    const previewImages = [...new Map(previewButtons.map(button => [
        button.closest('[data-item]').dataset.imageId,
        button
    ])).values()];
    let previewIndex = -1;
    let previewTrigger = null;
    let imageRequest = 0;
    let pendingImage = null;
    const cancelImageRequest = () => {
        imageRequest += 1;
        if (pendingImage) {
            pendingImage.onload = null;
            pendingImage.onerror = null;
            pendingImage = null;
        }
    };
    const updateImageDetails = (button, index) => {
        const title = button.dataset.previewTitle || 'Image preview';
        imageRaw.alt = title;
        imageTitle.textContent = title;
        imageCount.textContent = `${index + 1} / ${previewImages.length}`;
    };
    const showPreviewImage = index => {
        if (!previewImages.length) return;
        cancelImageRequest();
        previewIndex = (index + previewImages.length) % previewImages.length;
        const button = previewImages[previewIndex];
        const item = button.closest('[data-item]');
        if (!imageRaw.getAttribute('src')) updateImageDetails(button, previewIndex);
        imageStatus.textContent = 'Loading image preview…';
        previousImage.classList.toggle('d-none', previewImages.length < 2);
        nextImage.classList.toggle('d-none', previewImages.length < 2);
        const request = imageRequest;
        const requestedIndex = previewIndex;
        const loader = new Image();
        pendingImage = loader;
        const isCurrent = () => request === imageRequest && previewIndex >= 0 && imageModalElement.isConnected;
        // Commit the photo, details and applied logo together, only for the latest request.
        loader.onload = () => {
            if (!isCurrent()) return;
            pendingImage = null;
            imageRaw.src = loader.src;
            imageRaw.style.visibility = 'visible';
            updateImageDetails(button, requestedIndex);
            if (item.classList.contains('logo-applied')) {
                const appliedLogo = item.querySelector('.item-logo');
                imageLogo.src = appliedLogo.src;
                imageLogo.className = appliedLogo.className.replace('item-logo', 'preview-watermark');
                imageLogo.style.opacity = appliedLogo.style.opacity;
                imageLogo.style.maxWidth = appliedLogo.style.maxWidth;
                imageLogo.style.visibility = 'visible';
            } else {
                imageLogo.className = 'preview-watermark d-none';
                imageLogo.removeAttribute('src');
            }
            imageStatus.textContent = '';
        };
        loader.onerror = () => {
            if (!isCurrent()) return;
            pendingImage = null;
            imageRaw.style.visibility = 'hidden';
            imageRaw.removeAttribute('src');
            imageLogo.className = 'preview-watermark d-none';
            imageLogo.removeAttribute('src');
            updateImageDetails(button, requestedIndex);
            imageStatus.textContent = 'Image preview could not be loaded. Try another image or reopen this preview to retry.';
        };
        loader.src = button.dataset.previewSrc;
    };

    previewButtons.forEach(button => {
        button.addEventListener('click', () => {
            previewTrigger = button;
            const id = button.closest('[data-item]').dataset.imageId;
            showPreviewImage(previewImages.findIndex(image => image.closest('[data-item]').dataset.imageId === id));
            imageModal.show();
        });
    });
    previousImage.addEventListener('click', () => { if (previewIndex >= 0) showPreviewImage(previewIndex - 1); });
    nextImage.addEventListener('click', () => { if (previewIndex >= 0) showPreviewImage(previewIndex + 1); });
    imageModalElement.addEventListener('keydown', event => {
        if (previewIndex < 0 || previewImages.length < 2 || event.target?.closest('input, textarea, select, [contenteditable="true"]')) return;
        if (event.key === 'ArrowLeft' || event.key === 'ArrowRight') {
            event.preventDefault();
            showPreviewImage(previewIndex + (event.key === 'ArrowRight' ? 1 : -1));
        }
    });
    imageModalElement.addEventListener('hidden.bs.modal', () => {
        cancelImageRequest();
        previewIndex = -1;
        imageRaw.style.visibility = 'hidden';
        imageRaw.removeAttribute('src');
        imageLogo.removeAttribute('src');
        imageLogo.className = 'preview-watermark d-none';
        imageStatus.textContent = '';
        imageCount.textContent = '';
        // This modal is opened manually, so Bootstrap does not restore trigger focus.
        if (previewTrigger?.isConnected) previewTrigger.focus();
        previewTrigger = null;
    });


    /* -------------------------------------------------------
     * Watermark Preview Modal
     * ----------------------------------------------------- */

    const settingsModal =
        document.getElementById(
            'watermarkPreviewModal'
        );

    const previewPosition =
        document.getElementById(
            'previewPosition'
        );

    const previewOpacity =
        document.getElementById(
            'previewOpacity'
        );

    const previewScale =
        document.getElementById(
            'previewScale'
        );

    const sampleLogo =
        document.getElementById(
            'sampleLogo'
        );

    const previewOpacityValue =
        document.getElementById(
            'previewOpacityValue'
        );

    const previewScaleValue =
        document.getElementById(
            'previewScaleValue'
        );


    const drawSample = () => {

        const settings =
            getSettings();

        sampleLogo.src =
            settings.url;

        sampleLogo.className =
            `preview-watermark ${previewPosition.value}`;

        sampleLogo.style.opacity =
            previewOpacity.value / 100;

        sampleLogo.style.maxWidth =
            `${40 * previewScale.value / 100}%`;

        previewOpacityValue.textContent =
            `${previewOpacity.value}%`;

        previewScaleValue.textContent =
            `${previewScale.value}%`;
    };


    settingsModal.addEventListener(
        'show.bs.modal',
        () => {

            const settings =
                getSettings();

            previewPosition.value =
                settings.position;

            previewOpacity.value =
                settings.opacity;

            previewScale.value =
                settings.scale;

            drawSample();
        }
    );


    [
        previewPosition,
        previewOpacity,
        previewScale
    ].forEach(control => {

        control.addEventListener(
            'input',
            drawSample
        );

    });


    /* -------------------------------------------------------
     * Use Preview Settings
     * ----------------------------------------------------- */

    document
        .getElementById('usePreviewSettings')
        .addEventListener('click', () => {

            position.value =
                previewPosition.value;

            opacity.value =
                previewOpacity.value;

            scale.value =
                previewScale.value;

            fillBulkSettings();
        });


    /* -------------------------------------------------------
     * Update Settings
     * ----------------------------------------------------- */

    defaultForm
        .querySelectorAll('input, select')
        .forEach(control => {

            control.addEventListener(
                'change',
                fillBulkSettings
            );

        });


    /* Initial state */
    syncSelection();

});
</script>

<?= view('layouts/app_footer') ?>
