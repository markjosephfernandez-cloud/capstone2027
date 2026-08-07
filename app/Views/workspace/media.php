<?= view('layouts/app_header', ['user' => $user, 'activePage' => $activePage, 'title' => $title]) ?>
<?php $visibleFileCount = count($files); ?>
<?php $previewableImageTypes = ['jpg', 'jpeg', 'png', 'gif', 'webp']; ?>
<?php $logoWatermarkTypes = ['jpg', 'jpeg', 'png', 'webp']; ?>
<?php $canManageMedia = (bool) ($canManage ?? false); ?>

<style>
    .media-workspace { --media-primary: #315efb; --media-primary-soft: #eef3ff; --media-ink: #17223b; --media-muted: #68748b; --media-border: #e4e9f2; }
    .media-workspace .library-hero { background: transparent; border: 0; padding: .35rem 0; }
    .media-workspace .hero-symbol { align-items: center; background: #e9efff; border-radius: 14px; color: #315efb; display: flex; flex: 0 0 auto; font-size: 1.35rem; height: 50px; justify-content: center; width: 50px; }
    .media-workspace .hero-eyebrow { color: #73809a; font-size: .72rem; font-weight: 700; letter-spacing: .06em; margin-bottom: .2rem; text-transform: uppercase; }
    .media-workspace .library-hero h2 { color: var(--media-ink); font-size: 1.4rem; font-weight: 700; margin: 0; }
    .media-workspace .library-hero p { color: var(--media-muted); margin: .35rem 0 0; }
    .media-workspace .media-count { align-items: center; background: #fff; border: 1px solid #e1e7f7; border-radius: 11px; color: var(--media-ink); display: inline-flex; font-size: .84rem; font-weight: 600; gap: .45rem; padding: .55rem .75rem; }
    .media-workspace .media-count i { color: var(--media-primary); font-size: 1rem; }
    .media-workspace .upload-toggle { border-radius: 9px; box-shadow: 0 4px 10px rgba(65, 84, 241, .18); font-weight: 600; padding: .6rem .9rem; }
    .media-workspace .folder-toggle { border-radius: 9px; font-weight: 600; padding: .6rem .9rem; }
    .media-workspace .drive-new-button { background: #fff; border: 1px solid #dde3ec; border-radius: 16px; box-shadow: 0 2px 7px rgba(60, 64, 67, .16); color: #25324a; font-weight: 600; min-width: 112px; padding: .72rem 1rem; }
    .media-workspace .drive-new-button:hover, .media-workspace .drive-new-button:focus { background: #f8faff; border-color: #ccd6e8; box-shadow: 0 4px 10px rgba(60, 64, 67, .18); }
    .media-workspace .drive-new-menu { border: 0; border-radius: 12px; box-shadow: 0 8px 28px rgba(60, 64, 67, .2); min-width: 215px; padding: .45rem; }
    .media-workspace .drive-new-menu .dropdown-item { border-radius: 8px; color: #34405b; padding: .65rem .75rem; }
    .media-workspace .drive-new-menu .dropdown-item:hover { background: #eef3ff; color: #2455d6; }
    .media-workspace .upload-card { background: #fff; border: 1px solid var(--media-border); border-radius: 16px; box-shadow: 0 8px 24px rgba(37, 52, 85, .06); padding: 1.2rem; }
    .media-workspace .create-folder-card { background: #fff; border: 1px solid var(--media-border); border-radius: 16px; box-shadow: 0 8px 24px rgba(37, 52, 85, .06); padding: 1.2rem; }
    .media-workspace .upload-icon { align-items: center; background: #eaf0ff; border-radius: 12px; color: var(--media-primary); display: flex; font-size: 1.25rem; height: 42px; justify-content: center; width: 42px; }
    .media-workspace .upload-card h3 { color: var(--media-ink); font-size: 1rem; font-weight: 700; margin: 0; }
    .media-workspace .upload-card .form-label { color: #44516e; font-size: .78rem; font-weight: 700; margin-bottom: .35rem; text-transform: uppercase; }
    .media-workspace .upload-card .form-control { border-color: #dce3f2; min-height: 42px; }
    .media-workspace .upload-selection { color: #71809b; display: block; font-size: .76rem; margin-top: .35rem; }
    .media-workspace .upload-picker { position: relative; }
    .media-workspace .upload-picker input[type="file"] { height: 1px; opacity: 0; overflow: hidden; position: absolute; width: 1px; }
    .media-workspace .upload-picker-label { align-items: center; background: #f8faff; border: 1px dashed #bdc9e8; border-radius: 12px; color: #3e4c68; cursor: pointer; display: flex; gap: .75rem; min-height: 82px; padding: .8rem; transition: background .15s, border-color .15s, box-shadow .15s; }
    .media-workspace .upload-picker-label:hover, .media-workspace .upload-picker.is-dragging .upload-picker-label { background: #eef3ff; border-color: var(--media-primary); box-shadow: 0 3px 12px rgba(49,94,251,.12); }
    .media-workspace .upload-picker-icon { align-items: center; background: #e6edff; border-radius: 10px; color: var(--media-primary); display: flex; flex: 0 0 auto; font-size: 1.2rem; height: 40px; justify-content: center; width: 40px; }
    .media-workspace .upload-picker-title { color: #263754; display: block; font-size: .86rem; font-weight: 750; }.media-workspace .upload-picker-hint { color: #76839b; display: block; font-size: .7rem; margin-top: .15rem; }
    .media-workspace .upload-picker.has-files .upload-picker-label { background: #eef8f2; border-color: #78bd91; }.media-workspace .upload-picker.has-files .upload-picker-icon { background: #dff2e6; color: #23834b; }
    .media-workspace .upload-progress { background: #eef4ff; border: 1px solid #d4e2ff; border-radius: 10px; color: #40516f; padding: .75rem .9rem; }
    .media-workspace .upload-progress .progress { height: 7px; }
    .media-workspace .library-card { border: 1px solid var(--media-border); border-radius: 18px; box-shadow: none; overflow: hidden; }
    .media-workspace .browser-heading { align-items: center; display: flex; justify-content: space-between; margin-bottom: .85rem; }
    .media-workspace .browser-heading h3 { color: var(--media-ink); font-size: 1rem; font-weight: 700; margin: 0; }
    .media-workspace .browser-heading p { color: var(--media-muted); font-size: .78rem; margin: .2rem 0 0; }
    .media-workspace .filter-bar { background: #fff; border-bottom: 1px solid #edf0f6; padding: 1rem 1.25rem; }
    .media-workspace .filter-bar .input-group-text { background: #fff; border-right: 0; color: var(--media-primary); }
    .media-workspace .filter-bar .input-group .form-control { border-left: 0; }
    .media-workspace .filter-bar .input-group { background: #f0f4f9; border: 1px solid transparent; border-radius: 24px; overflow: hidden; }
    .media-workspace .filter-bar .input-group:focus-within { background: #fff; border-color: #c9d7ff; box-shadow: 0 1px 5px rgba(49, 94, 251, .12); }
    .media-workspace .filter-bar .input-group .input-group-text, .media-workspace .filter-bar .input-group .form-control { background: transparent; border: 0; min-height: 46px; }
    .media-workspace .filter-bar .form-select { border-color: #dce3ee; border-radius: 22px; min-height: 46px; }
    .media-workspace .filter-bar .form-control:focus, .media-workspace .filter-bar .form-select:focus { box-shadow: 0 0 0 .2rem rgba(65, 84, 241, .12); }
    .media-workspace .media-view-switcher { background: #fff; border: 1px solid #d7deed; border-radius: 999px; display: inline-flex; overflow: hidden; padding: 2px; }
    .media-workspace .media-view-toggle { align-items: center; background: transparent; border: 0; border-radius: 999px; color: #53627c; display: inline-flex; font-size: 1.2rem; height: 40px; justify-content: center; transition: background .15s ease, color .15s ease; width: 50px; }
    .media-workspace .media-view-toggle:hover, .media-workspace .media-view-toggle:focus-visible { background: #eef2ff; color: var(--media-primary); outline: 0; }
    .media-workspace .media-view-toggle.is-active { background: #1670f8; box-shadow: 0 3px 8px rgba(22, 112, 248, .25); color: #fff; }
    .media-workspace .media-table { margin: 0; }
    .media-workspace .media-table thead th { background: #f8faff; border-bottom: 1px solid #e7ecf6; color: #65708a; font-size: .72rem; font-weight: 700; letter-spacing: .04em; padding: .9rem 1.25rem; text-transform: uppercase; white-space: nowrap; }
    .media-workspace .media-table tbody td { border-color: #eef1f6; color: #49566f; padding: 1rem 1.25rem; vertical-align: middle; }
    .media-workspace .media-table tbody tr { transition: background .15s ease; }
    .media-workspace .media-table tbody tr:hover { background: #fbfcff; }
    .media-workspace .file-icon { align-items: center; background: #eef3ff; border-radius: 10px; color: var(--media-primary); display: inline-flex; font-size: 1.1rem; height: 38px; justify-content: center; width: 38px; }
    .media-workspace .file-thumbnail { background: transparent; border: 0; border-radius: 10px; display: inline-flex; height: 44px; overflow: hidden; padding: 0; width: 44px; }
    .media-workspace .file-thumbnail img { border: 1px solid #e0e7f5; border-radius: 10px; height: 44px; object-fit: cover; transition: transform .15s ease, box-shadow .15s ease; width: 44px; }
    .media-workspace .file-thumbnail:hover img, .media-workspace .file-thumbnail:focus-visible img { box-shadow: 0 4px 12px rgba(65, 84, 241, .25); transform: scale(1.06); }
    .media-workspace .image-preview-dialog { max-width: min(1100px, calc(100vw - 2rem)); }
    .media-workspace .image-preview-dialog .modal-content { max-height: calc(100vh - 2rem); overflow: hidden; }
    .media-workspace .image-preview-body { align-items: center; background: #101a31; display: flex; justify-content: center; min-height: 260px; max-height: calc(100vh - 190px); overflow: hidden; padding: 0; }
    .media-workspace .image-preview-body img { display: block; height: auto; max-height: calc(100vh - 190px); max-width: 100%; object-fit: contain; width: auto; }
    .media-workspace .media-grid { display: grid; gap: 1rem; grid-template-columns: repeat(auto-fill, minmax(205px, 1fr)); padding: 1.25rem; }
    .media-workspace .folder-browser { background: #fbfcff; border-bottom: 1px solid #edf0f6; padding: 1.15rem 1.25rem; }
    .media-workspace .folder-browser-title { color: var(--media-ink); font-size: .9rem; font-weight: 700; margin: 0; }
    .media-workspace .folder-browser-subtitle { color: var(--media-muted); font-size: .78rem; margin: .2rem 0 0; }
    .media-workspace .folder-grid { display: grid; gap: .8rem; grid-template-columns: repeat(auto-fill, minmax(185px, 1fr)); margin-top: .9rem; }
    .media-workspace .folder-list-header, .media-workspace .folder-list-cell { display: none; }
    .media-workspace .folder-grid.is-list-view { --folder-list-columns: minmax(220px, 2fr) minmax(140px, 1fr) minmax(105px, .65fr) 48px; background: #fff; border: 1px solid #e0e4ea; border-radius: 12px; display: block; overflow: hidden; }
    .media-workspace .folder-grid.is-list-view .folder-list-header { align-items: center; border-bottom: 1px solid #dadce0; color: #5f6368; display: grid; font-size: .75rem; font-weight: 600; gap: 12px; grid-template-columns: var(--folder-list-columns); height: 44px; padding: 0 8px 0 16px; }
    .media-workspace .folder-grid.is-list-view .folder-card { align-items: stretch; background: #fff; border: 0; border-bottom: 1px solid #e8eaed; border-radius: 0; display: grid; gap: 12px; grid-template-columns: var(--folder-list-columns); min-height: 58px; padding: 0 8px 0 16px; width: 100%; }
    .media-workspace .folder-grid.is-list-view .folder-card:last-child { border-bottom: 0; }
    .media-workspace .folder-grid.is-list-view .folder-card:hover { background: #f1f3f4; box-shadow: none; cursor: pointer; }
    .media-workspace .folder-grid.is-list-view .folder-card:focus-within { background: #eef3fd; }
    .media-workspace .folder-grid.is-list-view .folder-card-main { align-items: center; display: grid; gap: 12px; grid-column: 1 / 4; grid-template-columns: minmax(220px, 2fr) minmax(140px, 1fr) minmax(105px, .65fr); height: 100%; min-height: 58px; padding: 0; }
    .media-workspace .folder-grid.is-list-view .folder-card-main::after { content: ""; inset: 0; position: absolute; }
    .media-workspace .folder-grid.is-list-view .folder-name-cell { align-items: center; display: flex; gap: 12px; min-width: 0; }
    .media-workspace .folder-grid.is-list-view .folder-card-icon { background: transparent; border-radius: 0; color: #5f6368; font-size: 1.25rem; height: 28px; width: 28px; }
    .media-workspace .folder-grid.is-list-view .folder-card-name { color: #202124; font-size: .875rem; font-weight: 550; line-height: 1.25; }
    .media-workspace .folder-grid.is-list-view .folder-card-meta { display: none; }
    .media-workspace .folder-grid.is-list-view .folder-list-cell { align-items: center; color: #5f6368; display: flex; font-size: .8rem; font-weight: 400; height: 100%; overflow: hidden; padding-right: 12px; text-overflow: ellipsis; white-space: nowrap; }
    .media-workspace .folder-grid.is-list-view .folder-card-menu { align-items: center; display: flex; grid-column: 4; height: 100%; justify-content: center; position: static; }
    .media-workspace .folder-grid.is-list-view .folder-card-menu .drive-more-button { position: relative; z-index: 2; }
    .media-workspace .folder-card { background: #f0f4f9; border: 1px solid transparent; border-radius: 12px; color: inherit; min-width: 0; position: relative; transition: background .15s ease, border-color .15s ease, box-shadow .15s ease; }
    .media-workspace .folder-card:hover { background: #e8eef8; border-color: #d8e1ef; box-shadow: 0 2px 6px rgba(60, 64, 67, .10); color: inherit; }
    .media-workspace .folder-card-main { align-items: center; color: inherit; display: flex; gap: .7rem; min-width: 0; padding: .85rem 3.1rem .85rem .9rem; text-decoration: none; }
    .media-workspace .folder-card-main:hover { color: inherit; }
    .media-workspace .folder-card-menu { position: absolute; right: .55rem; top: .57rem; z-index: 3; }
    .media-workspace .drive-more-button { align-items: center; background: transparent; border: 0; border-radius: 50%; color: #5f6b7d; display: inline-flex; height: 34px; justify-content: center; width: 34px; }
    .media-workspace .drive-more-button:hover, .media-workspace .drive-more-button:focus { background: rgba(60, 64, 67, .1); color: #1f2b43; }
    .media-workspace .drive-action-menu { border: 0; border-radius: 10px; box-shadow: 0 7px 24px rgba(60, 64, 67, .2); min-width: 205px; padding: .4rem; }
    .media-workspace .drive-action-menu .dropdown-item { border: 0; border-radius: 7px; color: #3e4a60; font-size: .84rem; padding: .55rem .65rem; text-align: left; width: 100%; }
    .media-workspace .drive-action-menu .dropdown-item:hover { background: #eef3ff; }
    .media-workspace .folder-card-icon { align-items: center; background: #edf2ff; border-radius: 10px; color: var(--media-primary); display: inline-flex; flex: 0 0 auto; font-size: 1.25rem; height: 40px; justify-content: center; width: 40px; }
    .media-workspace .folder-card-name { color: #34405b; font-size: .86rem; font-weight: 700; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    .media-workspace .folder-card-meta { color: #7d899f; font-size: .75rem; margin-top: .15rem; }
    .media-workspace .folder-location { background: #eef3ff; border-bottom: 1px solid #dfe7f7; color: #4a5e89; font-size: .83rem; padding: .75rem 1.25rem; }
    .media-workspace .bulk-toolbar { align-items: center; background: #fff; border-bottom: 1px solid #e8edf5; display: flex; flex-wrap: wrap; gap: .75rem; min-height: 58px; padding: .8rem 1.25rem; }
    .media-workspace .bulk-toolbar:not(.has-selection) .bulk-actions { display: none; }
    .media-workspace .bulk-selection { align-items: center; display: flex; flex-wrap: wrap; gap: .45rem; }
    .media-workspace .bulk-selection-count { background: #f1f4fa; border-radius: 999px; color: #44516e; font-size: .78rem; font-weight: 700; padding: .4rem .65rem; }
    .media-workspace .bulk-actions { align-items: center; display: flex; flex: 1; flex-wrap: wrap; gap: .45rem; justify-content: flex-end; }
    .media-workspace .publication-actions { align-items: center; background: #f7f9ff; border: 1px solid #e1e7f5; border-radius: 10px; display: flex; flex-wrap: wrap; gap: .4rem; padding: .35rem; }
    .media-workspace .publication-label { color: #6b7891; font-size: .7rem; font-weight: 700; padding-left: .35rem; text-transform: uppercase; }
    .media-workspace .bulk-channel-select { min-width: 145px; width: auto; }
    .media-workspace .bulk-toolbar .btn { border-radius: 8px; }
    .media-workspace .media-select { cursor: pointer; height: 1.05rem; width: 1.05rem; }
    .media-workspace .media-grid-card { background: #f0f4f9; border: 1px solid transparent; border-radius: 14px; box-shadow: none; display: flex; flex-direction: column; min-width: 0; overflow: visible; position: relative; transition: background .15s ease, box-shadow .15s ease; }
    .media-workspace .media-grid-card:hover { background: #eaf0f8; box-shadow: 0 3px 9px rgba(60, 64, 67, .12); }
    .media-workspace .grid-thumbnail, .media-workspace .grid-file-icon { align-items: center; background: #fff; border: 0; border-radius: 10px; color: var(--media-primary); display: flex; font-size: 2rem; height: 160px; justify-content: center; margin: .5rem; overflow: hidden; padding: 0; width: calc(100% - 1rem); }
    .media-workspace .grid-thumbnail img { border: 0; border-radius: 10px; height: 100%; object-fit: cover; width: 100%; }
    .media-workspace .grid-card-content { display: flex; flex: 1; flex-direction: column; gap: .65rem; padding: .9rem; }
    .media-workspace .grid-file-name { color: #34405b; font-size: .9rem; font-weight: 700; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    .media-workspace .grid-file-info { color: #7a869d; font-size: .78rem; }
    .media-workspace .grid-actions { border-top: 1px solid #eef1f6; display: flex; gap: .45rem; margin-top: auto; padding-top: .75rem; }
    .media-workspace .grid-actions .btn { flex: 1; }
    .media-workspace .grid-select { align-items: center; background: rgba(255, 255, 255, .94); border: 1px solid #d9e2f4; border-radius: 8px; display: inline-flex; height: 30px; justify-content: center; left: .75rem; padding: 0; position: absolute; top: .75rem; width: 30px; z-index: 2; }
    .media-workspace .grid-more { position: absolute; right: .72rem; top: .72rem; z-index: 4; }
    .media-workspace .file-name { color: #34405b; font-size: .9rem; font-weight: 700; }
    .media-workspace .file-description { color: #8791a7; font-size: .78rem; margin-top: .2rem; max-width: 280px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    .media-workspace .file-type { background: #f0f3f9; border-radius: 999px; color: #59667e; display: inline-block; font-size: .73rem; font-weight: 700; padding: .35rem .6rem; text-transform: uppercase; }
    .media-workspace .tags-value { color: #69768d; font-size: .8rem; max-width: 150px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    .media-workspace .action-button { align-items: center; border-radius: 8px; display: inline-flex; gap: .35rem; justify-content: center; min-width: 36px; }
    .media-workspace .empty-state { padding: 3.5rem 1rem !important; }
    .media-workspace .empty-state-icon { align-items: center; background: #eef3ff; border-radius: 50%; color: var(--media-primary); display: inline-flex; font-size: 1.7rem; height: 66px; justify-content: center; width: 66px; }
    .media-workspace .empty-state h3 { color: var(--media-ink); font-size: 1.05rem; font-weight: 700; margin: 1rem 0 .35rem; }
    .media-workspace .empty-state p { color: var(--media-muted); margin: 0 auto 1.1rem; max-width: 390px; }
    @media (max-width: 991.98px) { .media-workspace .bulk-actions { justify-content: flex-start; width: 100%; } }
    @media (max-width: 767.98px) { .media-workspace .library-hero { padding: 1.1rem; } .media-workspace .hero-symbol { height: 44px; width: 44px; } .media-workspace .filter-bar { padding: 1rem; } .media-workspace .media-table thead th, .media-workspace .media-table tbody td { padding-left: .9rem; padding-right: .9rem; } .media-workspace .media-grid { grid-template-columns: repeat(auto-fill, minmax(165px, 1fr)); padding: 1rem; } .media-workspace .grid-thumbnail, .media-workspace .grid-file-icon { height: 125px; } .media-workspace .publication-actions { align-items: stretch; width: 100%; } .media-workspace .publication-actions .btn, .media-workspace .bulk-channel-select { flex: 1 1 auto; } .media-workspace .folder-grid.is-list-view { --folder-list-columns: minmax(150px, 1fr) 88px 44px; } .media-workspace .folder-grid.is-list-view .folder-list-header { grid-template-columns: var(--folder-list-columns); } .media-workspace .folder-grid.is-list-view .folder-list-modified { display: none; } .media-workspace .folder-grid.is-list-view .folder-card-main { grid-column: 1 / 3; grid-template-columns: minmax(150px, 1fr) 88px; } .media-workspace .folder-grid.is-list-view .folder-card-menu { grid-column: 3; } }
</style>

<main id="main" class="main media-workspace">
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
                        <button class="btn drive-new-button dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false"><i class="bi bi-plus-lg me-2"></i>New</button>
                        <ul class="dropdown-menu drive-new-menu">
                            <li><button class="dropdown-item" type="button" data-bs-toggle="collapse" data-bs-target="#createFolderPanel"><i class="bi bi-folder-plus me-2"></i><?= $selectedFolder === null ? 'New folder' : 'New subfolder' ?></button></li>
                            <li><hr class="dropdown-divider"></li>
                            <li><button class="dropdown-item" type="button" data-bs-toggle="collapse" data-bs-target="#uploadPanel"><i class="bi bi-file-earmark-arrow-up me-2"></i>Upload files</button></li>
                            <li><button class="dropdown-item" type="button" data-bs-toggle="collapse" data-bs-target="#uploadPanel"><i class="bi bi-folder2-open me-2"></i>Upload a folder</button></li>
                        </ul>
                    </div>
                    <?php endif; ?>
                    <span class="media-count"><i class="bi bi-folder"></i><?= esc((string) count($folders)) ?> <?= count($folders) === 1 ? ($selectedFolder === null ? 'folder' : 'subfolder') : ($selectedFolder === null ? 'folders' : 'subfolders') ?></span>
                    <span class="media-count"><i class="bi bi-collection"></i><?= esc((string) $visibleFileCount) ?> visible <?= $visibleFileCount === 1 ? 'file' : 'files' ?></span>
                    <?php if ($canManage): ?><a class="media-count text-decoration-none" href="<?= esc(site_url('workspace/file-management/duplicates')) ?>"><i class="bi bi-copy"></i>Duplicates (<?= (int) $duplicateCount ?>)</a><?php endif; ?>
                </div>
            </div>
        </div>

        <?php if ($canManage): ?><div class="collapse mb-4" id="createFolderPanel">
            <div class="create-folder-card">
                <div class="d-flex align-items-center gap-3 mb-3"><div class="upload-icon"><i class="bi bi-folder-plus"></i></div><div><h3 class="mb-0 fs-6 fw-bold"><?= $selectedFolder === null ? 'Create a folder' : 'Create inside ' . esc($selectedFolder['name']) ?></h3><p class="mb-0 text-muted small"><?= $selectedFolder === null ? 'Create a space first, then upload media directly into it.' : 'This new folder will be placed inside the folder you are viewing.' ?></p></div></div>
                <form class="row g-3 align-items-end" action="<?= esc(site_url('workspace/file-management/folders')) ?>" method="post">
                    <?= csrf_field() ?>
                    <?php if ($folderId !== null): ?><input type="hidden" name="parent_id" value="<?= (int) $folderId ?>"><?php endif; ?>
                    <div class="col-md-5"><label class="form-label" for="folder_name">Folder name</label><input class="form-control" id="folder_name" name="name" maxlength="120" required placeholder="e.g. EXPO 2026"></div>
                    <div class="col-md-5"><label class="form-label" for="folder_description">Description</label><input class="form-control" id="folder_description" name="description" maxlength="5000" placeholder="Optional"></div>
                    <div class="col-md-2 d-grid"><button class="btn btn-primary" type="submit"><i class="bi bi-folder-plus me-1"></i>Create</button></div>
                </form>
            </div>
        </div><?php endif; ?>

        <?php if ($canUpload): ?><div class="collapse <?= $files === [] && $folders === [] ? 'show' : '' ?> mb-4" id="uploadPanel">
            <div class="upload-card">
                <div class="d-flex align-items-center justify-content-between gap-3 mb-3">
                    <div class="d-flex align-items-center gap-3"><div class="upload-icon"><i class="bi bi-cloud-arrow-up"></i></div><div><h3>Add media to your library</h3><p class="mb-0 text-muted small">Choose individual files or preserve a complete folder structure.</p></div></div>
                    <button class="btn btn-sm btn-light" type="button" data-bs-toggle="collapse" data-bs-target="#uploadPanel" aria-label="Close upload panel"><i class="bi bi-x-lg"></i></button>
                </div>
                <form class="row g-3 align-items-end" id="batchUploadForm" action="<?= esc(site_url('workspace/file-management/upload')) ?>" method="post" enctype="multipart/form-data">
                    <?= csrf_field() ?>
                    <div class="col-md-6 col-xl-3"><div class="upload-picker" data-upload-drop><input type="file" id="files" name="files[]" accept="image/jpeg,image/png,image/gif,image/webp,video/mp4,video/quicktime,video/x-msvideo,video/x-matroska,video/webm,video/x-m4v,audio/mpeg,audio/wav,audio/ogg,application/pdf,.doc,.docx,.xls,.xlsx" multiple><label class="upload-picker-label" for="files"><span class="upload-picker-icon"><i class="bi bi-files"></i></span><span><span class="upload-picker-title">Browse files</span><span class="upload-picker-hint">Images, videos, audio, and documents</span></span></label><span class="upload-selection" id="fileSelection">No files selected</span></div></div>
                    <div class="col-md-6 col-xl-3"><div class="upload-picker"><input type="file" id="folder_files" name="folder_files[]" webkitdirectory directory multiple><label class="upload-picker-label" for="folder_files"><span class="upload-picker-icon"><i class="bi bi-folder2-open"></i></span><span><span class="upload-picker-title">Browse a folder</span><span class="upload-picker-hint">Preserves supported subfolders</span></span></label><span class="upload-selection" id="folderSelection">Chrome or Edge recommended</span></div></div>
                    <div class="col-md-4 col-xl-2"><label class="form-label" for="folder_id">Upload to folder</label><select class="form-select" id="folder_id" name="folder_id"><option value="">Root library</option><?php foreach ($folderOptions as $folder): ?><option value="<?= (int) $folder['id'] ?>" <?= $folderId === (int) $folder['id'] ? 'selected' : '' ?>><?= esc($folder['display_name']) ?></option><?php endforeach; ?></select></div>
                    <div class="col-md-5 col-xl-3"><label class="form-label" for="display_title">Display Title</label><input class="form-control" id="display_title" name="display_title" maxlength="150" required placeholder="Required title shown to users" pattern=".*\S.*" aria-describedby="displayTitleHelp"><div class="form-text" id="displayTitleHelp">Up to 150 characters; applied to all selected files.</div></div>
                    <div class="col-md-5 col-xl-2"><label class="form-label" for="description">Description</label><input class="form-control" id="description" name="description" maxlength="5000" placeholder="Optional; applied to all uploads"></div>
                    <div class="col-md-3 col-xl-2 d-grid"><button class="btn btn-primary h-100" id="uploadSubmit" type="submit" disabled><i class="bi bi-cloud-arrow-up me-1"></i>Start upload</button></div>
                    <div class="col-md-4"><label class="form-label" for="distribution_type">Distribution Type</label><select class="form-select" id="distribution_type" name="distribution_type"><option value="">Process only</option><option value="mobile_app">Mobile App</option><option value="department">Department Assignment</option></select></div>
                    <div class="col-md-4"><label class="form-label" for="distribution_target">Publish To</label><select class="form-select" id="distribution_target" name="distribution_target"><option value="">Select distribution type first</option></select></div>
                    <div class="col-md-4 d-none" id="assignmentMethodWrap"><label class="form-label" for="assignment_method">Assignment Method</label><select class="form-select" id="assignment_method" name="assignment_method"><option value="direct">Direct Send</option><option value="request">Assign to Existing Request</option></select></div>
                    <div class="col-md-6 d-none" id="requestWrap"><label class="form-label" for="request_id">Existing Request</label><select class="form-select" id="request_id" name="request_id"><option value="">Choose request</option><?php foreach ($openRequests as $request): ?><option value="<?= (int) $request['id'] ?>" data-department="<?= esc($request['department'], 'attr') ?>"><?= esc($request['department'] . ' — ' . $request['title']) ?></option><?php endforeach; ?></select></div>
                    <div class="col-12"><p class="mb-0 text-muted small"><i class="bi bi-droplet-half me-1"></i>Watermark processing runs automatically when enabled; the stored original is never changed.</p></div>
                    <div class="col-12 d-none" id="uploadProgress" role="status" aria-live="polite"><div class="upload-progress"><div class="d-flex justify-content-between gap-3 mb-2"><span id="uploadProgressLabel">Preparing uploads&hellip;</span><span id="uploadProgressCount"></span></div><div class="progress"><div class="progress-bar progress-bar-striped progress-bar-animated" id="uploadProgressBar" style="width: 0%"></div></div></div></div>
                    <div class="col-12"><p class="mb-0 text-muted small"><i class="bi bi-shield-check me-1"></i>No application file-count limit: selected media is uploaded one at a time. Maximum size: 100 MB per file &middot; Each upload receives event analysis and a metadata passport.</p></div>
                </form>
            </div>
        </div><?php endif; ?>

        <div class="card library-card">
            <form class="filter-bar" method="get">
                <?php if ($folderId !== null): ?><input type="hidden" name="folder" value="<?= (int) $folderId ?>"><?php endif; ?>
                <?php if ($stage !== ''): ?><input type="hidden" name="stage" value="<?= esc($stage) ?>"><?php endif; ?>
                <div class="browser-heading">
                    <div><h3><?= $query !== '' ? 'Matching Media' : 'Browse media' ?></h3><p>Search display titles, folder names, AI-generated tags, and manual tags.</p></div>
                    <?php if ($query !== '' || $type !== '' || $distribution !== ''): ?><a class="btn btn-sm btn-link text-decoration-none" href="<?= esc(site_url('workspace/file-management')) ?>"><i class="bi bi-x-circle me-1"></i>Clear filters</a><?php endif; ?>
                </div>
                <div class="row g-2 align-items-center">
                    <div class="col-lg-4">
                        <label class="visually-hidden" for="mediaSearch">Search media by display title, folder, or tag</label>
                        <div class="input-group"><span class="input-group-text"><i class="bi bi-search"></i></span><input class="form-control" id="mediaSearch" name="q" placeholder="Search all folders and tags" value="<?= esc($query) ?>" autocomplete="off"></div>
                    </div>
                    <div class="col-sm-4 col-lg-2">
                        <label class="visually-hidden" for="mediaType">Filter media by file type</label>
                        <select class="form-select" id="mediaType" name="type">
                            <option value="">All file types</option>
                            <?php foreach ($fileTypes as $fileType): ?>
                                <option value="<?= esc((string) $fileType['filetype']) ?>" <?= $type === $fileType['filetype'] ? 'selected' : '' ?>><?= esc((string) $fileType['filetype']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-sm-4 col-lg-3"><label class="visually-hidden" for="distributionFilter">Distribution</label><select class="form-select" id="distributionFilter" name="distribution"><option value="">All distributions</option><option value="mobile_app" <?= $distribution === 'mobile_app' ? 'selected' : '' ?>>Mobile App</option><option value="department" <?= $distribution === 'department' ? 'selected' : '' ?>>Department Assignment</option></select></div>
                    <div class="col-sm-4 col-lg-3">
                        <div class="d-flex align-items-center gap-2">
                            <button class="btn btn-primary flex-grow-1" type="submit"><i class="bi bi-funnel me-1"></i> Apply filters</button>
                            <div class="media-view-switcher" role="group" aria-label="Choose media view">
                                <button class="media-view-toggle" type="button" data-media-view="list" aria-label="Show file list" aria-pressed="false" title="List view"><i class="bi bi-list-ul"></i></button>
                                <button class="media-view-toggle is-active" type="button" data-media-view="grid" aria-label="Show image grid" aria-pressed="true" title="Grid view"><i class="bi bi-grid-3x3-gap-fill"></i></button>
                            </div>
                        </div>
                    </div>
                </div>
            </form>

            <?php if ($query !== '' && $folders === [] && $files === []): ?><div class="alert alert-light border m-3"><i class="bi bi-search me-1"></i>No matching folders or media found.</div><?php endif; ?>

            <?php if ($selectedFolder !== null): ?>
                <div class="folder-location d-flex align-items-center justify-content-between flex-wrap gap-2">
                    <span><i class="bi bi-folder2-open me-1"></i>Viewing folder: <strong><?= esc($selectedFolder['name']) ?></strong></span>
                    <div class="d-flex flex-wrap gap-2">
                        <a class="btn btn-sm btn-outline-primary" href="<?= esc($parentFolder === null ? site_url('workspace/file-management') : site_url('workspace/file-management?folder=' . (int) $parentFolder['id'])) ?>"><i class="bi bi-arrow-left me-1"></i><?= $parentFolder === null ? 'All folders' : 'Parent folder' ?></a>
                        <?php if ($canManage): ?><div class="dropdown"><button class="drive-more-button" type="button" data-bs-toggle="dropdown" aria-expanded="false" aria-label="Folder actions"><i class="bi bi-three-dots-vertical"></i></button><ul class="dropdown-menu dropdown-menu-end drive-action-menu"><li><button class="dropdown-item" type="button" data-bs-toggle="modal" data-bs-target="#renameFolderModal" data-folder-name="<?= esc($selectedFolder['name']) ?>" data-folder-action="<?= esc(site_url('workspace/file-management/folders/' . (int) $selectedFolder['id'] . '/rename')) ?>"><i class="bi bi-pencil me-2"></i>Rename</button></li><li><hr class="dropdown-divider"></li><li><form action="<?= esc(site_url('workspace/file-management/folders/' . (int) $selectedFolder['id'] . '/delete')) ?>" method="post" onsubmit="return confirm('Delete this folder and its subfolders? Their active files will be moved to Trash and can be restored.');"><?= csrf_field() ?><button class="dropdown-item text-danger" type="submit"><i class="bi bi-trash me-2"></i>Delete folder</button></form></li></ul></div><?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>
            <?php if ($folders !== []): ?>
                <div class="folder-browser">
                    <p class="folder-browser-title"><i class="bi bi-folder2-open me-1"></i><?= $query !== '' ? 'Matching Folders' : ($selectedFolder === null ? 'Folders' : 'Subfolders') ?></p>
                    <p class="folder-browser-subtitle"><?= $selectedFolder === null ? 'Open a folder to view the media uploaded inside it.' : 'Open a subfolder or create another folder inside the current folder.' ?></p>
                    <div class="folder-grid" id="folderBrowserView">
                        <div class="folder-list-header" aria-hidden="true"><span>Folder name</span><span class="folder-list-modified">Date modified</span><span>File count</span><span class="text-center">Actions</span></div>
                        <?php foreach ($folders as $folder): ?>
                            <div class="folder-card">
                                <a class="folder-card-main" href="<?= esc(site_url('workspace/file-management?folder=' . (int) $folder['id'])) ?>" aria-label="Open folder <?= esc($folder['name']) ?>">
                                    <span class="folder-name-cell"><span class="folder-card-icon"><i class="bi bi-folder-fill"></i></span><span class="min-w-0"><span class="folder-card-name d-block" title="<?= esc($folder['name']) ?>"><?= esc($folder['name']) ?></span><span class="folder-card-meta"><?= esc((string) $folder['file_count']) ?> <?= (int) $folder['file_count'] === 1 ? 'file' : 'files' ?></span></span></span>
                                    <span class="folder-list-cell folder-list-modified"><?= esc(! empty($folder['created_at']) ? date('M d, Y', strtotime((string) $folder['created_at'])) : '-') ?></span>
                                    <span class="folder-list-cell"><?= esc((string) $folder['file_count']) ?> <?= (int) $folder['file_count'] === 1 ? 'file' : 'files' ?></span>
                                </a>
                                <?php if ($canManage): ?><div class="dropdown folder-card-menu">
                                    <button class="drive-more-button" type="button" data-bs-toggle="dropdown" aria-expanded="false" aria-label="Actions for <?= esc($folder['name']) ?>"><i class="bi bi-three-dots-vertical"></i></button>
                                    <ul class="dropdown-menu dropdown-menu-end drive-action-menu">
                                        <li><a class="dropdown-item" href="<?= esc(site_url('workspace/file-management?folder=' . (int) $folder['id'])) ?>"><i class="bi bi-folder2-open me-2"></i>Open folder</a></li>
                                        <li><button class="dropdown-item" type="button" data-bs-toggle="modal" data-bs-target="#renameFolderModal" data-folder-name="<?= esc($folder['name']) ?>" data-folder-action="<?= esc(site_url('workspace/file-management/folders/' . (int) $folder['id'] . '/rename')) ?>"><i class="bi bi-pencil me-2"></i>Rename</button></li>
                                        <li><hr class="dropdown-divider"></li>
                                        <li><form action="<?= esc(site_url('workspace/file-management/folders/' . (int) $folder['id'] . '/delete')) ?>" method="post" onsubmit="return confirm('Delete this folder and its subfolders? Their active files will be moved to Trash and can be restored.');"><?= csrf_field() ?><button class="dropdown-item text-danger" type="submit"><i class="bi bi-trash me-2"></i>Delete folder</button></form></li>
                                    </ul>
                                </div><?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>

            <div class="modal fade" id="renameFolderModal" tabindex="-1" aria-labelledby="renameFolderTitle" aria-hidden="true">
                <div class="modal-dialog modal-dialog-centered"><div class="modal-content"><form id="renameFolderForm" method="post"><?= csrf_field() ?><div class="modal-header"><h5 class="modal-title" id="renameFolderTitle">Rename folder</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div><div class="modal-body"><label class="form-label" for="renameFolderName">New folder name</label><input class="form-control" id="renameFolderName" name="name" maxlength="120" required></div><div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button><button class="btn btn-primary" type="submit"><i class="bi bi-check2 me-1"></i>Save name</button></div></form></div></div>
            </div>
            <form class="bulk-toolbar" id="bulkActionForm" method="post" action="<?= esc(site_url('workspace/file-management/bulk-download' . ($folderId === null ? '' : '?folder=' . (int) $folderId))) ?>">
                <?= csrf_field() ?>
                <span id="bulkSelectedInputs"></span>
                <div class="bulk-selection">
                    <button class="btn btn-sm btn-outline-primary" id="selectAllMedia" type="button"><i class="bi bi-check2-square me-1"></i>Select all</button>
                    <button class="btn btn-sm btn-link text-secondary px-1" id="clearMediaSelection" type="button" disabled>Clear</button>
                    <span class="bulk-selection-count" id="bulkSelectionCount">0 selected</span>
                </div>
                <div class="bulk-actions">
                    <?php if ($canManage): ?>
                    <button class="btn btn-sm btn-outline-secondary" type="button" data-bulk-action="tags" data-bs-toggle="modal" data-bs-target="#manualTaggingModal" disabled><i class="bi bi-tags me-1"></i>Tags</button>
                    <?php endif; ?>
                    <button class="btn btn-sm btn-outline-primary" type="submit" data-bulk-action="download" disabled><i class="bi bi-file-earmark-zip me-1"></i>Download</button>
                    <?php if ($canManage): ?><button class="btn btn-sm btn-outline-danger" type="submit" data-bulk-action="trash" formaction="<?= esc(site_url('workspace/file-management/bulk-trash' . ($folderId === null ? '' : '?folder=' . (int) $folderId))) ?>" disabled><i class="bi bi-trash me-1"></i>Trash</button><?php endif; ?>
                </div>
            </form>

            <div class="table-responsive d-none" id="mediaListView">
                <table class="table media-table align-middle">
                    <thead><tr><th class="text-center"><input class="form-check-input media-select" type="checkbox" data-media-select-all aria-label="Select all visible media"></th><th>File</th><th>Type</th><th>Size</th><th>Uploaded</th><th>Tags</th><th class="text-end">Actions</th></tr></thead>
                    <tbody>
                        <?php foreach ($files as $file): ?>
                            <?php $isPreviewableImage = in_array(strtolower((string) $file['filetype']), $previewableImageTypes, true); $supportsLogo = $canManage && in_array(strtolower((string) $file['filetype']), $logoWatermarkTypes, true); $previewUrl = site_url('workspace/file-management/' . (int) $file['id'] . ($supportsLogo ? '/watermarked-preview' : '/preview')); $mediaTitle = \App\Libraries\MediaTitle::forFile($file); ?>
                            <tr>
                                <td class="text-center"><input class="form-check-input media-select" type="checkbox" data-media-select data-media-id="<?= (int) $file['id'] ?>" aria-label="Select <?= esc($mediaTitle) ?>"></td>
                                <td>
                                    <div class="d-flex align-items-center gap-2">
                                        <?php if ($isPreviewableImage): ?>
                                            <button class="file-thumbnail" type="button" data-bs-toggle="modal" data-bs-target="#imagePreviewModal" data-image-preview data-preview-src="<?= esc($previewUrl) ?>" data-original-preview-src="<?= esc(site_url('workspace/file-management/' . (int) $file['id'] . '/preview')) ?>" data-preview-name="<?= esc($mediaTitle) ?>" data-download-url="<?= esc(site_url('workspace/file-management/' . (int) $file['id'] . '/download')) ?>" data-logo-url="<?= $supportsLogo ? esc(site_url('workspace/watermarking#media-' . (int) $file['id'])) : '' ?>" aria-label="Preview <?= esc($mediaTitle) ?>">
                                                <img src="<?= esc(site_url('workspace/file-management/' . (int) $file['id'] . '/preview')) ?>" alt="Preview of <?= esc($mediaTitle) ?>" loading="lazy">
                                            </button>
                                        <?php else: ?>
                                            <span class="file-icon"><i class="bi bi-file-earmark"></i></span>
                                        <?php endif; ?>
                                        <div><div class="file-name"><?= esc($mediaTitle) ?></div><?php if (! empty($file['description'])): ?><div class="file-description"><?= esc($file['description']) ?></div><?php endif; ?></div>
                                    </div>
                                </td>
                                <td><span class="file-type"><?= esc($file['filetype'] ?: 'Unknown') ?></span></td>
                                <td><?= esc(format_bytes((int) $file['size'])) ?></td>
                                <td><?= esc($file['dateupload'] ? date('M d, Y', strtotime((string) $file['dateupload'])) : '-') ?></td>
                                <td><span class="tags-value" title="<?= esc($file['tags'] ?: '') ?>"><?= esc($file['tags'] ?: '-') ?></span></td>
                                <td class="text-end text-nowrap">
                                    <a class="btn btn-outline-primary btn-sm action-button" href="<?= esc(site_url('workspace/file-management/' . (int) $file['id'] . '/download')) ?>" title="Download <?= esc($mediaTitle) ?>"><i class="bi bi-download"></i><span class="d-none d-xl-inline">Download</span></a>
                                    <?php if ($canManage && in_array(strtolower((string) $file['filetype']), $logoWatermarkTypes, true)): ?><a class="btn btn-outline-secondary btn-sm action-button" href="<?= esc(site_url('workspace/watermarking#media-' . (int) $file['id'])) ?>" title="Download with a department logo"><i class="bi bi-patch-check"></i><span class="d-none d-xxl-inline">Logo copy</span></a><?php endif; ?>
                                    <span class="badge bg-success">Published<?= ! empty($file['publication_channel']) ? ': ' . esc($file['publication_channel']) : '' ?></span>
                                    <?php if ($canManage): ?><form class="d-inline" action="<?= esc(site_url('workspace/file-management/' . (int) $file['id'] . '/trash')) ?>" method="post" onsubmit="return confirm('Move this file to Trash?');">
                                        <?= csrf_field() ?>
                                        <button class="btn btn-outline-danger btn-sm action-button" type="submit" title="Move <?= esc($mediaTitle) ?> to Trash"><i class="bi bi-trash"></i><span class="d-none d-xl-inline">Trash</span></button>
                                    </form><?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if ($files === []): ?>
                            <tr><td colspan="7" class="empty-state text-center"><span class="empty-state-icon"><i class="bi bi-images"></i></span><h3>Your media library is empty</h3><p>Upload your first file to start building a secure, searchable library for your team.</p><button class="btn btn-primary btn-sm" type="button" data-bs-toggle="collapse" data-bs-target="#uploadPanel"><i class="bi bi-cloud-arrow-up me-1"></i> Upload a file</button></td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <div class="media-grid" id="mediaGridView">
                <?php foreach ($files as $file): ?>
                    <?php $isPreviewableImage = in_array(strtolower((string) $file['filetype']), $previewableImageTypes, true); $supportsLogo = $canManage && in_array(strtolower((string) $file['filetype']), $logoWatermarkTypes, true); $previewUrl = site_url('workspace/file-management/' . (int) $file['id'] . ($supportsLogo ? '/watermarked-preview' : '/preview')); $mediaTitle = \App\Libraries\MediaTitle::forFile($file); ?>
                    <article class="media-grid-card">
                        <label class="grid-select" title="Select <?= esc($mediaTitle) ?>"><input class="form-check-input media-select" type="checkbox" data-media-select data-media-id="<?= (int) $file['id'] ?>" aria-label="Select <?= esc($mediaTitle) ?>"></label>
                        <div class="dropdown grid-more">
                            <button class="drive-more-button bg-white" type="button" data-bs-toggle="dropdown" aria-expanded="false" aria-label="Actions for <?= esc($mediaTitle) ?>"><i class="bi bi-three-dots-vertical"></i></button>
                            <ul class="dropdown-menu dropdown-menu-end drive-action-menu">
                                <?php if ($isPreviewableImage): ?><li><button class="dropdown-item" type="button" data-bs-toggle="modal" data-bs-target="#imagePreviewModal" data-image-preview data-preview-src="<?= esc($previewUrl) ?>" data-original-preview-src="<?= esc(site_url('workspace/file-management/' . (int) $file['id'] . '/preview')) ?>" data-preview-name="<?= esc($mediaTitle) ?>" data-download-url="<?= esc(site_url('workspace/file-management/' . (int) $file['id'] . '/download')) ?>" data-logo-url="<?= $supportsLogo ? esc(site_url('workspace/watermarking#media-' . (int) $file['id'])) : '' ?>"><i class="bi bi-eye me-2"></i>Preview</button></li><?php endif; ?>
                                <li><a class="dropdown-item" href="<?= esc(site_url('workspace/file-management/' . (int) $file['id'] . '/download')) ?>"><i class="bi bi-download me-2"></i>Download</a></li>
                                <?php if ($canManage && in_array(strtolower((string) $file['filetype']), $logoWatermarkTypes, true)): ?><li><a class="dropdown-item" href="<?= esc(site_url('workspace/watermarking#media-' . (int) $file['id'])) ?>"><i class="bi bi-patch-check me-2"></i>Download with logo</a></li><?php endif; ?>
                                <?php if ($canManage): ?><li><hr class="dropdown-divider"></li><li><form action="<?= esc(site_url('workspace/file-management/' . (int) $file['id'] . '/trash')) ?>" method="post" onsubmit="return confirm('Move this file to Trash?');"><?= csrf_field() ?><button class="dropdown-item text-danger" type="submit"><i class="bi bi-trash me-2"></i>Move to Trash</button></form></li><?php endif; ?>
                            </ul>
                        </div>
                        <?php if ($isPreviewableImage): ?>
                            <button class="grid-thumbnail" type="button" data-bs-toggle="modal" data-bs-target="#imagePreviewModal" data-image-preview data-preview-src="<?= esc($previewUrl) ?>" data-original-preview-src="<?= esc(site_url('workspace/file-management/' . (int) $file['id'] . '/preview')) ?>" data-preview-name="<?= esc($mediaTitle) ?>" data-download-url="<?= esc(site_url('workspace/file-management/' . (int) $file['id'] . '/download')) ?>" data-logo-url="<?= $supportsLogo ? esc(site_url('workspace/watermarking#media-' . (int) $file['id'])) : '' ?>" aria-label="Preview <?= esc($mediaTitle) ?>">
                                <img src="<?= esc(site_url('workspace/file-management/' . (int) $file['id'] . '/preview')) ?>" alt="Preview of <?= esc($mediaTitle) ?>" loading="lazy">
                            </button>
                        <?php else: ?>
                            <div class="grid-file-icon"><i class="bi bi-file-earmark"></i></div>
                        <?php endif; ?>
                        <div class="grid-card-content">
                            <div class="grid-file-name" title="<?= esc($mediaTitle) ?>"><?= esc($mediaTitle) ?></div>
                            <div class="d-flex align-items-center justify-content-between gap-2"><span class="file-type"><?= esc($file['filetype'] ?: 'Unknown') ?></span><span class="grid-file-info"><?= esc(format_bytes((int) $file['size'])) ?></span></div>
                            <div class="grid-file-info"><i class="bi bi-calendar3 me-1"></i><?= esc($file['dateupload'] ? date('M d, Y', strtotime((string) $file['dateupload'])) : '-') ?></div>
                            <span class="badge bg-success-subtle text-success border border-success-subtle align-self-start"><i class="bi bi-check2-circle me-1"></i>Published<?= ! empty($file['publication_channel']) ? ' · ' . esc($file['publication_channel']) : '' ?></span>
                        </div>
                    </article>
                <?php endforeach; ?>
                <?php if ($files === []): ?>
                    <div class="empty-state text-center"><span class="empty-state-icon"><i class="bi bi-images"></i></span><h3>Your media library is empty</h3><p>Upload your first file to start building a secure, searchable library for your team.</p><button class="btn btn-primary btn-sm" type="button" data-bs-toggle="collapse" data-bs-target="#uploadPanel"><i class="bi bi-cloud-arrow-up me-1"></i> Upload a file</button></div>
                <?php endif; ?>
            </div>
        </div>
    </section>
</main>
<?php if ($canManage): ?>
<div class="modal fade" id="manualTaggingModal" tabindex="-1" aria-labelledby="manualTaggingTitle" aria-hidden="true"><div class="modal-dialog modal-dialog-centered" style="max-width:460px"><div class="modal-content"><form id="manualTaggingForm" action="<?= esc(site_url('workspace/file-management/tags')) ?>" method="post"><?= csrf_field() ?><div class="modal-header"><h5 class="modal-title" id="manualTaggingTitle">Manual Tagging</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div><div class="modal-body"><label class="form-label" for="manualTagInput">Tags</label><input class="form-control" id="manualTagInput" maxlength="50" placeholder="Enter a tag and press Enter" autocomplete="off"><div class="d-flex flex-wrap gap-2 mt-3" id="manualTagChips"></div><div id="manualTagValues"></div><div id="manualTagFileIds"></div><div class="form-text">Press Enter or comma to add. Tags are trimmed, limited to 50 characters, and duplicates are ignored.</div></div><div class="modal-footer"><button class="btn btn-light" type="button" data-bs-dismiss="modal">Cancel</button><button class="btn btn-primary" type="submit">Save Tags</button></div></form></div></div></div>
<?php endif; ?>
<?php if ($files !== []): ?>
    <div class="modal fade media-workspace" id="imagePreviewModal" tabindex="-1" aria-labelledby="imagePreviewTitle" aria-hidden="true">
        <div class="modal-dialog modal-xl modal-dialog-centered image-preview-dialog">
            <div class="modal-content">
                <div class="modal-header"><div><h5 class="modal-title" id="imagePreviewTitle">Image preview</h5><div class="small text-muted" id="imagePreviewStatus">The current default logo is applied when available. Your original file stays unchanged.</div></div><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
                <div class="modal-body image-preview-body"><img id="imagePreviewImage" src="" alt=""></div>
                <div class="modal-footer"><a class="btn btn-primary" id="imagePreviewLogoDownload" href="#"><i class="bi bi-patch-check me-1"></i>Choose logo &amp; download</a><a class="btn btn-outline-primary" id="imagePreviewDownload" href="#"><i class="bi bi-download me-1"></i>Download original</a><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Close</button></div>
            </div>
        </div>
    </div>
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            var modal = document.getElementById('imagePreviewModal');
            var previewImage = document.getElementById('imagePreviewImage');
            var previewTitle = document.getElementById('imagePreviewTitle');
            var previewStatus = document.getElementById('imagePreviewStatus');
            var downloadLink = document.getElementById('imagePreviewDownload');
            var logoDownloadLink = document.getElementById('imagePreviewLogoDownload');
            modal.addEventListener('show.bs.modal', function (event) {
                var button = event.relatedTarget;
                if (!button || !button.matches('[data-image-preview]')) { return; }
                var originalPreview = button.dataset.originalPreviewSrc || button.dataset.previewSrc;
                previewImage.dataset.originalPreviewSrc = originalPreview;
                previewImage.dataset.fallbackUsed = 'false';
                previewImage.alt = 'Preview of ' + button.dataset.previewName;
                previewTitle.textContent = button.dataset.previewName;
                previewStatus.textContent = button.dataset.previewSrc === originalPreview
                    ? 'Original image preview. Your stored file remains unchanged.'
                    : 'The current default logo is applied when available. Your original file stays unchanged.';
                downloadLink.href = button.dataset.downloadUrl;
                logoDownloadLink.href = button.dataset.logoUrl || '#';
                logoDownloadLink.classList.toggle('d-none', !button.dataset.logoUrl);
                previewImage.src = button.dataset.previewSrc;
            });
            previewImage.addEventListener('error', function () {
                var originalPreview = previewImage.dataset.originalPreviewSrc;
                if (originalPreview && previewImage.dataset.fallbackUsed !== 'true' && previewImage.src !== originalPreview) {
                    previewImage.dataset.fallbackUsed = 'true';
                    previewStatus.textContent = 'Watermarked preview was unavailable, so the original image is shown.';
                    previewImage.src = originalPreview;
                }
            });
            modal.addEventListener('hidden.bs.modal', function () { previewImage.src = ''; previewImage.removeAttribute('data-original-preview-src'); });

            var bulkForm = document.getElementById('bulkActionForm');
            if (bulkForm) {
                var selectedMediaIds = new Set();
                var selectionInputs = document.getElementById('bulkSelectedInputs');
                var selectionCount = document.getElementById('bulkSelectionCount');
                var selectAllButton = document.getElementById('selectAllMedia');
                var clearButton = document.getElementById('clearMediaSelection');
                var getVisibleIds = function () {
                    var ids = [];
                    document.querySelectorAll('[data-media-select]').forEach(function (checkbox) {
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
                    bulkForm.classList.toggle('has-selection', selectedCount > 0);
                    clearButton.disabled = selectedCount === 0;
                    document.querySelectorAll('[data-bulk-action]').forEach(function (button) { button.disabled = selectedCount === 0; });
                };
                document.querySelectorAll('[data-media-select]').forEach(function (checkbox) {
                    checkbox.addEventListener('change', function () {
                        if (checkbox.checked) { selectedMediaIds.add(checkbox.dataset.mediaId); } else { selectedMediaIds.delete(checkbox.dataset.mediaId); }
                        syncSelection();
                    });
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
                    getVisibleIds().forEach(function (id) { selectedMediaIds.add(id); });
                    syncSelection();
                });
                clearButton.addEventListener('click', function () {
                    selectedMediaIds.clear();
                    syncSelection();
                });
                bulkForm.addEventListener('submit', function (event) {
                    if (selectedMediaIds.size === 0) {
                        event.preventDefault();
                        return;
                    }
                    if (event.submitter && event.submitter.dataset.bulkAction === 'trash' && !window.confirm('Move the selected media files to Trash?')) {
                        event.preventDefault();
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
        var folderView = document.getElementById('folderBrowserView');
        var viewButtons = document.querySelectorAll('[data-media-view]');
        if (listView && gridView && viewButtons.length > 0) {
            var setMediaView = function (view) {
                var activeView = view === 'list' ? 'list' : 'grid';
                var isGrid = activeView === 'grid';
                listView.classList.toggle('d-none', isGrid);
                gridView.classList.toggle('d-none', !isGrid);
                if (folderView) { folderView.classList.toggle('is-list-view', !isGrid); }
                viewButtons.forEach(function (button) {
                    var isActive = button.dataset.mediaView === activeView;
                    button.classList.toggle('is-active', isActive);
                    button.setAttribute('aria-pressed', isActive ? 'true' : 'false');
                });
                try { window.localStorage.setItem('greyhound-media-view', activeView); } catch (error) { }
            };

            viewButtons.forEach(function (button) {
                button.addEventListener('click', function () {
                    setMediaView(button.dataset.mediaView);
                });
            });

            var initialView = 'grid';
            try {
                var savedView = window.localStorage.getItem('greyhound-media-view');
                if (savedView === 'list' || savedView === 'grid') { initialView = savedView; }
            } catch (error) { }
            setMediaView(initialView);
        }

        var renameFolderForm = document.getElementById('renameFolderForm');
        var renameFolderName = document.getElementById('renameFolderName');
        document.querySelectorAll('[data-bs-target="#renameFolderModal"]').forEach(function (button) {
            button.addEventListener('click', function () {
                renameFolderForm.action = button.dataset.folderAction;
                renameFolderName.value = button.dataset.folderName;
                window.setTimeout(function () { renameFolderName.focus(); renameFolderName.select(); }, 250);
            });
        });

        var searchInput = document.getElementById('mediaSearch');
        if (searchInput) {
            var searchTimer;
            searchInput.addEventListener('input', function () { window.clearTimeout(searchTimer); searchTimer = window.setTimeout(function () { searchInput.form.submit(); }, 350); });
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

        var distributionType = document.getElementById('distribution_type');
        if (distributionType) {
            var targetSelect=document.getElementById('distribution_target'), methodWrap=document.getElementById('assignmentMethodWrap'), methodSelect=document.getElementById('assignment_method'), requestWrap=document.getElementById('requestWrap'), requestSelect=document.getElementById('request_id');
            var departments=<?= json_encode(array_values(array_filter(array_column($departments, 'department'))), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
            var mobileTargets=['General Events','CCS','CBA','CN','CHM'];
            var updateTargets=function(){var values=distributionType.value==='mobile_app'?mobileTargets:(distributionType.value==='department'?departments:[]);targetSelect.replaceChildren(new Option(values.length?'Choose destination':'Select distribution type first',''));values.forEach(function(value){targetSelect.add(new Option(value,value));});methodWrap.classList.toggle('d-none',distributionType.value!=='department');requestWrap.classList.add('d-none');};
            distributionType.addEventListener('change',updateTargets);methodSelect.addEventListener('change',function(){requestWrap.classList.toggle('d-none',methodSelect.value!=='request');});requestSelect.addEventListener('change',function(){var option=requestSelect.selectedOptions[0];if(option&&option.dataset.department){targetSelect.value=option.dataset.department;}});updateTargets();
        }

        var uploadForm = document.getElementById('batchUploadForm');
        if (!uploadForm) { return; }

        var filesInput = document.getElementById('files');
        var folderInput = document.getElementById('folder_files');
        var fileSelection = document.getElementById('fileSelection');
        var folderSelection = document.getElementById('folderSelection');
        var progress = document.getElementById('uploadProgress');
        var progressLabel = document.getElementById('uploadProgressLabel');
        var progressCount = document.getElementById('uploadProgressCount');
        var progressBar = document.getElementById('uploadProgressBar');
        var submitButton = document.getElementById('uploadSubmit');
        var csrfInput = uploadForm.querySelector('input[name="<?= esc(csrf_token()) ?>"]');

        var updateSelection = function () {
            var fileCount = filesInput.files.length;
            var folderCount = folderInput.files.length;
            fileSelection.textContent = fileCount === 0 ? 'No files selected' : fileCount + ' file' + (fileCount === 1 ? '' : 's') + ' selected';
            folderSelection.textContent = folderCount === 0 ? 'Keeps subfolders (Chrome or Edge)' : folderCount + ' folder file' + (folderCount === 1 ? '' : 's') + ' selected';
            filesInput.closest('.upload-picker').classList.toggle('has-files', fileCount > 0);
            folderInput.closest('.upload-picker').classList.toggle('has-files', folderCount > 0);
            submitButton.disabled = fileCount + folderCount === 0;
        };
        filesInput.addEventListener('change', updateSelection);
        folderInput.addEventListener('change', updateSelection);
        var dropZone = document.querySelector('[data-upload-drop]');
        if (dropZone) {
            ['dragenter', 'dragover'].forEach(function (name) { dropZone.addEventListener(name, function (event) { event.preventDefault(); dropZone.classList.add('is-dragging'); }); });
            ['dragleave', 'drop'].forEach(function (name) { dropZone.addEventListener(name, function (event) { event.preventDefault(); dropZone.classList.remove('is-dragging'); }); });
            dropZone.addEventListener('drop', function (event) { if (event.dataTransfer && event.dataTransfer.files.length > 0) { filesInput.files = event.dataTransfer.files; updateSelection(); } });
        }
        updateSelection();

        if (!window.fetch || !window.FormData) { return; }

        uploadForm.addEventListener('submit', async function (event) {
            var queuedFiles = [];
            Array.prototype.forEach.call(filesInput.files, function (file) {
                queuedFiles.push({ file: file, relativePath: '' });
            });
            Array.prototype.forEach.call(folderInput.files, function (file) {
                queuedFiles.push({ file: file, relativePath: file.webkitRelativePath || file.name });
            });
            if (queuedFiles.length === 0) { return; }

            event.preventDefault();
            var displayTitle = document.getElementById('display_title').value.trim().replace(/\s+/g, ' ');
            if (!displayTitle || displayTitle.length > 150) {
                document.getElementById('display_title').setCustomValidity(displayTitle ? 'Display Title may not exceed 150 characters.' : 'Display Title is required.');
                document.getElementById('display_title').reportValidity();
                return;
            }
            document.getElementById('display_title').setCustomValidity('');
            var destinationFolder = document.getElementById('folder_id').value;
            var description = document.getElementById('description').value;
            var csrfToken = csrfInput ? csrfInput.value : '';
            var completed = 0;
            var successful = 0;
            var errors = [];
            progress.classList.remove('d-none');
            submitButton.disabled = true;

            for (var index = 0; index < queuedFiles.length; index += 1) {
                var item = queuedFiles[index];
                progressLabel.textContent = 'Uploading ' + item.file.name;
                progressCount.textContent = (index + 1) + ' of ' + queuedFiles.length;
                progressBar.style.width = Math.round((index / queuedFiles.length) * 100) + '%';

                var formData = new FormData();
                formData.append('file', item.file);
                formData.append('display_title', displayTitle);
                formData.append('folder_id', destinationFolder);
                formData.append('description', description);
                formData.append('relative_path', item.relativePath);
                ['distribution_type','distribution_target','assignment_method','request_id'].forEach(function(name){var field=document.getElementById(name);if(field&&field.value){formData.append(name,field.value);}});
                if (csrfInput) { formData.append(csrfInput.name, csrfToken); }

                try {
                    var response = await fetch(uploadForm.action, {
                        method: 'POST',
                        body: formData,
                        credentials: 'same-origin',
                        headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
                    });
                    var payload = await response.json();
                    if (payload.csrfToken && csrfInput) {
                        csrfToken = payload.csrfToken;
                        csrfInput.value = csrfToken;
                    }
                    if (response.ok && payload.ok) {
                        successful += payload.uploaded || 1;
                    } else {
                        errors.push((payload.errors && payload.errors[0]) || payload.message || item.file.name + ' could not be uploaded.');
                    }
                } catch (error) {
                    errors.push(item.file.name + ' could not be uploaded. Check your connection and try again.');
                }
                completed += 1;
                progressBar.style.width = Math.round((completed / queuedFiles.length) * 100) + '%';
            }

            progressBar.classList.remove('progress-bar-animated');
            progressCount.textContent = successful + ' uploaded, ' + errors.length + ' skipped';
            progressLabel.textContent = errors.length === 0
                ? 'Upload complete. Refreshing your media library...'
                : 'Upload complete. ' + errors.slice(0, 2).join(' ');
            if (successful > 0) {
                window.setTimeout(function () { window.location.reload(); }, errors.length === 0 ? 900 : 2800);
            } else {
                submitButton.disabled = false;
                progressBar.classList.add('bg-danger');
            }
        });
    });
</script>
<?= view('layouts/app_footer') ?>
