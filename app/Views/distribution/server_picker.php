<div class="modal fade distribution-workflow" id="serverTransmitModal" tabindex="-1" aria-labelledby="serverTransmitTitle" aria-hidden="true" data-browse-url="<?= esc(site_url('workspace/distribution/server-library')) ?>" data-selection-url="<?= esc(site_url('workspace/distribution/server-library/selection')) ?>">
    <div class="modal-dialog modal-xl modal-dialog-centered media-picker-dialog"><div class="modal-content media-picker-content">
        <form id="serverTransmitForm" class="media-picker-form" method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="department" id="serverPickerDepartment" value="">
            <div class="modal-header"><div><h5 id="serverTransmitTitle" class="modal-title">Transmit Files</h5><div id="serverTransmitContext" class="small text-muted"></div></div><button class="btn-close" type="button" data-bs-dismiss="modal" aria-label="Close"></button></div>
            <div class="modal-body media-picker-body">
                <div id="serverPickerStatus" role="status" aria-live="polite" class="small text-muted mb-2"></div>
                <div id="serverPickerBrowse" class="mini-library">
                    <div class="mini-library-head"><div class="mini-library-title"><span class="mini-library-symbol"><i class="bi bi-hdd-network"></i></span><div><h6>Server Library</h6><p>Select server files or folders to transmit. Up to 500 files per transmission.</p></div></div><label class="input-group mini-search" for="serverPickerSearch"><span class="input-group-text"><i class="bi bi-search"></i></span><input class="form-control" id="serverPickerSearch" type="search" placeholder="Search..." maxlength="120" autocomplete="off"></label></div>
                    <div id="serverPickerBreadcrumb" class="mini-breadcrumb"></div>
                    <div class="mini-browser"><p class="mini-section-title">Folders</p><div id="serverPickerFolders" class="mini-grid"></div><p class="mini-section-title">Files</p><div id="serverPickerFiles" class="mini-grid"></div><div class="d-flex justify-content-between align-items-center"><button id="serverPickerPrevious" type="button" class="btn btn-outline-secondary btn-sm">Previous</button><span id="serverPickerPage" class="small text-muted"></span><button id="serverPickerNext" type="button" class="btn btn-outline-secondary btn-sm">Next</button></div></div>
                </div>
                <div id="serverPickerPreview" hidden><h6 id="serverPickerPreviewHeading">Department Request</h6><p id="serverPickerPreviewContext" class="text-muted"></p><hr><div id="serverPickerPreviewSummary" class="small mb-3"></div><div id="serverPickerPreviewFiles" class="preview-media-grid"></div></div>
                <div id="serverPickerInputs"></div>
            </div>
            <div class="modal-footer"><button id="serverPickerSelectAll" class="btn btn-outline-primary btn-sm" type="button">Select all on page</button><button id="serverPickerClear" class="btn btn-light btn-sm" type="button" disabled>Clear selection</button><span id="serverPickerCount" aria-live="polite" class="picker-footer-summary">0 folders • 0 files selected</span><button id="serverPickerRetry" type="button" class="btn btn-outline-secondary" hidden>Retry</button><button id="serverPickerBack" type="button" class="btn btn-light" hidden>Back</button><button id="serverPickerContinue" type="button" class="btn btn-primary">Continue</button><button id="serverPickerTransmit" type="submit" class="btn btn-primary" hidden>Transmit</button></div>
        </form>
    </div></div>
</div>
<script>
<?php require FCPATH . 'assets/js/distribution-server-picker.js'; ?>
</script>
