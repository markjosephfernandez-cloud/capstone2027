<?php if ($canManage): ?>
<div class="modal fade" id="duplicatesModal" tabindex="-1" aria-labelledby="serverDuplicatesTitle" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable"><div class="modal-content">
        <div class="modal-header"><div><h5 class="modal-title" id="serverDuplicatesTitle">Duplicate review</h5><p class="small text-muted mb-0">Review matching uploads before keeping another copy.</p></div><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
        <div class="modal-body">
            <?php if ($duplicates === []): ?><div class="text-center py-5"><i class="bi bi-check2-circle text-success fs-1"></i><h6 class="mt-3">No duplicates to review</h6><p class="text-muted">Matching uploads will appear here.</p></div><?php endif; ?>
            <?php foreach ($duplicates as $id=>$duplicate): ?>
            <div class="border rounded-3 p-3 mb-3">
                <div class="d-flex justify-content-between gap-3"><strong class="text-break"><?= esc($duplicate['name']) ?></strong><span class="badge text-bg-light align-self-start"><?= esc(number_format($duplicate['similarity'],1)) ?>% match</span></div>
                <p class="small text-muted mt-2 mb-2 text-break">Existing file: <?= esc($duplicate['original']) ?></p>
                <?php $previewKind = strtolower(pathinfo($duplicate['name'],PATHINFO_EXTENSION)); ?>
                <?php if (in_array($previewKind,['jpg','jpeg','png','gif','webp','mp4','mov','mkv','avi','webm','m4v'],true)): ?>
                <div class="row g-3 my-2">
                    <div class="col-6"><div class="small fw-semibold mb-1">Original</div><img class="w-100 rounded border" style="height:160px;object-fit:contain" loading="lazy" alt="Original preview" src="<?= esc(site_url('workspace/server-library/media').'?'.http_build_query(['path'=>$duplicate['original'],'thumbnail'=>1])) ?>"></div>
                    <div class="col-6"><div class="small fw-semibold mb-1">Uploaded copy</div><img class="w-100 rounded border" style="height:160px;object-fit:contain" loading="lazy" alt="Uploaded copy preview" src="<?= esc(site_url('workspace/server-library/media').'?'.http_build_query(['duplicate'=>$id,'thumbnail'=>1])) ?>"></div>
                </div>
                <?php endif; ?>
                <div class="d-flex flex-wrap gap-2">
                    <a class="btn btn-sm btn-outline-secondary" data-no-ajax href="<?= esc(site_url('workspace/server-library/media').'?'.http_build_query(['duplicate'=>$id,'download'=>1])) ?>">Download copy</a>
                    <a class="btn btn-sm btn-outline-secondary" data-no-ajax href="<?= esc(site_url('workspace/server-library/media').'?'.http_build_query(['path'=>$duplicate['original'],'download'=>1])) ?>">Download original</a>
                    <form method="post" action="<?= esc(site_url('workspace/server-library/duplicates/restore')) ?>"><?= csrf_field() ?><input type="hidden" name="duplicate_id" value="<?= esc($id) ?>"><button class="btn btn-sm btn-primary" type="submit">Keep this copy</button></form>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <div class="modal-footer"><button class="btn btn-light" type="button" data-bs-dismiss="modal">Close</button></div>
    </div></div>
</div>
<?php endif; ?>
