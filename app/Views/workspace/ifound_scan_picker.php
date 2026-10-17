<style id="ifoundScanStyles">
#ifoundScanProgress {position:fixed;right:24px;bottom:20px;width:390px;max-width:calc(100vw - 24px);background:white;border:1px solid #dfe5ee;border-radius:14px;box-shadow:0 8px 36px #14223a30;z-index:1040;padding:16px;margin:0!important}
#ifoundScanFileList {max-height:210px;overflow:auto}#ifoundScanFileList > div {display:flex;justify-content:space-between;gap:12px;border-top:1px solid #edf0f5;padding:9px 0;font-size:.82rem}#ifoundScanFileList span:first-child {overflow-wrap:anywhere;min-width:0}#ifoundScanFileList span:last-child {flex-shrink:0;color:#64748b}
#ifoundScanProgress.is-collapsed [data-scan-detail] {display:none!important}
@media(max-width:575px){#ifoundScanProgress {right:12px;bottom:12px}}
</style>
<?= view('components/scan_file_picker', ['pickerPrefix'=>'ifound', 'pickerTitle'=>'Scan files for iFound', 'pickerBatchSize'=>min(100, (int) ($scanBatchSize ?? 10))]) ?>
<script>
if (!document.head.querySelector('#ifoundScanStyles')) document.head.appendChild(document.getElementById('ifoundScanStyles'));
<?php require FCPATH . 'assets/js/ifound-scan-picker.js'; ?>
</script>
