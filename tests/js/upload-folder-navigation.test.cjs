const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const flush = () => new Promise(resolve => setImmediate(resolve));
function setup(initial = '') {
    const nodes = [], requests = [], busy = [];
    function node(tag = 'div') {
        const item = { tag, disabled: false, children: [], listeners: {}, dataset: {}, value: '', textContent: '',
            addEventListener(type, fn) { this.listeners[type] = fn; },
            fire(type, extra = {}) { return this.listeners[type]?.({ target: this, preventDefault() {}, stopPropagation() {}, ...extra }); },
            append(...items) { this.children.push(...items); }, prepend(...items) { this.children.unshift(...items); },
            appendChild(item) { this.append(item); }, after(item) { this.afterNode = item; },
            replaceChildren(...items) { this.children = items; }, setAttribute(key, value) { this[key] = value; },
            querySelectorAll(tag) { return this.children.flatMap(child => [...(child.tag === tag ? [child] : []), ...child.querySelectorAll(tag)]); },
            querySelector(tag) { return this.querySelectorAll(tag)[0] || null; },
            contains(other) { return other === this || this.children.some(child => child.contains(other)); },
            dispatchEvent(event) { this.fire(event.type); }, focus() { this.focused = true; }
        }; nodes.push(item); return item;
    }
    const summary = node('summary'), label = node(), search = node('input'), menu = node(), status = node(), picker = node('details');
    picker.dataset.createUrl = '/create';
    picker.querySelector = selector => ({ summary, '[data-upload-folder-label]': label, 'input[type="search"]': search, '.upload-folder-menu': menu, '[role="status"]': status })[selector];
    const destination = node('select'); destination.value = initial; destination.dataset.foldersUrl = '/folders';
    destination.options = [{ value: initial }]; destination.appendChild = option => destination.options.push(option);
    const form = { dataset: { tokenUrl: '/token' }, elements: { namedItem: () => ({}) } };
    const document = { createElement: node, listeners: {}, addEventListener(type, handler) { this.listeners[type] = handler; } };
    picker.append(summary, menu);
    menu.append(search, destination, status);
    const window = { location: { href: 'http://localhost/library' } };
    vm.runInNewContext(fs.readFileSync('public/assets/js/upload-folders.js', 'utf8'), {
        window, document, URL, Event, FormData,
        fetch(url, options) { return new Promise(resolve => requests.push({ url: String(url), options, resolve })); }
    });
    const api = window.GreyhoundUploadFolders(form, picker, destination, new AbortController().signal, value => busy.push(value));
    function respond(index, data, ok = true) { requests[index].resolve({ ok, redirected: false, headers: { get: () => 'application/json' }, json: async () => data }); }
    return { picker, destination, requests, respond, api, busy, nodes, label, status, document, summary, search,
        list: destination.afterNode, button: text => nodes.find(item => item.tag === 'button' && item.textContent === text),
        async open() { picker.open = true; picker.fire('toggle'); await flush(); } };
}

test('selecting folders immediately loads children without closing; Back and Root navigate', async () => {
    const app = setup(); await app.open();
    app.respond(0, { folders: [{ id: 'Events', name: 'Events' }, { id: 'Audio', name: 'Audio' }] }); await flush();
    app.button('Events').fire('click'); assert.equal(app.destination.value, 'Events'); assert.equal(app.picker.open, true);
    assert.equal(new URL(app.requests[1].url).searchParams.get('folder'), 'Events');
    app.respond(1, { folders: [{ id: 'Events/2026', name: 'Events/2026' }, { id: 'Audio', name: 'Audio' }, { id: 'Events/2026/Photos', name: 'Events/2026/Photos' }] }); await flush();
    assert.deepEqual(app.list.children.map(item => item.textContent), ['2026']);
    app.button('2026').fire('click'); assert.equal(app.destination.value, 'Events/2026');
    app.button('Back to parent').fire('click'); assert.equal(app.destination.value, 'Events');
    app.button('Root library').fire('click'); assert.equal(app.destination.value, '');
});

test('late folder responses cannot replace a newer level', async () => {
    const app = setup('Events'); await app.open();
    app.button('Root library').fire('click');
    app.respond(1, { folders: [{ id: 'Audio', name: 'Audio' }] }); await flush();
    app.respond(0, { folders: [{ id: 'Events/Photos', name: 'Events/Photos' }] }); await flush();
    assert.deepEqual(app.list.children.map(item => item.textContent), ['Audio']);
});

test('creates under selected parent and selects the returned folder without reloading', async () => {
    const app = setup('Events');
    app.picker.open = true;
    const input = app.nodes.find(item => item.placeholder === 'Subfolder name');
    input.value = 'Photos'; const pending = app.button('Create').fire('click');
    assert.equal(app.api.busy(), true);
    app.respond(0, { csrfTokenName: 'csrf', csrfToken: 'token' }); await flush();
    assert.equal(app.requests[1].options.body.get('parent_id'), 'Events');
    assert.equal(app.requests[1].options.body.get('name'), 'Photos');
    assert.equal(app.requests[1].options.body.get('csrf'), 'token');
    app.respond(1, { ok: true, folder: { id: 'Events/Photos' } }); await pending;
    assert.equal(app.destination.value, 'Events/Photos'); assert.equal(app.label.textContent, 'Events/Photos');
    assert.equal(app.api.busy(), false); assert.deepEqual(app.busy, [true, false]);
    assert.equal(app.picker.open, true);
    assert.equal(new URL(app.requests[2].url).searchParams.get('folder'), 'Events/Photos');
    app.respond(2, { folders: [] }); await flush();
    assert.match(app.status.textContent, /No subfolders/);
});

test('creation failures retain destination and allow retry; invalid names send no requests', async () => {
    const app = setup('Events'); const input = app.nodes.find(item => item.placeholder === 'Subfolder name');
    input.value = '../Photos'; await app.button('Create').fire('click'); assert.equal(app.requests.length, 0);
    input.value = 'Photos'; const pending = app.button('Create').fire('click');
    app.respond(0, { csrfTokenName: 'csrf', csrfToken: 'token' }); await flush();
    app.respond(1, { ok: false, message: 'Folder already exists.' }, false); await pending;
    assert.equal(app.destination.value, 'Events'); assert.equal(app.api.busy(), false);
    assert.equal(app.status.textContent, 'Folder already exists.'); assert.equal(app.button('Create').disabled, false);
});


test('touch blur before click leaves the folder available for selection', async () => {
    const app = setup();
    await app.open();
    app.respond(0, { folders: [{ id: 'Events', name: 'Events' }] }); await flush();
    app.picker.fire('focusout', { target: app.search, relatedTarget: null });
    assert.equal(app.picker.open, true, 'The tap must not hide the folder before click');
    assert.equal(app.destination.value, '');
    app.button('Events').fire('click');
    assert.equal(app.destination.value, 'Events');
    assert.equal(app.label.textContent, 'Events');
    assert.equal(app.picker.open, true);
    assert.equal(new URL(app.requests[1].url).searchParams.get('folder'), 'Events');
});

test('focus changes and outside taps do not close the picker; the header retains native toggling', () => {
    const app = setup();
    app.picker.open = true;
    app.picker.fire('focusout', { relatedTarget: app.search });
    app.picker.fire('focusout', { relatedTarget: {} });
    app.picker.fire('focusout', { relatedTarget: null });
    app.document.listeners.click?.({ target: {} });
    assert.equal(app.picker.open, true);
    let prevented = false;
    app.summary.fire('click', { preventDefault() { prevented = true; } });
    assert.equal(prevented, false, 'Native details toggling must be allowed when idle');
});

test('touch blur does not prevent opening the create-subfolder controls', () => {
    const app = setup('Events');
    app.picker.open = true;
    app.picker.fire('focusout', { relatedTarget: null });
    assert.equal(app.picker.open, true);
    app.button('Create subfolder').fire('click');
    assert.equal(app.nodes.find(item => item.placeholder === 'Subfolder name').focused, true);
    assert.equal(app.destination.value, 'Events');
});
