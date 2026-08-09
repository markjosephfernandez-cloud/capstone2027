<?= view('layouts/app_header', ['user' => $user, 'activePage' => $activePage, 'title' => $title]) ?>
<?php
use App\Libraries\MediaTitle;

$activeTab = (string) (service('request')->getGet('tab') ?: 'requests');
$mediaFiles = array_map(static function (array $file): array {
    $fileId = (int) $file['id'];
    $mime = (string) ($file['mime_type'] ?? '');
    $type = (string) ($file['filetype'] ?? '');
    $isImage = str_starts_with($mime, 'image/') || in_array(strtolower($type), ['jpg', 'jpeg', 'png', 'gif', 'webp'], true);

    return [
        'id' => $fileId,
        'title' => MediaTitle::forFile($file),
        'filename' => (string) ($file['filename'] ?? ''),
        'filetype' => strtoupper((string) ($file['filetype'] ?? '')),
        'size' => (int) ($file['size'] ?? 0),
        'tags' => (string) ($file['tags'] ?? ''),
        'folder_id' => empty($file['folder_id']) ? null : (int) $file['folder_id'],
        'preview_url' => $isImage ? site_url('workspace/file-management/' . $fileId . '/preview') : null,
    ];
}, $files);
$mediaFolders = array_map(static fn (array $folder): array => [
    'id' => (int) $folder['id'],
    'parent_id' => empty($folder['parent_id']) ? null : (int) $folder['parent_id'],
    'name' => (string) $folder['name'],
    'path' => (string) ($folder['path'] ?? $folder['name']),
    'file_count' => (int) ($folder['file_count'] ?? 0),
], $folders ?? []);
$requestPreviewFiles = [];
foreach ($requestFiles ?? [] as $requestId => $items) {
    $requestPreviewFiles[(int) $requestId] = array_map(static function (array $file): array {
        $fileId = (int) $file['id'];
        $mime = (string) ($file['mime_type'] ?? '');
        $type = (string) ($file['filetype'] ?? '');
        $isImage = str_starts_with($mime, 'image/') || in_array(strtolower($type), ['jpg', 'jpeg', 'png', 'gif', 'webp'], true);

        return [
            'id' => $fileId,
            'title' => (string) ($file['media_title'] ?? MediaTitle::forFile($file)),
            'folder' => (string) ($file['folder_name'] ?? 'Root library'),
            'preview_url' => $isImage ? site_url('workspace/file-management/' . $fileId . '/preview') : null,
        ];
    }, $items);
}
?>
<style>
.distribution-workflow{--mini-primary:#1f6fff;--mini-primary-soft:#eaf1ff;--mini-ink:#11264b;--mini-muted:#65728b;--mini-border:#dce5f3}.distribution-workflow .status-path{display:flex;gap:.4rem;flex-wrap:wrap}.distribution-workflow .status-dot{align-items:center;display:inline-flex;gap:.35rem}.distribution-workflow .status-dot::before{background:#d7dfed;border-radius:999px;content:"";height:.55rem;width:.55rem}.distribution-workflow .status-dot.is-done::before{background:#0d6efd}.distribution-workflow .status-dot.is-complete::before{background:#198754}.distribution-workflow .request-details{max-width:280px}.distribution-workflow .request-description{white-space:normal}.distribution-workflow .media-picker-dialog{max-width:min(1040px,calc(100vw - 1.5rem))}.distribution-workflow .media-picker-content{border:0;border-radius:8px;box-shadow:0 18px 48px rgba(31,49,83,.22);max-height:calc(100vh - 2rem);overflow:hidden}.distribution-workflow .media-picker-form{display:flex;flex-direction:column;max-height:calc(100vh - 2rem)}.distribution-workflow .media-picker-body{background:#f5f8fd;overflow:auto;padding:1rem}.distribution-workflow .mini-library{background:#fff;border:1px solid var(--mini-border);border-radius:8px;box-shadow:0 4px 14px rgba(37,52,85,.06);overflow:hidden}.distribution-workflow .mini-library-head{align-items:center;display:flex;gap:1rem;justify-content:space-between;padding:1rem 1.1rem}.distribution-workflow .mini-library-title{align-items:center;display:flex;gap:.75rem}.distribution-workflow .mini-library-symbol{align-items:center;background:#e9efff;border-radius:8px;color:var(--mini-primary);display:flex;font-size:1.2rem;height:44px;justify-content:center;width:44px}.distribution-workflow .mini-library h6{color:var(--mini-ink);font-size:1.08rem;font-weight:750;margin:0}.distribution-workflow .mini-library p{color:var(--mini-muted);font-size:.82rem;margin:.2rem 0 0}.distribution-workflow .mini-search{background:#f0f4f9;border:1px solid transparent;border-radius:999px;max-width:260px;min-width:210px;overflow:hidden}.distribution-workflow .mini-search:focus-within{background:#fff;border-color:#b7ccff;box-shadow:0 1px 5px rgba(49,94,251,.12)}.distribution-workflow .mini-search .input-group-text,.distribution-workflow .mini-search .form-control{background:transparent;border:0;min-height:40px}.distribution-workflow .mini-search .input-group-text{color:var(--mini-primary)}.distribution-workflow .mini-breadcrumb{background:#f3f6fb;border-bottom:1px solid #dfe7f7;border-top:1px solid #edf1f7;color:#4a5e89;display:flex;flex-wrap:wrap;font-size:.82rem;gap:.35rem;padding:.7rem 1.1rem}.distribution-workflow .mini-crumb{background:transparent;border:0;color:#1f6fff;font-weight:650;padding:0}.distribution-workflow .mini-crumb[aria-current="page"]{color:#4a5e89;cursor:default}.distribution-workflow .mini-browser{padding:1rem 1.1rem 1.15rem}.distribution-workflow .mini-section-title{color:var(--mini-ink);font-size:.9rem;font-weight:750;margin:0 0 .75rem}.distribution-workflow .mini-grid{display:grid;gap:.85rem;grid-template-columns:repeat(auto-fill,minmax(160px,1fr));margin-bottom:1rem}.distribution-workflow .mini-tile{appearance:none;background:#f1f5fa;border:1px solid #e4eaf4;border-radius:8px;color:#34405b;cursor:pointer;display:flex;flex-direction:column;min-height:156px;overflow:hidden;padding:.65rem;position:relative;text-align:left;transition:background .15s,border-color .15s,box-shadow .15s,transform .15s;width:100%}.distribution-workflow .mini-tile:hover{background:#fff;border-color:#aebfe0;box-shadow:0 5px 14px rgba(60,64,67,.12);transform:translateY(-1px)}.distribution-workflow .mini-tile:focus-visible{box-shadow:0 0 0 3px rgba(31,111,255,.22);outline:0}.distribution-workflow .mini-tile.is-selected{background:#eef4ff;border-color:var(--mini-primary);box-shadow:0 0 0 2px rgba(31,111,255,.13)}.distribution-workflow .mini-select-mark{align-items:center;background:rgba(255,255,255,.96);border:1px solid #d9e2f4;border-radius:6px;color:transparent;display:flex;height:28px;justify-content:center;left:.65rem;position:absolute;top:.65rem;width:28px;z-index:2}.distribution-workflow .mini-tile.is-selected .mini-select-mark{background:var(--mini-primary);border-color:var(--mini-primary);color:#fff}.distribution-workflow .mini-thumb{align-items:center;background:#fff;border-radius:7px;color:var(--mini-primary);display:flex;font-size:2rem;height:92px;justify-content:center;margin-bottom:.65rem;overflow:hidden;width:100%}.distribution-workflow .mini-thumb img{height:100%;object-fit:cover;width:100%}.distribution-workflow .mini-name{color:#34405b;font-size:.88rem;font-weight:750;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}.distribution-workflow .mini-meta{color:#7a869d;font-size:.76rem;margin-top:.2rem;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}.distribution-workflow .mini-empty{align-items:center;color:#7a869d;display:flex;grid-column:1/-1;justify-content:center;min-height:120px;text-align:center}.distribution-workflow .picker-footer-summary{color:#44516e;font-size:.86rem;font-weight:700;margin-right:auto}.distribution-workflow .media-picker-form .modal-footer{background:#fff;border-top:1px solid #e8edf5;bottom:0;position:sticky;z-index:4}.distribution-workflow .preview-media-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(120px,1fr));gap:.75rem}.distribution-workflow .preview-media-card{background:#fff;border:1px solid #e1e7f2;border-radius:8px;overflow:hidden}.distribution-workflow .preview-media-card .mini-thumb{border-radius:0;height:84px;margin:0;width:100%}.distribution-workflow .preview-media-card .small{padding:.45rem}.distribution-workflow .readonly-preview-list{max-height:360px;overflow:auto}
@media (max-width:767.98px){.distribution-workflow .mini-library-head{align-items:stretch;flex-direction:column}.distribution-workflow .mini-search{max-width:none;width:100%}.distribution-workflow .mini-grid{grid-template-columns:repeat(auto-fill,minmax(135px,1fr))}.distribution-workflow .mini-tile{min-height:145px}.distribution-workflow .mini-thumb{height:82px}}
</style>
<main id="main" class="main distribution-workflow">
    <div class="pagetitle"><h1><?= $isDepartment ? 'My Requests' : 'Distribution Workflow' ?></h1><nav><ol class="breadcrumb"><li class="breadcrumb-item"><a href="<?= esc(site_url('dashboard')) ?>">Dashboard</a></li><li class="breadcrumb-item active"><?= $isDepartment ? 'My Requests' : 'Distribution Workflow' ?></li></ol></nav></div>
    <section class="section">
        <?php if ($success): ?><div class="alert alert-success"><?= esc($success) ?></div><?php endif; ?>
        <?php if ($error): ?><div class="alert alert-danger"><?= esc($error) ?></div><?php endif; ?>

        <?php if ($isDepartment): ?>
            <div class="card mb-4" id="request-media">
                <div class="card-body pt-3">
                    <h2 class="h5">Request Media</h2>
                    <form class="row g-3" action="<?= esc(site_url('workspace/distribution/requests')) ?>" method="post">
                        <?= csrf_field() ?>
                        <div class="col-md-4"><label class="form-label">Requested media or event</label><input class="form-control" name="title" maxlength="180" required placeholder="e.g. Dal-uy 2026"></div>
                        <div class="col-md-6"><label class="form-label">Request details</label><input class="form-control" name="description" maxlength="4000" placeholder="Describe the media needed"></div>
                        <div class="col-md-2 d-grid align-items-end"><button class="btn btn-primary" type="submit">Submit Request</button></div>
                    </form>
                </div>
            </div>
        <?php endif; ?>

        <ul class="nav nav-tabs mb-3">
            <li class="nav-item"><a class="nav-link <?= $activeTab !== 'direct' ? 'active' : '' ?>" href="?tab=requests">Department Requests</a></li>
            <li class="nav-item"><a class="nav-link <?= $activeTab === 'direct' ? 'active' : '' ?>" href="?tab=direct">Direct Send</a></li>
        </ul>

        <?php if ($activeTab !== 'direct'): ?>
            <div class="card">
                <div class="card-body pt-3">
                    <div class="table-responsive">
                        <table class="table align-middle">
                            <thead><tr><th>Department</th><th>Request</th><th>Requested By</th><th>Timeline</th><th>Status</th><th class="text-end">Action</th></tr></thead>
                            <tbody>
                            <?php foreach ($requests as $request): ?>
                                <?php $status = (string) $request['status']; $requestId = (int) $request['id']; ?>
                                <tr>
                                    <td><?= esc($request['department']) ?></td>
                                    <td class="request-details"><strong><?= esc($request['title']) ?></strong><?php if (! empty($request['description'])): ?><div class="small text-muted request-description"><?= esc($request['description']) ?></div><?php endif; ?><div class="small text-muted"><?= esc(date('M j, Y g:i A', strtotime((string) $request['submitted_at']))) ?></div></td>
                                    <td><?= esc($request['requester_name'] ?: 'Department user') ?></td>
                                    <td>
                                        <div class="status-path">
                                            <span class="status-dot is-done">Submitted</span>
                                            <span class="status-dot <?= in_array($status, ['transmitted', 'completed'], true) ? 'is-done' : '' ?>">Transmitted</span>
                                            <span class="status-dot <?= $status === 'completed' ? 'is-complete' : '' ?>">Completed</span>
                                        </div>
                                        <?php if ($request['transmitter_name']): ?><div class="small text-muted mt-2">Transmitted by: <strong><?= esc($request['transmitter_name']) ?></strong><br><?= esc(date('M j, Y g:i A', strtotime((string) $request['transmitted_at']))) ?></div><?php endif; ?>
                                    </td>
                                    <td><span class="badge <?= $status === 'completed' ? 'bg-success-subtle text-success' : (in_array($status, ['transmitted', 'sent'], true) ? 'bg-primary-subtle text-primary' : 'bg-light text-dark') ?>"><?= esc(ucfirst($status)) ?></span></td>
                                    <td class="text-end text-nowrap">
                                        <?php if ($canManage && $status === 'submitted'): ?>
                                            <button class="btn btn-primary btn-sm" type="button" data-bs-toggle="modal" data-bs-target="#mediaPickerModal" data-mode="request" data-action="<?= esc(site_url('workspace/distribution/requests/' . $requestId . '/transmit'), 'attr') ?>" data-request-id="<?= $requestId ?>" data-request-title="<?= esc($request['title'], 'attr') ?>" data-request-user="<?= esc($request['requester_name'] ?: 'Department user', 'attr') ?>" data-department="<?= esc($request['department'], 'attr') ?>">Transmit</button>
                                        <?php endif; ?>
                                        <?php if (($canManage || $isDepartment) && in_array($status, ['transmitted', 'completed'], true)): ?>
                                            <button class="btn btn-outline-primary btn-sm" type="button" data-bs-toggle="modal" data-bs-target="#requestPreviewModal" data-request-id="<?= $requestId ?>" data-request-title="<?= esc($request['title'], 'attr') ?>" data-request-user="<?= esc($request['requester_name'] ?: 'Department user', 'attr') ?>" data-department="<?= esc($request['department'], 'attr') ?>" data-status="<?= esc(ucfirst($status), 'attr') ?>">Preview</button>
                                        <?php endif; ?>
                                        <?php if ($isDepartment && $status === 'transmitted'): ?>
                                            <form class="d-inline" action="<?= esc(site_url('workspace/distribution/requests/' . $requestId . '/complete')) ?>" method="post"><?= csrf_field() ?><button class="btn btn-success btn-sm" type="submit">Complete</button></form>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            <?php if ($requests === []): ?><tr><td colspan="6" class="text-center text-muted py-5">No department requests found.</td></tr><?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        <?php else: ?>
            <?php if ($canManage): ?>
                <div class="card mb-4">
                    <div class="card-body pt-3">
                        <h2 class="h5">Direct Send</h2>
                        <div class="row g-3 align-items-end">
                            <div class="col-md-5"><label class="form-label" for="directDepartment">Department</label><select class="form-select" id="directDepartment" required><option value="">Select department</option><?php foreach ($departments as $department): ?><option><?= esc($department['department']) ?></option><?php endforeach; ?></select></div>
                            <div class="col-md-7"><button class="btn btn-primary" type="button" id="openDirectPicker" data-mode="direct" data-action="<?= esc(site_url('workspace/distribution/direct-send'), 'attr') ?>">Choose Media &amp; Preview</button></div>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
            <div class="card"><div class="card-body pt-3"><div class="table-responsive"><table class="table"><thead><tr><th>Department</th><th>Files Sent</th><th>Direct Sent By</th><th>Timestamp</th><th>Status</th></tr></thead><tbody><?php foreach ($directSends as $send): ?><tr><td><?= esc($send['department']) ?></td><td><?= (int) $send['file_count'] ?></td><td><strong><?= esc($send['sender_name'] ?: 'Unknown user') ?></strong><div class="small text-muted">Admin / Creative Staff</div></td><td><?= esc(date('M j, Y g:i A', strtotime((string) $send['sent_at']))) ?></td><td><span class="badge bg-success-subtle text-success"><?= esc(ucfirst((string) $send['status'])) ?></span></td></tr><?php endforeach; ?><?php if ($directSends === []): ?><tr><td colspan="5" class="text-center text-muted py-5">No direct sends found.</td></tr><?php endif; ?></tbody></table></div></div></div>
        <?php endif; ?>
    </section>
</main>

<?php if ($canManage || $isDepartment): ?>
<div class="distribution-workflow">
<?php if ($canManage): ?>
<div class="modal fade" id="mediaPickerModal" tabindex="-1" aria-labelledby="mediaPickerTitle" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-centered media-picker-dialog">
        <div class="modal-content media-picker-content">
            <form id="mediaPickerForm" class="media-picker-form" method="post">
                <?= csrf_field() ?>
                <div class="modal-header"><div><h5 class="modal-title" id="mediaPickerTitle">Select media</h5><div class="small text-muted" id="mediaPickerContext"></div></div><button class="btn-close" type="button" data-bs-dismiss="modal" aria-label="Close"></button></div>
                <div class="modal-body media-picker-body">
                    <div id="pickerStep">
                        <div class="mini-library">
                            <div class="mini-library-head">
                                <div class="mini-library-title">
                                    <span class="mini-library-symbol"><i class="bi bi-collection-play"></i></span>
                                    <div>
                                        <h6>Media Library</h6>
                                        <p>Select media to transmit to this department.</p>
                                    </div>
                                </div>
                                <label class="input-group mini-search" for="miniMediaSearch">
                                    <span class="input-group-text"><i class="bi bi-search"></i></span>
                                    <input class="form-control" id="miniMediaSearch" type="search" placeholder="Search..." autocomplete="off">
                                </label>
                            </div>
                            <div class="mini-breadcrumb" id="pickerBreadcrumb"></div>
                            <div class="mini-browser">
                                <div id="miniFoldersSection">
                                    <p class="mini-section-title">Folders</p>
                                    <div class="mini-grid" id="pickerFolderGrid"></div>
                                </div>
                                <div id="miniFilesSection">
                                    <p class="mini-section-title">Files</p>
                                    <div class="mini-grid" id="pickerFileGrid"></div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div id="previewStep" class="d-none">
                        <div class="mb-3"><h6 class="mb-1">Department Request</h6><div id="previewContext" class="text-muted"></div></div>
                        <hr>
                        <div class="row g-3 mb-3">
                            <div class="col-md-6"><h6>Selected folders</h6><div id="previewFolders" class="small"></div></div>
                            <div class="col-md-6"><h6>Selected files</h6><div id="previewFilesList" class="small"></div></div>
                        </div>
                        <div class="d-flex gap-3 mb-3"><span class="badge bg-primary-subtle text-primary" id="previewFileCount">Total Files: 0</span><span class="badge bg-light text-dark" id="previewFolderCount">Total Folders: 0</span></div>
                        <div class="preview-media-grid" id="previewMediaGrid"></div>
                    </div>
                    <input type="hidden" name="department" id="pickerDepartment">
                    <div id="pickerHiddenInputs"></div>
                </div>
                <div class="modal-footer"><span class="picker-footer-summary" id="pickerSelectedCount">0 folders &bull; 0 files selected</span><button class="btn btn-light" type="button" id="previewBack">Back</button><button class="btn btn-primary" type="button" id="continuePreview">Continue</button><button class="btn btn-primary d-none" type="submit" id="finalTransmit">Transmit</button></div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>
<div class="modal fade" id="requestPreviewModal" tabindex="-1" aria-labelledby="requestPreviewTitle" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header"><div><h5 class="modal-title" id="requestPreviewTitle">Transmitted media</h5><div class="small text-muted" id="requestPreviewContext"></div></div><button class="btn-close" type="button" data-bs-dismiss="modal" aria-label="Close"></button></div>
            <div class="modal-body"><div class="preview-media-grid readonly-preview-list" id="requestPreviewGrid"></div></div>
            <div class="modal-footer"><button class="btn btn-secondary" type="button" data-bs-dismiss="modal">Close</button></div>
        </div>
    </div>
</div>
<script>
document.addEventListener('DOMContentLoaded', function () {
    var files = <?= json_encode($mediaFiles, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>;
    var folders = <?= json_encode($mediaFolders, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>;
    var requestFiles = <?= json_encode($requestPreviewFiles, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>;
    var selectedFiles = new Set();
    var selectedFolders = new Set();
    var currentFolder = null;
    var searchTerm = '';
    var folderClickTimers = {};
    var folderById = {};
    var childrenByParent = {};
    folders.forEach(function (folder) {
        folderById[folder.id] = folder;
        var parent = folder.parent_id || 0;
        if (!childrenByParent[parent]) childrenByParent[parent] = [];
        childrenByParent[parent].push(folder);
    });
    function filesInFolder(folderId) { return files.filter(function (file) { return (file.folder_id || null) === (folderId || null); }); }
    function h(value) {
        return String(value || '').replace(/[&<>"']/g, function (char) {
            return {'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;'}[char];
        });
    }
    function formatBytes(bytes) {
        bytes = Number(bytes || 0);
        if (bytes < 1024) return bytes + ' B';
        var units = ['KB', 'MB', 'GB'];
        var value = bytes / 1024;
        var unit = 0;
        while (value >= 1024 && unit < units.length - 1) {
            value = value / 1024;
            unit++;
        }
        return value.toFixed(value >= 10 ? 0 : 1) + ' ' + units[unit];
    }
    function fileById(id) { return files.find(function (file) { return file.id === id; }) || null; }
    function folderPath(folderId) {
        var path = [];
        var folder = folderById[folderId];
        while (folder) {
            path.unshift(folder);
            folder = folder.parent_id ? folderById[folder.parent_id] : null;
        }
        return path;
    }
    function searchable(value) { return String(value || '').toLowerCase(); }
    function folderMatches(folder) { return searchable(folder.name + ' ' + folder.path).indexOf(searchTerm) !== -1; }
    function fileMatches(file) { return searchable(file.title + ' ' + file.filename + ' ' + file.tags + ' ' + file.filetype).indexOf(searchTerm) !== -1; }
    function descendantFolderIds(folderId) {
        var ids = [folderId];
        (childrenByParent[folderId] || []).forEach(function (child) { ids = ids.concat(descendantFolderIds(child.id)); });
        return ids;
    }
    function fileIdsInFolderTree(folderId) {
        var ids = descendantFolderIds(folderId);
        return files.filter(function (file) { return ids.indexOf(file.folder_id) !== -1; }).map(function (file) { return file.id; });
    }
    function selectedFileObjects() {
        var ids = new Set(Array.from(selectedFiles));
        selectedFolders.forEach(function (folderId) { fileIdsInFolderTree(folderId).forEach(function (fileId) { ids.add(fileId); }); });
        return files.filter(function (file) { return ids.has(file.id); });
    }
    function renderThumb(file) {
        return file.preview_url ? '<span class="mini-thumb"><img src="' + file.preview_url + '" alt=""></span>' : '<span class="mini-thumb"><i class="bi bi-file-earmark"></i></span>';
    }
    function updateSelectedCount() {
        var fileCount = selectedFileObjects().length;
        var folderCount = selectedFolders.size;
        document.getElementById('pickerSelectedCount').innerHTML = folderCount + ' ' + (folderCount === 1 ? 'folder' : 'folders') + ' &bull; ' + fileCount + ' ' + (fileCount === 1 ? 'file' : 'files') + ' selected';
    }
    function renderBreadcrumb() {
        var crumb = document.getElementById('pickerBreadcrumb');
        var parts = [{id: null, name: 'Root Library'}].concat(currentFolder === null ? [] : folderPath(currentFolder).map(function (folder) { return {id: folder.id, name: folder.name}; }));
        crumb.innerHTML = parts.map(function (part, index) {
            var last = index === parts.length - 1;
            var separator = index === 0 ? '' : '<span>/</span>';
            return separator + '<button class="mini-crumb" type="button" data-folder-id="' + (part.id === null ? '' : part.id) + '"' + (last ? ' aria-current="page"' : '') + '>' + h(part.name) + '</button>';
        }).join('');
        crumb.querySelectorAll('.mini-crumb').forEach(function (button) {
            button.addEventListener('click', function () {
                if (button.getAttribute('aria-current') === 'page') return;
                var id = button.dataset.folderId;
                currentFolder = id === '' ? null : Number(id);
                searchTerm = '';
                var search = document.getElementById('miniMediaSearch');
                if (search) search.value = '';
                renderPicker();
            });
        });
    }
    function tileSelected(type, id) {
        return type === 'folder' ? selectedFolders.has(id) : selectedFiles.has(id);
    }
    function folderTile(folder) {
        var selected = tileSelected('folder', folder.id);
        return '<button class="mini-tile' + (selected ? ' is-selected' : '') + '" type="button" data-picker-folder="' + folder.id + '"><span class="mini-select-mark"><i class="bi bi-check2"></i></span><span class="mini-thumb"><i class="bi bi-folder-fill"></i></span><span class="mini-name" title="' + h(folder.name) + '">' + h(folder.name) + '</span><span class="mini-meta">' + folder.file_count + ' ' + (folder.file_count === 1 ? 'file' : 'files') + '</span></button>';
    }
    function fileTile(file) {
        var selected = tileSelected('file', file.id);
        return '<button class="mini-tile' + (selected ? ' is-selected' : '') + '" type="button" data-picker-file="' + file.id + '">' + '<span class="mini-select-mark"><i class="bi bi-check2"></i></span>' + renderThumb(file) + '<span class="mini-name" title="' + h(file.title) + '">' + h(file.title) + '</span><span class="mini-meta">' + h(file.filetype || 'FILE') + (file.size ? ' - ' + h(formatBytes(file.size)) : '') + '</span></button>';
    }
    function renderPicker() {
        var folderGrid = document.getElementById('pickerFolderGrid');
        var fileGrid = document.getElementById('pickerFileGrid');
        var foldersToShow = searchTerm ? folders.filter(folderMatches) : (childrenByParent[currentFolder || 0] || []);
        var filesToShow = searchTerm ? files.filter(fileMatches) : filesInFolder(currentFolder);
        renderBreadcrumb();
        folderGrid.innerHTML = foldersToShow.map(folderTile).join('') || '<div class="mini-empty">No matching folders.</div>';
        fileGrid.innerHTML = filesToShow.map(fileTile).join('') || '<div class="mini-empty">No matching files.</div>';
        folderGrid.querySelectorAll('[data-picker-folder]').forEach(function (button) {
            button.addEventListener('click', function () {
                var id = Number(button.dataset.pickerFolder);
                window.clearTimeout(folderClickTimers[id]);
                folderClickTimers[id] = window.setTimeout(function () {
                    selectedFolders.has(id) ? selectedFolders.delete(id) : selectedFolders.add(id);
                    renderPicker();
                }, 220);
            });
            button.addEventListener('dblclick', function () {
                var id = Number(button.dataset.pickerFolder);
                window.clearTimeout(folderClickTimers[id]);
                currentFolder = Number(button.dataset.pickerFolder);
                searchTerm = '';
                var search = document.getElementById('miniMediaSearch');
                if (search) search.value = '';
                renderPicker();
            });
        });
        fileGrid.querySelectorAll('[data-picker-file]').forEach(function (button) {
            button.addEventListener('click', function () {
                var id = Number(button.dataset.pickerFile);
                selectedFiles.has(id) ? selectedFiles.delete(id) : selectedFiles.add(id);
                renderPicker();
            });
        });
        var back = document.getElementById('previewBack');
        if (document.getElementById('previewStep').classList.contains('d-none')) {
            back.disabled = currentFolder === null;
            back.textContent = 'Back';
        }
        updateSelectedCount();
    }
    function showPickerStep() {
        document.getElementById('pickerStep').classList.remove('d-none');
        document.getElementById('previewStep').classList.add('d-none');
        document.getElementById('previewBack').classList.remove('d-none');
        document.getElementById('continuePreview').classList.remove('d-none');
        document.getElementById('finalTransmit').classList.add('d-none');
        renderPicker();
    }
    function buildPreview() {
        var chosen = selectedFileObjects();
        if (chosen.length === 0) {
            alert('Select at least one file or folder.');
            return;
        }
        document.getElementById('pickerStep').classList.add('d-none');
        document.getElementById('previewStep').classList.remove('d-none');
        document.getElementById('previewBack').classList.remove('d-none');
        document.getElementById('previewBack').disabled = false;
        document.getElementById('continuePreview').classList.add('d-none');
        document.getElementById('finalTransmit').classList.remove('d-none');
        document.getElementById('previewFileCount').textContent = 'Total Files: ' + chosen.length;
        document.getElementById('previewFolderCount').textContent = 'Total Folders: ' + selectedFolders.size;
        document.getElementById('previewFolders').innerHTML = Array.from(selectedFolders).map(function (id) {
            var folderFiles = fileIdsInFolderTree(id).map(fileById).filter(Boolean);
            var nested = folderFiles.slice(0, 8).map(function (file) { return '<div>' + h(file.title) + '</div>'; }).join('');
            if (folderFiles.length > 8) nested += '<div>and ' + (folderFiles.length - 8) + ' more</div>';
            return '<div class="mb-2"><div><i class="bi bi-folder-fill text-primary me-1"></i>' + h(folderById[id] ? folderById[id].name : 'Folder') + '</div><div class="ms-3 text-muted">' + (nested || 'No processed files inside.') + '</div></div>';
        }).join('') || '<span class="text-muted">No folders selected.</span>';
        document.getElementById('previewFilesList').innerHTML = Array.from(selectedFiles).map(function (id) { var file = fileById(id); return file ? '<div>' + h(file.title) + '</div>' : ''; }).join('') || '<span class="text-muted">No individual files selected.</span>';
        document.getElementById('previewMediaGrid').innerHTML = chosen.map(function (file) { return '<div class="preview-media-card">' + renderThumb(file) + '<div class="small text-truncate">' + h(file.title) + '</div></div>'; }).join('');
        var hidden = document.getElementById('pickerHiddenInputs');
        hidden.innerHTML = '';
        selectedFiles.forEach(function (id) { hidden.insertAdjacentHTML('beforeend', '<input type="hidden" name="file_ids[]" value="' + id + '">'); });
        selectedFolders.forEach(function (id) { hidden.insertAdjacentHTML('beforeend', '<input type="hidden" name="folder_ids[]" value="' + id + '">'); });
    }
    var pickerModal = document.getElementById('mediaPickerModal');
    if (pickerModal) {
        pickerModal.addEventListener('show.bs.modal', function (event) {
            var trigger = event.relatedTarget;
            selectedFiles = new Set();
            selectedFolders = new Set();
            currentFolder = null;
            searchTerm = '';
            document.getElementById('mediaPickerForm').action = trigger.dataset.action || '';
            document.getElementById('pickerDepartment').value = trigger.dataset.department || '';
            var title = trigger.dataset.mode === 'direct' ? 'Direct Send' : 'Transmit files';
            document.getElementById('mediaPickerTitle').textContent = title;
            document.getElementById('finalTransmit').textContent = trigger.dataset.mode === 'direct' ? 'Direct Send' : 'Transmit';
            document.getElementById('mediaPickerContext').textContent = (trigger.dataset.department || '') + (trigger.dataset.requestTitle ? ' - ' + trigger.dataset.requestTitle + ' requested by ' + (trigger.dataset.requestUser || 'Department user') : '');
            document.getElementById('previewContext').textContent = document.getElementById('mediaPickerContext').textContent;
            document.getElementById('pickerHiddenInputs').innerHTML = '';
            document.getElementById('miniMediaSearch').value = '';
            showPickerStep();
        });
        document.getElementById('miniMediaSearch').addEventListener('input', function (event) {
            searchTerm = event.target.value.trim().toLowerCase();
            renderPicker();
        });
        document.getElementById('continuePreview').addEventListener('click', buildPreview);
        document.getElementById('previewBack').addEventListener('click', function () {
            if (!document.getElementById('previewStep').classList.contains('d-none')) {
                showPickerStep();
                return;
            }
            currentFolder = currentFolder === null || !folderById[currentFolder] ? null : (folderById[currentFolder].parent_id || null);
            renderPicker();
        });
    }
    var directButton = document.getElementById('openDirectPicker');
    if (directButton) {
        directButton.addEventListener('click', function () {
            var department = document.getElementById('directDepartment').value;
            if (!department) {
                alert('Select a department first.');
                return;
            }
            directButton.dataset.department = department;
            if (pickerModal) bootstrap.Modal.getOrCreateInstance(pickerModal).show(directButton);
        });
    }
    var previewModal = document.getElementById('requestPreviewModal');
    previewModal.addEventListener('show.bs.modal', function (event) {
        var trigger = event.relatedTarget;
        var id = trigger.dataset.requestId || '';
        var transmitted = requestFiles[id] || [];
        document.getElementById('requestPreviewTitle').textContent = trigger.dataset.requestTitle || 'Transmitted media';
        document.getElementById('requestPreviewContext').textContent = (trigger.dataset.department || '') + ' - ' + (trigger.dataset.requestUser || 'Department user') + ' - ' + (trigger.dataset.status || '');
        document.getElementById('requestPreviewGrid').innerHTML = transmitted.map(function (file) { return '<div class="preview-media-card">' + renderThumb(file) + '<div class="small"><strong class="d-block text-truncate">' + h(file.title) + '</strong><span class="text-muted text-truncate d-block">' + h(file.folder || 'Root library') + '</span></div></div>'; }).join('') || '<div class="text-muted">No transmitted files were recorded for this request.</div>';
    });
});
</script>
</div>
<?php endif; ?>
<?= view('layouts/app_footer') ?>
