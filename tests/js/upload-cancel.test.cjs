const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const view = fs.readFileSync(path.join(__dirname, '../../app/Views/components/media_upload_script.php'), 'utf8');
const chunkCode = fs.readFileSync(path.join(__dirname, '../../public/assets/js/chunk-uploads.js'), 'utf8');
const sessionCode = fs.readFileSync(path.join(__dirname, '../../public/assets/js/upload-session.js'), 'utf8');
const between = (start, end) => view.slice(view.indexOf(start), view.indexOf(end, view.indexOf(start)));
const busyCode = between('var setUploading = function (busy)', 'var createReviewCard');
const cancelCode = between("cancelButtons.forEach(function (button) { button.addEventListener('click', function ()", "reviewList.addEventListener('click'");
const submitCode = between("uploadForm.addEventListener('submit', async function (event)", '\n});\n</script>');
const flush = () => new Promise(resolve => setImmediate(resolve));

function setup({ preparing = false, large = false, deferred = false } = {}) {
  const element = () => ({ disabled: false, style: {}, classList: { add() {}, remove() {} }, listeners: {},
    addEventListener(name, callback) { this.listeners[name] = callback; }, setAttribute() {},
    querySelectorAll() { return []; }, append(...children) { this.children = children; this.firstChild = children[0]; } });
  const elements = Object.fromEntries(['progress', 'progressLabel', 'progressCount', 'progressBar', 'submitButton', 'uploadBatchInput', 'selectionSummary', 'uploadPanel', 'reviewList', 'folder_id'].map(id => [id, element()]));
  elements.folder_id.value = ''; elements.uploadBatchInput.value = '';
  const cancel = element(), form = element(); form.action = '/upload';
  const state = { requests: 0, cleanups: 0, aborted: false, cleared: false, refreshes: 0, timers: [], parts: [], listeners: {} };
  const root = { hidden: false, isConnected: true };
  const abortWait = signal => new Promise((resolve, reject) => signal.addEventListener('abort', () => {
    state.aborted = true;
    const error = new Error('Aborted'); error.name = 'AbortError'; reject(error);
  }, { once: true }));
  class FormData { constructor() { this.fields = {}; } append(key, value) { this.fields[key] = value; } }
  const context = vm.createContext({ ...elements, AbortController, FormData, Uint8Array,
    document: { getElementById: id => elements[id], createElement: element },
    window: { crypto: require('node:crypto').webcrypto, setTimeout(callback) { state.timers.push(callback); }, clearTimeout,
      location: { href: '/workspace/file-management?folder=7' },
      GreyhoundAjax: { refresh() { state.refreshes++; return Promise.resolve(); } },
      addEventListener(name, listener) { state.listeners[name] = listener; } },
    uploadWorkspace: root,
    syncFolderPicker() {},
    uploadForm: form, destinationInput: elements.folder_id, uploadSession: null,
    cancelButtons: [cancel], pickerButtons: [], duplicateModeInputs: [],
    csrfInput: null, csrfToken: '', maxUploadBytes: 0, uploadChunkBytes: 4,
    uploading: false, cancelRequested: false, uploadController: null,
    selectedFiles: [1, 2].map(id => ({ id, relativePath: '', file: { name: id + '.mp4', size: large ? 10 : 3, slice(start, end) { return { size: end - start }; } } })),
    clearSelection() { state.cleared = true; }, updateSelection() {}, releasePreview() {}, releaseAllPreviews() {},
    selectedDuplicateMode: () => 'media_library', duplicateModeName: () => 'Media Library',
    refreshUploadToken: signal => preparing ? abortWait(signal) : Promise.resolve(),
    fetch(url, options) {
      if (options.body.fields.cancel_upload) {
        state.cleanups++;
        return Promise.resolve({ ok: true, json: async () => ({ ok: true, cancelled: true }) });
      }
      state.requests++;
      state.parts.push(options.body.fields);
      if (deferred) return new Promise(resolve => { state.completePart = () => resolve({ ok: true, json: async () => ({ ok: true, uploaded: 1 }) }); });
      if (large && options.body.fields.chunk_offset === '0') {
        return Promise.resolve({ ok: true, json: async () => ({ ok: true, nextOffset: 4 }) });
      }
      return abortWait(options.signal);
    }
  });
  vm.runInContext(sessionCode + '\n' + chunkCode + '\n' + busyCode + '\n' + cancelCode + '\n' + submitCode, context);
  context.uploadSession = context.window.GreyhoundUploadSession;
  context.bindPickers = () => { state.bound = (state.bound || 0) + 1; };
  context.previewController = new AbortController();
  context.previews = { destroy() { state.destroyed = true; } };
  vm.runInContext(between('if (uploadSession) uploadSession.register({', '        [filesInput, folderInput]'), context);
  const navigate = library => {
    const body = { children: [], appendChild(node) { this.children.push(node); },
      querySelector() { return library ? { replaceWith(node) { body.restored = node; } } : null; } };
    context.uploadSession.beforeReplace(body);
    if (library && body.restored) context.uploadSession.restore(body.restored);
    return body;
  };
  return { state, context, cancel, elements, root, navigate, start: () => form.listeners.submit({ preventDefault() {} }) };
}

test('Cancel stays enabled during upload, aborts the request, and stops the queue', async () => {
  const app = setup(); const pending = app.start(); await flush();
  assert.equal(app.cancel.disabled, false);
  assert.equal(app.state.requests, 1);
  app.cancel.listeners.click(); await pending;
  assert.equal(app.state.aborted, true);
  assert.equal(app.state.requests, 1);
  assert.equal(app.context.uploading, false);
  assert.match(app.elements.progressLabel.textContent, /Upload cancelled/);
  assert.equal(app.cancel.disabled, false);
  app.cancel.listeners.click(); assert.equal(app.state.cleared, true);
});

test('cancelling while preparing the upload sends no files', async () => {
  const app = setup({ preparing: true }); const pending = app.start(); await flush();
  app.cancel.listeners.click(); await pending;
  assert.equal(app.state.requests, 0);
  assert.equal(app.context.uploading, false);
  assert.match(app.elements.progressLabel.textContent, /Upload cancelled/);
});

test('cancelling a large upload removes its partial data and resets it for a fresh attempt', async () => {
  const app = setup({ large: true }); const pending = app.start(); await flush();
  assert.equal(app.state.requests, 2);
  app.cancel.listeners.click(); await pending;
  assert.equal(app.state.requests, 2);
  assert.equal(app.state.cleanups, 1);
  assert.equal(app.context.selectedFiles[0].chunkState.id, undefined);
  assert.equal(app.context.uploading, false);
});

test('upload survives iFound and return with the original queue, destination and cancel control', async () => {
  const app = setup(); app.elements.folder_id.value = '7';
  const pending = app.start(); await flush();
  const controller = app.context.uploadController;
  const batchId = app.elements.uploadBatchInput.value;
  const away = app.navigate(false);
  assert.equal(app.root.hidden, true);
  assert.match(away.children[1].firstChild.textContent, /continuing in the background/);
  const back = app.navigate(true);
  assert.equal(back.restored, app.root);
  assert.equal(app.root.hidden, false);
  assert.equal(app.context.uploadController, controller);
  assert.equal(controller.signal.aborted, false);
  assert.equal(app.context.selectedFiles.length, 2);
  assert.equal(app.elements.uploadBatchInput.value, batchId);
  assert.equal(app.elements.folder_id.value, '7');
  assert.equal(app.state.requests, 1);
  assert.equal(app.state.destroyed, undefined);
  app.cancel.listeners.click(); await pending;
  assert.equal(controller.signal.aborted, true);
  assert.match(app.elements.progressLabel.textContent, /Upload cancelled/);
});

test('completing the batch on iFound preserves the destination and does not refresh iFound', async () => {
  const app = setup({ deferred: true }); app.elements.folder_id.value = '7';
  const pending = app.start(); await flush();
  const away = app.navigate(false);
  // Upload callbacks must not depend on the currently displayed page's fields.
  app.context.document.getElementById = () => null;
  app.state.completePart(); await flush();
  assert.equal(app.state.requests, 2);
  assert.deepEqual(app.state.parts.map(part => part.folder_id), ['7', '7']);
  app.state.completePart(); await pending;
  assert.equal(app.context.uploading, false);
  assert.equal(app.context.selectedFiles.length, 0);
  assert.match(away.children[1].firstChild.textContent, /finished/);
  app.state.timers.forEach(callback => callback());
  assert.equal(app.state.refreshes, 0);
  const back = app.navigate(true);
  assert.equal(back.restored, undefined);
  assert.equal(app.state.destroyed, true);
});

test('completed upload refreshes the visible library but does not discard a new selection', async () => {
  for (const selectMore of [false, true]) {
    const app = setup({ deferred: true }); const pending = app.start(); await flush();
    app.state.completePart(); await flush(); app.state.completePart(); await pending;
    if (selectMore) app.context.selectedFiles.push({ id: 3 });
    app.state.timers.forEach(callback => callback());
    assert.equal(app.state.refreshes, selectMore ? 0 : 1);
  }
});

test('reload warning only applies during an active upload, including while on iFound', async () => {
  const app = setup(); let prevented = 0;
  const event = { preventDefault() { prevented++; } };
  app.state.listeners.beforeunload(event);
  assert.equal(prevented, 0);
  const pending = app.start(); await flush(); app.navigate(false);
  app.state.listeners.beforeunload(event);
  assert.equal(prevented, 1);
  assert.equal(event.returnValue, '');
  app.navigate(true); app.cancel.listeners.click(); await pending;
  app.state.listeners.beforeunload(event);
  assert.equal(prevented, 1);
});

test('Local and Server uploads retain separate roots and destinations across library switches', () => {
  const app = setup();
  app.root.dataset = { library: 'local' };
  const server = { dataset: { library: 'server' }, hidden: false, id: 'uploadWorkspace' };
  let serverBinds = 0;
  app.context.uploadSession.register({ root: server, url: '/workspace/server-library?folder=Events', shouldRetain: () => true, isBusy: () => true,
    bindPickers() { serverBinds++; }, destroy() { throw new Error('Active server upload destroyed'); } });
  function navigate(library) {
    const body = { children: [], appendChild(node) { this.children.push(node); },
      querySelector() { return { dataset: { library }, replaceWith(root) { body.restored = root; } }; } };
    app.context.uploadSession.beforeReplace(body);
    return body;
  }
  const localPage = navigate('local');
  assert.equal(localPage.restored, app.root);
  assert.equal(app.root.hidden, false); assert.equal(server.hidden, true);
  assert.equal(server.id, 'uploadWorkspace-server');
  assert.equal(app.context.uploadSession.restore(app.root), true);
  assert.equal(app.context.uploadSession.restore({ dataset: { library: 'server' } }), false);
  const serverPage = navigate('server');
  assert.equal(serverPage.restored, server);
  assert.equal(server.hidden, false); assert.equal(app.root.hidden, true);
  assert.equal(app.root.id, 'uploadWorkspace-local');
  assert.equal(server.id, 'uploadWorkspace');
  assert.equal(app.context.uploadSession.restore(server), true);
  assert.equal(serverBinds, 1);
});

test('Server uploads work without duplicate controls, scan fields, or scan progress messages', async () => {
  const app = setup({ deferred: true });
  app.context.uploadWorkspace.dataset = { library: 'server' };
  app.context.duplicateModeHelp = null;
  assert.doesNotMatch(view, /name="duplicate_mode"|selectedDuplicateMode/);
  const pending = app.start(); await flush();
  assert.match(app.elements.progressLabel.textContent, /Uploading and processing/);
  assert.doesNotMatch(app.elements.progressLabel.textContent, /scan/i);
  app.state.completePart(); await flush(); app.state.completePart(); await pending;
  assert.equal(app.state.requests, 2);
  app.state.parts.forEach(part => assert.equal(Object.hasOwn(part, 'duplicate_mode'), false));
  assert.match(app.elements.progressLabel.textContent, /Upload complete/);
  assert.doesNotMatch(app.elements.progressLabel.textContent, /duplicate|scan/i);
});
