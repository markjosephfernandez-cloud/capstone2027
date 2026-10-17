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
    .media-workspace .upload-card { background: #fff; border: 1px solid var(--media-border); border-radius: 16px; box-shadow: 0 8px 24px rgba(37, 52, 85, .06); padding: 1.25rem; }
    .media-workspace .new-folder-dialog { max-width: 400px; }
    .media-workspace .new-folder-dialog .modal-content { border: 0; border-radius: 24px; box-shadow: 0 12px 36px rgba(0, 0, 0, .18); padding: 24px; }
    .media-workspace .new-folder-dialog .modal-title { color: #242424; font-size: 24px; font-weight: 400; line-height: 1.3; margin: 0 0 18px; }
    .media-workspace .new-folder-input { border: 1px solid #747775; border-radius: 4px; color: #242424; font-size: 16px; height: 56px; padding: 12px 14px; }
    .media-workspace .new-folder-input:focus { border-color: #0b57d0; box-shadow: inset 0 0 0 1px #0b57d0; outline: 1px solid #0b57d0; }
    .media-workspace .new-folder-actions { display: flex; gap: 8px; justify-content: flex-end; margin-top: 24px; }
    .media-workspace .new-folder-actions .btn { border: 0; border-radius: 20px; color: #0b57d0; font-size: 14px; min-height: 40px; min-width: 68px; padding: 8px 12px; text-decoration: none; }
    .media-workspace .new-folder-actions .btn:hover, .media-workspace .new-folder-actions .btn:focus-visible { background: #eef3ff; }
    .media-workspace .upload-icon { align-items: center; background: #eaf0ff; border-radius: 12px; color: var(--media-primary); display: flex; flex-shrink: 0; font-size: 1.25rem; height: 42px; justify-content: center; width: 42px; }
    .media-workspace .upload-card h3 { color: var(--media-ink); font-size: 1rem; font-weight: 700; margin: 0; }
    .media-workspace .upload-card .form-label { color: #44516e; font-size: .78rem; font-weight: 700; margin-bottom: .35rem; text-transform: uppercase; }
    .media-workspace .upload-card .form-control { border-color: #dce3f2; min-height: 42px; }
    .media-workspace .upload-folder-picker { position: relative; min-width: 0; }
    .media-workspace .upload-folder-picker summary { cursor: pointer; list-style: none; }
    .media-workspace .upload-folder-picker summary::-webkit-details-marker { display: none; }
    .media-workspace .upload-folder-picker summary span { display: block; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    .media-workspace .upload-folder-picker summary[aria-disabled="true"] { background-color: #e9ecef; cursor: default; }
    .media-workspace .upload-folder-menu { position: absolute; top: calc(100% + 6px); left: 0; right: 0; z-index: 20; padding: .75rem; background: #fff; border: 1px solid #dce3f1; border-radius: 12px; box-shadow: 0 8px 24px rgba(26, 42, 70, .16); }
    .media-workspace .upload-folder-choices { max-height: min(224px, 30vh); overflow-y: auto; overscroll-behavior: contain; }
    .media-workspace .upload-folder-choice { display: block; width: 100%; text-align: left; border: 0; border-radius: 6px; background: transparent; padding: .5rem; color: #23304d; overflow-wrap: anywhere; }
    .media-workspace .upload-folder-choice:hover, .media-workspace .upload-folder-choice:focus-visible { background: #eff2ff; }
    .media-workspace .upload-folder-menu select { height: 224px; max-height: 35vh; padding: .25rem; background-image: none; }
    .media-workspace .upload-folder-menu option { padding: .4rem .5rem; border-radius: 5px; overflow: hidden; text-overflow: ellipsis; }
    .media-workspace .upload-destination, .media-workspace .upload-action { display: flex; flex-direction: column; justify-content: flex-end; min-height: 72px; }
    .media-workspace .upload-destination .form-select, .media-workspace .upload-action .btn { border-radius: 10px; min-height: 48px; }
    .media-workspace .upload-review-list { border: 1px solid var(--media-border); border-radius: 12px; display: grid; gap: .75rem; grid-template-columns: repeat(auto-fill, minmax(min(150px, 100%), 1fr)); list-style: none; margin: 0; max-height: 420px; overflow-y: auto; padding: .75rem; }
    .media-workspace .upload-review-item { background: #fff; border: 1px solid var(--media-border); border-radius: 10px; display: flex; flex-direction: column; min-width: 0; overflow: hidden; }
    .media-workspace .upload-review-preview { align-items: center; background: #f0f4f9; display: flex; flex-direction: column; gap: .4rem; height: 140px; justify-content: center; overflow: hidden; }
    .media-workspace .upload-review-preview img { display: block; height: 100%; image-orientation: from-image; object-fit: contain; width: 100%; }
    .media-workspace .upload-review-preview i { color: var(--media-primary); font-size: 2rem; }
    .media-workspace .upload-review-fallback { color: var(--media-muted); font-size: .72rem; }
    .media-workspace .upload-review-name { color: var(--media-ink); display: block; font-size: .75rem; overflow: hidden; padding: .6rem .6rem 0; text-overflow: ellipsis; white-space: nowrap; }
    .media-workspace .upload-review-footer { align-items: center; display: flex; gap: .25rem; justify-content: space-between; padding: .4rem .6rem .6rem; }
    .media-workspace .upload-review-size { color: var(--media-muted); font-size: .72rem; white-space: nowrap; }
    .media-workspace .upload-review-remove { flex-shrink: 0; font-size: .75rem; }
    .media-workspace .upload-progress { background: #eef4ff; border: 1px solid #d4e2ff; border-radius: 10px; color: #40516f; padding: .75rem .9rem; }
    .media-workspace .upload-progress .progress { height: 7px; }
    .media-workspace .duplicate-mode-wrap { align-items: center; display: flex; flex-wrap: wrap; gap: .7rem 1rem; }
    .media-workspace .duplicate-mode-switch { background: var(--media-primary); border: 2px solid var(--media-primary); border-radius: 999px; display: grid; flex: 0 1 455px; grid-template-columns: repeat(2, minmax(0, 1fr)); padding: 3px; }
    .media-workspace .duplicate-mode-option { cursor: pointer; margin: 0; min-width: 0; position: relative; }
    .media-workspace .duplicate-mode-option input { opacity: 0; pointer-events: none; position: absolute; }
    .media-workspace .duplicate-mode-label { align-items: center; border-radius: 999px; color: #fff; display: flex; font-size: .8rem; font-weight: 750; gap: .4rem; justify-content: center; min-height: 38px; padding: .5rem .7rem; text-align: center; transition: background .18s ease, box-shadow .18s ease, color .18s ease; }
    .media-workspace .duplicate-mode-option input:checked + .duplicate-mode-label { background: #fff; box-shadow: 0 3px 10px rgba(25, 57, 145, .22); color: var(--media-primary); }
    .media-workspace .duplicate-mode-option input:focus-visible + .duplicate-mode-label { outline: 3px solid rgba(255, 255, 255, .65); outline-offset: -3px; }
    .media-workspace .duplicate-mode-help { color: var(--media-muted); flex: 1 1 260px; font-size: .76rem; margin: 0; }
    @media (max-width: 575.98px) { .media-workspace .duplicate-mode-label { font-size: .73rem; min-height: 36px; padding: .45rem .35rem; } }
    .media-workspace .library-card { border: 1px solid var(--media-border); border-radius: 18px; box-shadow: none; overflow: hidden; }
    .media-workspace .browser-heading { align-items: center; display: flex; justify-content: space-between; margin-bottom: .85rem; }
    .media-workspace .browser-heading h3 { color: var(--media-ink); font-size: 1rem; font-weight: 700; margin: 0; }
    .media-workspace .browser-heading p { color: var(--media-muted); font-size: .78rem; margin: .2rem 0 0; }
    .media-workspace .filter-bar { background: #fff; border-bottom: 1px solid #edf0f6; padding: 1rem 1.25rem; }
    .media-workspace .filter-bar .input-group-text { background: #fff; border-right: 0; color: var(--media-primary); }
    .media-workspace .filter-bar .input-group .form-control { border-left: 0; }
    .media-workspace .filter-bar .input-group { background: #f0f4f9; border: 1px solid transparent; border-radius: 24px; overflow: hidden; }
    .media-workspace .filter-bar .input-group:focus-within { background: #fff; border-color: #c9d7ff; box-shadow: 0 1px 5px rgba(49, 94, 251, .12); }
    .media-workspace .filter-bar .input-group .input-group-text, .media-workspace .filter-bar .input-group .form-control { background: transparent; border: 0; height: 46px; }
    .media-workspace .filter-bar .form-select { border-color: #dce3ee; border-radius: 24px; height: 48px; }
    .media-workspace .filter-bar .form-control:focus, .media-workspace .filter-bar .form-select:focus { box-shadow: 0 0 0 .2rem rgba(65, 84, 241, .12); }
    .media-workspace .filter-actions { align-items: center; display: flex; gap: .75rem; }
    .media-workspace .filter-actions > .btn { border-radius: 10px; min-height: 48px; }
    .media-workspace .media-search-box { position: relative; }
    .media-workspace .media-search-clear { align-items: center; align-self: center; background: transparent; border: 0; border-radius: 50% !important; color: var(--media-muted); display: inline-flex; flex-shrink: 0; height: 34px; justify-content: center; margin-right: 6px; padding: 0; width: 34px; }
    .media-workspace .media-search-clear[hidden] { display: none !important; }
    .media-workspace .media-search-clear:hover { background: #e1e8f4; color: var(--media-ink); }
    .media-workspace .media-search-clear:focus-visible { outline: 2px solid var(--media-primary); outline-offset: -2px; }
    .media-workspace .folder-path a[aria-current="page"] { font-weight: 700; }
    .media-workspace .media-suggestions { position: absolute; top: calc(100% + 6px); left: 0; right: 0; z-index: 1010; background: #fff; border: 1px solid var(--media-border); border-radius: 12px; box-shadow: 0 8px 24px #17223b24; padding: 6px; max-height: 320px; overflow-y: auto; }
    .media-workspace .media-suggestions[hidden] { display: none !important; }
    .media-workspace .media-suggestion { display: flex; align-items: center; gap: 12px; width: 100%; border: 0; border-radius: 7px; background: transparent; padding: 10px 12px; color: var(--media-ink); text-align: left; font-size: .875rem; }
    .media-workspace .media-suggestion:hover, .media-workspace .media-suggestion[aria-selected="true"] { background: #eef3ff; color: #2455dd; }
    .media-workspace .media-suggestion span { flex: 1; min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    .media-workspace .media-suggestion small { flex-shrink: 0; color: var(--media-muted); font-size: .72rem; }
    .media-workspace .library-card:has(.media-suggestions:not([hidden])) { overflow: visible; }
    .media-workspace .media-type-dropdown { width: 100%; max-width: 240px; }
    .media-workspace .media-type-dropdown[hidden] { display: none !important; }
    .media-workspace .media-type-toggle { align-items: center; background: #f0f4f9; border: 1px solid #dce3ee; border-radius: 10px; color: var(--media-ink); display: flex; font-size: .875rem; gap: 10px; height: 48px; padding: 0 14px; text-align: left; width: 100%; }
    .media-workspace .media-type-toggle > span { flex: 1; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    .media-workspace .media-type-toggle > .bi-chevron-down { display: inline-block; flex-shrink: 0; transition: transform .2s ease; }
    .media-workspace .media-type-toggle[aria-expanded="true"] > .bi-chevron-down { transform: rotate(180deg); }
    @media (prefers-reduced-motion: reduce) { .media-workspace .media-type-toggle > .bi-chevron-down { transition: none; } }
    .media-workspace .media-type-toggle:hover, .media-workspace .media-type-toggle[aria-expanded="true"] { background: #fff; border-color: #a9bdfb; }
    .media-workspace .media-type-toggle:focus-visible { outline: 3px solid #c9d7ff; outline-offset: 2px; }
    .media-workspace .media-type-menu { border: 1px solid var(--media-border); border-radius: 12px; box-shadow: 0 8px 24px #17223b24; max-height: 260px; min-width: 0; overflow-y: auto; padding: 6px; width: 100%; }
    .media-workspace .media-type-menu .dropdown-item { align-items: center; border-radius: 7px; display: flex; font-size: .875rem; gap: 8px; min-height: 38px; padding: 8px 10px; }
    .media-workspace .media-type-menu .dropdown-item span { flex: 1; overflow: hidden; text-overflow: ellipsis; }
    .media-workspace .media-type-menu .dropdown-item i { visibility: hidden; }
    .media-workspace .media-type-menu .dropdown-item[aria-checked="true"] { background: #eef3ff; color: #2455dd; font-weight: 600; }
    .media-workspace .media-type-menu .dropdown-item[aria-checked="true"] i { visibility: visible; }
    .media-workspace .media-type-menu .dropdown-item:hover, .media-workspace .media-type-menu .dropdown-item:focus { background: #e4ecff; color: #2455dd; }
    .media-workspace .library-card:has(.media-type-menu.show) { overflow: visible; }
    .media-workspace .filter-bar { border-radius: 18px 18px 0 0; }
    .media-workspace .search-live-status { align-items: center; color: var(--media-muted); display: flex; font-size: .72rem; gap: .35rem; margin-top: .35rem; min-height: 1rem; padding-left: .8rem; }
    .media-workspace .search-live-status.is-searching { color: var(--media-primary); }
    .media-workspace .media-view-switcher { background: #fff; border: 1px solid #d7deed; border-radius: 999px; display: inline-flex; flex-shrink: 0; overflow: hidden; padding: 2px; }
    .media-workspace .media-view-toggle { align-items: center; background: transparent; border: 0; border-radius: 999px; color: #53627c; display: inline-flex; font-size: 1.2rem; height: 42px; justify-content: center; transition: background .15s ease, color .15s ease; width: 50px; }
    .media-workspace .media-view-toggle:hover, .media-workspace .media-view-toggle:focus-visible { background: #eef2ff; color: var(--media-primary); outline: 0; }
    .media-workspace[data-media-layout="list"] [data-media-view="list"], .media-workspace:not([data-media-layout="list"]) [data-media-view="grid"] { background: #1670f8; box-shadow: 0 3px 8px rgba(22, 112, 248, .25); color: #fff; }
    .media-workspace:not([data-media-layout="list"]) #mediaListView, .media-workspace[data-media-layout="list"] #mediaGridView { display: none; }
    .media-workspace .media-table { margin: 0; }
    .media-workspace .media-table thead th { background: #f8faff; border-bottom: 1px solid #e7ecf6; color: #65708a; font-size: .72rem; font-weight: 700; letter-spacing: .04em; padding: .9rem 1.25rem; text-transform: uppercase; white-space: nowrap; }
    .media-workspace .media-table tbody td { border-color: #eef1f6; color: #49566f; padding: 1rem 1.25rem; vertical-align: middle; }
    .media-workspace .media-table tbody tr { transition: background .15s ease; }
    .media-workspace .media-table tbody tr:hover { background: #fbfcff; }
    .media-workspace .file-icon { align-items: center; background: #eef3ff; border-radius: 10px; color: var(--media-primary); display: inline-flex; font-size: 1.1rem; height: 38px; justify-content: center; width: 38px; }
    .media-workspace .file-thumbnail { background: transparent; border: 0; border-radius: 10px; display: inline-flex; height: 44px; overflow: hidden; padding: 0; width: 44px; }
    .media-workspace .file-thumbnail img { border: 1px solid #e0e7f5; border-radius: 10px; height: 44px; image-orientation: from-image; object-fit: cover; transition: transform .15s ease, box-shadow .15s ease; width: 44px; }
    .media-workspace .file-thumbnail:hover img, .media-workspace .file-thumbnail:focus-visible img { box-shadow: 0 4px 12px rgba(65, 84, 241, .25); transform: scale(1.06); }
    .media-workspace .image-preview-dialog { max-width: min(1100px, calc(100vw - 2rem)); }
    .media-workspace .image-preview-dialog .modal-content { max-height: calc(100vh - 2rem); overflow: hidden; }
    .media-workspace .image-preview-body { align-items: center; background: #101a31; display: flex; flex: 0 1 auto; justify-content: center; height: min(65vh, 640px); min-height: 0; overflow: hidden; padding: 0; }
    .media-workspace .image-preview-body img, .media-workspace .image-preview-body video { display: block; height: 100%; max-height: 100%; max-width: 100%; object-fit: contain; width: 100%; }
    .media-workspace .image-preview-body img { image-orientation: from-image; }
    #mediaPreviewModal .modal-header > div { min-width: 0; flex: 1; }
    #mediaPreviewTitle { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    #mediaPreviewStatus { height: 3em; overflow-y: auto; }
    #mediaPreviewCount { flex-shrink: 0; white-space: nowrap; }
    .media-workspace .media-grid { display: grid; gap: 1rem; grid-template-columns: repeat(auto-fill, minmax(min(205px, 100%), 1fr)); padding: 1.25rem; }
    .media-workspace .folder-browser { background: #fbfcff; border-bottom: 1px solid #edf0f6; padding: 1.15rem 1.25rem; }
    .media-workspace .folder-browser-title { color: var(--media-ink); font-size: .9rem; font-weight: 700; margin: 0; }
    .media-workspace .folder-browser-subtitle { color: var(--media-muted); font-size: .78rem; margin: .2rem 0 0; }
    .media-workspace .folder-grid { display: grid; gap: 1.25rem; grid-template-columns: repeat(auto-fill, minmax(min(185px, 100%), 1fr)); margin-top: .9rem; }
    .media-workspace:not([data-media-layout="list"]) .folder-grid > .folder-card { width: 100%; margin: 0; }
    .media-workspace .folder-list-header, .media-workspace .folder-list-cell { display: none; }
    .media-workspace[data-media-layout="list"] .folder-grid { --folder-list-columns: minmax(0, 2fr) minmax(110px, 1fr) minmax(90px, .65fr); background: #fff; border: 1px solid #e0e4ea; border-radius: 12px; display: block; overflow: visible; }
    .media-workspace[data-media-layout="list"] .folder-grid .folder-list-header { align-items: center; border-bottom: 1px solid #dadce0; color: #5f6368; display: grid; font-size: .75rem; font-weight: 600; gap: 12px; grid-template-columns: var(--folder-list-columns); height: 44px; padding: 0 8px 0 16px; }
    .media-workspace[data-media-layout="list"] .folder-grid .folder-card { align-items: stretch; background: #fff; border: 0; border-bottom: 1px solid #e8eaed; border-radius: 0; display: grid; gap: 12px; grid-template-columns: var(--folder-list-columns); min-height: 58px; padding: 0 8px 0 16px; width: 100%; }
    .media-workspace[data-media-layout="list"] .folder-grid .folder-card { margin: 0; }
    .media-workspace[data-media-layout="list"] .folder-grid .folder-card:last-child { border-bottom: 0; }
    .media-workspace[data-media-layout="list"] .folder-grid .folder-card:hover { background: #f1f3f4; box-shadow: none; cursor: pointer; }
    .media-workspace[data-media-layout="list"] .folder-grid .folder-card:focus-within { background: #eef3fd; }
    .media-workspace[data-media-layout="list"] .folder-grid .folder-card-main { align-items: center; display: grid; gap: 12px; grid-column: 1 / 4; grid-template-columns: var(--folder-list-columns); height: 100%; min-height: 58px; padding: 0; }
    .media-workspace[data-media-layout="list"] .folder-grid .folder-card-main::after { content: ""; inset: 0; position: absolute; }
    .media-workspace[data-media-layout="list"] .folder-grid .folder-name-cell { align-items: center; display: flex; gap: 12px; min-width: 0; }
    .media-workspace[data-media-layout="list"] .folder-grid .folder-card-icon { background: transparent; border-radius: 0; color: #5f6368; font-size: 1.25rem; height: 28px; width: 28px; }
    .media-workspace[data-media-layout="list"] .folder-grid .folder-card-name { color: #202124; font-size: .875rem; font-weight: 550; line-height: 1.25; }
    .media-workspace[data-media-layout="list"] .folder-grid .folder-card-meta { display: none; }
    .media-workspace[data-media-layout="list"] .folder-grid .folder-list-cell { align-items: center; color: #5f6368; display: flex; font-size: .8rem; font-weight: 400; height: 100%; min-width: 0; overflow: hidden; padding-right: 12px; text-overflow: ellipsis; white-space: normal; }
    .media-workspace[data-media-layout="list"] .folder-grid .folder-card-menu { align-items: center; display: flex; grid-column: 4; height: 100%; justify-content: center; position: static; }
    .media-workspace[data-media-layout="list"] .folder-grid .folder-card-menu .drive-more-button { position: relative; z-index: 2; }
    .media-workspace .folder-card { background: #f0f4f9; border: 1px solid transparent; border-radius: 12px; color: inherit; min-width: 0; position: relative; transition: background .15s ease, border-color .15s ease, box-shadow .15s ease; }
    .media-workspace .folder-card:hover { background: #e8eef8; border-color: #d8e1ef; box-shadow: 0 2px 6px rgba(60, 64, 67, .10); color: inherit; transform: none; }
    .media-workspace .folder-card:focus-within, .media-workspace .folder-card:has(.folder-card-menu.show) { z-index: 20; }
    .media-workspace .folder-card-main { align-items: center; color: inherit; display: flex; gap: .7rem; min-width: 0; padding: .85rem .9rem; text-decoration: none; width: 100%; }
    .media-workspace .folder-name-cell { min-width: 0; width: 100%; }
    .media-workspace .folder-name-cell > .min-w-0 { display: block; min-width: 0; }
    .media-workspace .folder-card-main:hover { color: inherit; }
    .media-workspace .folder-card-menu { position: absolute; right: .55rem; top: .57rem; z-index: 3; }
    .media-workspace .folder-card.has-folder-actions .folder-card-main { padding-right: 3rem; }
    .media-workspace[data-media-layout="list"] .folder-grid { overflow: visible; }
    .media-workspace[data-media-layout="list"] .folder-grid:has(.has-folder-actions) .folder-list-header { padding-right: 54px; }
    .media-workspace[data-media-layout="list"] .folder-grid .folder-card.has-folder-actions { grid-auto-columns: 34px; }
    .media-workspace[data-media-layout="list"] .folder-grid .folder-card.has-folder-actions .folder-card-main { padding-right: 0; }
    .media-workspace[data-media-layout="list"] .folder-grid .folder-card:last-child { border-radius: 0 0 12px 12px; }
    @media (max-width: 767.98px) { .media-workspace[data-media-layout="list"] .folder-grid .folder-card-menu { grid-column: 3; } }
    .media-workspace .drive-more-button { align-items: center; background: transparent; border: 0; border-radius: 50%; color: #5f6b7d; display: inline-flex; height: 34px; justify-content: center; width: 34px; }
    .media-workspace .drive-more-button:hover, .media-workspace .drive-more-button:focus { background: rgba(60, 64, 67, .1); color: #1f2b43; }
    .media-workspace .drive-action-menu { border: 0; border-radius: 10px; box-shadow: 0 7px 24px rgba(60, 64, 67, .2); min-width: 205px; padding: .4rem; z-index: 30; }
    .media-workspace .drive-action-menu .dropdown-item { border: 0; border-radius: 7px; color: #3e4a60; font-size: .84rem; padding: .55rem .65rem; text-align: left; width: 100%; }
    .media-workspace .drive-action-menu .dropdown-item:hover { background: #eef3ff; }
    .media-workspace .folder-card-icon { align-items: center; background: #edf2ff; border-radius: 10px; color: var(--media-primary); display: inline-flex; flex: 0 0 auto; font-size: 1.25rem; height: 40px; justify-content: center; width: 40px; }
    .media-workspace .folder-card-name { color: #34405b; font-size: .86rem; font-weight: 700; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    .media-workspace .folder-card-meta { color: #7d899f; font-size: .75rem; margin-top: .15rem; }
    .media-workspace .folder-location { background: #eef3ff; border-bottom: 1px solid #dfe7f7; color: #4a5e89; font-size: .83rem; padding: .75rem 1.25rem; }
    .media-workspace .folder-path { align-items: center; display: flex; flex-wrap: wrap; gap: .35rem; min-width: 0; }
    .media-workspace .folder-path a { color: #315da8; font-weight: 600; text-decoration: none; }
    .media-workspace .folder-path a:hover { text-decoration: underline; }
    .media-workspace .bulk-toolbar { align-items: center; background: #fff; border-bottom: 1px solid #e8edf5; display: flex; flex-wrap: wrap; gap: .75rem; min-height: 58px; padding: .8rem 1.25rem; }
    .media-workspace .bulk-toolbar:not(.has-selection) .bulk-actions { display: none; }
    .media-workspace .bulk-selection { align-items: center; display: flex; flex-wrap: wrap; gap: .45rem; }
    .media-workspace .bulk-selection-count { background: #e9efff; border-radius: 999px; color: #3159b7; font-size: .78rem; font-weight: 700; padding: .4rem .7rem; }
    .media-workspace .bulk-actions { align-items: center; display: flex; flex: 1; flex-wrap: wrap; gap: .45rem; justify-content: flex-end; }
    .media-workspace .publication-actions { align-items: center; background: #f7f9ff; border: 1px solid #e1e7f5; border-radius: 10px; display: flex; flex-wrap: wrap; gap: .4rem; padding: .35rem; }
    .media-workspace .publication-label { color: #6b7891; font-size: .7rem; font-weight: 700; padding-left: .35rem; text-transform: uppercase; }
    .media-workspace .bulk-toolbar .btn { border-radius: 8px; }
    .media-workspace .media-select { cursor: pointer; height: 1.05rem; width: 1.05rem; }
    .media-workspace .media-grid-card { background: #f0f4f9; border: 1px solid transparent; border-radius: 14px; box-shadow: none; display: flex; flex-direction: column; min-width: 0; overflow: visible; position: relative; transition: background .15s ease, box-shadow .15s ease; }
    .media-workspace .media-grid-card:hover { background: #eaf0f8; box-shadow: 0 3px 9px rgba(60, 64, 67, .12); }
    .media-workspace .grid-thumbnail, .media-workspace .grid-file-icon { align-items: center; background: #fff; border: 0; border-radius: 10px; color: var(--media-primary); display: flex; font-size: 2rem; height: 160px; justify-content: center; margin: .5rem; overflow: hidden; padding: 0; width: calc(100% - 1rem); }
    .media-workspace .grid-thumbnail img { border: 0; border-radius: 10px; height: 100%; image-orientation: from-image; object-fit: cover; width: 100%; }
    .media-workspace .grid-thumbnail video { border: 0; border-radius: 10px; height: 100%; object-fit: cover; pointer-events: none; width: 100%; }
    .media-workspace .video-thumbnail { position: relative; }
    .media-workspace img[data-server-thumbnail]:not([data-thumbnail-state="loaded"]) { visibility: hidden; }
    .media-workspace .video-thumbnail:has(img[data-server-thumbnail]:not([data-thumbnail-state="loaded"])) { background: #e8edf5; }
    .media-workspace .video-thumbnail:has(img[data-server-thumbnail]:not([data-thumbnail-state="loaded"]))::before { bottom: 12px; color: #52617b; content: "Loading thumbnail…"; font-size: 12px; left: 8px; position: absolute; right: 8px; text-align: center; }
    .media-workspace .video-thumbnail:has(img[data-thumbnail-state="error"])::before { content: "Thumbnail unavailable"; }
    .media-workspace .video-thumbnail::after { align-items: center; background: rgba(17,38,75,.8); border-radius: 50%; color: #fff; content: "\F4F4"; display: flex; font-family: bootstrap-icons; font-size: 1rem; height: 40px; justify-content: center; left: 50%; position: absolute; top: 50%; transform: translate(-50%,-50%); width: 40px; }
    .media-workspace .grid-card-content { display: flex; flex: 1; flex-direction: column; gap: .65rem; padding: .9rem; }
    .media-workspace .grid-file-name { color: #34405b; font-size: .9rem; font-weight: 700; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    .media-workspace .grid-file-info { color: #7a869d; font-size: .78rem; }
    .media-workspace .grid-actions { border-top: 1px solid #eef1f6; display: flex; gap: .45rem; margin-top: auto; padding-top: .75rem; }
    .media-workspace .grid-actions .btn { flex: 1; }
    .media-workspace .grid-select { align-items: center; background: rgba(255, 255, 255, .94); border: 1px solid #d9e2f4; border-radius: 8px; display: inline-flex; height: 30px; justify-content: center; left: .75rem; padding: 0; position: absolute; top: .75rem; width: 30px; z-index: 2; }
    .media-workspace .grid-more { position: absolute; right: .72rem; top: .72rem; z-index: 4; }
    .media-workspace .file-name { color: #34405b; font-size: .9rem; font-weight: 700; }
    .media-workspace .file-description { color: #8791a7; font-size: .78rem; margin-top: .2rem; max-width: 280px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    .media-workspace .file-location-link { align-items: center; color: #526fae; display: inline-flex; font-size: .72rem; gap: .25rem; margin-top: .25rem; max-width: min(420px, 100%); text-decoration: none; }
    .media-workspace .file-location-link span { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    .media-workspace .file-location-link:hover, .media-workspace .file-location-link:focus-visible { color: var(--media-primary); text-decoration: underline; }
    .media-workspace .file-type { background: #f0f3f9; border-radius: 999px; color: #59667e; display: inline-block; font-size: .73rem; font-weight: 700; padding: .35rem .6rem; text-transform: uppercase; }
    .media-workspace .tags-value { color: #69768d; font-size: .8rem; max-width: 150px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    .media-workspace .action-button { align-items: center; border-radius: 8px; display: inline-flex; gap: .35rem; justify-content: center; min-width: 36px; }
    .media-workspace .empty-state { padding: 3.5rem 1rem !important; }
    .media-workspace .empty-state-icon { align-items: center; background: #eef3ff; border-radius: 50%; color: var(--media-primary); display: inline-flex; font-size: 1.7rem; height: 66px; justify-content: center; width: 66px; }
    .media-workspace .empty-state h3 { color: var(--media-ink); font-size: 1.05rem; font-weight: 700; margin: 1rem 0 .35rem; }
    .media-workspace .empty-state p { color: var(--media-muted); margin: 0 auto 1.1rem; max-width: 390px; }
    .duplicate-dialog { max-width: min(1320px, calc(100vw - 2rem)); }
    .duplicate-dialog .modal-content { border: 0; max-height: calc(100vh - 2rem); overflow: hidden; }
    .duplicate-modal-body { background: #fbfcff; overflow-y: auto; padding: 1.25rem; }
    .duplicate-stats { display: grid; gap: .8rem; grid-template-columns: repeat(2, minmax(0, 1fr)); }
    .duplicate-stat { background: #fff; border: 1px solid #dfe5ef; border-radius: 10px; box-shadow: 0 2px 6px rgba(31,49,83,.04); padding: 1rem; }
    .duplicate-stat strong { color: #17223b; display: block; font-size: 1.25rem; }
    .duplicate-stat span { color: #68748b; font-size: .76rem; }
    .duplicate-group { border-bottom: 1px solid #e4e9f2; padding: 1.2rem 0; }
    .duplicate-group:last-child { border-bottom: 0; }
    .duplicate-group-title { color: #17223b; font-size: .92rem; font-weight: 750; }
    .duplicate-similarity { color: #68748b; font-size: .77rem; }
    .duplicate-grid { display: grid; gap: .75rem; grid-template-columns: repeat(auto-fill, minmax(min(205px, 100%), 1fr)); margin-top: .8rem; }
    .duplicate-card { background: #fff; border: 1px solid #dfe5ef; border-radius: 10px; min-width: 0; overflow: hidden; position: relative; }
    .duplicate-card-thumb { align-items: center; background: #eef2f8; color: #61708d; display: flex; height: 135px; justify-content: center; overflow: hidden; width: 100%; }
    .duplicate-card-thumb img { height: 100%; image-orientation: from-image; object-fit: cover; transition: transform .15s; width: 100%; }
    .duplicate-card-thumb:hover img { transform: scale(1.03); }
    .duplicate-card-select { background: rgba(255,255,255,.94); border-radius: 6px; left: .55rem; padding: .2rem; position: absolute; top: .55rem; z-index: 2; }
    .duplicate-card-menu { position: absolute; right: .5rem; top: .5rem; z-index: 3; }
    .duplicate-card-body { padding: .7rem; }
    .duplicate-card-name { color: #24334f; font-size: .82rem; font-weight: 750; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    .duplicate-card-meta { color: #71809b; font-size: .72rem; margin-top: .28rem; }
    .duplicate-original-location { display: inline-block; overflow-wrap: anywhere; margin-left: .35rem; }
    .media-workspace [data-located-file] { outline: 3px solid var(--media-primary); outline-offset: 2px; scroll-margin-top: 90px; }
    .duplicate-details { background: #f5f8ff; border-radius: 8px; color: #53627c; font-size: .78rem; margin-top: .75rem; padding: .75rem; }
    .duplicates-empty { color: #71809b; padding: 4rem 1rem; text-align: center; }
    @media (max-width: 991.98px) { .media-workspace .bulk-actions { justify-content: flex-start; width: 100%; } }
    @media (max-width: 767.98px) { .media-workspace .upload-card { padding: 1rem; } .media-workspace .upload-action { min-height: 48px; } .media-workspace .library-hero { padding: 1.1rem; } .media-workspace .hero-symbol { height: 44px; width: 44px; } .media-workspace .filter-bar { padding: 1rem; } .media-workspace .media-table thead th, .media-workspace .media-table tbody td { padding-left: .9rem; padding-right: .9rem; } .media-workspace .media-grid { grid-template-columns: repeat(auto-fill, minmax(min(165px, 100%), 1fr)); padding: 1rem; } .media-workspace .grid-thumbnail, .media-workspace .grid-file-icon { height: 125px; } .media-workspace .publication-actions { align-items: stretch; width: 100%; } .media-workspace .publication-actions .btn { flex: 1 1 auto; } .media-workspace[data-media-layout="list"] .folder-grid { --folder-list-columns: minmax(0, 1fr) 70px; } .media-workspace[data-media-layout="list"] .folder-grid .folder-list-header { grid-template-columns: var(--folder-list-columns); } .media-workspace[data-media-layout="list"] .folder-grid .folder-list-modified { display: none; } .media-workspace[data-media-layout="list"] .folder-grid .folder-card-main { grid-column: 1 / 3; grid-template-columns: var(--folder-list-columns); } .duplicate-stats { grid-template-columns: 1fr; } }
</style>
