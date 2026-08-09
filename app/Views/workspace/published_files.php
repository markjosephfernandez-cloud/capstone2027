<?= view('layouts/app_header', ['user' => $user, 'activePage' => $activePage, 'title' => 'Published Files']) ?>
<?php
$previewableImageTypes = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
$previewableVideoTypes = ['mp4', 'webm', 'mov', 'avi', 'mkv', 'm4v'];
$logoWatermarkTypes = ['jpg', 'jpeg', 'png', 'webp'];
$canManageMedia = (bool) ($canManage ?? false);
$workspaceMediaUrl = static function (int $fileId, string $action): string {
    $basePath = (string) (parse_url(site_url(''), PHP_URL_PATH) ?: '');
    $route = trim($basePath, '/') . '/workspace/file-management/' . $fileId . '/' . trim($action, '/');
    return '/' . ltrim($route, '/');
};
$isFolderView = isset($parentFolder) && $parentFolder;
$formatPublishedDate = static function ($value): string {
    $timestamp = is_string($value) && trim($value) !== '' ? strtotime($value) : false;
    return $timestamp === false ? 'Date unavailable' : date('M d, Y', $timestamp);
};
?>

<style>
    .media-workspace { --media-primary: #315efb; --media-ink: #17223b; --media-muted: #68748b; --media-border: #e4e9f2; }
    .media-workspace .library-hero { background: transparent; border: 0; padding: .35rem 0; }
    .media-workspace .hero-symbol { align-items: center; background: #e9efff; border-radius: 14px; color: #315efb; display: flex; flex: 0 0 auto; font-size: 1.35rem; height: 50px; justify-content: center; width: 50px; }
    .media-workspace .hero-eyebrow { color: #73809a; font-size: .72rem; font-weight: 700; letter-spacing: .06em; margin-bottom: .2rem; text-transform: uppercase; }
    .media-workspace .library-hero h2 { color: var(--media-ink); font-size: 1.4rem; font-weight: 700; margin: 0; }
    .media-workspace .library-hero p { color: var(--media-muted); margin: .35rem 0 0; }
    .media-workspace .library-card { border: 1px solid var(--media-border); border-radius: 18px; box-shadow: none; overflow: hidden; }
    .media-workspace .media-grid { align-items: stretch; display: grid; gap: 1.1rem; grid-template-columns: repeat(auto-fill, minmax(220px, 1fr)); padding: 1.25rem; }
    .media-workspace .media-grid-card { background: #fff; border: 1px solid var(--media-border); border-radius: 14px; color: inherit; display: flex; flex-direction: column; min-height: 320px; overflow: hidden; position: relative; text-decoration: none; transition: box-shadow .2s, transform .2s; }
    .media-workspace .media-grid-card:hover { box-shadow: 0 8px 24px rgba(37,52,85,.08); color: inherit; transform: translateY(-1px); }
    .media-workspace .grid-thumbnail { background: #f0f4f9; border: 0; border-bottom: 1px solid #eef1f6; display: block; flex: 0 0 170px; height: 170px; overflow: hidden; padding: 0; position: relative; width: 100%; }
    .media-workspace .grid-thumbnail img { height: 100%; object-fit: cover; transition: transform .3s; width: 100%; }
    .media-workspace .grid-thumbnail video { height: 100%; object-fit: cover; width: 100%; }
    .media-workspace .video-play-indicator { align-items: center; background: rgba(17,38,75,.78); border: 0; border-radius: 50%; color: #fff; display: flex; font-size: 1.2rem; height: 42px; justify-content: center; left: 50%; pointer-events: none; position: absolute; top: 50%; transform: translate(-50%,-50%); width: 42px; }
    .media-workspace .media-grid-card:hover .grid-thumbnail img { transform: scale(1.05); }
    .media-workspace .grid-file-icon { align-items: center; background: #f8faff; border-bottom: 1px solid #eef1f6; color: #a4b3cf; display: flex; flex: 0 0 170px; font-size: 3rem; height: 170px; justify-content: center; }
    .media-workspace .folder-tile-icon { background: linear-gradient(180deg, #f3f7ff 0%, #e8efff 100%); color: #6a85c2; }
    .media-workspace .grid-card-content { background: #fff; display: flex; flex: 1 1 auto; flex-direction: column; padding: 1rem; }
    .media-workspace .grid-file-name { color: #17223b; display: -webkit-box; font-size: .9rem; font-weight: 700; line-height: 1.25; margin-bottom: .35rem; min-height: 2.25rem; overflow: hidden; -webkit-box-orient: vertical; -webkit-line-clamp: 2; }
    .media-workspace .grid-file-info { color: #68748b; font-size: .75rem; margin-bottom: .4rem; }
    .media-workspace .grid-card-footer { margin-top: auto; }
    .media-workspace .file-type { background: #eef3ff; border-radius: 4px; color: #2455d6; display: inline-block; font-size: .65rem; font-weight: 700; padding: .15rem .35rem; text-transform: uppercase; }
    .media-workspace .empty-state { grid-column: 1 / -1; padding: 4rem 1rem; }
    .media-workspace .empty-state-icon { background: #f3f6fc; border-radius: 50%; color: #9ab0da; display: inline-flex; font-size: 3rem; height: 80px; justify-content: center; padding: 1rem; width: 80px; }
    .media-workspace .empty-state h3 { color: #263859; font-size: 1.15rem; font-weight: 700; margin: 1rem 0 .4rem; }
    .media-workspace .empty-state p { color: #6b7a99; font-size: .88rem; margin: 0 auto 1.5rem; max-width: 400px; }
    @media (max-width: 575.98px) {
        .media-workspace .media-grid { gap: .85rem; grid-template-columns: repeat(auto-fill, minmax(165px, 1fr)); padding: .85rem; }
        .media-workspace .media-grid-card { min-height: 300px; }
        .media-workspace .grid-thumbnail, .media-workspace .grid-file-icon { flex-basis: 145px; height: 145px; }
    }
</style>

<main class="main" id="main">
    <div class="pagetitle">
        <div class="d-flex justify-content-between align-items-center gap-3">
            <h1>Published Files</h1>
            <?php if ($isFolderView): ?>
                <a href="<?= esc(site_url('workspace/published-files')) ?>" class="btn btn-outline-secondary btn-sm">
                    <i class="bi bi-arrow-left me-1"></i>Back to Published Files
                </a>
            <?php endif; ?>
        </div>
        <nav>
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="<?= esc(site_url('dashboard')) ?>">Home</a></li>
                <li class="breadcrumb-item">Release Media</li>
                <li class="breadcrumb-item<?= $isFolderView ? '' : ' active' ?>">Published Files</li>
                <?php if ($isFolderView): ?>
                    <li class="breadcrumb-item active"><?= esc($parentFolder['name']) ?></li>
                <?php endif; ?>
            </ol>
        </nav>
    </div>

    <?php if (session()->has('message')): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <i class="bi bi-check-circle me-1"></i><?= esc(session('message')) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>
    <?php if (session()->has('error')): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <i class="bi bi-exclamation-octagon me-1"></i><?= esc(session('error')) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <section class="media-workspace">
        <div class="library-hero mb-3">
            <div class="d-flex gap-3 align-items-center">
                <div class="hero-symbol"><i class="bi <?= $isFolderView ? 'bi-folder2-open' : 'bi-send-check' ?>"></i></div>
                <div>
                    <div class="hero-eyebrow">Release Media</div>
                    <h2><?= $isFolderView ? esc($parentFolder['name']) : 'Published Files' ?></h2>
                    <p><?= $isFolderView ? 'Published media inside this folder is arranged in a clean grid.' : 'Review published folders and standalone files side by side in one organized grid.' ?></p>
                </div>
            </div>
        </div>

        <div class="card library-card">
            <div class="media-grid">
                <?php foreach ($folders as $folder): ?>
                    <a href="<?= esc(site_url('workspace/published-files/' . (int) $folder['id'])) ?>" class="media-grid-card">
                        <div class="grid-file-icon folder-tile-icon"><i class="bi bi-folder-fill"></i></div>
                        <div class="grid-card-content">
                            <div class="grid-file-name" title="<?= esc($folder['name']) ?>"><?= esc($folder['name']) ?></div>
                            <div class="d-flex align-items-center justify-content-between gap-2">
                                <span class="file-type">Folder</span>
                                <span class="grid-file-info mb-0"><?= (int) ($folder['file_count'] ?? 0) ?> file<?= (int) ($folder['file_count'] ?? 0) === 1 ? '' : 's' ?></span>
                            </div>
                            <div class="grid-file-info mt-2">
                                Posted by <?= esc($folder['publisher_name']) ?><br>
                                on <?= esc($formatPublishedDate($folder['published_at'] ?? null)) ?>
                            </div>
                            <div class="grid-card-footer">
                                <span class="badge bg-success-subtle text-success border border-success-subtle">
                                    <i class="bi bi-check2-circle me-1"></i>Published<?= ! empty($folder['publication_channel']) ? ' - ' . esc($folder['publication_channel']) : '' ?>
                                </span>
                            </div>
                        </div>
                    </a>
                <?php endforeach; ?>

                <?php foreach ($files as $file): ?>
                    <?php
                    $fileId = (int) $file['id'];
                    $fileType = strtolower((string) ($file['filetype'] ?? ''));
                    $fileMime = strtolower((string) ($file['mime_type'] ?? ''));
                    $isPreviewableImage = in_array($fileType, $previewableImageTypes, true);
                    $isPreviewableVideo = str_starts_with($fileMime, 'video/') || in_array($fileType, $previewableVideoTypes, true);
                    $supportsLogo = $canManageMedia && in_array($fileType, $logoWatermarkTypes, true);
                    $originalPreviewUrl = $workspaceMediaUrl($fileId, 'preview');
                    $videoPreviewUrl = $workspaceMediaUrl($fileId, 'video-preview');
                    $previewUrl = $workspaceMediaUrl($fileId, $supportsLogo ? 'watermarked-preview' : 'preview');
                    $mediaTitle = \App\Libraries\MediaTitle::forFile($file);
                    ?>
                    <article class="media-grid-card">
                        <?php if ($isPreviewableImage): ?>
                            <button
                                class="grid-thumbnail"
                                type="button"
                                data-bs-toggle="modal"
                                data-bs-target="#imagePreviewModal"
                                data-preview-src="<?= esc($previewUrl) ?>"
                                data-preview-name="<?= esc($mediaTitle) ?>"
                                data-preview-kind="image"
                                data-download-url="<?= esc($workspaceMediaUrl($fileId, 'download')) ?>"
                                data-logo-url="<?= $supportsLogo ? esc(site_url('workspace/watermarking#media-' . $fileId)) : '' ?>"
                                aria-label="Preview <?= esc($mediaTitle) ?>"
                            >
                                <img src="<?= esc($originalPreviewUrl) ?>" alt="Preview of <?= esc($mediaTitle) ?>" loading="lazy">
                            </button>
                        <?php elseif ($isPreviewableVideo): ?>
                            <button
                                class="grid-thumbnail"
                                type="button"
                                data-bs-toggle="modal"
                                data-bs-target="#imagePreviewModal"
                                data-preview-src="<?= esc($videoPreviewUrl) ?>"
                                data-preview-name="<?= esc($mediaTitle) ?>"
                                data-preview-kind="video"
                                data-download-url="<?= esc($workspaceMediaUrl($fileId, 'download')) ?>"
                                aria-label="Play <?= esc($mediaTitle) ?>"
                            >
                                <video src="<?= esc($videoPreviewUrl) ?>" muted playsinline preload="metadata" aria-hidden="true"></video>
                                <span class="video-play-indicator"><i class="bi bi-play-fill"></i></span>
                            </button>
                        <?php else: ?>
                            <div class="grid-file-icon"><i class="bi bi-file-earmark"></i></div>
                        <?php endif; ?>
                        <div class="grid-card-content">
                            <div class="grid-file-name" title="<?= esc($mediaTitle) ?>"><?= esc($mediaTitle) ?></div>
                            <div class="d-flex align-items-center justify-content-between gap-2">
                                <span class="file-type"><?= esc($file['filetype'] ?: 'Unknown') ?></span>
                            </div>
                            <div class="grid-file-info mt-2">
                                Posted by <?= esc($file['publisher_name']) ?><br>
                                on <?= esc($formatPublishedDate($file['published_at'] ?? null)) ?>
                            </div>
                            <div class="grid-card-footer">
                                <span class="badge bg-success-subtle text-success border border-success-subtle">
                                    <i class="bi bi-check2-circle me-1"></i>Published<?= ! empty($file['publication_channel']) ? ' - ' . esc($file['publication_channel']) : '' ?>
                                </span>
                            </div>
                        </div>
                    </article>
                <?php endforeach; ?>

                <?php if (empty($folders) && empty($files)): ?>
                    <div class="empty-state text-center">
                        <span class="empty-state-icon"><i class="bi bi-send-x"></i></span>
                        <h3><?= $isFolderView ? 'No published media in this folder' : 'No published files yet' ?></h3>
                        <p><?= $isFolderView ? 'Published images, videos, and other files will appear here.' : 'Media posted to channels will appear here.' ?></p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </section>
</main>

<?php if (!empty($files)): ?>
    <div class="modal fade media-workspace" id="imagePreviewModal" tabindex="-1" aria-labelledby="imagePreviewTitle" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-xl">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="imagePreviewTitle">Preview</h5>
                    <div class="ms-auto d-flex gap-2">
                        <a class="btn btn-primary btn-sm d-none" id="previewDownloadBtn" href="#"><i class="bi bi-download me-1"></i>Download Original</a>
                        <a class="btn btn-outline-secondary btn-sm d-none" id="previewLogoBtn" href="#"><i class="bi bi-patch-check me-1"></i>Download with Logo</a>
                    </div>
                    <button type="button" class="btn-close ms-2" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body text-center p-0" style="background:#000;">
                    <img id="previewImageTarget" src="" style="max-height:75vh; max-width:100%; object-fit:contain;" alt="">
                    <video id="previewVideoTarget" class="d-none" controls playsinline style="max-height:75vh; max-width:100%;" preload="metadata"></video>
                </div>
            </div>
        </div>
    </div>
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            var previewModal = document.getElementById('imagePreviewModal');
            if (!previewModal) return;

            var previewTarget = document.getElementById('previewImageTarget');
            var previewVideoTarget = document.getElementById('previewVideoTarget');
            var previewTitle = document.getElementById('imagePreviewTitle');
            var previewDownload = document.getElementById('previewDownloadBtn');
            var previewLogo = document.getElementById('previewLogoBtn');

            previewModal.addEventListener('show.bs.modal', function (event) {
                var button = event.relatedTarget;
                if (!button) return;

                var previewKind = button.dataset.previewKind || 'image';
                previewTarget.src = '';
                previewVideoTarget.pause();
                previewVideoTarget.removeAttribute('src');
                previewVideoTarget.load();
                previewTarget.classList.toggle('d-none', previewKind === 'video');
                previewVideoTarget.classList.toggle('d-none', previewKind !== 'video');
                if (previewKind === 'video') {
                    previewVideoTarget.src = button.dataset.previewSrc || '';
                } else {
                    previewTarget.src = button.dataset.previewSrc || '';
                }
                previewTitle.textContent = button.dataset.previewName || 'Preview';
                previewDownload.href = button.dataset.downloadUrl || '#';
                previewDownload.classList.remove('d-none');

                if (button.dataset.logoUrl) {
                    previewLogo.href = button.dataset.logoUrl;
                    previewLogo.classList.remove('d-none');
                } else {
                    previewLogo.classList.add('d-none');
                }
            });
        });
    </script>
<?php endif; ?>
<?= view('layouts/app_footer') ?>
