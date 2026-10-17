<?= view('layouts/app_header', ['user'=>$user,'activePage'=>$activePage,'title'=>$title]) ?>
<style>
.recent-media-button { display:flex; align-items:center; gap:14px; border:0; background:transparent; color:#0d6efd; text-align:left; padding:0; }
.recent-media-thumbnail { position:relative; display:flex; align-items:center; justify-content:center; flex-shrink:0; width:96px; height:72px; border-radius:8px; overflow:hidden; background:#eef2fa; color:#53617c; }
.recent-media-thumbnail img { position:absolute; inset:0; width:100%; height:100%; object-fit:cover; }
.recent-media-thumbnail .recent-play { position:absolute; color:white; background:#26334bcc; border-radius:50%; padding:4px 8px; }
#recentMediaModal .modal-body { height:min(68vh,720px); background:#101827; display:flex; align-items:center; justify-content:center; }
#recentMediaModal img, #recentMediaModal video { width:100%; height:100%; object-fit:contain; }
#recentMediaTitle { overflow-wrap:anywhere; }
</style>
<main id="main" class="main">
    <div class="pagetitle"><h1>Recently viewed</h1><p>Your latest 100 opened images and videos. Select media to preview it.</p></div>
    <div class="card"><div class="card-body pt-3 table-responsive">
        <table class="table align-middle"><thead><tr><th>Media</th><th>Folder</th><th>Last viewed</th></tr></thead><tbody>
        <?php foreach ($items as $item): ?>
            <?php
            $name = basename($item['path']);
            $folder = dirname($item['path']);
            $base = !empty($departmentRecent) ? 'workspace/file-management/' . (int)$item['id'] : 'workspace/server-library/items/m' . (int)$item['id'];
            $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
            $isVideo = in_array($extension, ['mp4','mov','m4v','webm','avi','mkv'], true);
            $previewable = $isVideo || in_array($extension, \App\Services\CameraImagePreviewService::IMAGE_EXTENSIONS, true);
            $folderUrl = !empty($departmentRecent)
                ? site_url('workspace/file-management').'?'.http_build_query(['folder'=>$item['folder_id']])
                : site_url('workspace/server-library').'?'.http_build_query(['folder'=>$folder === '.' ? '' : $folder]);
            ?>
            <tr><td>
                <?php if ($previewable): ?>
                <button type="button" class="recent-media-button" data-bs-toggle="modal" data-bs-target="#recentMediaModal"
                    data-recent-media data-preview-kind="<?= $isVideo ? 'video' : 'image' ?>"
                    data-preview-src="<?= esc(site_url($base . ($isVideo ? '/video-preview' : '/preview'))) ?>"
                    data-preview-name="<?= esc($name) ?>" data-download-url="<?= esc(site_url($base . '/download')) ?>" aria-label="<?= esc('Preview ' . $name) ?>">
                    <span class="recent-media-thumbnail"><i class="bi bi-<?= $isVideo ? 'film' : 'image' ?>" aria-hidden="true"></i><img src="<?= esc(site_url($base . '/preview') . '?thumbnail=1') ?>" alt="" loading="lazy" decoding="async"><?php if ($isVideo): ?><span class="recent-play" aria-hidden="true"><i class="bi bi-play-fill"></i></span><?php endif; ?></span>
                    <span><?= esc($name) ?></span>
                </button>
                <?php else: ?><a href="<?= esc(site_url($base . '/download')) ?>"><?= esc($name) ?></a><?php endif; ?>
            </td><td><span><?= esc($folder === '.' ? 'Root Library' : $folder) ?></span><br><a class="small" href="<?= esc($folderUrl) ?>">Open folder</a></td><td class="text-nowrap"><?= esc(\App\Libraries\DisplayTime::format($item['viewed_at'])) ?> PHT</td></tr>
        <?php endforeach; ?>
        <?php if (!$items): ?><tr><td colspan="3">Open an image or video in File Management to see it here.</td></tr><?php endif; ?>
        </tbody></table>
    </div></div>
</main>
<div class="modal fade" id="recentMediaModal" tabindex="-1" aria-labelledby="recentMediaTitle" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-centered"><div class="modal-content">
        <div class="modal-header"><h5 class="modal-title" id="recentMediaTitle">Media preview</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
        <div class="modal-body"><img class="d-none" id="recentMediaImage" alt=""><video class="d-none" id="recentMediaVideo" controls playsinline preload="metadata"></video><p class="text-white d-none" id="recentMediaError" role="alert">Preview unavailable. Try again or download the original file.</p></div>
        <div class="modal-footer"><span class="small text-muted me-auto" id="recentMediaStatus" role="status"></span><button class="btn btn-outline-secondary" type="button" id="recentMediaPrevious" aria-label="Previous media"><i class="bi bi-chevron-left"></i></button><button class="btn btn-outline-secondary" type="button" id="recentMediaNext" aria-label="Next media"><i class="bi bi-chevron-right"></i></button><a class="btn btn-outline-primary" id="recentMediaDownload">Download original</a><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button></div>
    </div></div>
</div>
<?php // AJAX navigation executes inline page scripts, but does not load external scripts. ?>
<script><?= file_get_contents(ROOTPATH . 'public/assets/js/server-recent-preview.js') ?></script>
<?= view('layouts/app_footer') ?>
