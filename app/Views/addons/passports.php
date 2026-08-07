<?= view('layouts/app_header', ['user' => $user, 'activePage' => $activePage, 'title' => $title]) ?>
<main id="main" class="main">
<style>
    .passport-preview { align-items:center; background:#f1f5ff; border:0; border-radius:9px; color:#4154f1; display:inline-flex; height:48px; justify-content:center; overflow:hidden; padding:0; width:48px; }
    .passport-preview img { height:48px; object-fit:cover; width:48px; }
    #passportPreviewImage { max-height:min(70vh,720px); object-fit:contain; width:100%; }
</style>
<div class="pagetitle"><h1>Metadata Passports</h1><nav><ol class="breadcrumb"><li class="breadcrumb-item"><a href="<?= esc(site_url('dashboard')) ?>">Home</a></li><li class="breadcrumb-item active">Metadata Passports</li></ol></nav></div>
<section class="section">
    <?php if (! empty($success)): ?><div class="alert alert-success"><?= esc($success) ?></div><?php endif; ?>
    <?php if (! empty($error)): ?><div class="alert alert-danger"><?= esc($error) ?></div><?php endif; ?>
    <div class="row">
        <div class="col-md-6"><div class="card info-card"><div class="card-body"><h5 class="card-title">GENERATED</h5><h6><?= esc(number_format($summary['total'])) ?></h6></div></div></div>
        <div class="col-md-6"><div class="card info-card"><div class="card-body"><h5 class="card-title">LOW-CONFIDENCE AI FLAGS</h5><h6><?= esc(number_format($summary['ai_flags'])) ?></h6></div></div></div>
    </div>
    <div class="card"><div class="card-body">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3"><div><h5 class="card-title mb-1">AI Media Passport Metadata</h5><p class="text-muted small mb-0">Passports are generated automatically during upload and do not block publication.</p></div><?php if ($isAdmin): ?><div class="d-flex gap-2"><form method="post" action="<?= esc(site_url('workspace/passports/analyze')) ?>"><?= csrf_field() ?><button class="btn btn-outline-primary btn-sm" type="submit"><i class="bi bi-cpu me-1"></i>Run missing AI analysis</button></form><form method="post" action="<?= esc(site_url('workspace/passports/backfill')) ?>"><?= csrf_field() ?><button class="btn btn-primary btn-sm" type="submit"><i class="bi bi-arrow-repeat me-1"></i>Generate missing passports</button></form></div><?php endif; ?></div>
        <div class="table-responsive"><table class="table align-middle"><thead class="table-light"><tr><th>Preview</th><th>Media</th><th>Event</th><th>AI confidence</th><th>Rights</th><th>Integrity</th><th>Processing</th></tr></thead><tbody>
        <?php if ($passports === []): ?><tr><td class="text-center text-muted py-4" colspan="7">No passports have been generated yet.</td></tr><?php endif; ?>
        <?php foreach ($passports as $passport): ?><?php $fileId=(int)($passport['file_id']??0); $title=(string)($passport['display_title']??'Untitled media'); $isImage=in_array(strtolower((string)($passport['filetype']??'')),['jpg','jpeg','png','gif','webp','bmp'],true); ?>
            <tr>
                <td><?php if ($isImage): ?><button class="passport-preview" type="button" data-bs-toggle="modal" data-bs-target="#passportPreviewModal" data-preview-src="<?= esc(site_url('workspace/file-management/'.$fileId.'/preview')) ?>" data-preview-name="<?= esc($title) ?>" data-download-url="<?= esc(site_url('workspace/file-management/'.$fileId.'/download')) ?>" aria-label="Preview <?= esc($title) ?>"><img src="<?= esc(site_url('workspace/file-management/'.$fileId.'/preview')) ?>" alt="Preview of <?= esc($title) ?>" loading="lazy"></button><?php else: ?><span class="passport-preview"><i class="bi bi-file-earmark"></i></span><?php endif; ?></td>
                <td><strong><?= esc($title) ?></strong><div class="small text-muted"><?= esc((string)$passport['passport_uid']) ?></div></td>
                <td><?= esc((string)($passport['event_name']?:'Unclassified')) ?></td>
                <td><?= $passport['confidence_score']===null?'—':esc(number_format((float)$passport['confidence_score'],2).'%') ?></td>
                <td><?= esc((string)($passport['distribution_rights']?:'Internal Use Only')) ?></td>
                <td><span class="badge <?= !empty($passport['digital_signature'])?'bg-success':'bg-secondary' ?>"><?= !empty($passport['digital_signature'])?'Sealed':'Unavailable' ?></span></td>
                <td><span class="badge <?= (int)$passport['needs_review']===1?'bg-warning text-dark':'bg-success' ?>"><?= (int)$passport['needs_review']===1?'Low AI confidence':'Complete' ?></span></td>
            </tr>
        <?php endforeach; ?>
        </tbody></table></div>
    </div></div>
</section>
<div class="modal fade" id="passportPreviewModal" tabindex="-1" aria-labelledby="passportPreviewTitle" aria-hidden="true"><div class="modal-dialog modal-xl modal-dialog-centered"><div class="modal-content"><div class="modal-header"><h5 class="modal-title text-truncate" id="passportPreviewTitle">Media preview</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div><div class="modal-body text-center bg-light"><img id="passportPreviewImage" src="" alt="Selected media preview"></div><div class="modal-footer"><a id="passportPreviewDownload" class="btn btn-primary" href="#"><i class="bi bi-download me-1"></i>Download</a><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button></div></div></div></div>
<script>document.addEventListener('DOMContentLoaded',function(){var modal=document.getElementById('passportPreviewModal');modal.addEventListener('show.bs.modal',function(event){var trigger=event.relatedTarget;document.getElementById('passportPreviewTitle').textContent=trigger.dataset.previewName||'Media preview';document.getElementById('passportPreviewImage').src=trigger.dataset.previewSrc||'';document.getElementById('passportPreviewDownload').href=trigger.dataset.downloadUrl||'#';});modal.addEventListener('hidden.bs.modal',function(){document.getElementById('passportPreviewImage').src='';});});</script>
</main>
<?= view('layouts/app_footer') ?>
