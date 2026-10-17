<?= view('components/distribution_picker_styles') ?>
<?php $p = $pickerPrefix; $route = $p === 'passport' ? 'passports' : 'ifound'; ?>
<div class="modal fade distribution-workflow" id="<?= esc($p) ?>ScanPicker" tabindex="-1" aria-labelledby="<?= esc($p) ?>ScanPickerTitle" aria-hidden="true" data-browse-url="<?= esc(site_url('workspace/' . $route . '/scan/browse')) ?>" data-batch-size="<?= max(1, (int) ($pickerBatchSize ?? 100)) ?>">
 <div class="modal-dialog modal-xl modal-dialog-centered media-picker-dialog"><div class="modal-content media-picker-content"><div class="media-picker-form">
  <div class="modal-header"><div><h5 class="modal-title" id="<?= esc($p) ?>ScanPickerTitle"><?= esc($pickerTitle) ?></h5><div class="small text-muted">Select images, review your selection, then start scanning.</div></div><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close file manager"></button></div>
  <div class="modal-body media-picker-body">
   <div id="<?= esc($p) ?>PickerStatus" class="small text-muted mb-2" role="status" aria-live="polite"></div>
   <div id="<?= esc($p) ?>PickerBrowse" class="mini-library">
    <div class="mini-library-head"><div class="mini-library-title"><span class="mini-library-symbol"><i class="bi bi-hdd-network" aria-hidden="true"></i></span><div><h6>Server Library</h6><p>Select images or folders. Selections are processed in batches.</p></div></div>
     <form id="<?= esc($p) ?>PickerSearchForm" class="input-group mini-search" data-ajax="false"><span class="input-group-text"><i class="bi bi-search" aria-hidden="true"></i></span><input id="<?= esc($p) ?>PickerSearch" class="form-control" type="search" placeholder="Search..." aria-label="Search this folder" maxlength="120" autocomplete="off"><button type="submit" class="btn btn-link px-2" aria-label="Search"><i class="bi bi-arrow-right"></i></button></form>
    </div>
    <nav id="<?= esc($p) ?>PickerCrumbs" class="mini-breadcrumb" aria-label="Scan folders"></nav>
    <div class="mini-browser"><p class="mini-section-title">Folders</p><div id="<?= esc($p) ?>PickerFolders" class="mini-grid"></div><p class="mini-section-title">Files</p><div id="<?= esc($p) ?>PickerFiles" class="mini-grid"></div>
     <div class="d-flex justify-content-between align-items-center"><button id="<?= esc($p) ?>PickerPrevious" class="btn btn-outline-secondary btn-sm" type="button">Previous</button><span id="<?= esc($p) ?>PickerPage" class="small text-muted"></span><button id="<?= esc($p) ?>PickerNext" class="btn btn-outline-secondary btn-sm" type="button">Next</button></div>
    </div>
   </div>
   <div id="<?= esc($p) ?>PickerPreview" hidden><h6>Review selected images</h6><p class="text-muted"><?= $p === 'passport' ? 'Read image metadata and generate missing passports. Existing passports are preserved.' : 'Run iFound AI analysis on these images.' ?></p><hr><div id="<?= esc($p) ?>PickerPreviewSummary" class="small mb-3"></div><div id="<?= esc($p) ?>PickerPreviewFiles" class="preview-media-grid"></div></div>
  </div>
  <div class="modal-footer"><button id="<?= esc($p) ?>PickerSelectPage" class="btn btn-outline-primary btn-sm" type="button">Select all on page</button><button id="<?= esc($p) ?>PickerClear" class="btn btn-light btn-sm" type="button">Clear selection</button><span id="<?= esc($p) ?>PickerCount" class="picker-footer-summary" aria-live="polite">0 folders • 0 files selected</span><button id="<?= esc($p) ?>PickerRetry" class="btn btn-outline-secondary" type="button" hidden>Retry</button><button id="<?= esc($p) ?>PickerBack" class="btn btn-light" type="button" hidden>Back</button><button id="<?= esc($p) ?>PickerContinue" class="btn btn-primary" type="button" disabled>Continue</button><button id="<?= esc($p) ?>PickerStart" class="btn btn-primary" type="button" hidden disabled><i class="bi bi-search me-1"></i>Start scan</button></div>
 </div></div></div>
</div>
