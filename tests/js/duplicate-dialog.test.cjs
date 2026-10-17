const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const path = require('node:path');
const view = fs.readFileSync(path.join(__dirname, '../../app/Views/workspace/media.php'), 'utf8');
const script = [...view.matchAll(/<script>([\s\S]*?)<\/script>/g)].map(match => match[1]).find(code => code.includes("getElementById('duplicateGroupsBody')"));

function setup() {
  class Element {
    constructor() { this.listeners = {}; this.children = []; this.isConnected = true; this.dataset = {}; }
    addEventListener(event, fn) { this.listeners[event] = fn; }
    setAttribute() {}
    removeAttribute() {}
    replaceChildren() { this.children = []; }
    appendChild(child) { this.children.push(child); }
    querySelectorAll() { return this.boxes || []; }
  }
  const ids = ['duplicatesModal', 'duplicateGroupsBody', 'duplicateSelectionCount', 'clearDuplicateSelection', 'selectAllDuplicates', 'restoreSelectedDuplicates', 'restoreDuplicateInputs'];
  const elements = Object.fromEntries(ids.map(id => [id, new Element()]));
  elements.duplicateGroupsBody.dataset.groupsUrl = '/workspace/file-management/duplicates/groups';
  let requests = 0, finish;
  const document = {
    getElementById: id => elements[id],
    createElement: () => new Element(),
    addEventListener(event, fn) { fn(); }
  };
  vm.runInNewContext(script, { document, fetch: () => {
    requests++;
    return new Promise(resolve => { finish = resolve; });
  } });
  return { elements, get requests() { return requests; },
    open: () => elements.duplicatesModal.listeners['show.bs.modal'](),
    respond(ok, html = '<section>Duplicate files</section>') { finish({ ok, json: async () => ({ html }) }); }
  };
}

test('loads only when opened, prevents duplicate requests, and restores dynamically loaded selections', async () => {
  const app = setup();
  assert.equal(app.requests, 0);
  const pending = app.open();
  await app.open();
  assert.equal(app.requests, 1);
  const boxes = [{ value: '20', checked: false }, { value: '21', checked: false }];
  app.elements.duplicateGroupsBody.boxes = boxes;
  app.respond(true);
  await pending;
  app.elements.selectAllDuplicates.listeners.click();
  assert.equal(app.elements.duplicateSelectionCount.textContent, '2 selected');
  assert.equal(app.elements.restoreSelectedDuplicates.disabled, false);
  assert.deepEqual(app.elements.restoreDuplicateInputs.children.map(input => [input.name, input.value]), [['duplicate_ids[]', '20'], ['duplicate_ids[]', '21']]);
  app.elements.clearDuplicateSelection.listeners.click();
  assert.equal(app.elements.restoreSelectedDuplicates.disabled, true);
  await app.open();
  assert.equal(app.requests, 1);
});

test('failed loading offers a retry and leaves restore disabled', async () => {
  const app = setup();
  let pending = app.open();
  app.respond(false);
  await pending;
  assert.match(app.elements.duplicateGroupsBody.innerHTML, /data-retry-duplicates/);
  assert.equal(app.elements.restoreSelectedDuplicates.disabled, true);
  pending = app.open();
  app.respond(true);
  await pending;
  assert.equal(app.requests, 2);
  assert.equal(app.elements.selectAllDuplicates.disabled, true);
});

test('a response arriving after navigation does not update the discarded dialog', async () => {
  const app = setup();
  const pending = app.open();
  app.elements.duplicatesModal.isConnected = false;
  app.respond(true, '<section>Should not be inserted</section>');
  await pending;
  assert.doesNotMatch(app.elements.duplicateGroupsBody.innerHTML, /Should not be inserted/);
});
