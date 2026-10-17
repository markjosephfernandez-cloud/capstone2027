<script>
document.addEventListener('DOMContentLoaded', function () {
    var root = document.getElementById('serverLibrary');
    var form = document.getElementById('serverEditForm');
    if (!root || !form) return;
    var modal = document.getElementById('serverEditModal');
    var action = document.getElementById('serverEditAction');
    var submit = document.getElementById('serverEditSubmit');
    var error = document.getElementById('serverEditError');
    var tagInput = document.getElementById('serverEditTagInput');
    var tags = [];
    var labels = {'create-folder':'New folder',rename:'Rename',move:'Move',tags:'Add tags',trash:'Move to Trash',send:'Send for review',post:'Post to Mobile App',download:'Download selected files'};
    function selected() { return Array.from(root.querySelectorAll('[data-server-select]:checked')).map(function (node) { return node.value; }); }
    function syncSelection() {
        var count = selected().length;
        document.getElementById('serverSelectedCount').textContent = count + ' selected';
        document.getElementById('serverSelectedCount').hidden = count === 0;
        document.getElementById('serverSelectionActions').hidden = count === 0;
        document.getElementById('serverSelectionToolbar').className = 'bulk-toolbar' + (count > 0 ? ' has-selection' : '');
        root.querySelectorAll('[data-server-selection]').forEach(function (button) { button.disabled = count === 0 || (button.dataset.serverEdit === 'rename' && count !== 1); });
        var all = document.getElementById('serverSelectAll');
        var total = root.querySelectorAll('[data-server-select]').length;
        all.checked = total > 0 && count === total; all.indeterminate = count > 0 && count < total;
        document.getElementById('serverSelectAllLabel').textContent = all.checked ? 'Unselect all' : 'Select all';
    }
    root.addEventListener('change', function (event) {
        if (event.target.id === 'serverSelectAll') root.querySelectorAll('[data-server-select]').forEach(function (box) { box.checked = event.target.checked; });
        syncSelection();
    });
    // Delegation also covers newly loaded file cards.
    root.addEventListener('click', function (event) {
        var button = event.target.closest('[data-server-edit]');
        if (!button || button.disabled) return;
        var operation = button.dataset.serverEdit;
        var paths = button.dataset.path ? [button.dataset.path] : selected();
        if (operation !== 'create-folder' && !paths.length) return;
        form.reset(); tags = []; renderTags();
        modal.dataset.operation = operation;
        action.value = operation; error.hidden = true; submit.disabled = false;
        form.action = {send:form.dataset.sendUrl,post:form.dataset.postUrl,download:form.dataset.downloadUrl}[operation] || form.dataset.editUrl;
        form.method = operation === 'download' ? 'get' : 'post';
        form.querySelector('input[name="<?= csrf_token() ?>"]').disabled = operation === 'download';
        document.getElementById('serverEditTitle').textContent = labels[operation];
        submit.textContent = operation === 'create-folder' ? 'Create' : (operation === 'tags' ? 'Save tags' : labels[operation]);
        submit.className = 'btn ' + (operation === 'trash' ? 'btn-danger' : 'btn-primary');
        document.getElementById('serverEditSummary').textContent = operation === 'trash'
            ? 'Move ' + paths.length + ' item(s) to Trash? You can restore them later.'
            : (paths.length === 1 ? paths[0] : (paths.length ? paths.length + ' selected files' : 'Create a folder in the current server location.'));
        var fields = {'create-folder':'name',rename:'name',move:'destination',tags:'tags',send:'department',post:'post'};
        form.querySelectorAll('[data-server-field]').forEach(function (group) {
            group.hidden = group.dataset.serverField !== fields[operation];
            group.querySelectorAll('input, select, textarea').forEach(function (input) { input.disabled = group.hidden; });
        });
        document.getElementById('serverEditName').value = operation === 'rename' ? paths[0].split('/').pop() : (operation === 'create-folder' ? 'Untitled folder' : '');
        var pathInputs = document.getElementById('serverEditPaths'); pathInputs.replaceChildren();
        paths.forEach(function (path) { var input = document.createElement('input'); input.type = 'hidden'; input.name = 'paths[]'; input.value = path; pathInputs.appendChild(input); });
        bootstrap.Modal.getOrCreateInstance(modal).show();
    });
    function renderTags() {
        document.getElementById('serverEditTags').value = tags.join(', ');
        var chips = document.getElementById('serverEditTagChips'); chips.replaceChildren();
        tags.forEach(function (tag, index) {
            var chip = document.createElement('button'); chip.type = 'button'; chip.className = 'btn btn-sm btn-light border';
            chip.textContent = tag + ' ×'; chip.setAttribute('aria-label', 'Remove tag ' + tag);
            chip.addEventListener('click', function () { tags.splice(index, 1); renderTags(); }); chips.appendChild(chip);
        });
    }
    function addTags() {
        var next = tags.slice();
        for (var value of tagInput.value.split(',')) {
            var tag = value.trim();
            if (!tag) continue;
            if (tag.length > 50 || next.length >= 25 && !next.some(function (item) { return item.toLowerCase() === tag.toLowerCase(); })) {
                error.textContent = 'Use up to 25 tags, with no more than 50 characters each.'; error.hidden = false; return false;
            }
            if (!next.some(function (item) { return item.toLowerCase() === tag.toLowerCase(); })) next.push(tag);
        }
        tags = next; tagInput.value = ''; error.hidden = true; renderTags(); return true;
    }
    tagInput.addEventListener('keydown', function (event) { if (!event.isComposing && (event.key === 'Enter' || event.key === ',')) { event.preventDefault(); addTags(); } });
    tagInput.addEventListener('input', function (event) { if (!event.isComposing && tagInput.value.includes(',')) addTags(); });
    form.addEventListener('submit', function (event) {
        if (action.value === 'tags' && (!addTags() || tags.length === 0)) { event.preventDefault(); error.textContent = 'Enter at least one valid tag.'; error.hidden = false; return; }
        if (['send', 'post'].includes(action.value) && form.querySelectorAll('input[name="paths[]"]').length > 20) { event.preventDefault(); error.textContent = 'Select no more than 20 files to send or post.'; error.hidden = false; return; }
        if (action.value === 'download') { bootstrap.Modal.getOrCreateInstance(modal).hide(); return; }
        submit.disabled = true; submit.textContent = 'Saving…';
    });
    root.addEventListener('server:files-loaded', syncSelection);
    syncSelection();
});
</script>
