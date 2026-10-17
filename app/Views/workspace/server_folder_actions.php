<style>
#folderSelectionToolbar [hidden] { display: none !important; }
#folderSelectionToolbar { margin-top: .8rem; padding: .65rem .75rem; background: #fff; border: 1px solid #e4eaf4; border-radius: 10px; }
#folderSelectionToolbar .btn { display: inline-flex; align-items: center; gap: .35rem; border-radius: 7px; font-size: .78rem; padding: .3rem .55rem; white-space: nowrap; }
#folderSelectionCount { background: #eef3ff !important; border-radius: 999px; padding: .5rem .65rem; font-weight: 600; }
#folderBrowserView .folder-card [data-folder-select] { position: absolute; left: 12px; top: 12px; transform: none; width: 17px; height: 17px; margin: 0 !important; border-color: #bcc9dd; border-radius: 4px; cursor: pointer; z-index: 2; }
#folderBrowserView .folder-card [data-folder-select]:checked { background-color: #0d6efd; border-color: #0d6efd; }
#folderBrowserView .folder-card [data-folder-select]:focus-visible { outline: 3px solid #a9c9ff; outline-offset: 3px; }
.media-workspace:not([data-media-layout="list"]) #folderBrowserView .folder-card:has([data-folder-select]) .folder-card-main { padding: 2.6rem 1rem 1rem; }
.media-workspace[data-media-layout="list"] #folderBrowserView .folder-card:has([data-folder-select]) .folder-name-cell { padding-left: 2rem; }
#folderBrowserView .folder-card:has([data-folder-select]:checked) { background: #eaf2ff; outline: 2px solid #0d6efd; outline-offset: -2px; }
</style>
<div class="modal fade" id="folderActionModal" tabindex="-1" aria-labelledby="folderActionTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
        <form class="modal-content" id="folderActionForm" method="post" data-ajax="true" action="<?= esc(site_url('workspace/server-library/folders/selection')) ?>">
            <?= csrf_field() ?>
            <input type="hidden" name="folder" value="<?= esc($folderId ?? '', 'attr') ?>">
            <input type="hidden" name="action" id="folderActionKind">
            <input type="hidden" name="destination" id="folderMoveDestination">
            <div id="folderActionPaths"></div>
            <div class="modal-header"><h2 class="modal-title fs-5" id="folderActionTitle">Move folders</h2><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
            <div class="modal-body">
                <p id="folderActionSummary" class="text-break"></p>
                <div id="folderDestinationPicker" data-url="<?= esc(site_url('workspace/server-library/folders'), 'attr') ?>">
                    <nav id="folderMoveBreadcrumbs" class="d-flex flex-wrap gap-1 mb-3" aria-label="Destination folder"></nav>
                    <p id="folderMoveStatus" role="status"></p>
                    <div id="folderMoveListing" class="list-group"></div>
                </div>
                <p class="small text-muted mt-3 mb-0" id="folderActionHelp">All files and subfolders inside the selected folders will also be moved.</p>
            </div>
            <div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn btn-primary" id="confirmFolderAction" disabled>Move here</button></div>
        </form>
    </div>
</div>
<script>
<?php require FCPATH . 'assets/js/folder-move-progress.js'; ?>
<?php require FCPATH . 'assets/js/server-folder-actions.js'; ?>
</script>
