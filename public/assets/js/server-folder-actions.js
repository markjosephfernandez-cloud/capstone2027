(function () {
    'use strict';
    const toolbar = document.getElementById('folderSelectionToolbar');
    if (!toolbar || toolbar.dataset.ready) return;
    toolbar.dataset.ready = '1';
    const boxes = Array.from(document.querySelectorAll('[data-folder-select]'));
    const all = document.getElementById('toggleFolderSelection');
    const move = document.getElementById('openFolderMove');
    const trash = document.getElementById('openFolderTrash');
    const modal = document.getElementById('folderActionModal');
    const form = document.getElementById('folderActionForm');
    const picker = document.getElementById('folderDestinationPicker');
    const confirm = document.getElementById('confirmFolderAction');
    const kind = document.getElementById('folderActionKind');
    const destination = document.getElementById('folderMoveDestination');
    const status = document.getElementById('folderMoveStatus');
    const listing = document.getElementById('folderMoveListing');
    const crumbs = document.getElementById('folderMoveBreadcrumbs');
    let selection = [];
    let requestVersion = 0;
    const parent = path => path.includes('/') ? path.slice(0, path.lastIndexOf('/')) : '';
    const invalid = path => selection.some(box => path === box.value || path.startsWith(box.value + '/'));
    function update() {
        const selected = boxes.filter(box => box.checked);
        document.getElementById('folderSelectionCount').textContent = selected.length + ' selected';
        document.getElementById('folderSelectionCount').hidden = selected.length === 0;
        move.hidden = trash.hidden = selected.length === 0;
        const allSelected = boxes.length > 0 && boxes.every(box => box.checked);
        all.querySelector('span').textContent = allSelected ? 'Unselect all' : 'Select all';
        all.setAttribute('aria-pressed', String(allSelected));
        all.classList.toggle('btn-primary', allSelected);
        all.classList.toggle('btn-outline-primary', !allSelected);
        move.disabled = trash.disabled = selected.length === 0 || selected.length > 50;
        if (selected.length > 50) document.getElementById('folderSelectionCount').textContent += ' — select up to 50 at a time';
    }
    boxes.forEach(box => box.addEventListener('change', update));
    all.addEventListener('click', () => {
        const select = !boxes.every(box => box.checked);
        boxes.forEach(box => { box.checked = select; });
        update();
    });
    function browseButton(label, path) {
        const button = document.createElement('button');
        button.type = 'button';
        button.className = 'btn btn-outline-secondary btn-sm text-break';
        button.textContent = label;
        button.disabled = invalid(path);
        button.addEventListener('click', () => browse(path));
        return button;
    }
    async function browse(path) {
        const version = ++requestVersion;
        confirm.disabled = true;
        destination.value = path;
        listing.replaceChildren();
        crumbs.replaceChildren(browseButton('Root Library', ''));
        let prefix = '';
        path.split('/').filter(Boolean).forEach(part => {
            prefix = prefix ? prefix + '/' + part : part;
            crumbs.append(document.createTextNode(' / '), browseButton(part, prefix));
        });
        status.textContent = 'Loading folders…';
        try {
            const url = new URL(picker.dataset.url, window.location.href);
            url.searchParams.set('folder', path);
            const response = await fetch(url, { headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' } });
            const data = await response.json();
            if (!response.ok) throw new Error(data.message || 'Unable to load folders.');
            if (version !== requestVersion || !modal.isConnected) return;
            if (!Array.isArray(data.folders)) throw new Error('Unable to load folders. Please refresh and try again.');
            data.folders.forEach(folder => {
                const button = browseButton('📁 ' + folder.id.split('/').pop() + ' →', folder.id);
                button.className = 'list-group-item list-group-item-action text-break';
                if (button.disabled) button.title = 'A selected folder and its subfolders cannot be destinations.';
                listing.append(button);
            });
            const sameParent = selection.some(box => parent(box.value) === path);
            confirm.disabled = invalid(path) || sameParent;
            status.textContent = sameParent ? 'A selected folder is already in this location. Choose another destination.' : 'Destination: ' + (path || 'Root Library');
        } catch (error) {
            if (version === requestVersion) status.textContent = error.message;
        }
    }
    function open(action) {
        selection = boxes.filter(box => box.checked);
        if (!selection.length || selection.length > 50) return;
        kind.value = action;
        const inputs = document.getElementById('folderActionPaths');
        inputs.replaceChildren();
        selection.forEach(box => {
            const input = document.createElement('input');
            input.type = 'hidden'; input.name = 'paths[]'; input.value = box.value;
            inputs.append(input);
        });
        document.getElementById('folderActionSummary').textContent = 'Selected: ' + selection.map(box => box.dataset.folderName).join(', ');
        document.getElementById('folderActionTitle').textContent = action === 'move' ? 'Move ' + selection.length + ' folder(s)' : 'Move folders to Trash?';
        document.getElementById('folderActionHelp').textContent = action === 'move' ? 'All files and subfolders inside the selected folders will also be moved.' : 'The selected folders and all their contents will be removed from the active library and retained in Trash.';
        picker.hidden = action !== 'move';
        confirm.textContent = action === 'move' ? 'Move here' : 'Move to Trash';
        confirm.className = action === 'move' ? 'btn btn-primary' : 'btn btn-danger';
        confirm.disabled = false;
        bootstrap.Modal.getOrCreateInstance(modal).show();
        if (action === 'move') browse(form.elements.folder.value);
    }
    move.addEventListener('click', () => open('move'));
    trash.addEventListener('click', () => open('trash'));
    form.addEventListener('submit', async event => {
        if (confirm.disabled || form.getAttribute('aria-busy') === 'true') {
            event.preventDefault(); return;
        }
        if (kind.value !== 'move') { bootstrap.Modal.getInstance(modal).hide(); return; }
        event.preventDefault();
        if (window.GreyhoundFolderMove.isBusy()) return;
        const finish = window.GreyhoundFolderMove.start(selection.map(box => box.value), destination.value);
        if (!finish) return;
        const pageUrl = window.location.href;
        const body = new FormData(form);
        form.setAttribute('aria-busy', 'true'); confirm.disabled = true;
        bootstrap.Modal.getInstance(modal).hide();
        try {
            // The hidden input named "action" shadows the form.action property.
            const response = await fetch(form.getAttribute('action'), { method: 'POST', body, credentials: 'same-origin',
                headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' } });
            if (response.redirected || !response.headers.get('content-type')?.includes('application/json')) {
                throw new Error('Could not confirm the move. Refresh and check the folder locations before retrying.');
            }
            const data = await response.json();
            const csrf = form.querySelector('input[name*="csrf"]');
            if (csrf && data.csrfToken) csrf.value = data.csrfToken;
            if (!response.ok || !data.ok) throw new Error(data.message || 'The move could not be completed. Check the folder locations before retrying.');
            finish(true, data.message || 'Selected folders moved.');
            if (window.GreyhoundAjax && window.location.href === pageUrl) {
                window.GreyhoundAjax.refresh({preserveScroll:true, ifIdle:true}).catch(() => {});
            }
        } catch (error) {
            finish(false, error instanceof TypeError ? 'Connection interrupted. The move may still finish; check the folder locations before retrying.' : error.message);
        } finally {
            form.removeAttribute('aria-busy'); confirm.disabled = false;
        }
    });
    modal.addEventListener('hidden.bs.modal', () => { requestVersion++; });
    update();
}());
