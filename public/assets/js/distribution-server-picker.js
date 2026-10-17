document.addEventListener('DOMContentLoaded', function () {
    var modal = document.getElementById('serverTransmitModal');
    if (!modal) return;
    var el = function (id) { return document.getElementById(id); };
    var form = el('serverTransmitForm'), folder = '', page = 1, pages = 1, listing = {files:[],folders:[]};
    var files = new Map(), folders = new Map(), controller, generation = 0, searchTimer, clickTimers = new Map(), busy = false;
    var status = el('serverPickerStatus'), search = el('serverPickerSearch');
    var h = function (value) { return String(value ?? '').replace(/[&<>"']/g, function (char) { return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[char]; }); };
    function cancel() { generation++; if (controller) controller.abort(); controller = null; clearTimeout(searchTimer); clickTimers.forEach(clearTimeout); clickTimers.clear(); }
    function setBusy(value) { busy = value; ['serverPickerContinue','serverPickerSelectAll','serverPickerTransmit'].forEach(function (id) { el(id).disabled = value; }); count(); }
    function count() { el('serverPickerContinue').disabled = busy || (!folders.size && !files.size); el('serverPickerClear').disabled = busy || (!folders.size && !files.size); el('serverPickerSelectAll').disabled = busy || !(listing.folders.length + listing.files.length); el('serverPickerCount').textContent = folders.size + ' folders • ' + files.size + ' files selected'; }
    function thumb(file) { return '<span class="mini-thumb">' + (file.preview_url ? '<img src="' + h(file.preview_url) + '" alt="' + h(file.name) + '" loading="lazy">' : '<i class="bi bi-file-earmark"></i>') + '</span>'; }
    function render() {
        el('serverPickerFolders').innerHTML = listing.folders.map(function (item) { return '<button type="button" class="mini-tile' + (folders.has(item.id) ? ' is-selected' : '') + '" aria-pressed="' + folders.has(item.id) + '" data-folder="' + h(item.id) + '"><span class="mini-select-mark"><i class="bi bi-check2"></i></span><span class="mini-thumb"><i class="bi bi-folder-fill"></i></span><span class="mini-name">' + h(item.name) + '</span><span class="mini-meta">Double-click to open</span></button>'; }).join('') || '<div class="mini-empty">No matching folders.</div>';
        el('serverPickerFiles').innerHTML = listing.files.map(function (item) { return '<button type="button" class="mini-tile' + (files.has(item.id) ? ' is-selected' : '') + '" aria-pressed="' + files.has(item.id) + '" data-file="' + h(item.id) + '"><span class="mini-select-mark"><i class="bi bi-check2"></i></span>' + thumb(item) + '<span class="mini-name">' + h(item.name) + '</span></button>'; }).join('') || '<div class="mini-empty">No matching files.</div>';
        var all = listing.folders.length + listing.files.length > 0 && listing.folders.every(function (item) { return folders.has(item.id); }) && listing.files.every(function (item) { return files.has(item.id); });
        el('serverPickerSelectAll').textContent = all ? 'Unselect all on page' : 'Select all on page'; count();
    }
    async function request(url, options) {
        cancel(); var version = generation; controller = new AbortController();
        setBusy(true); status.textContent = 'Loading server files…'; el('serverPickerRetry').hidden = true;
        var current = controller, timeout = setTimeout(function () { current.abort(); }, 25000);
        try {
            var response = await fetch(url, Object.assign({credentials:'same-origin',signal:current.signal,headers:{Accept:'application/json'}}, options));
            var data = await response.json();
            if (version !== generation || !modal.isConnected) return null;
            if (data.csrfToken) form.querySelector('input[type="hidden"]').value = data.csrfToken;
            if (!response.ok || response.redirected) throw new Error(data.message || 'Sign in again or refresh the connection.');
            status.textContent = ''; return data;
        } catch (error) {
            if (version === generation && modal.isConnected) { status.textContent = error.name === 'AbortError' ? 'The server took too long to respond. Try again.' : error.message; el('serverPickerRetry').hidden = false; }
            return null;
        } finally { clearTimeout(timeout); if (version === generation) { controller = null; setBusy(false); } }
    }
    async function browse() {
        var url = new URL(modal.dataset.browseUrl, window.location.href);
        url.searchParams.set('folder', folder); url.searchParams.set('page', page); url.searchParams.set('q', search.value.trim());
        // Do not leave stale tiles available during navigation or failed reads.
        listing = {files:[],folders:[]}; render();
        var data = await request(url.toString()); if (!data) return;
        listing = data; page = data.pagination.current_page; pages = data.pagination.total_pages;
        el('serverPickerBreadcrumb').innerHTML = data.breadcrumbs.map(function (crumb) { return '<button class="mini-crumb" type="button" data-open="' + h(crumb.id) + '">' + h(crumb.name) + '</button>'; }).join('<span>/</span>');
        el('serverPickerPage').textContent = 'Page ' + page + ' of ' + pages;
        el('serverPickerPrevious').disabled = page <= 1; el('serverPickerNext').disabled = page >= pages;
        render();
    }
    function toggle(map, item) { if (map.has(item.id)) map.delete(item.id); else map.set(item.id, item); render(); }
    function hidden(name, value) { var input = document.createElement('input'); input.type = 'hidden'; input.name = name; input.value = value; el('serverPickerInputs').appendChild(input); }
    function back() { el('serverPickerBrowse').hidden = false; el('serverPickerPreview').hidden = true; el('serverPickerBack').hidden = true; el('serverPickerContinue').hidden = false; el('serverPickerSelectAll').hidden = false; el('serverPickerClear').hidden = false; el('serverPickerTransmit').hidden = true; }
    modal.addEventListener('show.bs.modal', function (event) {
        cancel(); files.clear(); folders.clear(); folder = ''; page = 1; search.value = ''; back();
        var trigger = event.relatedTarget; form.action = trigger.dataset.action;
        var direct = trigger.dataset.mode === 'direct';
        el('serverPickerDepartment').value = direct ? (trigger.dataset.department || '') : '';
        el('serverTransmitTitle').textContent = direct ? 'Direct Send' : 'Transmit Files';
        el('serverPickerTransmit').textContent = direct ? 'Direct Send' : 'Transmit';
        el('serverPickerPreviewHeading').textContent = direct ? 'Direct Send' : 'Department Request';
        el('serverTransmitContext').textContent = (trigger.dataset.department || '') + ' — ' + (trigger.dataset.requestTitle || '');
        el('serverPickerInputs').replaceChildren(); browse();
    });
    modal.addEventListener('hidden.bs.modal', cancel);
    el('serverPickerFolders').addEventListener('click', function (event) {
        var button = event.target.closest('[data-folder]'); if (!button || busy) return;
        var item = listing.folders.find(function (item) { return item.id === button.dataset.folder; });
        if (event.detail === 0) { toggle(folders,item); return; }
        clearTimeout(clickTimers.get(item.id)); clickTimers.set(item.id, setTimeout(function () { toggle(folders,item); clickTimers.delete(item.id); },220));
    });
    el('serverPickerFolders').addEventListener('dblclick', function (event) {
        var button = event.target.closest('[data-folder]'); if (!button || busy) return;
        clearTimeout(clickTimers.get(button.dataset.folder)); clickTimers.delete(button.dataset.folder);
        folder = button.dataset.folder; page = 1; search.value = ''; browse();
    });
    el('serverPickerFiles').addEventListener('click', function (event) { var button = event.target.closest('[data-file]'); if (!button || busy) return; toggle(files,listing.files.find(function (item) { return item.id === button.dataset.file; })); });
    el('serverPickerBreadcrumb').addEventListener('click', function (event) { var button = event.target.closest('[data-open]'); if (!button) return; folder = button.dataset.open; page = 1; search.value = ''; browse(); });
    window.GreyhoundSearch.attach(search, async function (term, signal) {
        var url = new URL(modal.dataset.browseUrl, window.location.href);
        url.searchParams.set('folder', folder); url.searchParams.set('q', term); url.searchParams.set('page', '1');
        var response = await fetch(url.toString(), {credentials:'same-origin',signal:signal,headers:{Accept:'application/json'}});
        if (!response.ok || response.redirected) throw new Error('Unavailable');
        var data = await response.json();
        return (data.folders || []).concat(data.files || []).map(function (item) { return {value:item.name}; });
    }, function () { page = 1; browse(); });
    el('serverPickerPrevious').addEventListener('click', function () { if (page>1) { page--; browse(); } });
    el('serverPickerNext').addEventListener('click', function () { if (page<pages) { page++; browse(); } });
    el('serverPickerRetry').addEventListener('click', function () { back(); browse(); });
    el('serverPickerClear').addEventListener('click', function () { if (busy) return; files.clear(); folders.clear(); render(); });
    el('serverPickerSelectAll').addEventListener('click', function () {
        if (busy) return;
        var all = listing.folders.every(function (item) { return folders.has(item.id); }) && listing.files.every(function (item) { return files.has(item.id); });
        listing.folders.forEach(function (item) { if (all) folders.delete(item.id); else folders.set(item.id,item); });
        listing.files.forEach(function (item) { if (all) files.delete(item.id); else files.set(item.id,item); }); render();
    });
    el('serverPickerBack').addEventListener('click', back);
    el('serverPickerContinue').addEventListener('click', async function () {
        if (busy) return;
        if (!files.size && !folders.size) { status.textContent = 'Select at least one file or folder.'; return; }
        el('serverPickerInputs').replaceChildren();
        files.forEach(function (item) { hidden('server_file_ids[]',item.id); }); folders.forEach(function (item) { hidden('server_folder_ids[]',item.id); });
        var data = await request(modal.dataset.selectionUrl, {method:'POST',body:new FormData(form)}); if (!data) return;
        el('serverPickerInputs').replaceChildren();
        data.files.forEach(function (item) { hidden('server_file_ids[]',item.id); }); data.folders.forEach(function (item) { hidden('server_folder_ids[]',item.id); });
        el('serverPickerPreviewContext').textContent = el('serverTransmitContext').textContent;
        el('serverPickerPreviewSummary').textContent = data.folders.length + ' selected folders • ' + data.files.length + ' files. Server originals remain unchanged.';
        el('serverPickerPreviewFiles').innerHTML = data.files.map(function (item) { return '<div class="preview-media-card">' + thumb(item) + '<div class="small text-truncate" title="' + h(item.path) + '">' + h(item.name) + '</div></div>'; }).join('');
        el('serverPickerBrowse').hidden = true; el('serverPickerPreview').hidden = false; el('serverPickerBack').hidden = false; el('serverPickerContinue').hidden = true; el('serverPickerSelectAll').hidden = true; el('serverPickerClear').hidden = true; el('serverPickerTransmit').hidden = false;
    });
    form.addEventListener('submit', function (event) { if (busy || el('serverPickerPreview').hidden) { event.preventDefault(); return; } setBusy(true); status.textContent = 'Transmitting server files…'; });
});
