<style id="passportScanStyles">
#passportScanProgress {position:fixed;right:24px;bottom:20px;width:390px;max-width:calc(100vw - 24px);background:white;border:1px solid #dfe5ee;border-radius:14px;box-shadow:0 8px 36px #14223a30;z-index:1040;padding:16px;margin:0!important}
#passportScanFileList {max-height:210px;overflow:auto}#passportScanFileList > div {display:flex;justify-content:space-between;gap:12px;border-top:1px solid #edf0f5;padding:9px 0;font-size:.82rem}#passportScanFileList span:first-child {overflow-wrap:anywhere;min-width:0}#passportScanFileList span:last-child {flex-shrink:0;max-width:55%;overflow-wrap:anywhere;color:#64748b}
#passportScanProgress.is-collapsed [data-scan-detail] {display:none!important}
@media(max-width:575px){#passportScanProgress {right:12px;bottom:12px}}
</style>
<?= view('components/scan_file_picker', ['pickerPrefix'=>'passport', 'pickerTitle'=>'Scan image metadata']) ?>
<form id="passportScanForm" data-ajax="false" data-token-url="<?= esc(site_url('workspace/passports/scan/token')) ?>" action="<?= esc(site_url('workspace/passports/scan/image')) ?>" method="post" hidden><?= csrf_field() ?></form>
<div id="passportScanProgress" class="d-none" aria-label="Metadata scan progress">
 <div class="d-flex align-items-center justify-content-between mb-2"><strong><i class="bi bi-card-list me-2"></i>Scanning metadata</strong><div><button id="passportScanCollapse" class="btn btn-sm btn-light" type="button" aria-label="Minimize metadata progress" aria-expanded="true"><i class="bi bi-chevron-down"></i></button><button id="passportScanDismiss" type="button" class="btn btn-sm btn-light" aria-label="Dismiss metadata progress" hidden>×</button></div></div>
 <div id="passportScanLabel" role="status" aria-live="polite" class="small mb-2">Preparing scan…</div>
 <div data-scan-detail>
  <div class="progress mb-2" style="height:8px"><div id="passportScanBar" class="progress-bar" role="progressbar" aria-label="Images processed" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0" style="width:0%"></div></div>
  <div id="passportScanFileList"></div>
  <div id="passportScanSummary" class="small text-muted mt-2"></div>
  <a id="passportScanResults" href="<?= esc(site_url('workspace/passports')) ?>" class="btn btn-outline-primary btn-sm mt-2 d-none">View metadata passports</a>
 </div>
</div>
<script>
if (!document.head.querySelector('#passportScanStyles')) document.head.appendChild(document.getElementById('passportScanStyles'));
<?php require FCPATH . 'assets/js/ifound-scan-picker.js'; ?>
<?php require FCPATH . 'assets/js/passport-scan.js'; ?>
</script>
