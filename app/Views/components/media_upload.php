<?php $isServerUpload = ($uploadLibrary ?? 'local') === 'server'; ?>
        <div id="uploadWorkspace" data-library="<?= esc($uploadLibrary ?? 'local') ?>">
        <input class="visually-hidden" tabindex="-1" aria-hidden="true" type="file" id="files" name="files[]" accept="image/jpeg,image/png,image/gif,image/webp,video/mp4,video/quicktime,video/x-msvideo,video/x-matroska,video/webm,video/x-m4v,audio/mpeg,audio/wav,audio/ogg,application/pdf,.doc,.docx,.xls,.xlsx" multiple>
        <input class="visually-hidden" tabindex="-1" aria-hidden="true" type="file" id="folder_files" name="folder_files[]" webkitdirectory directory mozdirectory multiple>
        <div class="d-none mb-4" id="uploadPanel">
            <div class="upload-card">
                <div class="d-flex align-items-center justify-content-between gap-3 mb-3">
                    <div class="d-flex align-items-center gap-3"><div class="upload-icon"><i class="bi bi-cloud-arrow-up"></i></div><div><h3 id="uploadReviewTitle" tabindex="-1">Review your upload</h3><p class="mb-0 text-muted small" id="uploadSelectionSummary" role="status" aria-live="polite">No files selected</p></div></div>
                    <button class="btn btn-sm btn-light" type="button" data-upload-cancel aria-label="Cancel upload"><i class="bi bi-x-lg"></i></button>
                </div>
                <form class="row g-3 align-items-start" id="batchUploadForm" action="<?= esc($uploadAction ?? site_url('workspace/file-management/upload')) ?>" data-token-url="<?= esc($uploadTokenUrl ?? site_url('workspace/file-management/upload/token')) ?>" data-max-upload-bytes="<?= (int) ($uploadMaxBytes ?? 0) ?>" method="post" enctype="multipart/form-data">
                    <?= csrf_field() ?>
                    <div class="col-12 d-flex flex-wrap gap-2">
                        <button class="btn btn-outline-primary btn-sm" type="button" data-upload-picker="files"><i class="bi bi-plus-lg me-1"></i>Add more files</button>
                        <button class="btn btn-outline-primary btn-sm" type="button" data-upload-picker="folder"><i class="bi bi-folder-plus me-1"></i>Add a folder</button>
                    </div>
                    <div class="col-12"><ul class="upload-review-list" id="uploadReviewList" aria-label="Selected files"></ul></div>
                    <div class="col-md-6 upload-destination">
                        <label class="form-label" for="folder_id">Upload to folder</label>
                        <details class="upload-folder-picker" data-upload-folder-picker <?= !empty($uploadCreateFolderUrl) ? 'data-create-url="'.esc($uploadCreateFolderUrl).'"' : '' ?>>
                            <summary class="form-select" aria-label="Upload to folder"><span data-upload-folder-label>Choose a folder</span></summary>
                            <div class="upload-folder-menu">
                                <label class="visually-hidden" for="uploadFolderSearch">Search folders</label>
                                <input class="form-control mb-2" id="uploadFolderSearch" type="search" placeholder="Search folders…" autocomplete="off">
                                <select class="form-select" id="folder_id" name="folder_id" size="7" <?= !empty($uploadFoldersUrl) ? 'data-folders-url="'.esc($uploadFoldersUrl).'"' : '' ?> aria-describedby="uploadFolderEmpty">
                                    <option value="">Root library</option>
                                    <?php foreach ($folderOptions as $folder): ?>
                                        <option value="<?= esc((string) $folder['id']) ?>" <?= (string) $folderId === (string) $folder['id'] ? 'selected' : '' ?>><?= esc($folder['display_name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <p class="small text-muted mb-0" id="uploadFolderEmpty" role="status" hidden>No matching folders.</p>
                            </div>
                        </details>
                        <?php if (!empty($uploadFoldersUrl) && empty($showDuplicateModes)): ?><div class="form-text" id="uploadFolderHelp" role="status">Choose a folder to load its subfolders.</div><?php endif; ?>
                    </div>
                    <div class="col-md-6 upload-action"><div class="d-flex gap-2"><button class="btn btn-light" type="button" data-upload-cancel>Cancel upload</button><button class="btn btn-primary flex-grow-1" id="uploadSubmit" type="submit" disabled><i class="bi bi-cloud-arrow-up me-1"></i>Start upload</button></div></div>

                    <input type="hidden" name="upload_batch_id" id="uploadBatchId" value="">
                    <div class="col-12"><p class="mt-2 mb-0 text-muted small"><i class="bi bi-image me-1"></i>Uploads are stored as raw originals. Watermarks are added only when downloaded from Watermarking.</p></div>
                    <div class="col-12 d-none" id="uploadProgress" role="status" aria-live="polite"><div class="upload-progress"><div class="d-flex justify-content-between gap-3 mb-2"><span id="uploadProgressLabel">Preparing uploads&hellip;</span><span id="uploadProgressCount"></span></div><div class="progress"><div class="progress-bar progress-bar-striped progress-bar-animated" id="uploadProgressBar" style="width: 0%"></div></div></div></div>
                    <div class="col-12"><p class="mb-0 text-muted small"><i class="bi bi-shield-check me-1"></i>No application file-count limit: selected media is uploaded one at a time. <?php if ((int) ($uploadMaxBytes ?? 0) > 0): ?>Maximum size: <?= esc(number_format(((int) $uploadMaxBytes) / 1048576, 0)) ?> MB per file<?php else: ?>No fixed file-size limit; available server storage applies<?php endif; ?> &middot; Each upload receives event analysis and a metadata passport.</p></div>
                </form>
            </div>
        </div>
        </div>
