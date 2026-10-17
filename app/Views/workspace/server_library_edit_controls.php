<div class="bulk-toolbar" id="serverSelectionToolbar">
    <div class="bulk-selection">
        <label class="btn btn-sm btn-outline-primary mb-0" title="Select all loaded files"><input type="checkbox" class="form-check-input me-1" id="serverSelectAll"> <span id="serverSelectAllLabel">Select all</span></label>
        <span id="serverSelectedCount" class="bulk-selection-count" role="status" hidden>0 selected</span>
    </div>
    <div class="bulk-actions" id="serverSelectionActions" hidden>
    <button class="btn btn-sm btn-outline-primary" type="button" data-server-edit="download" data-server-selection disabled>Download</button>
    <?php if ($canManage): ?>
    <?php foreach (['rename'=>'Rename','move'=>'Move','tags'=>'Add tags','post'=>'Post','trash'=>'Trash'] as $action=>$label): ?>
    <button class="btn btn-sm btn-outline-<?= $action === 'trash' ? 'danger' : 'primary' ?>" type="button" data-server-edit="<?= $action ?>" data-server-selection disabled><?= $label ?></button>
    <?php endforeach; ?>
    <?php endif; ?>
    <?php if ($canSend): ?><button class="btn btn-sm btn-primary" type="button" data-server-edit="send" data-server-selection disabled>Send for review</button><?php endif; ?>
    </div>
</div>
<div class="modal fade" id="serverEditModal" tabindex="-1" aria-labelledby="serverEditTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered"><div class="modal-content">
        <form id="serverEditForm" method="post" action="<?= esc(site_url('workspace/server-library/edit')) ?>" data-edit-url="<?= esc(site_url('workspace/server-library/edit')) ?>" data-send-url="<?= esc(site_url('workspace/server-library/send')) ?>" data-post-url="<?= esc(site_url('workspace/server-library/post')) ?>" data-download-url="<?= esc(site_url('workspace/server-library/download-selected')) ?>" data-no-ajax>
            <?= csrf_field() ?><input type="hidden" name="folder" value="<?= esc($folder) ?>"><input type="hidden" name="action" id="serverEditAction"><div id="serverEditPaths"></div>
            <div class="modal-header"><h5 class="modal-title" id="serverEditTitle">Edit server item</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
            <div class="modal-body">
                <p id="serverEditSummary" class="text-muted text-break"></p>
                <div data-server-field="name"><label class="form-label" for="serverEditName">Name</label><input class="form-control" id="serverEditName" name="name" maxlength="240" autocomplete="off" required></div>
                <div data-server-field="destination" hidden><label class="form-label" for="serverEditDestination">Destination folder</label><input class="form-control" id="serverEditDestination" name="destination" placeholder="e.g. Events/2026"><div class="form-text">Enter an existing folder path from Server root, or leave blank for Server root.</div></div>
                <div data-server-field="tags" hidden><label class="form-label" for="serverEditTagInput">Tags</label><input class="form-control" id="serverEditTagInput" placeholder="Enter a tag and press Enter" maxlength="50" autocomplete="off"><input type="hidden" name="tags" id="serverEditTags"><div class="d-flex flex-wrap gap-2 mt-2" id="serverEditTagChips"></div><div class="form-text">Press Enter or comma to add. Up to 25 tags, 50 characters each. Duplicates are ignored.</div></div>
                <div data-server-field="department" hidden><label class="form-label" for="serverEditDepartment">Department</label><select class="form-select" id="serverEditDepartment" name="department" required><option value="">Choose department</option><?php foreach ($departments as $department): ?><option value="<?= esc($department['department']) ?>"><?= esc($department['department']) ?></option><?php endforeach; ?></select><div class="form-text">Creates a review copy for the department. Up to 20 files per submission.</div></div>
                <p id="serverEditError" class="text-danger mt-2 mb-0" role="alert" hidden></p>
                <div data-server-field="post" hidden><label class="form-label" for="serverPostTitle">Post title</label><input class="form-control" id="serverPostTitle" name="display_title" maxlength="150" required><label class="form-label mt-3" for="serverPostDescription">Description (optional)</label><textarea class="form-control" id="serverPostDescription" name="description" maxlength="5000" rows="3"></textarea><div class="form-text">Sends copies of up to 20 photos or videos to GreyhoundHub for app admins to edit and publish.</div></div>
            </div>
            <div class="modal-footer"><button class="btn btn-light" type="button" data-bs-dismiss="modal">Cancel</button><button class="btn btn-primary" type="submit" id="serverEditSubmit">Save</button></div>
        </form>
    </div></div>
</div>
