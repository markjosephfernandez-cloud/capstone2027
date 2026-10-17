<?= view('layouts/app_header', ['user' => $user, 'activePage' => $activePage, 'title' => $title]) ?>
<main id="main" class="main">
<style>
    .passport-preview { align-items:center; background:#f1f5ff; border:0; border-radius:9px; color:#4154f1; display:inline-flex; height:48px; justify-content:center; overflow:hidden; padding:0; width:48px; }
    .passport-preview img { height:48px; image-orientation:from-image; object-fit:cover; width:48px; }
    #passportPreviewImage { max-height:min(70vh,720px); object-fit:contain; width:100%; }
    .passport-details-dialog { max-width:min(1180px,calc(100vw - 1.5rem)); }
    .passport-details-content { border:0; border-radius:16px; box-shadow:0 22px 60px rgba(23,34,59,.24); overflow:hidden; }
    .passport-details-header { border-bottom:1px solid #e8edf6; padding:1rem 1.25rem; }
    .passport-details-header .modal-title { color:#17223b; font-size:1.05rem; font-weight:750; }
    .passport-details-subtitle { color:#6f7d95; font-size:.76rem; margin-top:.15rem; }
    .passport-details-body { padding:0; }
    .passport-details-layout { display:grid; grid-template-columns:minmax(0,1.08fr) minmax(365px,.92fr); min-height:560px; }
    .passport-details-photo { align-items:center; background:linear-gradient(145deg,#111b32,#243656); display:flex; justify-content:center; min-height:560px; overflow:hidden; padding:1.25rem; }
    .passport-details-photo img { display:block; image-orientation:from-image; max-height:calc(100vh - 190px); max-width:100%; object-fit:contain; }
    .passport-details-file { align-items:center; color:#b9c8e6; display:flex; flex-direction:column; font-size:4rem; gap:.8rem; text-align:center; }
    .passport-details-file span { font-size:.85rem; }
    .passport-details-panel { background:#fff; max-height:calc(100vh - 150px); overflow:auto; padding:1.15rem 1.25rem 1.35rem; }
    .passport-detail-section { border-bottom:1px solid #edf1f7; padding:.25rem 0 .9rem; }
    .passport-detail-section:last-child { border-bottom:0; padding-bottom:0; }
    .passport-detail-heading { align-items:center; color:#315efb; display:flex; font-size:.76rem; font-weight:800; gap:.4rem; letter-spacing:.045em; margin:.65rem 0 .6rem; text-transform:uppercase; }
    .passport-detail-grid { display:grid; gap:.55rem .9rem; grid-template-columns:repeat(2,minmax(0,1fr)); }
    .passport-detail-item { min-width:0; }
    .passport-detail-item.is-wide { grid-column:1/-1; }
    .passport-detail-label { color:#7a869d; display:block; font-size:.68rem; font-weight:700; margin-bottom:.12rem; text-transform:uppercase; }
    .passport-detail-value { color:#263754; display:block; font-size:.82rem; overflow-wrap:anywhere; }
    .passport-signature { background:#f5f7fb; border:1px solid #e5eaf3; border-radius:8px; color:#44516e; font-family:ui-monospace,SFMono-Regular,Consolas,monospace; font-size:.68rem; padding:.55rem; }
    .passport-details-button { border-radius:8px; font-size:.78rem; font-weight:650; }
    .passport-search { background:#f7f9fd; border:1px solid #e5eaf3; border-radius:12px; padding:.7rem; }
    .passport-search .input-group-text { background:#fff; border-color:#dce3ef; border-right:0; color:#64748b; }
    .passport-search .form-control { border-color:#dce3ef; border-left:0; box-shadow:none; min-height:40px; }
    .passport-search .form-control:focus { border-color:#86a5ff; }
    .passport-results-count { color:#6c7890; font-size:.78rem; }
    .passport-pagination .page-link { border-color:#e1e7f1; color:#315efb; min-width:36px; text-align:center; }
    .passport-pagination .page-item.active .page-link { background:#315efb; border-color:#315efb; color:#fff; }
    .passport-pagination .page-item.disabled .page-link { color:#9aa6b8; }
    @media (max-width:991.98px) { .passport-details-layout { grid-template-columns:1fr; }.passport-details-photo { min-height:300px; }.passport-details-photo img { max-height:52vh; }.passport-details-panel { max-height:none; } }
    @media (max-width:575.98px) { .passport-detail-grid { grid-template-columns:1fr; }.passport-detail-item.is-wide { grid-column:auto; }.passport-details-photo { min-height:240px; padding:.75rem; } }
</style>
<div class="pagetitle"><h1>Metadata Passports</h1><nav><ol class="breadcrumb"><li class="breadcrumb-item"><a href="<?= esc(site_url('dashboard')) ?>">Home</a></li><li class="breadcrumb-item active">Metadata Passports</li></ol></nav></div>
<section class="section">
    <?php if (! empty($success)): ?><div class="alert alert-success" role="alert" data-auto-dismiss><?= esc($success) ?></div><?php endif; ?>
    <?php if (! empty($error)): ?><div class="alert alert-danger" role="alert" data-auto-dismiss><?= esc($error) ?></div><?php endif; ?>
    <div class="row">
        <div class="col-md-6"><div class="card info-card"><div class="card-body"><h5 class="card-title">GENERATED</h5><h6><?= esc(number_format($summary['total'])) ?></h6></div></div></div>
        <div class="col-md-6"><div class="card info-card"><div class="card-body"><h5 class="card-title">LOW-CONFIDENCE AI FLAGS</h5><h6><?= esc(number_format($summary['ai_flags'])) ?></h6></div></div></div>
    </div>
    <div class="card"><div class="card-body">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3"><div><h5 class="card-title mb-1">AI Media Passport Metadata</h5><p class="text-muted small mb-0">Passports are generated automatically during upload and do not block publication.</p></div><?php if ($isAdmin): ?><div class="d-flex flex-wrap gap-2"><form method="post" action="<?= esc(site_url('workspace/passports/analyze')) ?>"><?= csrf_field() ?><button class="btn btn-outline-primary btn-sm" type="submit"><i class="bi bi-cpu me-1"></i>Run missing AI analysis</button></form><button class="btn btn-primary btn-sm" id="passportScanButton" type="button" data-bs-toggle="modal" data-bs-target="#passportScanPicker"><i class="bi bi-search me-1"></i>Generate missing passports</button></div><?php endif; ?></div>
        <?php
            $searchQuery = (string) ($searchQuery ?? '');
            $pagination = $pagination ?? ['current_page'=>1,'total_pages'=>1,'total_results'=>count($passports),'per_page'=>20,'from'=>count($passports)===0?0:1,'to'=>count($passports)];
            $currentPage = (int) $pagination['current_page'];
            $totalPages = (int) $pagination['total_pages'];
            $passportPageUrl = static function (int $page) use ($searchQuery): string {
                $parameters = ['page' => $page];
                if ($searchQuery !== '') $parameters['q'] = $searchQuery;
                return site_url('workspace/passports') . '?' . http_build_query($parameters);
            };
            $pageNumbers = $totalPages <= 7
                ? range(1, $totalPages)
                : array_values(array_unique(array_filter([1, $currentPage-2, $currentPage-1, $currentPage, $currentPage+1, $currentPage+2, $totalPages], static fn(int $page): bool => $page >= 1 && $page <= $totalPages)));
        ?>
        <form class="passport-search mb-3" method="get" action="<?= esc(site_url('workspace/passports')) ?>" role="search" data-ajax="true" data-ajax-preserve-scroll>
            <div class="d-flex flex-column flex-md-row gap-2">
                <div class="input-group flex-grow-1 search-reset-field" data-search-reset><span class="input-group-text"><i class="bi bi-search"></i></span><input class="form-control" data-search-suggestions="<?= esc(site_url('workspace/search-suggestions/passports')) ?>" type="search" name="q" value="<?= esc($searchQuery) ?>" maxlength="100" placeholder="Search filename, original date taken, or event" aria-label="Search metadata passports"><a class="search-reset-clear" data-search-clear data-ajax-preserve-scroll href="<?= esc(site_url('workspace/passports')) ?>" aria-label="Clear search and show all passports" title="Clear search" <?= $searchQuery === '' ? 'hidden' : '' ?>>&times;</a></div>
                <button class="btn btn-primary px-4" type="submit">Search</button>
            </div>
        </form>
        <div class="table-responsive"><table class="table align-middle"><thead class="table-light"><tr><th>Preview</th><th>Media</th><th>Event</th><th>AI confidence</th><th>Rights</th><th>Integrity</th><th>Processing</th><th class="text-end">Details</th></tr></thead><tbody>
        <?php if ($passports === []): ?><tr><td class="text-center text-muted py-4" colspan="8"><?= $searchQuery !== '' ? 'No passports match your search.' : 'No passports have been generated yet.' ?></td></tr><?php endif; ?>
        <?php foreach ($passports as $passport): ?><?php
            $fileId=(int)($passport['file_id']??0);
            $title=(string)($passport['display_title']??'Untitled media');
            $isImage=in_array(strtolower((string)($passport['filetype']??'')),['jpg','jpeg','png','gif','webp','bmp'],true);
            $previewUrl=$isImage?site_url('workspace/file-management/'.$fileId.'/preview'):'';
            $downloadUrl=site_url('workspace/file-management/'.$fileId.'/download');
            $detailPayload=json_encode([
                'title'=>$title,'passport_uid'=>(string)($passport['passport_uid']??''),'media_id'=>$fileId,
                'filename'=>(string)($passport['filename']??''),'filetype'=>strtoupper((string)($passport['filetype']??'')),
                'mime_type'=>(string)($passport['mime_type']??''),'size'=>(int)($passport['size']??0),
                'preview_url'=>$previewUrl,'download_url'=>$downloadUrl,'is_image'=>$isImage,
                'event_name'=>(string)($passport['event_name']?:'Unclassified'),'department'=>(string)($passport['department']?:'Not provided'),
                'photographer'=>(string)($passport['photographer']?:'Not provided'),'uploader'=>(string)($passport['uploader_name']?:($passport['uploaded_by']?:'Not provided')),
                'date_taken'=>$passport['date_taken']?:'Not available','upload_date'=>\App\Libraries\DisplayTime::format($passport['upload_date'] ?? null, 'M d, Y g:i A', 'Not available'),
                'ai_classification'=>(string)($passport['ai_classification']?:'Unclassified'),
                'confidence'=>$passport['confidence_score']===null?'Not available':number_format((float)$passport['confidence_score'],2).'%',
                'ai_version'=>(string)($passport['ai_version']?:'Not available'),
                'processing_time'=>$passport['processing_time_ms']===null?'Not available':number_format((int)$passport['processing_time_ms']).' ms',
                'review_status'=>(int)$passport['needs_review']===1?'Low AI confidence':'Complete',
                'copyright_owner'=>(string)($passport['copyright_owner']?:'Not provided'),
                'distribution_rights'=>(string)($passport['distribution_rights']?:'Internal Use Only'),
                'usage_restrictions'=>(string)($passport['usage_restrictions']?:'None specified'),
                'integrity_status'=>!empty($passport['digital_signature'])?'Sealed':'Unavailable',
                'digital_signature'=>(string)($passport['digital_signature']?:'Not available'),
                'c2pa_status'=>!empty($passport['c2pa_compliant'])?'Compliant':'Not configured',
            ],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
        ?>
            <tr>
                <td><?php if ($isImage): ?><button class="passport-preview" type="button" data-bs-toggle="modal" data-bs-target="#passportPreviewModal" data-preview-src="<?= esc($previewUrl) ?>" data-preview-name="<?= esc($title) ?>" data-download-url="<?= esc($downloadUrl) ?>" aria-label="Preview <?= esc($title) ?>"><img src="<?= esc($previewUrl) ?>" alt="Preview of <?= esc($title) ?>" loading="lazy"></button><?php else: ?><span class="passport-preview"><i class="bi bi-file-earmark"></i></span><?php endif; ?></td>
                <td><strong><?= esc($title) ?></strong><div class="small text-muted"><?= esc((string)$passport['passport_uid']) ?></div></td>
                <td><?= esc((string)($passport['event_name']?:'Unclassified')) ?></td>
                <td><?= $passport['confidence_score']===null?'—':esc(number_format((float)$passport['confidence_score'],2).'%') ?></td>
                <td><?= esc((string)($passport['distribution_rights']?:'Internal Use Only')) ?></td>
                <td><span class="badge <?= !empty($passport['digital_signature'])?'bg-success':'bg-secondary' ?>"><?= !empty($passport['digital_signature'])?'Sealed':'Unavailable' ?></span></td>
                <td><span class="badge <?= (int)$passport['needs_review']===1?'bg-warning text-dark':'bg-success' ?>"><?= (int)$passport['needs_review']===1?'Low AI confidence':'Complete' ?></span></td>
                <td class="text-end"><button class="btn btn-outline-primary btn-sm passport-details-button" type="button" data-bs-toggle="modal" data-bs-target="#passportDetailsModal" data-passport-details="<?= esc((string)$detailPayload,'attr') ?>"><i class="bi bi-card-list me-1"></i>Details</button></td>
            </tr>
        <?php endforeach; ?>
        </tbody></table></div>
        <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 pt-2">
            <div class="passport-results-count">Showing <?= esc(number_format((int)$pagination['from'])) ?>–<?= esc(number_format((int)$pagination['to'])) ?> of <?= esc(number_format((int)$pagination['total_results'])) ?> passports<?= $searchQuery !== '' ? ' matching “'.esc($searchQuery).'”' : '' ?></div>
            <?php if ($totalPages > 1): ?><nav class="passport-pagination" aria-label="Metadata passport pages"><ul class="pagination pagination-sm mb-0">
                <li class="page-item <?= $currentPage <= 1 ? 'disabled' : '' ?>"><a class="page-link" href="<?= $currentPage <= 1 ? '#' : esc($passportPageUrl($currentPage-1)) ?>" aria-label="Previous page"><i class="bi bi-chevron-left"></i><span class="d-none d-sm-inline ms-1">Previous</span></a></li>
                <?php $previousPageNumber=0; foreach ($pageNumbers as $pageNumber): ?>
                    <?php if ($previousPageNumber !== 0 && $pageNumber > $previousPageNumber+1): ?><li class="page-item disabled"><span class="page-link">…</span></li><?php endif; ?>
                    <li class="page-item <?= $pageNumber === $currentPage ? 'active' : '' ?>"><a class="page-link" href="<?= esc($passportPageUrl($pageNumber)) ?>"<?= $pageNumber === $currentPage ? ' aria-current="page"' : '' ?>><?= esc((string)$pageNumber) ?></a></li>
                    <?php $previousPageNumber=$pageNumber; ?>
                <?php endforeach; ?>
                <li class="page-item <?= $currentPage >= $totalPages ? 'disabled' : '' ?>"><a class="page-link" href="<?= $currentPage >= $totalPages ? '#' : esc($passportPageUrl($currentPage+1)) ?>" aria-label="Next page"><span class="d-none d-sm-inline me-1">Next</span><i class="bi bi-chevron-right"></i></a></li>
            </ul></nav><?php endif; ?>
        </div>
    </div></div>
</section>
<?php if ($isAdmin): ?><?= view('addons/passport_scan_picker') ?><?php endif; ?>
<div class="modal fade" id="passportDetailsModal" tabindex="-1" aria-labelledby="passportDetailsTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered passport-details-dialog">
        <div class="modal-content passport-details-content">
            <div class="modal-header passport-details-header"><div class="min-w-0"><h5 class="modal-title text-truncate" id="passportDetailsTitle">Photo details</h5><div class="passport-details-subtitle" id="passportDetailsUid"></div></div><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
            <div class="modal-body passport-details-body">
                <div class="passport-details-layout">
                    <div class="passport-details-photo"><img id="passportDetailsImage" src="" alt=""><div class="passport-details-file d-none" id="passportDetailsFile"><i class="bi bi-file-earmark"></i><span>Preview unavailable for this file type</span></div></div>
                    <div class="passport-details-panel">
                        <section class="passport-detail-section"><h6 class="passport-detail-heading"><i class="bi bi-image"></i>Media information</h6><div class="passport-detail-grid">
                            <div class="passport-detail-item is-wide"><span class="passport-detail-label">Filename</span><span class="passport-detail-value" data-detail-field="filename"></span></div>
                            <div class="passport-detail-item"><span class="passport-detail-label">Media ID</span><span class="passport-detail-value" data-detail-field="media_id"></span></div>
                            <div class="passport-detail-item"><span class="passport-detail-label">File size</span><span class="passport-detail-value" data-detail-field="size"></span></div>
                            <div class="passport-detail-item"><span class="passport-detail-label">Format</span><span class="passport-detail-value" data-detail-field="filetype"></span></div>
                            <div class="passport-detail-item"><span class="passport-detail-label">MIME type</span><span class="passport-detail-value" data-detail-field="mime_type"></span></div>
                            <div class="passport-detail-item is-wide"><span class="passport-detail-label">Event</span><span class="passport-detail-value" data-detail-field="event_name"></span></div>
                        </div></section>
                        <section class="passport-detail-section"><h6 class="passport-detail-heading"><i class="bi bi-camera"></i>Capture and ownership</h6><div class="passport-detail-grid">
                            <div class="passport-detail-item"><span class="passport-detail-label">Photographer</span><span class="passport-detail-value" data-detail-field="photographer"></span></div>
                            <div class="passport-detail-item"><span class="passport-detail-label">Department</span><span class="passport-detail-value" data-detail-field="department"></span></div>
                            <div class="passport-detail-item"><span class="passport-detail-label">Original date taken</span><span class="passport-detail-value" data-detail-field="date_taken"></span></div>
                            <div class="passport-detail-item"><span class="passport-detail-label">Upload date (PHT)</span><span class="passport-detail-value" data-detail-field="upload_date"></span></div>
                            <div class="passport-detail-item is-wide"><span class="passport-detail-label">Uploaded by</span><span class="passport-detail-value" data-detail-field="uploader"></span></div>
                        </div></section>
                        <section class="passport-detail-section"><h6 class="passport-detail-heading"><i class="bi bi-cpu"></i>AI analysis</h6><div class="passport-detail-grid">
                            <div class="passport-detail-item"><span class="passport-detail-label">Classification</span><span class="passport-detail-value" data-detail-field="ai_classification"></span></div>
                            <div class="passport-detail-item"><span class="passport-detail-label">Confidence</span><span class="passport-detail-value" data-detail-field="confidence"></span></div>
                            <div class="passport-detail-item"><span class="passport-detail-label">AI version</span><span class="passport-detail-value" data-detail-field="ai_version"></span></div>
                            <div class="passport-detail-item"><span class="passport-detail-label">Processing time</span><span class="passport-detail-value" data-detail-field="processing_time"></span></div>
                            <div class="passport-detail-item is-wide"><span class="passport-detail-label">Review status</span><span class="passport-detail-value" data-detail-field="review_status"></span></div>
                        </div></section>
                        <section class="passport-detail-section"><h6 class="passport-detail-heading"><i class="bi bi-shield-check"></i>Rights and restrictions</h6><div class="passport-detail-grid">
                            <div class="passport-detail-item"><span class="passport-detail-label">Copyright owner</span><span class="passport-detail-value" data-detail-field="copyright_owner"></span></div>
                            <div class="passport-detail-item"><span class="passport-detail-label">Distribution rights</span><span class="passport-detail-value" data-detail-field="distribution_rights"></span></div>
                            <div class="passport-detail-item is-wide"><span class="passport-detail-label">Usage restrictions</span><span class="passport-detail-value" data-detail-field="usage_restrictions"></span></div>
                        </div></section>
                        <section class="passport-detail-section"><h6 class="passport-detail-heading"><i class="bi bi-patch-check"></i>Integrity</h6><div class="passport-detail-grid">
                            <div class="passport-detail-item"><span class="passport-detail-label">Integrity status</span><span class="passport-detail-value" data-detail-field="integrity_status"></span></div>
                            <div class="passport-detail-item"><span class="passport-detail-label">C2PA status</span><span class="passport-detail-value" data-detail-field="c2pa_status"></span></div>
                            <div class="passport-detail-item is-wide"><span class="passport-detail-label">Digital signature</span><span class="passport-detail-value passport-signature" data-detail-field="digital_signature"></span></div>
                        </div></section>
                    </div>
                </div>
            </div>
            <div class="modal-footer"><a class="btn btn-primary" id="passportDetailsDownload" href="#"><i class="bi bi-download me-1"></i>Download original</a><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Close</button></div>
        </div>
    </div>
</div>
<div class="modal fade" id="passportPreviewModal" tabindex="-1" aria-labelledby="passportPreviewTitle" aria-hidden="true"><div class="modal-dialog modal-xl modal-dialog-centered"><div class="modal-content"><div class="modal-header"><h5 class="modal-title text-truncate" id="passportPreviewTitle">Media preview</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div><div class="modal-body text-center bg-light"><img id="passportPreviewImage" src="" alt="Selected media preview"></div><div class="modal-footer"><a id="passportPreviewDownload" class="btn btn-primary" href="#"><i class="bi bi-download me-1"></i>Download</a><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button></div></div></div></div>
<script>
document.addEventListener('DOMContentLoaded',function(){
    var previewModal=document.getElementById('passportPreviewModal');
    previewModal.addEventListener('show.bs.modal',function(event){var trigger=event.relatedTarget;document.getElementById('passportPreviewTitle').textContent=trigger.dataset.previewName||'Media preview';document.getElementById('passportPreviewImage').src=trigger.dataset.previewSrc||'';document.getElementById('passportPreviewDownload').href=trigger.dataset.downloadUrl||'#';});
    previewModal.addEventListener('hidden.bs.modal',function(){document.getElementById('passportPreviewImage').src='';});

    var detailsModal=document.getElementById('passportDetailsModal');
    var detailsImage=document.getElementById('passportDetailsImage');
    var detailsFile=document.getElementById('passportDetailsFile');
    var formatBytes=function(bytes){bytes=Number(bytes||0);if(bytes<1024)return bytes+' B';var units=['KB','MB','GB'];var value=bytes/1024,index=0;while(value>=1024&&index<units.length-1){value/=1024;index++;}return value.toFixed(value>=10?1:2)+' '+units[index];};
    detailsModal.addEventListener('show.bs.modal',function(event){
        var trigger=event.relatedTarget,data={};
        try{data=JSON.parse(trigger.dataset.passportDetails||'{}');}catch(error){data={};}
        document.getElementById('passportDetailsTitle').textContent=data.title||'Photo details';
        document.getElementById('passportDetailsUid').textContent=data.passport_uid||'';
        document.getElementById('passportDetailsDownload').href=data.download_url||'#';
        detailsImage.classList.toggle('d-none',!data.is_image);
        detailsFile.classList.toggle('d-none',!!data.is_image);
        detailsImage.alt=data.is_image?'Preview of '+(data.title||'selected photo'):'';
        detailsImage.src=data.is_image?(data.preview_url||''):'';
        detailsModal.querySelectorAll('[data-detail-field]').forEach(function(field){var key=field.dataset.detailField;var value=key==='size'?formatBytes(data[key]):data[key];field.textContent=value===null||value===undefined||value===''?'Not available':String(value);});
    });
    detailsModal.addEventListener('hidden.bs.modal',function(){detailsImage.src='';});
});
</script>
</main>
<?= view('layouts/app_footer') ?>
