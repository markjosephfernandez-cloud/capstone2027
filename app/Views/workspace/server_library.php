<?= view('layouts/app_header', ['user' => $user, 'activePage' => $activePage, 'title' => $title]) ?>
<?php extract(\App\Services\ServerLibraryPresentation::data(get_defined_vars()), EXTR_OVERWRITE); ?>
<?php $taggedImageSearch = in_array(\App\Libraries\RolePolicy::role($user), ['admin', 'super_admin'], true); ?>
<?php $visibleFileCount = count($files); ?>
<?php $previewableImageTypes = \App\Services\CameraImagePreviewService::IMAGE_EXTENSIONS; ?>
<?php $previewableVideoTypes = ['mp4', 'webm', 'mov', 'avi', 'mkv', 'm4v']; ?>
<?php $logoWatermarkTypes = ['jpg', 'jpeg', 'png', 'webp']; ?>
<?php $canManageMedia = (bool) ($canManage ?? false); ?>
<?php
$workspaceMediaUrl = static function (string $fileId, string $action): string {
    $basePath = (string) (parse_url(site_url(''), PHP_URL_PATH) ?: '');
    $route = trim($basePath, '/') . '/workspace/server-library/items/' . $fileId . '/' . trim($action, '/');
    return '/' . ltrim($route, '/');
};
$pagination = $pagination ?? ['current_page' => 1, 'total_pages' => 1, 'total_results' => count($files), 'from' => $files === [] ? 0 : 1, 'to' => count($files)];
$locatedFileId = $locatedFileId ?? 0;
$mediaTypeLabel = static function (string $value): string {
    $extension = strtolower(ltrim(trim($value), '.'));
    $category = in_array($extension, \App\Services\CameraImagePreviewService::IMAGE_EXTENSIONS, true) ? 'image'
        : (in_array($extension, ['mp4', 'mov', 'webm', 'avi', 'mkv', 'm4v', 'wmv'], true) ? 'video' : 'file');
    return strtoupper($extension) . ' ' . $category;
};
$mediaTypeOptions = ['' => 'All file types'];
foreach ($fileTypes as $fileType) {
    $value = (string) $fileType['filetype'];
    if (trim($value) !== '') $mediaTypeOptions[$value] = $mediaTypeLabel($value);
}
if ($type !== '' && !isset($mediaTypeOptions[$type])) $mediaTypeOptions[$type] = $mediaTypeLabel($type);
$folder_pagination = $query !== '' && $taggedImageSearch ? ($folder_pagination ?? null) : null;
$folderSearchPage = (int) ($folder_pagination['current_page'] ?? 1);
$mediaPageUrl = static function (int $page) use ($query, $type, $folderId, $stage, $distribution, $locatedFileId, $folderSearchPage): string {
    $params = array_filter(['q' => $query, 'type' => $type, 'folder' => $folderId, 'stage' => $stage, 'distribution' => $distribution, 'locate' => $locatedFileId], static fn ($value): bool => $value !== '' && $value !== null && $value !== 0);
    $params['page'] = $page;
    if ($folderSearchPage > 1) $params['folder_page'] = $folderSearchPage;
    return site_url('workspace/server-library') . '?' . http_build_query($params);
};
$folderPageUrl = static function (int $page) use ($mediaPageUrl, $pagination): string {
    $url = $mediaPageUrl((int) $pagination['current_page']);
    $url = preg_replace('/&folder_page=[0-9]+/', '', $url);
    return $url . '&folder_page=' . $page;
};
?>

<?= view('components/media_library_styles') ?>

<main id="main" class="main media-workspace" data-catalog-version="<?= esc($catalog_version ?? '', 'attr') ?>" data-catalog-valid="<?= empty($error) ? '1' : '0' ?>" data-catalog-state="<?= esc($catalog_status['status'] ?? '', 'attr') ?>" data-folder-pending="<?= !empty($folder_index_pending) ? '1' : '0' ?>" data-catalog="<?= !empty($catalog_mode) ? '1' : '0' ?>" data-server-search="<?= $query !== '' ? '1' : '0' ?>" data-search-pending="<?= !empty($search_incomplete) ? '1' : '0' ?>">
    <script>
        // Restore before the browser parses the folders and files, avoiding a grid flash.
        (function () {
            var workspace = document.getElementById('main');
            // AJAX navigation may already have carried over the current layout.
            if (workspace.dataset.mediaLayout === 'list' || workspace.dataset.mediaLayout === 'grid') { return; }
            var initialView = 'grid';
            try {
                var savedView = window.localStorage.getItem('greyhound-server-library-view');
                if (savedView === null) {
                    // Preserve the old preference once, then keep it separate from Watermarking.
                    savedView = window.localStorage.getItem('greyhound-media-view');
                }
                if (savedView === 'list' || savedView === 'grid') { initialView = savedView; }
            } catch (error) { }
            workspace.dataset.mediaLayout = initialView;
            try { window.localStorage.setItem('greyhound-server-library-view', initialView); } catch (error) { }
        }());
    </script>
    <div class="pagetitle d-flex flex-wrap align-items-center justify-content-between gap-2">
        <div>
            <h1>File Management</h1>
            <nav>
                <ol class="breadcrumb mb-0">
                    <li class="breadcrumb-item"><a href="<?= esc(site_url('dashboard')) ?>">Dashboard</a></li>
                    <li class="breadcrumb-item active">File Management</li>
                </ol>
            </nav>
        </div>
    </div>

    <section class="section">
        <?php if (\App\Libraries\RolePolicy::allows($user, 'files.view_all')): ?>
        <nav class="nav nav-pills gap-2 mb-3" aria-label="Library source">
            <a class="nav-link active" aria-current="page" href="<?= esc(site_url('workspace/server-library')) ?>"><i class="bi bi-hdd-network me-1"></i>Server Library</a>
            <?php if (!(new \Config\Storage())->centralized()): ?>
            <a class="nav-link" href="<?= esc(site_url('workspace/file-management')) ?>">Local Library</a>
            <?php endif; ?>
        </nav>
        <?php endif; ?>
        <div id="serverStorageStatus" hidden data-server-storage data-connected="<?= !empty($connected) ? '1' : '0' ?>" data-writable="<?= !empty($writable) ? '1' : '0' ?>" data-status-url="<?= esc(site_url('workspace/server-library/status') . '?' . http_build_query(['folder'=>$folderId ?? ''])) ?>">
            <span id="serverConnection"></span><span id="serverAccess"></span><span id="serverConnectionHelp"></span>
            <button id="serverRefreshConnection" type="button">Check connection</button>
        </div>
        <div id="serverWriteError" class="alert alert-warning" role="alert" hidden></div>
        <div id="catalogLiveStatus" hidden role="status" aria-live="polite"></div>
        <?php if (! empty($success)): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <?= esc($success) ?><button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>
        <?php if (! empty($error)): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <?= esc($error) ?><button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>

        <div class="library-hero mb-4">
            <div class="d-flex align-items-center justify-content-between flex-wrap gap-3">
                <div class="d-flex align-items-center gap-3">
                    <span class="hero-symbol"><i class="bi bi-collection-play"></i></span>
                    <div>
                        <div class="hero-eyebrow"><i class="bi bi-folder2-open me-1"></i><?= esc($selectedFolderPath) ?></div>
                        <h2>Media Library</h2>
                        <p>Organise, review, and publish your team's images and videos.</p>
                    </div>
                </div>
                <div class="d-flex align-items-center flex-wrap gap-2">
                    <?php if ($canUpload): ?>
                    <div class="dropdown">
                        <?php // Catalog browsing leaves share access unchecked; uploads validate access when saving. ?>
                        <button class="btn drive-new-button dropdown-toggle" id="mediaNewButton" <?= empty($catalog_mode) && empty($writable) ? 'disabled' : '' ?> type="button" data-bs-toggle="dropdown" aria-expanded="false"><i class="bi bi-plus-lg me-2"></i>New</button>
                        <ul class="dropdown-menu drive-new-menu">
                            <?php if ($canManage): ?>
                            <li><button class="dropdown-item" type="button" data-bs-toggle="modal" data-bs-target="#createFolderModal"><i class="bi bi-folder-plus me-2"></i>New folder</button></li>
                            <li><hr class="dropdown-divider"></li>
                            <?php endif; ?>
                            <li><button class="dropdown-item" type="button" data-upload-picker="files"><i class="bi bi-file-earmark-arrow-up me-2"></i>Upload files</button></li>
                            <li><button class="dropdown-item" type="button" data-upload-picker="folder"><i class="bi bi-folder2-open me-2"></i>Upload a folder</button></li>
                        </ul>
                    </div>
                    <?php endif; ?>
                    <span id="catalogFolderCount" class="media-count"><i class="bi bi-folder"></i><?= esc((string) count($folders)) ?> <?= count($folders) === 1 ? ($selectedFolder === null ? 'folder' : 'subfolder') : ($selectedFolder === null ? 'folders' : 'subfolders') ?></span>
                    <span id="catalogFileCount" class="media-count"><i class="bi bi-collection"></i><?= esc((string) $visibleFileCount) ?> visible <?= $visibleFileCount === 1 ? 'file' : 'files' ?></span>
                </div>
            </div>
        </div>

        <?php if ($canUpload): ?>
        <?= view('components/media_upload', ['folderOptions'=>$folderOptions, 'folderId'=>$folderId, 'uploadLibrary'=>'server', 'showDuplicateModes'=>false, 'uploadFoldersUrl'=>site_url('workspace/server-library/folders'), 'uploadCreateFolderUrl'=>$canManage ? site_url('workspace/server-library/folders/create') : '', 'uploadAction'=>site_url('workspace/server-library/upload'), 'uploadTokenUrl'=>site_url('workspace/server-library/upload/token')]) ?>
<?php endif; ?>

        <div class="card library-card">
            <form class="filter-bar" id="mediaFilterForm" method="get" role="search">
                <?php if ($folderId !== null): ?><input type="hidden" name="folder" value="<?= esc($folderId) ?>"><?php endif; ?>
                <?php if ($stage !== ''): ?><input type="hidden" name="stage" value="<?= esc($stage) ?>"><?php endif; ?>
                <?php if ($distribution !== ''): ?><input type="hidden" name="distribution" value="<?= esc($distribution) ?>"><?php endif; ?>
                <div class="browser-heading">
                    <div><h3><?= $query !== '' ? ($taggedImageSearch ? 'Matching Images' : 'Matching Media') : 'Browse media' ?></h3><p><?= $taggedImageSearch ? 'Search folder names and image tags. Only images with matching saved tags appear in image results.' : 'Search filenames, folder names, original dates taken, event names, and tags.' ?></p></div>
                </div>
                <div class="row g-3 align-items-center">
                    <div class="col-xl-5">
                        <label class="visually-hidden" for="mediaSearch"><?= $taggedImageSearch ? 'Search folder names or image tags' : 'Search by filename, folder, original date taken, event, or tag' ?></label>
                        <div class="media-search-box">
                            <div class="input-group"><span class="input-group-text"><i class="bi bi-search"></i></span><input class="form-control" id="mediaSearch" name="q" placeholder="<?= $taggedImageSearch ? 'Search folder names or image tags' : 'Search filename, folder, date taken, event, or tag' ?>" value="<?= esc($query) ?>" autocomplete="off" maxlength="120" role="combobox" aria-autocomplete="list" aria-expanded="false" aria-controls="mediaSearchSuggestions" aria-describedby="mediaSearchStatus" data-suggestions-url="<?= esc(site_url('workspace/server-library/suggestions')) ?>"><button class="media-search-clear" id="mediaSearchClear" type="button" aria-label="Clear search" title="Clear search" <?= $query === '' ? 'hidden' : '' ?>><i class="bi bi-x-lg" aria-hidden="true"></i></button></div>
                            <div class="media-suggestions" id="mediaSearchSuggestions" role="listbox" aria-label="Search suggestions" hidden></div>
                        </div>
                    </div>
                    <div class="col-sm-5 col-xl-3">
                        <label class="visually-hidden" for="mediaType">Filter media by file type</label>
                        <select class="form-select" id="mediaType" name="type">
                            <?php foreach ($mediaTypeOptions as $value => $label): ?>
                                <option value="<?= esc((string) $value) ?>" <?= $type === (string) $value ? 'selected' : '' ?>><?= esc($label) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <div class="dropdown media-type-dropdown" id="mediaTypeDropdown" hidden>
                            <button type="button" class="media-type-toggle" id="mediaTypeToggle" data-bs-toggle="dropdown" aria-expanded="false" aria-haspopup="menu" aria-controls="mediaTypeMenu" aria-label="File type: <?= esc($mediaTypeOptions[$type]) ?>">
                                <span id="mediaTypeLabel"><?= esc($mediaTypeOptions[$type]) ?></span><i class="bi bi-chevron-down small" aria-hidden="true"></i>
                            </button>
                            <ul class="dropdown-menu media-type-menu" id="mediaTypeMenu" role="menu" aria-labelledby="mediaTypeToggle">
                                <?php foreach ($mediaTypeOptions as $value => $label): ?>
                                <li role="none"><button type="button" class="dropdown-item" role="menuitemradio" aria-checked="<?= $type === (string) $value ? 'true' : 'false' ?>" data-media-type="<?= esc((string) $value) ?>"><span><?= esc($label) ?></span><i class="bi bi-check2" aria-hidden="true"></i></button></li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    </div>
                    <div class="col-sm-7 col-xl-4">
                        <div class="filter-actions">
                            <button class="btn btn-primary flex-grow-1" type="submit"><i class="bi bi-funnel me-1"></i> Apply filters</button>
                            <div class="media-view-switcher" role="group" aria-label="Choose media view">
                                <button class="media-view-toggle" type="button" data-media-view="list" aria-label="Show file list" aria-pressed="false" title="List view"><i class="bi bi-list-ul"></i></button>
                                <button class="media-view-toggle is-active" type="button" data-media-view="grid" aria-label="Show image grid" aria-pressed="true" title="Grid view"><i class="bi bi-grid-3x3-gap-fill"></i></button>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="search-live-status" id="mediaSearchStatus" role="status" aria-live="polite">Choose a suggestion, press Enter, or click Apply filters to search.</div>
            </form>

            <div id="catalogProgress">
            <?php if (empty($search_incomplete) && empty($error) && $query !== '' && $folders === [] && $files === []): ?><div class="alert alert-light border m-3"><i class="bi bi-search me-1"></i>No matching folders or media found.</div><?php endif; ?>

            </div>
            <div class="folder-location d-flex align-items-center justify-content-between flex-wrap gap-2">
                <nav class="folder-path" aria-label="Current library folder">
                    <i class="bi bi-folder2-open me-1" aria-hidden="true"></i>
                    <a data-ajax-preserve-scroll href="<?= esc(site_url('workspace/server-library')) ?>" <?= $selectedFolder === null ? 'aria-current="page"' : '' ?>>Root Library</a>
                    <?php foreach (($folderBreadcrumbs ?? []) as $crumb): ?>
                        <i class="bi bi-chevron-right small" aria-hidden="true"></i>
                        <a data-ajax-preserve-scroll href="<?= esc(site_url('workspace/server-library?folder=' . rawurlencode($crumb['id']))) ?>" <?= (string) $crumb['id'] === (string) ($folderId ?? '') ? 'aria-current="page"' : '' ?>><?= esc($crumb['name']) ?></a>
                    <?php endforeach; ?>
                </nav>
                </div>
            <div id="catalogFolders">
            <?php if ($folders !== []): ?>
                <div class="folder-browser">
                    <p class="folder-browser-title"><i class="bi bi-folder2-open me-1"></i><?= $query !== '' ? 'Matching Folders' : ($selectedFolder === null ? 'Folders' : 'Subfolders') ?></p>
                    <p class="folder-browser-subtitle"><?= $selectedFolder === null ? 'Open a folder to view the media uploaded inside it.' : 'Open a subfolder or create another folder inside the current folder.' ?></p>
                    <?php if ($canManage): ?>
                    <div class="d-flex align-items-center flex-wrap gap-2 mb-3" id="folderSelectionToolbar">
                        <button type="button" class="btn btn-outline-primary btn-sm" id="toggleFolderSelection" aria-pressed="false"><i class="bi bi-check2-square" aria-hidden="true"></i><span>Select all</span></button>
                        <span class="badge bg-light text-primary" id="folderSelectionCount" hidden aria-live="polite">0 selected</span>
                        <button type="button" class="btn btn-outline-primary btn-sm ms-auto" id="openFolderMove" hidden disabled><i class="bi bi-folder-symlink" aria-hidden="true"></i>Move to</button>
                        <button type="button" class="btn btn-outline-danger btn-sm" id="openFolderTrash" hidden disabled><i class="bi bi-trash" aria-hidden="true"></i>Trash</button>
                    </div>
                    <?php endif; ?>
                    <div class="folder-grid" id="folderBrowserView">
                        <div class="folder-list-header" aria-hidden="true"><span>Folder name</span><span class="folder-list-modified">Date modified</span><span>Contents</span></div>
                        <?php foreach ($folders as $folder): ?><?php $directFileCount=(int)($folder['file_count']??0); $directFolderCount=(int)($folder['subfolder_count']??0); ?>
                            <div class="folder-card<?= $canManage ? ' has-folder-actions' : '' ?>" data-server-count-path="<?= esc($folder['id'], 'attr') ?>" data-library-search="<?= esc(mb_strtolower((string) $folder['name']), 'attr') ?>">
                                <?php if ($canManage): ?><input type="checkbox" class="form-check-input ms-2 flex-shrink-0" data-folder-select value="<?= esc($folder['id'], 'attr') ?>" data-folder-name="<?= esc($folder['name'], 'attr') ?>" aria-label="Select folder <?= esc($folder['name'], 'attr') ?>"><?php endif; ?>
                                <a data-ajax-preserve-scroll class="folder-card-main" href="<?= esc(site_url('workspace/server-library?folder=' . rawurlencode($folder['id']))) ?>" aria-label="Open folder <?= esc($folder['name']) ?>">
                                    <span class="folder-name-cell"><span class="folder-card-icon"><i class="bi bi-folder-fill"></i></span><span class="min-w-0"><span class="folder-card-name d-block"><?= esc($folder['name']) ?></span><span class="folder-card-meta"><?php if (!empty($catalog_mode)): ?>Open folder to view contents<?php else: ?><?= $directFileCount ?> file<?= $directFileCount===1?'':'s' ?> &middot; <?= $directFolderCount ?> folder<?= $directFolderCount===1?'':'s' ?><?php endif; ?></span></span></span>
                                    <span class="folder-list-cell folder-list-modified"><?= esc(! empty($folder['created_at']) ? \App\Libraries\DisplayTime::format($folder['created_at'], 'M d, Y') : '-') ?></span>
                                    <span class="folder-list-cell"><?php if (!empty($catalog_mode)): ?>Open folder to view contents<?php else: ?><?= $directFileCount ?> file<?= $directFileCount===1?'':'s' ?> &middot; <?= $directFolderCount ?> folder<?= $directFolderCount===1?'':'s' ?><?php endif; ?></span>
                                </a>
                                <?php if ($canManage): ?>
                                    <div class="dropdown folder-card-menu">
                                        <button class="drive-more-button" type="button" data-bs-toggle="dropdown" aria-expanded="false" aria-label="Actions for folder <?= esc($folder['name'], 'attr') ?>"><i class="bi bi-three-dots-vertical" aria-hidden="true"></i></button>
                                        <ul class="dropdown-menu dropdown-menu-end drive-action-menu">
                                            <li><button class="dropdown-item" type="button" data-bs-toggle="modal" data-bs-target="#renameFolderModal" data-rename-url="<?= esc(site_url('workspace/server-library/folders/' . bin2hex($folder['id']) . '/rename'), 'attr') ?>" data-folder-name="<?= esc($folder['name'], 'attr') ?>"><i class="bi bi-pencil me-2" aria-hidden="true"></i>Rename</button></li>
                                            <li><hr class="dropdown-divider"></li>
                                            <li><form action="<?= esc(site_url('workspace/server-library/folders/' . bin2hex($folder['id']) . '/delete')) ?>" method="post" data-confirm-kind="trash" data-confirm-message="<?= esc('Move folder "' . $folder['name'] . '" and all its files and subfolders to Trash?', 'attr') ?>">
                                                <?= csrf_field() ?>
                                                <button class="dropdown-item text-danger" type="submit"><i class="bi bi-trash me-2" aria-hidden="true"></i>Delete</button>
                                            </form></li>
                                        </ul>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>

            <?php if (!empty($folder_pagination)): ?>
                <div class="px-3 py-2 text-muted small">Showing <?= (int) $folder_pagination['from'] ?>–<?= (int) $folder_pagination['to'] ?> of <?= (int) $folder_pagination['total_results'] ?> matching folders</div>
                <?= view('components/pagination', ['pagination'=>$folder_pagination, 'pageUrl'=>$folderPageUrl, 'paginationLabel'=>'Matching folder pages']) ?>
            <?php endif; ?>
            </div>
            <div class="modal fade" id="postMediaModal" tabindex="-1" aria-labelledby="postMediaTitle" aria-hidden="true">
                <div class="modal-dialog modal-dialog-centered"><div class="modal-content"><form id="postMediaForm" method="post" action="<?= esc(site_url('workspace/server-library/post-selection')) ?>"><?= csrf_field() ?><div class="modal-header"><h5 class="modal-title" id="postMediaTitle">Post to Mobile App</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div><div class="modal-body">
                <input type="hidden" name="post_type" id="postMediaType" value="">
                <input type="hidden" name="folder_id" id="postMediaFolderId" value="">
                <div id="postMediaFileIds"></div>
                <p class="mb-0">Are you sure you want to post this?</p>
                </div><div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button><button class="btn btn-primary" type="submit">Post</button></div></form></div></div>
            </div>
            <form class="bulk-toolbar" id="bulkActionForm" method="post" action="<?= esc(site_url('workspace/server-library/bulk-download' . ($folderId === null ? '' : '?folder=' . rawurlencode($folderId)))) ?>">
                <?= csrf_field() ?>
                <span id="bulkSelectedInputs"></span>
                <div class="bulk-selection">
                    <button class="btn btn-sm btn-outline-primary" id="selectAllMedia" type="button"><i class="bi bi-check2-square me-1"></i>Select all</button>
                    <span class="bulk-selection-count d-none" id="bulkSelectionCount" aria-live="polite">0 selected</span>
                </div>
                <div class="bulk-actions">
                    <?php if ($canManage): ?>
                    <button class="btn btn-sm btn-outline-primary" type="button" data-bulk-action="post" data-bs-toggle="modal" data-bs-target="#postMediaModal" disabled><i class="bi bi-send me-1"></i>Post</button>
                    <button class="btn btn-sm btn-outline-secondary" type="button" data-bulk-action="tags" data-bs-toggle="modal" data-bs-target="#manualTaggingModal" disabled><i class="bi bi-tags me-1"></i>Tags</button>
                    <?php endif; ?>
                    <button class="btn btn-sm btn-outline-primary" type="submit" data-bulk-action="download" disabled><i class="bi bi-file-earmark-zip me-1"></i>Download</button>
                    <?php if ($canManage): ?><button class="btn btn-sm btn-outline-danger" type="submit" data-bulk-action="trash" data-confirm-kind="trash" data-confirm-message="Move the selected media files to Trash?" formaction="<?= esc(site_url('workspace/server-library/bulk-trash' . ($folderId === null ? '' : '?folder=' . rawurlencode($folderId)))) ?>" disabled><i class="bi bi-trash me-1"></i>Trash</button><?php endif; ?>
                </div>
            </form>

            <div class="table-responsive" id="mediaListView">
                <table class="table media-table align-middle">
                    <thead><tr><th class="text-center"><input class="form-check-input media-select" type="checkbox" data-media-select-all aria-label="Select all visible media"></th><th>File</th><th>Type</th><th>Size</th><th>Uploaded</th><th>Tags</th></tr></thead>
                    <tbody>
                        <?php foreach ($files as $file): ?>
                            <?php $fileId = (string) $file['id']; $fileType = strtolower((string) $file['filetype']); $isPreviewableImage = in_array($fileType, $previewableImageTypes, true); $isPreviewableVideo = str_starts_with(strtolower((string) ($file['mime_type'] ?? '')), 'video/') || in_array($fileType, $previewableVideoTypes, true); $supportsLogo = $canManage && in_array($fileType, $logoWatermarkTypes, true); $previewUrl = $workspaceMediaUrl($fileId, 'preview'); $originalPreviewUrl = $workspaceMediaUrl($fileId, 'preview'); $videoPreviewUrl = $workspaceMediaUrl($fileId, 'video-preview'); $downloadUrl = $workspaceMediaUrl($fileId, 'download'); $mediaTitle = trim((string) ($file['filename'] ?? '')) ?: \App\Libraries\MediaTitle::forFile($file); $mediaSearchText = mb_strtolower(implode(' ', [$mediaTitle, (string) ($file['display_title'] ?? ''), (string) ($file['tags'] ?? ''), (string) ($file['classification'] ?? ''), (string) ($file['detected_objects'] ?? '')])); ?>
                            <tr <?= (string) ($locatedFileId ?? '') === $fileId ? 'data-located-file tabindex="-1"' : '' ?> data-library-search="<?= esc($mediaSearchText, 'attr') ?>">
                                <td class="text-center"><input class="form-check-input media-select" type="checkbox" data-media-select data-media-id="<?= esc($file['id']) ?>" aria-label="Select <?= esc($mediaTitle) ?>"></td>
                                <td>
                                    <div class="d-flex align-items-center gap-2">
                                        <?php if ($isPreviewableImage): ?>
                                            <button class="file-thumbnail" type="button" data-bs-toggle="modal" data-bs-target="#mediaPreviewModal" data-media-preview data-preview-kind="image" data-preview-src="<?= esc($previewUrl) ?>" data-original-preview-src="<?= esc($originalPreviewUrl) ?>" data-preview-name="<?= esc($mediaTitle) ?>" data-download-url="<?= esc($downloadUrl) ?>" data-logo-url="<?= $supportsLogo ? esc(site_url('workspace/server-library/watermarking?item=' . $fileId)) : '' ?>" aria-label="Preview <?= esc($mediaTitle) ?>">
                                                
                                                <img <?= !empty($catalog_mode) ? 'data-server-thumbnail' : 'src' ?>="<?= esc($originalPreviewUrl . '?thumbnail=1') ?>" decoding="async" alt="Preview of <?= esc($mediaTitle) ?>" loading="lazy">
                                            </button>
                                        <?php elseif ($isPreviewableVideo): ?>
                                            <button class="file-thumbnail" type="button" data-bs-toggle="modal" data-bs-target="#mediaPreviewModal" data-media-preview data-preview-kind="video" data-preview-src="<?= esc($videoPreviewUrl) ?>" data-preview-name="<?= esc($mediaTitle) ?>" data-download-url="<?= esc($downloadUrl) ?>" aria-label="Play <?= esc($mediaTitle) ?>"><span class="file-icon"><i class="bi bi-play-circle"></i></span></button>
                                        <?php else: ?>
                                            <span class="file-icon"><i class="bi bi-file-earmark"></i></span>
                                        <?php endif; ?>
                                        <div class="min-w-0"><div class="file-name"><?= esc($mediaTitle) ?></div><?php if (! empty($file['description'])): ?><div class="file-description"><?= esc($file['description']) ?></div><?php endif; ?><?php if ($query !== ''): ?><a class="file-location-link" href="<?= esc(empty($file['containing_folder_id']) ? site_url('workspace/server-library') : site_url('workspace/server-library?folder='.rawurlencode($file['containing_folder_id']))) ?>" title="Open <?= esc((string)($file['folder_display_path']??'Root Library')) ?>"><i class="bi bi-folder2-open"></i><span><?= esc((string)($file['folder_display_path']??'Root Library')) ?></span></a><?php endif; ?></div>
                                    </div>
                                </td>
                                <td><span class="file-type"><?= esc($file['filetype'] ?: 'Unknown') ?></span></td>
                                <td><?= esc(format_bytes((int) $file['size'])) ?></td>
                                <td><?= esc($file['dateupload'] ? \App\Libraries\DisplayTime::format($file['dateupload'], 'M d, Y') : '-') ?></td>
                                <td><span class="tags-value" title="<?= esc($file['tags'] ?: '') ?>"><?= esc($file['tags'] ?: '-') ?></span></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if ($files === [] && $folders === [] && $query === '' && empty($error) && empty($search_incomplete)): ?>
                            <tr><td colspan="6" class="empty-state text-center"><span class="empty-state-icon"><i class="bi bi-images"></i></span><h3>Your media library is empty</h3><p>Upload your first file to start building a secure, searchable library for your team.</p><button class="btn btn-primary btn-sm" type="button" data-upload-picker="files"><i class="bi bi-cloud-arrow-up me-1"></i> Upload a file</button></td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <div class="media-grid" id="mediaGridView">
                <?php foreach ($files as $file): ?>
                    <?php $fileId = (string) $file['id']; $fileType = strtolower((string) $file['filetype']); $isPreviewableImage = in_array($fileType, $previewableImageTypes, true); $isPreviewableVideo = str_starts_with(strtolower((string) ($file['mime_type'] ?? '')), 'video/') || in_array($fileType, $previewableVideoTypes, true); $supportsLogo = $canManage && in_array($fileType, $logoWatermarkTypes, true); $previewUrl = $workspaceMediaUrl($fileId, 'preview'); $originalPreviewUrl = $workspaceMediaUrl($fileId, 'preview'); $videoPreviewUrl = $workspaceMediaUrl($fileId, 'video-preview'); $downloadUrl = $workspaceMediaUrl($fileId, 'download'); $mediaTitle = trim((string) ($file['filename'] ?? '')) ?: \App\Libraries\MediaTitle::forFile($file); $mediaSearchText = mb_strtolower(implode(' ', [$mediaTitle, (string) ($file['display_title'] ?? ''), (string) ($file['tags'] ?? ''), (string) ($file['classification'] ?? ''), (string) ($file['detected_objects'] ?? '')])); ?>
                    <article class="media-grid-card" <?= (string) ($locatedFileId ?? '') === $fileId ? 'data-located-file tabindex="-1"' : '' ?> data-library-search="<?= esc($mediaSearchText, 'attr') ?>">
                        <label class="grid-select" title="Select <?= esc($mediaTitle) ?>"><input class="form-check-input media-select" type="checkbox" data-media-select data-media-id="<?= esc($file['id']) ?>" aria-label="Select <?= esc($mediaTitle) ?>"></label>
                        <div class="dropdown grid-more">
                            <button class="drive-more-button bg-white" type="button" data-bs-toggle="dropdown" aria-expanded="false" aria-label="Actions for <?= esc($mediaTitle) ?>"><i class="bi bi-three-dots-vertical"></i></button>
                            <ul class="dropdown-menu dropdown-menu-end drive-action-menu">
                                <?php if ($isPreviewableImage || $isPreviewableVideo): ?><li><button class="dropdown-item" type="button" data-bs-toggle="modal" data-bs-target="#mediaPreviewModal" data-media-preview data-preview-kind="<?= $isPreviewableVideo ? 'video' : 'image' ?>" data-preview-src="<?= esc($isPreviewableVideo ? $videoPreviewUrl : $previewUrl) ?>" data-original-preview-src="<?= $isPreviewableImage ? esc($originalPreviewUrl) : '' ?>" data-preview-name="<?= esc($mediaTitle) ?>" data-download-url="<?= esc($downloadUrl) ?>" data-logo-url="<?= $supportsLogo ? esc(site_url('workspace/server-library/watermarking?item=' . $fileId)) : '' ?>"><i class="bi <?= $isPreviewableVideo ? 'bi-play-circle' : 'bi-eye' ?> me-2"></i><?= $isPreviewableVideo ? 'Play video' : 'Preview' ?></button></li><?php endif; ?>
                                <li><a class="dropdown-item" href="<?= esc($downloadUrl) ?>"><i class="bi bi-download me-2"></i>Download</a></li>
                                <?php if ($canManage && in_array(strtolower((string) $file['filetype']), $logoWatermarkTypes, true)): ?><li><a class="dropdown-item" href="<?= esc(site_url('workspace/server-library/watermarking?item=' . $file['id'])) ?>"><i class="bi bi-patch-check me-2"></i>Download with logo</a></li><?php endif; ?>
                                <?php if ($canManage): ?><li><hr class="dropdown-divider"></li><li><form action="<?= esc(site_url('workspace/server-library/items/' . $file['id'] . '/trash')) ?>" method="post" data-confirm-kind="trash" data-confirm-message="Move this file to Trash?"><?= csrf_field() ?><button class="dropdown-item text-danger" type="submit"><i class="bi bi-trash me-2"></i>Move to Trash</button></form></li><?php endif; ?>
                            </ul>
                        </div>
                        <?php if ($isPreviewableImage): ?>
                            <button class="grid-thumbnail" type="button" data-bs-toggle="modal" data-bs-target="#mediaPreviewModal" data-media-preview data-preview-kind="image" data-preview-src="<?= esc($previewUrl) ?>" data-original-preview-src="<?= esc($originalPreviewUrl) ?>" data-preview-name="<?= esc($mediaTitle) ?>" data-download-url="<?= esc($downloadUrl) ?>" data-logo-url="<?= $supportsLogo ? esc(site_url('workspace/server-library/watermarking?item=' . $fileId)) : '' ?>" aria-label="Preview <?= esc($mediaTitle) ?>">
                                
                                <img <?= !empty($catalog_mode) ? 'data-server-thumbnail' : 'src' ?>="<?= esc($originalPreviewUrl . '?thumbnail=1') ?>" decoding="async" alt="Preview of <?= esc($mediaTitle) ?>" loading="lazy">
                            </button>
                        <?php elseif ($isPreviewableVideo): ?>
                            <button class="grid-thumbnail video-thumbnail" type="button" data-bs-toggle="modal" data-bs-target="#mediaPreviewModal" data-media-preview data-preview-kind="video" data-preview-src="<?= esc($videoPreviewUrl) ?>" data-preview-name="<?= esc($mediaTitle) ?>" data-download-url="<?= esc($downloadUrl) ?>" aria-label="Play <?= esc($mediaTitle) ?>">
                                <?php // Loading video metadata can transcode the entire NAS file and exhaust HTTP workers. ?>
                                <img <?= !empty($catalog_mode) ? 'data-server-thumbnail' : 'src' ?>="<?= esc($originalPreviewUrl . '?thumbnail=1') ?>" decoding="async" alt="Preview of <?= esc($mediaTitle) ?>" loading="lazy">
                            </button>
                        <?php else: ?>
                            <div class="grid-file-icon"><i class="bi bi-file-earmark"></i></div>
                        <?php endif; ?>
                        <div class="grid-card-content">
                            <div class="grid-file-name" title="<?= esc($mediaTitle) ?>"><?= esc($mediaTitle) ?></div>
                            <div class="d-flex align-items-center justify-content-between gap-2"><span class="file-type"><?= esc($file['filetype'] ?: 'Unknown') ?></span><span class="grid-file-info"><?= esc(format_bytes((int) $file['size'])) ?></span></div>
                            <div class="grid-file-info"><i class="bi bi-calendar3 me-1"></i><?= esc($file['dateupload'] ? \App\Libraries\DisplayTime::format($file['dateupload'], 'M d, Y') : '-') ?></div>
                            <?php if ($query !== ''): ?><a class="file-location-link" href="<?= esc(empty($file['containing_folder_id']) ? site_url('workspace/server-library') : site_url('workspace/server-library?folder='.rawurlencode($file['containing_folder_id']))) ?>" title="Open <?= esc((string)($file['folder_display_path']??'Root Library')) ?>"><i class="bi bi-folder2-open"></i><span><?= esc((string)($file['folder_display_path']??'Root Library')) ?></span></a><?php endif; ?>

                        </div>
                    </article>
                <?php endforeach; ?>
                <?php if ($files === [] && $folders === [] && $query === '' && empty($error) && empty($search_incomplete)): ?>
                    <div class="empty-state text-center"><span class="empty-state-icon"><i class="bi bi-images"></i></span><h3>Your media library is empty</h3><p>Upload your first file to start building a secure, searchable library for your team.</p><button class="btn btn-primary btn-sm" type="button" data-upload-picker="files"><i class="bi bi-cloud-arrow-up me-1"></i> Upload a file</button></div>
                <?php endif; ?>
            </div>
        </div>
            <div id="catalogPagination">
            <div class="px-3 py-2 text-muted small">Showing <?= (int) $pagination['from'] ?>–<?= (int) $pagination['to'] ?> of <?= (int) $pagination['total_results'] ?><?= !empty($folder_pagination) ? ' matching images' : (empty($catalog_mode) ? ' files' : ' entries') ?><?= !empty($search_incomplete) ? ' found so far' : '' ?></div>
            <?= view('components/pagination', ['pagination' => $pagination, 'pageUrl' => $mediaPageUrl, 'paginationLabel' => 'File Management pages']) ?>
            </div>
    </section>
</main>

<?php if ($canManage): ?>
<div class="modal media-workspace" id="renameFolderModal" tabindex="-1" aria-labelledby="renameFolderTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered new-folder-dialog">
        <div class="modal-content">
            <h2 class="modal-title" id="renameFolderTitle">Rename folder</h2>
            <form id="renameFolderForm" method="post">
                <?= csrf_field() ?>
                <label class="visually-hidden" for="rename_folder_name">Folder name</label>
                <input class="form-control new-folder-input" id="rename_folder_name" name="name" maxlength="120" required autocomplete="off">
                <div class="new-folder-actions">
                    <button class="btn btn-link" type="button" data-bs-dismiss="modal">Cancel</button>
                    <button class="btn btn-link" type="submit">Save</button>
                </div>
            </form>
        </div>
    </div>
</div>
<div class="modal media-workspace" id="createFolderModal" tabindex="-1" aria-labelledby="createFolderTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered new-folder-dialog">
        <div class="modal-content">
            <h2 class="modal-title" id="createFolderTitle">New folder</h2>
            <form id="createFolderForm" action="<?= esc(site_url('workspace/server-library/folders/create')) ?>" method="post">
                <?= csrf_field() ?>
                <?php if ($folderId !== null): ?><input type="hidden" name="parent_id" value="<?= esc($folderId) ?>"><?php endif; ?>
                <label class="visually-hidden" for="folder_name">Folder name</label>
                <input class="form-control new-folder-input" id="folder_name" name="name" value="Untitled folder" maxlength="120" required autocomplete="off">
                <div class="new-folder-actions">
                    <button class="btn btn-link" type="button" data-bs-dismiss="modal">Cancel</button>
                    <button class="btn btn-link" type="submit">Create</button>
                </div>
            </form>
        </div>
    </div>
</div>
<script>
document.addEventListener('DOMContentLoaded', function () {
    var renameModal = document.getElementById('renameFolderModal');
    var renameForm = document.getElementById('renameFolderForm');
    var renameInput = document.getElementById('rename_folder_name');
    renameModal.addEventListener('show.bs.modal', function (event) {
        var button = event.relatedTarget;
        if (!button || !button.dataset.renameUrl) {
            event.preventDefault();
            return;
        }
        renameForm.action = button.dataset.renameUrl;
        renameInput.value = button.dataset.folderName;
        renameInput.setCustomValidity('');
    });
    renameModal.addEventListener('shown.bs.modal', function () {
        renameInput.focus();
        renameInput.select();
    });
    renameInput.addEventListener('input', function () {
        renameInput.setCustomValidity('');
    });
    renameForm.addEventListener('submit', function (event) {
        if (renameForm.getAttribute('aria-busy') === 'true') {
            event.preventDefault();
            return;
        }
        renameInput.value = renameInput.value.trim();
        if (!renameInput.value) {
            event.preventDefault();
            renameInput.setCustomValidity('Enter a folder name.');
            renameInput.reportValidity();
            return;
        }
        bootstrap.Modal.getInstance(renameModal).hide();
    });

    var modal = document.getElementById('createFolderModal');
    var form = document.getElementById('createFolderForm');
    var nameInput = document.getElementById('folder_name');

    modal.addEventListener('shown.bs.modal', function () {
        form.reset();
        nameInput.setCustomValidity('');
        nameInput.focus();
        nameInput.select();
    });
    nameInput.addEventListener('input', function () {
        nameInput.setCustomValidity('');
    });
    form.addEventListener('submit', function (event) {
        if (form.getAttribute('aria-busy') === 'true') {
            event.preventDefault();
            return;
        }
        nameInput.value = nameInput.value.trim();
        if (!nameInput.value) {
            event.preventDefault();
            nameInput.setCustomValidity('Enter a folder name.');
            nameInput.reportValidity();
            return;
        }
        // Release the focus trap before AJAX replaces the page after creation.
        bootstrap.Modal.getInstance(modal).hide();
    });
});
</script>
<?php endif; ?>

<?php if ($canManage): ?>
<div class="modal fade" id="manualTaggingModal" tabindex="-1" aria-labelledby="manualTaggingTitle" aria-hidden="true"><div class="modal-dialog modal-dialog-centered" style="max-width:460px"><div class="modal-content"><form id="manualTaggingForm" action="<?= esc(site_url('workspace/server-library/tags')) ?>" method="post"><?= csrf_field() ?><div class="modal-header"><h5 class="modal-title" id="manualTaggingTitle">Manual Tagging</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div><div class="modal-body"><label class="form-label" for="manualTagInput">Tags</label><input class="form-control" id="manualTagInput" maxlength="50" placeholder="Enter a tag and press Enter" autocomplete="off"><div class="d-flex flex-wrap gap-2 mt-3" id="manualTagChips"></div><div id="manualTagValues"></div><div id="manualTagFileIds"></div><div class="form-text">Press Enter or comma to add. Tags are trimmed, limited to 50 characters, and duplicates are ignored.</div></div><div class="modal-footer"><button class="btn btn-light" type="button" data-bs-dismiss="modal">Cancel</button><button class="btn btn-primary" type="submit">Save Tags</button></div></form></div></div></div>

<?php endif; ?>
<?php if ($files !== [] || !empty($catalog_mode)): ?>
    <div class="modal fade media-workspace" id="mediaPreviewModal" tabindex="-1" aria-labelledby="mediaPreviewTitle" aria-hidden="true">
        <div class="modal-dialog modal-xl modal-dialog-centered image-preview-dialog">
            <div class="modal-content">
                <div class="modal-header"><div><h5 class="modal-title" id="mediaPreviewTitle">Media preview</h5><div class="small text-muted" id="mediaPreviewStatus" role="status">Your original file stays unchanged.</div></div><span class="small text-muted ms-3 me-auto" id="mediaPreviewCount" aria-live="polite"></span><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
                <div class="modal-body image-preview-body position-relative"><img class="d-none" id="mediaPreviewImage" alt=""><video class="d-none" id="mediaPreviewVideo" controls playsinline preload="metadata"></video>
                    <button class="preview-gallery-arrow previous d-none" id="mediaPreviewPrevious" type="button" aria-label="Previous image" title="Previous image"><i class="bi bi-chevron-left" aria-hidden="true"></i></button>
                    <button class="preview-gallery-arrow next d-none" id="mediaPreviewNext" type="button" aria-label="Next image" title="Next image"><i class="bi bi-chevron-right" aria-hidden="true"></i></button>
                </div>
                <div class="modal-footer"><a class="btn btn-primary" id="mediaPreviewLogoDownload" href="#"><i class="bi bi-patch-check me-1"></i>Choose logo &amp; download</a><a class="btn btn-outline-primary" id="mediaPreviewDownload" href="#"><i class="bi bi-download me-1"></i>Download original</a><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Close</button></div>
            </div>
        </div>
    </div>
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            var modal = document.getElementById('mediaPreviewModal');
            var previewImage = document.getElementById('mediaPreviewImage');
            var previewVideo = document.getElementById('mediaPreviewVideo');
            var previewTitle = document.getElementById('mediaPreviewTitle');
            var previewStatus = document.getElementById('mediaPreviewStatus');
            var downloadLink = document.getElementById('mediaPreviewDownload');
            var logoDownloadLink = document.getElementById('mediaPreviewLogoDownload');
            var previousImage = document.getElementById('mediaPreviewPrevious');
            var nextImage = document.getElementById('mediaPreviewNext');
            var imageCount = document.getElementById('mediaPreviewCount');
            var imageKey = function (button) { return button.dataset.originalPreviewSrc || button.dataset.previewSrc; };
            var readImageButtons = function () { return [...new Map([...document.querySelectorAll('[data-media-preview][data-preview-kind="image"]')].map(function (button) {
                return [imageKey(button), button];
            })).values()]; };
            var imageButtons = readImageButtons();
            var imageIndex = -1;
            var activePreviewKind = null;
            var imageRequest = 0;
            var pendingImage = null;
            var cancelImageRequest = function () {
                imageRequest += 1;
                if (pendingImage) {
                    pendingImage.onload = null;
                    pendingImage.onerror = null;
                    pendingImage = null;
                }
            };
            var resetVideo = function () {
                previewVideo.pause();
                previewVideo.removeAttribute('src');
                previewVideo.load();
            };
            var resetPreview = function () {
                cancelImageRequest();
                activePreviewKind = null;
                imageIndex = -1;
                previewImage.removeAttribute('src');
                previewImage.classList.toggle('d-none', true);
                previewStatus.textContent = '';
                imageCount.textContent = '';
                resetVideo();
            };
            var updateDetails = function (button, index) {
                var isVideo = button.dataset.previewKind === 'video';
                previewImage.alt = isVideo ? '' : 'Preview of ' + button.dataset.previewName;
                previewTitle.textContent = button.dataset.previewName;
                imageCount.textContent = index < 0 ? '' : (index + 1) + ' / ' + imageButtons.length;
                downloadLink.href = button.dataset.downloadUrl;
                logoDownloadLink.href = button.dataset.logoUrl || '#';
                logoDownloadLink.classList.toggle('d-none', isVideo || !button.dataset.logoUrl);
            };
            var showPreview = function (button) {
                if (window.greyhoundRecordViewed) window.greyhoundRecordViewed(button);
                var previewKind = button.dataset.previewKind || 'image';
                var originalPreview = button.dataset.originalPreviewSrc || button.dataset.previewSrc;
                cancelImageRequest();
                if (activePreviewKind === 'video') resetVideo();
                activePreviewKind = previewKind;
                imageIndex = previewKind === 'image' ? imageButtons.findIndex(function (image) { return imageKey(image) === imageKey(button); }) : -1;
                previousImage.classList.toggle('d-none', imageIndex < 0 || imageButtons.length < 2);
                nextImage.classList.toggle('d-none', imageIndex < 0 || imageButtons.length < 2);
                previewVideo.classList.toggle('d-none', previewKind !== 'video');
                if (previewKind === 'video') {
                    previewImage.classList.toggle('d-none', true);
                    previewImage.removeAttribute('src');
                    updateDetails(button, -1);
                    previewStatus.textContent = 'Loading video preview. Preparing older video formats may take a moment.';
                    previewVideo.src = button.dataset.previewSrc;
                    previewVideo.load();
                    return;
                }

                // Keep the displayed photo and its details together until the next photo is ready.
                // Each request owns its callbacks so rapid clicks cannot display an older result.
                if (!previewImage.getAttribute('src')) updateDetails(button, imageIndex);
                previewStatus.textContent = 'Loading image preview…';
                var request = imageRequest;
                var requestedIndex = imageIndex;
                var loader = new Image();
                var fallbackUsed = false;
                pendingImage = loader;
                var isCurrent = function () {
                    return request === imageRequest && activePreviewKind === 'image' && modal.isConnected;
                };
                loader.onload = function () {
                    if (!isCurrent()) return;
                    pendingImage = null;
                    previewImage.src = loader.src;
                    previewImage.classList.toggle('d-none', false);
                    updateDetails(button, requestedIndex);
                    previewStatus.textContent = fallbackUsed
                        ? 'Watermarked preview was unavailable, so the original image is shown.'
                        : button.dataset.previewSrc === originalPreview
                        ? 'Original image preview. Your stored file remains unchanged.'
                        : 'The current default logo is applied when available. Your original file stays unchanged.';
                };
                loader.onerror = function () {
                    if (!isCurrent()) return;
                    if (!fallbackUsed && originalPreview && loader.src !== new URL(originalPreview, window.location.href).href) {
                        fallbackUsed = true;
                        previewStatus.textContent = 'Loading original image preview…';
                        loader.src = originalPreview;
                        return;
                    }
                    pendingImage = null;
                    previewImage.classList.toggle('d-none', true);
                    previewImage.removeAttribute('src');
                    updateDetails(button, requestedIndex);
                    previewStatus.textContent = 'Image preview could not be loaded. Try another image or download the original.';
                };
                loader.src = button.dataset.previewSrc;
            };
            modal.addEventListener('show.bs.modal', function (event) {
                imageButtons = readImageButtons();
                var button = event.relatedTarget;
                if (button && button.matches('[data-media-preview]')) showPreview(button);
            });
            var moveImage = function (direction) {
                if (activePreviewKind !== 'image' || imageIndex < 0 || imageButtons.length < 2) return;
                showPreview(imageButtons[(imageIndex + direction + imageButtons.length) % imageButtons.length]);
            };
            previousImage.addEventListener('click', function () { moveImage(-1); });
            nextImage.addEventListener('click', function () { moveImage(1); });
            modal.addEventListener('keydown', function (event) {
                if (activePreviewKind !== 'image' || event.target?.closest('input, textarea, select, [contenteditable="true"]')) return;
                if (event.key === 'ArrowLeft' || event.key === 'ArrowRight') {
                    event.preventDefault();
                    moveImage(event.key === 'ArrowRight' ? 1 : -1);
                }
            });
            previewVideo.addEventListener('loadeddata', function () {
                if (activePreviewKind !== 'video') return;
                previewStatus.textContent = previewVideo.videoWidth > 0
                    ? 'Video preview. Use the player controls to play, seek, or adjust volume.'
                    : 'The video picture is unavailable. Download the original to open it in another player.';
            });
            previewVideo.addEventListener('error', function () {
                if (activePreviewKind !== 'video' || !previewVideo.getAttribute('src')) return;
                previewStatus.textContent = 'Video preview could not be loaded. Try again or download the original.';
            });
            modal.addEventListener('hidden.bs.modal', resetPreview);

            var bulkForm = document.getElementById('bulkActionForm');
            if (bulkForm) {
                var selectedMediaIds = new Set();
                var selectionInputs = document.getElementById('bulkSelectedInputs');
                var selectionCount = document.getElementById('bulkSelectionCount');
                var selectAllButton = document.getElementById('selectAllMedia');
                var getVisibleIds = function () {
                    var ids = [];
                    document.querySelectorAll('[data-media-select]').forEach(function (checkbox) {
                        var mediaItem = checkbox.closest('tr, .media-grid-card');
                        if (mediaItem && mediaItem.hidden) { return; }
                        if (ids.indexOf(checkbox.dataset.mediaId) === -1) { ids.push(checkbox.dataset.mediaId); }
                    });
                    return ids;
                };
                var syncSelection = function () {
                    document.querySelectorAll('[data-media-select]').forEach(function (checkbox) {
                        checkbox.checked = selectedMediaIds.has(checkbox.dataset.mediaId);
                    });
                    var visibleIds = getVisibleIds();
                    document.querySelectorAll('[data-media-select-all]').forEach(function (checkbox) {
                        checkbox.checked = visibleIds.length > 0 && visibleIds.every(function (id) { return selectedMediaIds.has(id); });
                        checkbox.indeterminate = selectedMediaIds.size > 0 && !checkbox.checked;
                    });
                    selectionInputs.replaceChildren();
                    selectedMediaIds.forEach(function (id) {
                        var input = document.createElement('input');
                        input.type = 'hidden';
                        input.name = 'file_ids[]';
                        input.value = id;
                        selectionInputs.appendChild(input);
                    });
                    var selectedCount = selectedMediaIds.size;
                    selectionCount.textContent = selectedCount + ' selected';
                    selectionCount.classList.toggle('d-none', selectedCount === 0);
                    var allVisibleSelected = visibleIds.length > 0 && visibleIds.every(function (id) { return selectedMediaIds.has(id); });
                    selectAllButton.innerHTML = '<i class="bi bi-check2-square me-1"></i>' + (allVisibleSelected ? 'Unselect all' : 'Select all');
                    bulkForm.classList.toggle('has-selection', selectedCount > 0);
                    document.querySelectorAll('[data-bulk-action]').forEach(function (button) { button.disabled = selectedCount === 0; });
                };
                document.getElementById('main').addEventListener('change', function (event) {
                    var checkbox = event.target;
                    if (!checkbox.matches('[data-media-select]')) return;
                    if (checkbox.checked) { selectedMediaIds.add(checkbox.dataset.mediaId); }
                    else { selectedMediaIds.delete(checkbox.dataset.mediaId); }
                    syncSelection();
                });
                document.querySelectorAll('[data-media-select-all]').forEach(function (checkbox) {
                    checkbox.addEventListener('change', function () {
                        getVisibleIds().forEach(function (id) {
                            if (checkbox.checked) { selectedMediaIds.add(id); } else { selectedMediaIds.delete(id); }
                        });
                        syncSelection();
                    });
                });
                selectAllButton.addEventListener('click', function () {
                    var visibleIds = getVisibleIds();
                    var shouldSelect = !visibleIds.every(function (id) { return selectedMediaIds.has(id); });
                    visibleIds.forEach(function (id) {
                        if (shouldSelect) { selectedMediaIds.add(id); } else { selectedMediaIds.delete(id); }
                    });
                    syncSelection();
                });
                bulkForm.addEventListener('submit', function (event) {
                    if (selectedMediaIds.size === 0) {
                        event.preventDefault();
                        return;
                    }
                });
                syncSelection();
            }
        });
    </script>
<?php endif; ?>
<script>
    document.addEventListener('DOMContentLoaded', function () {
        var listView = document.getElementById('mediaListView');
        var gridView = document.getElementById('mediaGridView');
        var workspace = document.getElementById('main');
        var viewButtons = document.querySelectorAll('[data-media-view]');
        if (listView && gridView && viewButtons.length > 0) {
            var setMediaView = function (view) {
                var activeView = view === 'list' ? 'list' : 'grid';
                workspace.dataset.mediaLayout = activeView;
                viewButtons.forEach(function (button) {
                    var isActive = button.dataset.mediaView === activeView;
                    button.classList.toggle('is-active', isActive);
                    button.setAttribute('aria-pressed', isActive ? 'true' : 'false');
                });
                try { window.localStorage.setItem('greyhound-server-library-view', activeView); } catch (error) { }
            };

            viewButtons.forEach(function (button) {
                button.addEventListener('click', function () {
                    setMediaView(button.dataset.mediaView);
                });
            });

            setMediaView(workspace.dataset.mediaLayout);
            window.requestAnimationFrame(function () {
                var located = Array.from(document.querySelectorAll('[data-located-file]')).find(function (item) {
                    return item.getClientRects().length > 0;
                });
                if (located) {
                    located.focus({ preventScroll: true });
                    located.scrollIntoView({ block: 'center' });
                }
            });
        }

        var tagModal = document.getElementById('manualTaggingModal');
        if (tagModal) {
            var tagInput = document.getElementById('manualTagInput'), tagChips = document.getElementById('manualTagChips'), tagValues = document.getElementById('manualTagValues'), tagFileIds = document.getElementById('manualTagFileIds'), tags = new Map();
            var renderTags = function () { tagChips.replaceChildren(); tagValues.replaceChildren(); tags.forEach(function (label, key) { var chip=document.createElement('span');chip.className='badge rounded-pill bg-primary-subtle text-primary';chip.textContent=label+' ×';chip.tabIndex=0;chip.addEventListener('click',function(){tags.delete(key);renderTags();});tagChips.appendChild(chip);var hidden=document.createElement('input');hidden.type='hidden';hidden.name='tags[]';hidden.value=label;tagValues.appendChild(hidden); }); };
            var addTag = function (value) { value=value.trim().replace(/\s+/g,' ');if(!value||value.length>50)return;tags.set(value.toLocaleLowerCase(),value);renderTags(); };
            tagInput.addEventListener('keydown',function(event){if(event.key==='Enter'||event.key===','){event.preventDefault();addTag(tagInput.value.replace(/,+$/,''));tagInput.value='';}});
            tagInput.addEventListener('input',function(){if(tagInput.value.indexOf(',')!==-1){tagInput.value.split(',').forEach(addTag);tagInput.value='';}});
            tagModal.addEventListener('show.bs.modal',function(){tagFileIds.replaceChildren();var seen=[];document.querySelectorAll('[data-media-select]:checked').forEach(function(box){if(seen.indexOf(box.dataset.mediaId)===-1){seen.push(box.dataset.mediaId);var hidden=document.createElement('input');hidden.type='hidden';hidden.name='file_ids[]';hidden.value=box.dataset.mediaId;tagFileIds.appendChild(hidden);}});window.setTimeout(function(){tagInput.focus();},250);});
        }

        var postModal = document.getElementById('postMediaModal');
        if (postModal) {
            var postForm = document.getElementById('postMediaForm');
            var postButton = postForm.querySelector('button[type="submit"]');
            var postMessage = postForm.querySelector('.modal-body p');
            new MutationObserver(function() {
                var busy = postForm.getAttribute('aria-busy') === 'true';
                postButton.textContent = busy ? 'Posting…' : 'Post';
                postMessage.textContent = busy
                    ? 'Copying the selected files for the mobile app. Please wait…'
                    : 'Are you sure you want to post this?';
            }).observe(postForm, {attributes:true, attributeFilter:['aria-busy']});
            var postTypeInput = document.getElementById('postMediaType');
            var postFolderIdInput = document.getElementById('postMediaFolderId');
            var postFileIdsContainer = document.getElementById('postMediaFileIds');

            postModal.addEventListener('show.bs.modal', function(event) {
                postFileIdsContainer.replaceChildren();
                var button = event.relatedTarget;
                if (!button || !button.dataset.postType) {
                    var selected = document.querySelectorAll('[data-media-select]:checked');
                    if (selected.length === 0) {
                        event.preventDefault();
                        return;
                    }
                    postTypeInput.value = 'files';
                    postFolderIdInput.value = '';
                    var seen = [];
                    selected.forEach(function(box) {
                        if (seen.indexOf(box.dataset.mediaId) === -1) {
                            seen.push(box.dataset.mediaId);
                            var hidden = document.createElement('input');
                            hidden.type = 'hidden';
                            hidden.name = 'file_ids[]';
                            hidden.value = box.dataset.mediaId;
                            postFileIdsContainer.appendChild(hidden);
                        }
                    });
                } else if (button.dataset.postType === 'folder') {
                    postTypeInput.value = 'folder';
                    postFolderIdInput.value = button.dataset.postTargetId;
                }
            });
        }


    });
</script>
<?= view('components/media_filter_script') ?>
<?= view('components/media_upload_script') ?>
<?= view('workspace/server_library_counts_script') ?>
<?= view('workspace/server_library_connection_script') ?>
<script>
<?php require FCPATH . 'assets/js/server-library-live.js'; ?>
<?php require FCPATH . 'assets/js/server-library-thumbnails.js'; ?>
</script>
<?php if ($canManage): ?><?= view('workspace/server_folder_actions', ['folderId'=>$folderId]) ?><?php endif; ?>
<?= view('layouts/app_footer') ?>

