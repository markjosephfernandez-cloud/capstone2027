(function () {
    'use strict';
    window.GreyhoundUploadFolders = function (form, picker, destination, signal, onBusy) {
        var summary = picker.querySelector('summary');
        var label = picker.querySelector('[data-upload-folder-label]');
        var search = picker.querySelector('input[type="search"]');
        var menu = picker.querySelector('.upload-folder-menu');
        var status = picker.querySelector('[role="status"]');
        var generation = 0, creating = false, folders = [];
        function element(tag, text, className) {
            var node = document.createElement(tag);
            node.textContent = text || '';
            node.className = className || '';
            return node;
        }
        function button(text, handler) {
            var node = element('button', text, 'btn btn-sm btn-light');
            node.type = 'button'; node.addEventListener('click', handler); return node;
        }
        var location = element('div', '', 'small text-break mb-2');
        var navigation = element('div', '', 'd-flex gap-2 mb-2');
        var back = button('Back to parent', function () { choose(parent(destination.value)); });
        var root = button('Root library', function () { choose(''); });
        navigation.append(back, root);
        var list = element('div', '', 'upload-folder-choices');
        var retry = button('Retry loading folders', load); retry.hidden = true;
        var create = element('div', '', 'mt-2');
        var createToggle = button('Create subfolder', function () { createFields.hidden = false; name.focus(); });
        var createFields = element('div'); createFields.hidden = true;
        var name = element('input', '', 'form-control my-2');
        name.type = 'text'; name.maxLength = 120; name.placeholder = 'Subfolder name';
        name.setAttribute('aria-label', 'New subfolder name');
        var save = button('Create', createFolder);
        var cancel = button('Cancel', function () { createFields.hidden = true; name.value = ''; });
        createFields.append(name, save, cancel); create.append(createToggle, createFields);
        create.hidden = !picker.dataset.createUrl;
        menu.prepend(location, navigation);
        destination.hidden = true;
        destination.after(list);
        menu.append(retry, create);
        function parent(path) { var index = path.lastIndexOf('/'); return index < 0 ? '' : path.slice(0, index); }
        function message(text) { status.hidden = !text; status.textContent = text; }
        function sync() {
            label.textContent = destination.value || 'Root library';
            location.textContent = 'Upload destination: ' + (destination.value || 'Root library');
            summary.setAttribute('aria-disabled', destination.disabled || creating ? 'true' : 'false');
            back.disabled = root.disabled = creating || destination.disabled || !destination.value;
            search.disabled = creating || destination.disabled;
            createToggle.disabled = save.disabled = cancel.disabled = name.disabled = creating || destination.disabled;
            if (destination.disabled) picker.open = false;
        }
        function choose(path) {
            if (creating || destination.disabled) return;
            generation++;
            if (!Array.from(destination.options).some(function (option) { return option.value === path; })) {
                var option = element('option', path || 'Root library'); option.value = path; destination.appendChild(option);
            }
            destination.value = path;
            search.value = ''; folders = []; list.replaceChildren(); message(''); retry.hidden = true;
            createFields.hidden = true; name.value = '';
            sync();
            destination.dispatchEvent(new Event('change', { bubbles: true }));
            if (picker.open) load();
        }
        function render() {
            list.replaceChildren();
            var query = search.value.trim().toLocaleLowerCase();
            folders.filter(function (folder) { return folder.name.toLocaleLowerCase().includes(query); }).forEach(function (folder) {
                var choice = button(folder.name.split('/').pop(), function () { choose(folder.id); });
                choice.className = 'upload-folder-choice'; choice.disabled = creating || destination.disabled;
                list.appendChild(choice);
            });
            message(list.children.length ? '' : query ? 'No matching subfolders.' : 'No subfolders. You can upload here or create a subfolder.');
        }
        async function load() {
            if (creating || destination.disabled) return;
            var request = ++generation;
            var path = destination.value;
            folders = []; list.replaceChildren(); search.value = ''; retry.hidden = true;
            message('Loading folders…'); sync();
            try {
                var url = new URL(destination.dataset.foldersUrl, window.location.href);
                url.searchParams.set('folder', path);
                var response = await fetch(url, { credentials: 'same-origin', cache: 'no-store', signal: signal });
                if (!response.ok || response.redirected) throw new Error('Could not load folders.');
                var data = await response.json();
                if (!Array.isArray(data.folders)) throw new Error('Could not load folders.');
                if (request !== generation || path !== destination.value) return;
                // Only immediate children belong in this level, even if a stale source returns more.
                folders = data.folders.filter(function (folder) { return parent(folder.id) === path; });
                folders.sort(function (a, b) { return a.name.localeCompare(b.name, undefined, { numeric: true, sensitivity: 'base' }); });
                render();
            } catch (error) {
                if (request !== generation || error.name === 'AbortError') return;
                message('Could not load folders. Retry or reopen the dropdown.'); retry.hidden = false;
            }
        }
        async function createFolder() {
            if (creating || destination.disabled) return;
            var folderName = name.value.trim();
            if (!folderName || /[\x00-\x1f\x7f/\\:*?"<>|]/.test(folderName) || folderName === '.' || folderName === '..' || folderName.endsWith('.')) {
                message('Enter a valid subfolder name without slashes.'); name.focus(); return;
            }
            var path = destination.value;
            creating = true; generation++; onBusy(true); sync();
            list.querySelectorAll('button').forEach(function (node) { node.disabled = true; });
            message('Creating subfolder…');
            try {
                var tokenResponse = await fetch(form.dataset.tokenUrl, { credentials: 'same-origin', cache: 'no-store', signal: signal });
                if (!tokenResponse.ok || tokenResponse.redirected) throw new Error('Could not prepare folder creation. Please retry.');
                var token = await tokenResponse.json();
                if (!token.csrfTokenName || !token.csrfToken) throw new Error('Could not prepare folder creation. Please retry.');
                var body = new FormData();
                body.append(token.csrfTokenName, token.csrfToken);
                body.append('parent_id', path); body.append('name', folderName);
                var response = await fetch(picker.dataset.createUrl, {
                    method: 'POST', credentials: 'same-origin', signal: signal, body: body,
                    headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' }
                });
                if (response.redirected || !(response.headers.get('content-type') || '').includes('application/json')) throw new Error('Could not create the folder. Check your access and retry.');
                var result = await response.json();
                var csrf = form.elements.namedItem(token.csrfTokenName);
                if (csrf && result.csrfToken) csrf.value = result.csrfToken;
                if (!response.ok || !result.ok || !result.folder) throw new Error(result.message || 'Could not create the folder.');
                creating = false;
                choose(result.folder.id);
            } catch (error) {
                if (error.name !== 'AbortError') message(error.message);
            } finally {
                creating = false; sync(); onBusy(false);
                list.querySelectorAll('button').forEach(function (node) { node.disabled = destination.disabled; });
            }
        }
        summary.addEventListener('click', function (event) { if (creating || destination.disabled) event.preventDefault(); });
        picker.addEventListener('toggle', function () { if (picker.open && !creating) { load(); search.focus(); } });
        // Keep browsing open until the admin toggles the summary, including on touch blur.
        name.addEventListener('keydown', function (event) { if (event.key === 'Enter') { event.preventDefault(); createFolder(); } });
        search.addEventListener('keydown', function (event) {
            if (event.key === 'Enter') event.preventDefault();
            if (event.key === 'ArrowDown') { event.preventDefault(); var first = list.querySelector('button'); if (first) first.focus(); }
        });
        search.addEventListener('input', function (event) { event.stopPropagation(); render(); });
        sync();
        return { sync: sync, busy: function () { return creating; } };
    };
})();
