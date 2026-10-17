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
        'preview_kind' => $isImage ? 'image' : (str_starts_with($mime, 'video/') || in_array(strtolower($type), ['mp4', 'mov', 'webm', 'avi', 'mkv', 'm4v'], true) ? 'video' : 'file'),
        'preview_url' => $isImage ? site_url('workspace/file-management/' . $fileId . '/preview?thumbnail=1') : null,
    ];
}, $files);
$mediaFolders = array_map(static fn (array $folder): array => [
    'id' => (int) $folder['id'],
    'parent_id' => empty($folder['parent_id']) ? null : (int) $folder['parent_id'],
    'name' => (string) $folder['name'],
    'path' => (string) ($folder['path'] ?? $folder['name']),
    'file_count' => (int) ($folder['file_count'] ?? 0),
    'subfolder_count' => (int) ($folder['subfolder_count'] ?? 0),
], $folders ?? []);
$requestPreviewFiles = [];
foreach ($requestFiles ?? [] as $requestId => $items) {
    $requestPreviewFiles[(int) $requestId] = array_map(static function (array $file) use ($requestId): array {
        $fileId = (int) $file['id'];
        $mime = (string) ($file['mime_type'] ?? '');
        $type = (string) ($file['filetype'] ?? '');
        $isImage = str_starts_with($mime, 'image/') || in_array(strtolower($type), ['jpg', 'jpeg', 'png', 'gif', 'webp'], true);
        $isVideo = str_starts_with($mime, 'video/') || in_array(strtolower($type), ['mp4', 'mov', 'webm', 'avi', 'mkv', 'm4v'], true);

        return [
            'id' => $fileId,
            'title' => (string) ($file['media_title'] ?? MediaTitle::forFile($file)),
            'folder' => (string) ($file['folder_name'] ?? 'Root library'),
            'cycle_number' => max(1, (int) ($file['cycle_number'] ?? 1)),
            'preview_kind' => $isVideo ? 'video' : ($isImage ? 'image' : 'file'),
            'preview_url' => ($isImage || $isVideo) ? site_url('workspace/distribution/requests/' . (int) $requestId . '/files/' . $fileId . '/preview?thumbnail=1') : null,
        ];
    }, $items);
}
?>
<style>
    .direct-department-picker .form-select { min-height: 38px; white-space: normal; overflow-wrap: anywhere; }
    .direct-department-picker .dropdown-menu { width: 100%; min-width: 0; max-height: 240px; overflow-y: auto; padding: .375rem; margin-top: .375rem; border: 1px solid #dee2e6; border-radius: .5rem; box-shadow: 0 .5rem 1.25rem rgba(1, 41, 112, .12); }
    .direct-department-picker .dropdown-item { padding: .6rem .75rem; border-radius: .25rem; white-space: normal; overflow-wrap: anywhere; }
    .direct-department-picker .dropdown-item.active { background-color: #edf2ff; color: #1746a2; font-weight: 600; }

.distribution-workflow .transaction-thumbnail { position: relative; background: #f0f4f9; }
.distribution-workflow .transaction-thumbnail img { position: absolute; inset: 0; background: #f0f4f9; object-fit: contain; }
.distribution-workflow .transaction-video-badge { position: absolute; right: 4px; bottom: 4px; border-radius: 4px; padding: 2px 5px; background: rgba(17,38,75,.85); color: #fff; font-size: 11px; line-height: 1.4; }
.distribution-workflow .history-header { display: flex; align-items: baseline; justify-content: space-between; gap: .75rem; margin: 1.5rem 0 1rem; }
.distribution-workflow .history-header h6 { margin: 0; color: #253858; font-weight: 700; }
.distribution-workflow .history-header span { color: #687791; font-size: .75rem; }
.distribution-workflow .history-timeline { list-style: none; margin: 0; padding: 0; }
.distribution-workflow .history-event { --event-color: #315efb; --event-bg: #edf2ff; position: relative; padding: 0 0 1rem 3rem; }
.distribution-workflow .history-event::before { content: ""; position: absolute; left: 16px; top: 34px; bottom: 0; width: 2px; background: #e4eaf3; }
.distribution-workflow .history-event:last-child { padding-bottom: 0; }
.distribution-workflow .history-event:last-child::before { display: none; }
.distribution-workflow .history-event.is-complete { --event-color: #187349; --event-bg: #eaf7ef; }
.distribution-workflow .history-event.is-incomplete { --event-color: #a54b16; --event-bg: #fff3e8; }
.distribution-workflow .history-event.is-submitted { --event-color: #63718a; --event-bg: #f0f3f8; }
.distribution-workflow .history-icon { position: absolute; left: 0; top: 0; display: grid; place-items: center; width: 34px; height: 34px; border-radius: 50%; color: var(--event-color); background: var(--event-bg); font-size: 1rem; }
.distribution-workflow .history-card { border: 1px solid #e1e7f0; border-radius: 10px; padding: .85rem 1rem; background: #fff; min-width: 0; }
.distribution-workflow .history-event-head { display: flex; justify-content: space-between; align-items: baseline; flex-wrap: wrap; gap: .35rem .75rem; }
.distribution-workflow .history-event-title { display: flex; align-items: center; flex-wrap: wrap; gap: .5rem; min-width: 0; }
.distribution-workflow .history-event-title strong { font-size: .9rem; color: #253858; }
.distribution-workflow .history-cycle { border-radius: 5px; padding: .15rem .45rem; background: #f0f3f8; color: #52617a; font-size: .7rem; font-weight: 600; }
.distribution-workflow .history-date, .distribution-workflow .history-actor { color: #687791; font-size: .76rem; }
.distribution-workflow .history-actor { margin-top: .3rem; overflow-wrap: anywhere; }
.distribution-workflow .history-statuses { display: flex; align-items: center; flex-wrap: wrap; gap: .4rem .65rem; margin-top: .65rem; font-size: .73rem; }
.distribution-workflow .history-status { background: var(--event-bg); color: var(--event-color); border-radius: 5px; padding: .2rem .5rem; font-weight: 600; }
.distribution-workflow .history-authorization { color: #687791; }
.distribution-workflow .history-feedback { margin-top: .75rem; border-left: 3px solid var(--event-color); border-radius: 0 6px 6px 0; background: var(--event-bg); padding: .6rem .75rem; }
.distribution-workflow .history-feedback-label { display: block; font-size: .7rem; font-weight: 700; color: var(--event-color); margin-bottom: .2rem; }
.distribution-workflow .history-feedback p { margin: 0; color: #34405b; font-size: .85rem; white-space: pre-wrap; overflow-wrap: anywhere; }
@media (max-width: 575.98px) {
    .distribution-workflow .history-event { padding-left: 2.65rem; }
    .distribution-workflow .history-card { padding: .75rem; }
    .distribution-workflow .history-date { flex-basis: 100%; }
}
</style>
<?= view('components/distribution_picker_styles') ?>
<main id="main" class="main distribution-workflow">
    <div class="pagetitle"><h1><?= $isDepartment ? 'My Requests' : 'Distribution Workflow' ?></h1><nav><ol class="breadcrumb"><li class="breadcrumb-item"><a href="<?= esc(site_url('dashboard')) ?>">Dashboard</a></li><li class="breadcrumb-item active"><?= $isDepartment ? 'My Requests' : 'Distribution Workflow' ?></li></ol></nav></div>
    <section class="section">
        <?php if ($success): ?><div class="alert alert-success" role="alert" data-auto-dismiss><?= esc($success) ?></div><?php endif; ?>
        <?php if ($error): ?><div class="alert alert-danger" role="alert" data-auto-dismiss><?= esc($error) ?></div><?php endif; ?>

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
                            <thead><tr><th>Transaction</th><th>Request</th><th>Requested By</th><th>Cycle</th><th>Status / Authorization</th><th class="text-end">Action</th></tr></thead>
                            <tbody>
                            <?php foreach ($requests as $request): ?>
                                <?php $status = (string) $request['status']; $requestId = (int) $request['id']; ?>
                                <tr id="request-<?= $requestId ?>" class="<?= ($focusedRequestId ?? null) === $requestId ? 'request-row-focus' : '' ?>">
                                    <td><strong><?= esc($request['transaction_code'] ?? ('TRX-' . $requestId)) ?></strong><div class="small text-muted"><?= esc($request['department']) ?></div></td>
                                    <td class="request-details"><strong><?= esc($request['title']) ?></strong><?php if (! empty($request['description'])): ?><div class="small text-muted request-description"><?= esc($request['description']) ?></div><?php endif; ?><div class="small text-muted"><?= esc(\App\Libraries\DisplayTime::format($request['submitted_at'], 'M j, Y g:i A')) ?></div></td>
                                    <td><?= esc($request['requester_name'] ?: 'Department user') ?></td>
                                    <td><span class="badge bg-light text-dark">Cycle <?= (int) ($request['current_cycle'] ?? 0) ?></span></td>
                                    <td><span class="badge <?= $status === 'complete' ? 'bg-success-subtle text-success' : ($status === 'pending_incomplete' ? 'bg-danger-subtle text-danger' : 'bg-warning-subtle text-warning') ?>"><?= esc($status === 'pending_incomplete' ? 'Pending – Incomplete' : ucfirst($status)) ?></span><div class="small mt-1"><?= ($request['authorization_status'] ?? 'not_authorized') === 'authorized' ? '<span class="text-success">Authorized</span>' : '<span class="text-muted">Not Authorized</span>' ?></div></td>
                                    <td class="text-end text-nowrap">
                                        <?php if ($canManage && in_array($status, ['submitted', 'pending_incomplete'], true)): ?>
                                            <button class="btn btn-primary btn-sm" type="button" data-bs-toggle="modal" data-bs-target="#serverTransmitModal" data-mode="request" data-action="<?= esc(site_url('workspace/distribution/requests/' . $requestId . '/transmit-server'), 'attr') ?>" data-request-id="<?= $requestId ?>" data-request-title="<?= esc($request['title'], 'attr') ?>" data-request-user="<?= esc($request['requester_name'] ?: 'Department user', 'attr') ?>" data-department="<?= esc($request['department'], 'attr') ?>"><?= $status === 'pending_incomplete' ? 'Send corrections' : 'Transmit' ?></button>
                                        <?php endif; ?>
                                        <?php if (($canManage || $isDepartment) && in_array($status, ['pending', 'pending_incomplete', 'complete'], true)): ?>
                                            <button class="btn btn-outline-primary btn-sm" type="button" data-bs-toggle="modal" data-bs-target="#requestPreviewModal" data-request-id="<?= $requestId ?>" data-request-title="<?= esc($request['title'], 'attr') ?>" data-request-user="<?= esc($request['requester_name'] ?: 'Department user', 'attr') ?>" data-department="<?= esc($request['department'], 'attr') ?>" data-status="<?= esc(ucfirst($status), 'attr') ?>" data-authorization="<?= esc((string) ($request['authorization_status'] ?? 'not_authorized'), 'attr') ?>">Preview</button>
                                        <?php endif; ?>
                                        <?php if ($isDepartment && $status === 'pending'): ?>
                                            <button class="btn btn-outline-danger btn-sm" type="button" data-bs-toggle="modal" data-bs-target="#reviewModal" data-review-action="<?= esc(site_url('workspace/distribution/requests/' . $requestId . '/incomplete'), 'attr') ?>" data-review-kind="incomplete">Mark as Incomplete</button>
                                            <button class="btn btn-success btn-sm" type="button" data-bs-toggle="modal" data-bs-target="#reviewModal" data-review-action="<?= esc(site_url('workspace/distribution/requests/' . $requestId . '/complete'), 'attr') ?>" data-review-kind="complete">Confirm Complete</button>
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
                            <div class="col-md-5">
                                <label class="form-label" for="directDepartmentToggle">Department</label>
                                <div class="dropdown direct-department-picker">
                                    <input type="hidden" id="directDepartment" value="">
                                    <button class="form-select text-start" type="button" id="directDepartmentToggle" data-bs-toggle="dropdown" data-bs-display="static" aria-expanded="false" aria-controls="directDepartmentMenu" <?= $departments === [] ? 'disabled' : '' ?>><?= $departments === [] ? 'No departments available' : 'Select department' ?></button>
                                    <ul class="dropdown-menu" id="directDepartmentMenu" aria-labelledby="directDepartmentToggle">
                                        <?php foreach ($departments as $department): ?>
                                            <li><button class="dropdown-item" type="button" data-direct-department="<?= esc($department['department'], 'attr') ?>"><?= esc($department['department']) ?></button></li>
                                        <?php endforeach; ?>
                                    </ul>
                                </div>
                            </div>
                            <div class="col-md-7"><button class="btn btn-primary" type="button" id="openDirectPicker" data-mode="direct" data-action="<?= esc(site_url('workspace/distribution/direct-send-server'), 'attr') ?>">Choose Media &amp; Preview</button></div>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
            <div class="card"><div class="card-body pt-3"><div class="table-responsive"><table class="table"><thead><tr><th>Transaction</th><th>Department</th><th>Files</th><th>Sender</th><th>Status / Authorization</th><th>Action</th></tr></thead><tbody><?php foreach ($directSends as $send): $directId=(int)$send['id']; $directStatus=(string)$send['status']; ?><tr><td><strong><?= esc($send['transaction_code']) ?></strong><div class="small text-muted">Cycle <?= (int)$send['current_cycle'] ?></div></td><td><?= esc($send['department']) ?></td><td><?= (int) $send['file_count'] ?></td><td><strong><?= esc($send['sender_name'] ?: 'Unknown user') ?></strong><div class="small text-muted"><?= esc(\App\Libraries\DisplayTime::format($send['sent_at'], 'M j, Y g:i A')) ?></div></td><td><span class="badge <?= $directStatus==='complete'?'bg-success-subtle text-success':($directStatus==='pending_incomplete'?'bg-danger-subtle text-danger':'bg-warning-subtle text-warning') ?>"><?= esc($directStatus==='pending_incomplete'?'Pending – Incomplete':ucfirst($directStatus)) ?></span><div class="small"><?= ($send['authorization_status']??'')==='authorized'?'Authorized':'Not Authorized' ?></div></td><td class="text-nowrap"><button class="btn btn-outline-primary btn-sm" type="button" data-bs-toggle="modal" data-bs-target="#requestPreviewModal" data-request-id="<?= $directId ?>" data-request-title="Direct Send" data-department="<?= esc($send['department'], 'attr') ?>" data-status="<?= esc($directStatus, 'attr') ?>" data-authorization="<?= esc((string) ($send['authorization_status'] ?? 'not_authorized'), 'attr') ?>">Preview</button><?php if($isDepartment&&$directStatus==='pending'): ?> <button class="btn btn-outline-danger btn-sm" data-bs-toggle="modal" data-bs-target="#reviewModal" data-review-action="<?= esc(site_url('workspace/distribution/requests/'.$directId.'/incomplete'),'attr') ?>" data-review-kind="incomplete">Incomplete</button> <button class="btn btn-success btn-sm" data-bs-toggle="modal" data-bs-target="#reviewModal" data-review-action="<?= esc(site_url('workspace/distribution/requests/'.$directId.'/complete'),'attr') ?>" data-review-kind="complete">Confirm Complete</button><?php endif; ?><?php if($canManage&&$directStatus==='pending_incomplete'): ?> <button class="btn btn-primary btn-sm" type="button" data-bs-toggle="modal" data-bs-target="#serverTransmitModal" data-mode="request" data-action="<?= esc(site_url('workspace/distribution/requests/'.$directId.'/transmit-server'),'attr') ?>" data-request-title="Direct Send corrections" data-department="<?= esc($send['department'],'attr') ?>">Send corrections</button><?php endif; ?></td></tr><?php endforeach; ?><?php if ($directSends === []): ?><tr><td colspan="6" class="text-center text-muted py-5">No direct sends found.</td></tr><?php endif; ?></tbody></table></div></div></div>
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
                <div class="modal-footer"><button class="btn btn-outline-primary btn-sm" type="button" id="pickerSelectAll">Select all on page</button><button class="btn btn-light btn-sm" type="button" id="pickerClear">Clear selection</button><span class="picker-footer-summary" id="pickerSelectedCount" aria-live="polite">0 folders &bull; 0 files selected</span><button class="btn btn-light d-none" type="button" id="previewBack">Back</button><button class="btn btn-primary" type="button" id="continuePreview">Continue</button><button class="btn btn-primary d-none" type="submit" id="finalTransmit">Transmit</button></div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>
<div class="modal fade" id="requestPreviewModal" tabindex="-1" aria-labelledby="requestPreviewTitle" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header"><div><h5 class="modal-title" id="requestPreviewTitle">Transmitted media</h5><div class="small text-muted" id="requestPreviewContext"></div></div><button class="btn-close" type="button" data-bs-dismiss="modal" aria-label="Close"></button></div>
            <div class="modal-body">
                <div class="alert alert-secondary py-2" id="requestAuthorizationNotice"></div>
                <p class="small text-muted">Media is grouped by the cycle in which it was first sent. Later cycles show additional files sent for corrections.</p>
                <div class="readonly-preview-list" id="requestPreviewGrid"></div>
                <section aria-labelledby="requestHistoryTitle">
                    <div class="history-header"><h6 id="requestHistoryTitle">Transaction history</h6><span>Latest activity first</span></div>
                    <ol class="history-timeline" id="requestHistory"></ol>
                </section>
            </div>
            <div class="modal-footer"><button class="btn btn-secondary" type="button" data-bs-dismiss="modal">Close</button></div>
        </div>
    </div>
</div>
<?php if ($isDepartment): ?>
<div class="modal fade" id="reviewModal" tabindex="-1" aria-hidden="true"><div class="modal-dialog modal-dialog-centered"><div class="modal-content"><form id="reviewForm" method="post"><?= csrf_field() ?><div class="modal-header"><h5 class="modal-title" id="reviewTitle">Review transaction</h5><button class="btn-close" type="button" data-bs-dismiss="modal"></button></div><div class="modal-body"><p id="reviewHelp"></p><label class="form-label" for="reviewFeedback" id="reviewFeedbackLabel">Feedback *</label><textarea class="form-control" id="reviewFeedback" name="feedback" rows="5" maxlength="4000" required></textarea><div class="form-text">This feedback is permanent and will be added to the transaction history.</div></div><div class="modal-footer"><button class="btn btn-secondary" type="button" data-bs-dismiss="modal">Cancel</button><button class="btn" id="reviewSubmit" type="submit">Submit</button></div></form></div></div></div>
<?php endif; ?>
<script>
document.addEventListener('DOMContentLoaded', function () {
    var files = <?= json_encode($mediaFiles, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>;
    var folders = <?= json_encode($mediaFolders, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>;
    var requestFiles = <?= json_encode($requestPreviewFiles, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>;
    var requestHistory = <?= json_encode($historyByRequest ?? [], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>;
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
    function visibleFolders() { return searchTerm ? folders.filter(folderMatches) : (childrenByParent[currentFolder || 0] || []); }
    function visibleFiles() { return searchTerm ? files.filter(fileMatches) : filesInFolder(currentFolder); }
    function renderThumb(file) {
        var icon = file.preview_kind === 'video' ? 'bi-film' : (file.preview_kind === 'image' ? 'bi-image' : 'bi-file-earmark');
        var fallback = '<i class="bi ' + icon + '" aria-hidden="true"></i>';
        if (!file.preview_url) return '<span class="mini-thumb">' + fallback + '</span>';
        return '<span class="mini-thumb transaction-thumbnail">' + fallback + '<img src="' + h(file.preview_url) + '" alt="' + h(file.title || 'Media preview') + '" loading="lazy" decoding="async" onerror="this.hidden=true">' + (file.preview_kind === 'video' ? '<span class="transaction-video-badge"><i class="bi bi-play-fill"></i> Video</span>' : '') + '</span>';
    }
    function updateSelectedCount() {
        var fileCount = selectedFileObjects().length;
        var folderCount = selectedFolders.size;
        document.getElementById('pickerSelectedCount').innerHTML = folderCount + ' ' + (folderCount === 1 ? 'folder' : 'folders') + ' &bull; ' + fileCount + ' ' + (fileCount === 1 ? 'file' : 'files') + ' selected';
        var shownFolders = visibleFolders();
        var shownFiles = visibleFiles();
        var hasVisibleItems = shownFolders.length + shownFiles.length > 0;
        var allVisibleSelected = hasVisibleItems && shownFolders.every(function (folder) { return selectedFolders.has(folder.id); }) && shownFiles.every(function (file) { return selectedFiles.has(file.id); });
        document.getElementById('pickerClear').disabled = !selectedFiles.size && !selectedFolders.size;
        document.getElementById('continuePreview').disabled = fileCount === 0;
        document.getElementById('pickerSelectAll').disabled = !hasVisibleItems;
        document.getElementById('pickerSelectAll').textContent = allVisibleSelected ? 'Unselect all on page' : 'Select all on page';
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
        var fileCount = Number(folder.file_count || 0);
        var subfolderCount = Number(folder.subfolder_count || 0);
        return '<button class="mini-tile' + (selected ? ' is-selected' : '') + '" type="button" data-picker-folder="' + folder.id + '"><span class="mini-select-mark"><i class="bi bi-check2"></i></span><span class="mini-thumb"><i class="bi bi-folder-fill"></i></span><span class="mini-name">' + h(folder.name) + '</span><span class="mini-meta">' + fileCount + ' ' + (fileCount === 1 ? 'file' : 'files') + ' &bull; ' + subfolderCount + ' ' + (subfolderCount === 1 ? 'folder' : 'folders') + '</span></button>';
    }
    function fileTile(file) {
        var selected = tileSelected('file', file.id);
        return '<button class="mini-tile' + (selected ? ' is-selected' : '') + '" type="button" data-picker-file="' + file.id + '">' + '<span class="mini-select-mark"><i class="bi bi-check2"></i></span>' + renderThumb(file) + '<span class="mini-name" title="' + h(file.title) + '">' + h(file.title) + '</span><span class="mini-meta">' + h(file.filetype || 'FILE') + (file.size ? ' - ' + h(formatBytes(file.size)) : '') + '</span></button>';
    }
    function renderPicker() {
        var folderGrid = document.getElementById('pickerFolderGrid');
        var fileGrid = document.getElementById('pickerFileGrid');
        var foldersToShow = visibleFolders();
        var filesToShow = visibleFiles();
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
            back.classList.toggle('d-none', currentFolder === null);
            back.textContent = 'Back';
        }
        updateSelectedCount();
    }
    function showPickerStep() {
        document.getElementById('pickerStep').classList.remove('d-none');
        document.getElementById('previewStep').classList.add('d-none');
        document.getElementById('continuePreview').classList.remove('d-none');
        document.getElementById('finalTransmit').classList.add('d-none');
        document.getElementById('pickerSelectAll').classList.remove('d-none');
        document.getElementById('pickerClear').classList.remove('d-none');
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
        document.getElementById('continuePreview').classList.add('d-none');
        document.getElementById('finalTransmit').classList.remove('d-none');
        document.getElementById('pickerSelectAll').classList.add('d-none');
        document.getElementById('pickerClear').classList.add('d-none');
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
        window.GreyhoundSearch.attach(document.getElementById('miniMediaSearch'), function (term) {
            return folders.map(function (folder) { return folder.name; }).concat(files.map(function (file) { return file.filename || file.title; }))
                .filter(function (name) { return name && name.toLowerCase().includes(term.toLowerCase()); });
        }, function (value) { searchTerm = value.trim().toLowerCase(); renderPicker(); });
        document.getElementById('pickerClear').addEventListener('click', function () { selectedFiles.clear(); selectedFolders.clear(); renderPicker(); });
        document.getElementById('pickerSelectAll').addEventListener('click', function () {
            var shownFolders = visibleFolders();
            var shownFiles = visibleFiles();
            var shouldSelect = !(shownFolders.length + shownFiles.length > 0 && shownFolders.every(function (folder) { return selectedFolders.has(folder.id); }) && shownFiles.every(function (file) { return selectedFiles.has(file.id); }));
            shownFolders.forEach(function (folder) { if (shouldSelect) selectedFolders.add(folder.id); else selectedFolders.delete(folder.id); });
            shownFiles.forEach(function (file) { if (shouldSelect) selectedFiles.add(file.id); else selectedFiles.delete(file.id); });
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
    var departmentToggle = document.getElementById('directDepartmentToggle');
    var departmentOptions = document.querySelectorAll('[data-direct-department]');
    departmentOptions.forEach(function (option) {
        option.addEventListener('click', function () {
            document.getElementById('directDepartment').value = option.dataset.directDepartment;
            departmentToggle.textContent = option.dataset.directDepartment;
            departmentOptions.forEach(function (item) {
                item.classList.toggle('active', item === option);
                if (item === option) item.setAttribute('aria-current', 'true');
                else item.removeAttribute('aria-current');
            });
            bootstrap.Dropdown.getOrCreateInstance(departmentToggle).hide();
            departmentToggle.focus();
        });
    });
    var directButton = document.getElementById('openDirectPicker');
    if (directButton) {
        directButton.addEventListener('click', function () {
            var department = document.getElementById('directDepartment').value;
            if (!department) {
                alert('Select a department first.');
                return;
            }
            directButton.dataset.department = department;
            var serverPicker = document.getElementById('serverTransmitModal');
            if (serverPicker) bootstrap.Modal.getOrCreateInstance(serverPicker).show(directButton);
        });
    }
    var previewModal = document.getElementById('requestPreviewModal');
    previewModal.addEventListener('show.bs.modal', function (event) {
        var trigger = event.relatedTarget;
        var id = trigger.dataset.requestId || '';
        var transmitted = requestFiles[id] || [];
        document.getElementById('requestPreviewTitle').textContent = trigger.dataset.requestTitle || 'Transmitted media';
        document.getElementById('requestPreviewContext').textContent = (trigger.dataset.department || '') + ' - ' + (trigger.dataset.requestUser || 'Department user') + ' - ' + (trigger.dataset.status || '');
        var filesByCycle = {};
        transmitted.forEach(function (file) {
            var cycle = Math.max(1, parseInt(file.cycle_number, 10) || 1);
            if (!filesByCycle[cycle]) filesByCycle[cycle] = [];
            filesByCycle[cycle].push(file);
        });
        document.getElementById('requestPreviewGrid').innerHTML = Object.keys(filesByCycle).sort(function (a, b) { return Number(a) - Number(b); }).map(function (cycle) {
            var cycleFiles = filesByCycle[cycle];
            var label = cycle === '1' ? 'First cycle (Cycle 1)' : (cycle === '2' ? 'Second cycle (Cycle 2)' : 'Cycle ' + cycle);
            var badgeClass = cycle === '1' ? 'bg-primary-subtle text-primary' : 'bg-success-subtle text-success';
            var cards = cycleFiles.map(function (file) {
                return '<div class="preview-media-card">' + renderThumb(file) + '<div class="small"><span class="badge ' + badgeClass + ' mb-1">Cycle ' + h(cycle) + '</span><strong class="d-block text-truncate">' + h(file.title) + '</strong><span class="text-muted text-truncate d-block">' + h(file.folder || 'Root library') + '</span></div></div>';
            }).join('');
            return '<section class="mb-3"><h6>' + h(label) + ' <span class="small text-muted">&middot; ' + cycleFiles.length + ' ' + (cycleFiles.length === 1 ? 'file' : 'files') + '</span></h6><div class="preview-media-grid">' + cards + '</div></section>';
        }).join('') || '<div class="text-muted">No transmitted files were recorded for this request.</div>';
        var normalizedStatus = String(trigger.dataset.status || '').toLowerCase();
        var normalizedAuthorization = String(trigger.dataset.authorization || '').toLowerCase();
        var authorized = normalizedAuthorization === 'authorized' || normalizedStatus === 'complete' || normalizedStatus === 'completed';
        document.getElementById('requestAuthorizationNotice').className = 'alert py-2 ' + (authorized ? 'alert-success' : 'alert-warning');
        document.getElementById('requestAuthorizationNotice').textContent = authorized ? 'Authorized — Available in Department File Management' : 'Not Authorized — Not yet available in Department File Management';
        document.getElementById('requestHistory').innerHTML = (requestHistory[id] || []).map(function (entry) {
            var action = String(entry.display_action || entry.action || '').toLowerCase();
            var actions = {
                submitted: ['Request submitted', 'bi-file-earmark-text', 'is-submitted'],
                original: ['Initial files sent', 'bi-send', ''],
                transmitted: ['Files sent', 'bi-send', ''],
                direct_sent: ['Files sent directly', 'bi-send', ''],
                resubmission: ['Corrections sent', 'bi-arrow-repeat', ''],
                marked_incomplete: ['Corrections requested', 'bi-exclamation-circle', 'is-incomplete'],
                confirmed_complete: ['Completed', 'bi-check-lg', 'is-complete']
            };
            var detail = Object.prototype.hasOwnProperty.call(actions, action) ? actions[action] : [action.replaceAll('_', ' ') || 'Activity recorded', 'bi-clock-history', 'is-submitted'];
            var statuses = { submitted: 'Awaiting files', pending: 'Awaiting review', transmitted: 'Awaiting review', sent: 'Awaiting review', pending_incomplete: 'Needs corrections', complete: 'Complete', completed: 'Complete' };
            var status = Object.prototype.hasOwnProperty.call(statuses, entry.transaction_status) ? statuses[entry.transaction_status] : String(entry.transaction_status || '').replaceAll('_', ' ');
            var authorization = entry.authorization_status === 'authorized' ? 'Authorized' : (entry.authorization_status === 'not_authorized' ? 'Not yet authorized' : String(entry.authorization_status || '').replaceAll('_', ' '));
            var feedbackLabel = action === 'marked_incomplete' ? 'What needs to change' : (action === 'confirmed_complete' ? 'Completion feedback' : 'Feedback');
            var feedback = entry.feedback ? '<div class="history-feedback"><span class="history-feedback-label">' + feedbackLabel + '</span><p>' + h(entry.feedback) + '</p></div>' : '';
            var cycleLabel = entry.display_cycle ? 'Cycle ' + entry.display_cycle : 'Request';
            var roles = { admin: 'Admin', department: 'Department', creative: 'Creative staff', creative_staff: 'Creative staff' };
            var role = Object.prototype.hasOwnProperty.call(roles, entry.actor_role) ? roles[entry.actor_role] : String(entry.actor_role || '').replaceAll('_', ' ');
            var rawDate = String(entry.created_at || '').trim();
            var utcDate = /^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}(?::\d{2}(?:\.\d+)?)?$/.test(rawDate)
                ? rawDate.replace(' ', 'T') + 'Z' : rawDate;
            var date = new Date(utcDate);
            var dateText = Number.isNaN(date.getTime()) ? 'Date unavailable' :
                date.toLocaleDateString('en-US', { timeZone: 'Asia/Manila', month: 'short', day: 'numeric', year: 'numeric' }) + ' · ' +
                date.toLocaleTimeString('en-US', { timeZone: 'Asia/Manila', hour: 'numeric', minute: '2-digit' }) + ' PHT';
            var statusLine = status || authorization ? '<div class="history-statuses">' + (status ? '<span class="history-status">' + h(status) + '</span>' : '') + (authorization ? '<span class="history-authorization">' + h(authorization) + '</span>' : '') + '</div>' : '';
            return '<li class="history-event ' + detail[2] + '"><span class="history-icon" aria-hidden="true"><i class="bi ' + detail[1] + '"></i></span><div class="history-card"><div class="history-event-head"><div class="history-event-title"><strong>' + h(detail[0]) + '</strong><span class="history-cycle">' + h(cycleLabel) + '</span></div><span class="history-date" title="' + h(dateText) + '">' + h(dateText) + '</span></div><div class="history-actor">' + h(entry.actor_name || 'Unknown user') + (role ? ' · ' + h(role) : '') + '</div>' + statusLine + feedback + '</div></li>';
        }).join('') || '<li class="text-muted small py-3">No activity yet. Updates will appear here as this request progresses.</li>';
    });
    var reviewModal = document.getElementById('reviewModal');
    if (reviewModal) reviewModal.addEventListener('show.bs.modal', function (event) {
        var trigger = event.relatedTarget, incomplete = trigger.dataset.reviewKind === 'incomplete';
        document.getElementById('reviewForm').action = trigger.dataset.reviewAction;
        document.getElementById('reviewTitle').textContent = incomplete ? 'Mark as Incomplete' : 'Confirm Complete';
        document.getElementById('reviewHelp').textContent = incomplete ? 'Explain what files or information are missing or incorrect.' : 'Confirm that every required file has been received and verified.';
        document.getElementById('reviewFeedbackLabel').textContent = incomplete ? 'Reason / Feedback *' : 'Completion Feedback *';
        document.getElementById('reviewSubmit').className = 'btn ' + (incomplete ? 'btn-danger' : 'btn-success');
        document.getElementById('reviewSubmit').textContent = incomplete ? 'Mark as Incomplete' : 'Confirm Complete';
        document.getElementById('reviewFeedback').value = '';
    });
});
</script>
</div>
<?php endif; ?>
<?php if ($canManage): ?><?= view('distribution/server_picker') ?><?php endif; ?>
<?= view('layouts/app_footer') ?>
