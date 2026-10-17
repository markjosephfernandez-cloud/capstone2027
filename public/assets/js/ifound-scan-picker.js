(function () {
    const modal = document.getElementById('ifoundScanPicker') || document.getElementById('passportScanPicker');
    if (!modal) return;
    const prefix = modal.id === 'passportScanPicker' ? 'passport' : 'ifound';
    const el = id => document.getElementById(prefix + 'Picker' + id);
    const selected = new Map(), folders = new Map(), clickTimers = new Map();
    let folder = '', page = 1, pages = 1, visible = [], controller, generation = 0, busy = false, searchTimer;
    function cancel() {
        generation++;
        if (controller) controller.abort();
        clearTimeout(searchTimer); clickTimers.forEach(clearTimeout); clickTimers.clear();
    }
    function sync() {
        el('Count').textContent = folders.size + ' folders • ' + selected.size + ' files selected';
        el('Continue').disabled = busy || (!selected.size && !folders.size);
        el('SelectPage').disabled = busy || !visible.length;
        el('Clear').disabled = busy || (!selected.size && !folders.size);
        el('Start').disabled = busy || !modal.scanSelection || !modal.scanSelection.length;
    }
    function back() {
        el('Browse').hidden = false; el('Preview').hidden = true; el('Back').hidden = true;
        el('Continue').hidden = false; el('SelectPage').hidden = false; el('Clear').hidden = false; el('Start').hidden = true;
        modal.scanSelection = []; sync();
    }
    function crumbs() {
        el('Crumbs').replaceChildren();
        const entries = [{path:'', name:'Server Library'}]; let path = '';
        folder.split('/').filter(Boolean).forEach(part => { path += (path ? '/' : '') + part; entries.push({path, name:part}); });
        entries.forEach((entry, index) => {
            if (index) { const divider = document.createElement('span'); divider.textContent = '/'; el('Crumbs').appendChild(divider); }
            const button = document.createElement('button'); button.type = 'button'; button.className = 'mini-crumb'; button.textContent = entry.name;
            if (index === entries.length - 1) button.setAttribute('aria-current','page');
            button.onclick = () => { if (!busy) navigate(entry.path); }; el('Crumbs').appendChild(button);
        });
    }
    function thumb(item) {
        const node = document.createElement('span'); node.className = 'mini-thumb';
        const icon = document.createElement('i'); icon.className = item.directory ? 'bi bi-folder-fill' : 'bi bi-file-earmark-image'; icon.setAttribute('aria-hidden','true');
        if (item.preview_url && !item.directory) {
            const image = document.createElement('img'); image.src = item.preview_url; image.alt = item.name; image.loading = 'lazy'; image.decoding = 'async';
            image.onerror = () => node.replaceChildren(icon); node.appendChild(image);
        } else node.appendChild(icon);
        return node;
    }
    function toggle(item) {
        if (busy) return;
        const map = item.directory ? folders : selected;
        if (map.has(item.path)) map.delete(item.path);
        else map.set(item.path, item);
        modal.scanSelection = []; render();
    }
    function render() {
        el('Folders').replaceChildren(); el('Files').replaceChildren();
        visible.forEach(item => {
            const row = document.createElement('button'), map = item.directory ? folders : selected;
            row.type = 'button'; row.className = 'mini-tile' + (map.has(item.path) ? ' is-selected' : '');
            row.setAttribute(item.directory ? 'data-folder' : 'data-file', item.path);
            row.setAttribute('aria-pressed', String(map.has(item.path))); row.setAttribute('aria-label', (item.directory ? 'Select folder ' : 'Select image ') + item.name); row.title = item.name;
            const mark = document.createElement('span'); mark.className = 'mini-select-mark'; mark.setAttribute('aria-hidden','true');
            const check = document.createElement('i'); check.className = 'bi bi-check2'; mark.appendChild(check);
            const name = document.createElement('span'); name.className = 'mini-name'; name.textContent = item.name;
            row.append(mark, thumb(item), name);
            if (item.directory) {
                const meta = document.createElement('span'); meta.className = 'mini-meta'; meta.textContent = 'Double-click to open'; row.appendChild(meta);
                row.onclick = event => {
                    if (busy) return;
                    if (event.detail === 0) { toggle(item); return; }
                    clearTimeout(clickTimers.get(item.path));
                    clickTimers.set(item.path, setTimeout(() => { clickTimers.delete(item.path); toggle(item); }, 220));
                };
                row.ondblclick = () => { clearTimeout(clickTimers.get(item.path)); clickTimers.delete(item.path); if (!busy) navigate(item.path); };
                row.onkeydown = event => { if (event.key === 'ArrowRight') { event.preventDefault(); if (!busy) navigate(item.path); } };
            } else row.onclick = () => toggle(item);
            el(item.directory ? 'Folders' : 'Files').appendChild(row);
        });
        ['Folders','Files'].forEach(kind => {
            if (!el(kind).children.length) { const empty = document.createElement('div'); empty.className = 'mini-empty'; empty.textContent = 'No matching ' + kind.toLowerCase() + '.'; el(kind).appendChild(empty); }
        });
        const all = visible.length && visible.every(item => (item.directory ? folders : selected).has(item.path));
        el('SelectPage').textContent = all ? 'Unselect all on page' : 'Select all on page';
        el('Page').textContent = 'Page ' + page + ' of ' + pages;
        el('Previous').disabled = busy || page <= 1; el('Next').disabled = busy || page >= pages; sync();
    }
    async function request(path, query, requestedPage, signal) {
        const url = new URL(modal.dataset.browseUrl, window.location.href);
        url.searchParams.set('folder', path); url.searchParams.set('q', query); url.searchParams.set('page', requestedPage);
        signal.throwIfAborted();
        const requestController = new AbortController();
        const abort = () => requestController.abort();
        signal.addEventListener('abort', abort, {once:true});
        const timeout = setTimeout(abort, 25000);
        try {
        const response = await fetch(url, {credentials:'same-origin',cache:'no-store',signal:requestController.signal,headers:{Accept:'application/json','X-Requested-With':'XMLHttpRequest'}});
        const data = await response.json();
        if (!response.ok || response.redirected || !data.ok) throw new Error(data.message || 'Could not load server files.');
        return data;
        } finally { clearTimeout(timeout); signal.removeEventListener('abort', abort); }
    }
    async function operation(work, expanding = false) {
        cancel(); const version = generation; controller = new AbortController(); const current = controller;
        const timer = expanding ? null : setTimeout(() => current.abort(), 25000);
        busy = true; el('Retry').hidden = true; render();
        try { await work(current.signal, () => version === generation && modal.isConnected); }
        catch (error) {
            if (version === generation && modal.isConnected) { el('Status').textContent = error.name === 'AbortError' ? 'The server took too long to respond. Try again or choose a smaller folder.' : error.message; el('Retry').hidden = false; }
        } finally { clearTimeout(timer); if (version === generation && modal.isConnected) { busy = false; render(); } }
    }
    async function browse() {
        visible = []; crumbs(); el('Status').textContent = 'Loading server files…';
        await operation(async (signal, current) => {
            const data = await request(folder, el('Search').value.trim(), page, signal);
            if (!current()) return;
            visible = data.items; page = data.page; pages = data.pages;
            el('Status').textContent = 'Click to select. Double-click a folder to open it; use Right Arrow when navigating by keyboard.';
        });
    }
    function navigate(path) { folder = path; page = 1; el('Search').value = ''; browse(); }
    modal.addEventListener('show.bs.modal', () => { cancel(); busy = false; back(); browse(); });
    modal.addEventListener('hidden.bs.modal', () => { cancel(); busy = false; });
    el('SearchForm').addEventListener('submit', event => { event.preventDefault(); page = 1; browse(); });
    // Match Direct Send's search suggestions while retaining explicit search submission.
    if (window.GreyhoundSearch) window.GreyhoundSearch.attach(el('Search'), async (term, signal) => {
        const data = await request(folder, term, 1, signal); return data.items.map(item => ({value:item.name}));
    }, () => { page = 1; browse(); });
    el('Previous').onclick = () => { if (!busy && page > 1) { page--; browse(); } };
    el('Next').onclick = () => { if (!busy && page < pages) { page++; browse(); } };
    el('Clear').onclick = () => { selected.clear(); folders.clear(); modal.scanSelection = []; render(); };
    el('SelectPage').onclick = () => {
        if (busy) return;
        const all = visible.every(item => (item.directory ? folders : selected).has(item.path));
        visible.forEach(item => { const map = item.directory ? folders : selected; if (all) map.delete(item.path); else map.set(item.path, item); });
        modal.scanSelection = []; render();
    };
    el('Back').onclick = back;
    el('Retry').onclick = () => { back(); browse(); };
    el('Continue').onclick = async () => {
        if (busy || (!selected.size && !folders.size)) return;
        el('Status').textContent = 'Preparing selected images…';
        await operation(async (signal, current) => {
            const images = new Map(selected), visited = new Set(), pending = Array.from(folders.keys());
            for (let index = 0; index < pending.length; index++) {
                const path = pending[index]; if (visited.has(path)) continue; visited.add(path);
                let folderPages = 1;
                for (let folderPage = 1; folderPage <= folderPages; folderPage++) {
                    const data = await request(path, '', folderPage, signal); if (!current()) return;
                    folderPages = data.pages;
                    data.items.forEach(item => { if (item.directory) pending.push(item.path); else images.set(item.path, item); });
                    el('Status').textContent = images.size + ' images found in ' + visited.size + ' folders…';
                }
            }
            if (!images.size) throw new Error('The selected folders contain no supported images.');
            if (!current()) return;
            modal.scanSelection = Array.from(images.values());
            el('PreviewFiles').replaceChildren();
            Array.from(images.values()).slice(0, 100).forEach(item => { const card = document.createElement('div'); card.className = 'preview-media-card'; const name = document.createElement('div'); name.className = 'small text-truncate'; name.textContent = item.name; name.title = item.path; card.append(thumb(item), name); el('PreviewFiles').appendChild(card); });
            el('PreviewSummary').textContent = folders.size + ' selected folders • ' + images.size + ' images ready to scan.' + (images.size > 100 ? ' Preview shows the first 100; all selected images will be scanned.' : '');
            el('Browse').hidden = true; el('Preview').hidden = false; el('Back').hidden = false; el('Continue').hidden = true; el('SelectPage').hidden = true; el('Clear').hidden = true; el('Start').hidden = false;
            el('Status').textContent = '';
        }, true);
    };
    el('Start').onclick = () => { if (!el('Start').disabled && !busy && !el('Preview').hidden && modal.scanSelection && modal.scanSelection.length) document.getElementById(prefix + 'ScanForm').requestSubmit(); };
    modal.scanSelection = []; sync();
})();
