const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const path = require('node:path');
const source = fs.readFileSync(path.join(__dirname, '../../public/assets/js/media-search-suggestions.js'), 'utf8');
const flush = () => new Promise(resolve => setImmediate(resolve));
function setup() {
  class Element {
    constructor() { this.listeners = {}; this.attributes = {}; this.dataset = {}; this.children = []; this.value = ''; this.hidden = true; this.isConnected = true; }
    addEventListener(name, fn) { (this.listeners[name] ||= new Set()).add(fn); }
    removeEventListener(name, fn) { this.listeners[name]?.delete(fn); }
    fire(name, values = {}) { const event = { target: this, preventDefault() { this.prevented = true; }, ...values }; for (const fn of this.listeners[name] || []) fn(event); return event; }
    setAttribute(name, value) { this.attributes[name] = value; }
    removeAttribute(name) { delete this.attributes[name]; }
    replaceChildren() { this.children = []; }
    append(...nodes) { this.children.push(...nodes); }
    appendChild(node) { this.append(node); }
    scrollIntoView() {}
  }
  const ids = Object.fromEntries(['mediaSearch', 'mediaSearchSuggestions', 'mediaSearchStatus', 'mediaFilterForm'].map(id => [id, new Element()]));
  const input = ids.mediaSearch, list = ids.mediaSearchSuggestions, form = ids.mediaFilterForm;
  input.dataset.suggestionsUrl = '/workspace/file-management/suggestions';
  const fields = { type: { value: 'jpg' }, stage: { value: '' }, distribution: { value: '' } };
  form.elements = { namedItem: name => fields[name] };
  let submissions = 0, timerId = 0;
  form.requestSubmit = () => { submissions++; form.fire('submit'); };
  const timers = new Map(), requests = [];
  const document = { activeElement: input, getElementById: id => ids[id], createElement: () => new Element() };
  const window = { location: { href: 'http://localhost/workspace/file-management' }, addEventListener() {} };
  vm.runInNewContext(source, { window, document, URL, AbortController,
    setTimeout: (fn, delay) => { timers.set(++timerId, { fn, delay }); return timerId; }, clearTimeout: id => timers.delete(id),
    fetch: (url, config) => new Promise((resolve, reject) => requests.push({ url, config, resolve, reject })),
  });
  window.GreyhoundMediaSearch.init();
  return { input, list, form, fields, requests, ids, window,
    get submissions() { return submissions; },
    type(value) { input.value = value; input.fire('input'); },
    tick() { for (const [id, timer] of [...timers]) if (timer.delay === 300) { timers.delete(id); timer.fn(); } },
    async reply(index, values) { requests[index].resolve({ ok: true, json: async () => ({ suggestions: values }) }); await flush(); },
    key(key) { return input.fire('keydown', { key }); },
  };
}

test('typing fetches debounced suggestions without searching; clicking submits the selected text once', async () => {
  const app = setup(); app.type('c'); app.tick(); assert.equal(app.requests.length, 0);
  app.type('ca'); app.type('camp'); app.tick(); assert.equal(app.requests.length, 1); assert.equal(app.submissions, 0);
  assert.match(app.requests[0].url, /q=camp&type=jpg/);
  await app.reply(0, [{ value: 'Campus', kind: 'Folder' }, { value: 'Campus event', kind: 'Tag' }]);
  assert.equal(app.list.hidden, false); assert.equal(app.input.attributes['aria-expanded'], 'true');
  assert.equal(app.list.children[0].children[0].textContent, 'Campus');
  assert.equal(app.list.children[0].fire('pointerdown').prevented, true);
  app.list.children[0].fire('click');
  assert.equal(app.input.value, 'Campus'); assert.equal(app.submissions, 1); assert.equal(app.list.hidden, true);
});

test('keyboard selection searches the highlighted suggestion while plain Enter remains a normal form submit', async () => {
  const app = setup(); app.type('camp'); app.tick();
  await app.reply(0, [{ value: 'Campus', kind: 'Folder' }, { value: 'Campus event', kind: 'Tag' }]);
  assert.equal(app.key('Enter').prevented, undefined); assert.equal(app.submissions, 0);
  assert.equal(app.key('ArrowDown').prevented, true); app.key('ArrowDown');
  assert.equal(app.input.attributes['aria-activedescendant'], 'mediaSuggestion-1');
  app.key('Enter'); assert.equal(app.input.value, 'Campus event'); assert.equal(app.submissions, 1);
});

test('stale requests cannot overwrite suggestions and Escape, clearing, blur and submit cancel lookups', async () => {
  for (const action of [app => app.key('Escape'), app => app.type(''), app => app.input.fire('blur'), app => app.form.fire('submit')]) {
    const app = setup(); app.type('old'); app.tick(); app.type('new'); app.tick();
    assert.equal(app.requests[0].config.signal.aborted, true);
    await app.reply(1, [{ value: 'New photo', kind: 'File' }]);
    await app.reply(0, [{ value: 'Old photo', kind: 'File' }]);
    assert.equal(app.list.children[0].children[0].textContent, 'New photo');
    app.type('pending'); app.tick(); action(app);
    assert.equal(app.requests[2].config.signal.aborted, true);
    await app.reply(2, [{ value: 'Late photo', kind: 'File' }]); assert.equal(app.list.hidden, true);
  }
});

test('failures and empty matches leave manual search available; suggestion text is not interpreted as markup', async () => {
  const app = setup(); app.type('test'); app.tick(); app.requests[0].reject(new Error('Offline')); await flush();
  assert.equal(app.list.hidden, true); assert.match(app.ids.mediaSearchStatus.textContent, /Press Enter/);
  app.type('empty'); app.tick(); await app.reply(1, []); assert.equal(app.list.hidden, true);
  app.type('image'); app.tick(); await app.reply(2, [{ value: '<img src=x onerror=alert(1)>', kind: 'File' }]);
  assert.equal(app.list.children[0].children[0].textContent, '<img src=x onerror=alert(1)>');
  assert.equal(app.submissions, 0);
});

test('IME composition and repeated initialization do not duplicate requests; detached pages cannot update', async () => {
  const app = setup(); app.input.fire('compositionstart'); app.type('camp'); app.tick(); assert.equal(app.requests.length, 0);
  app.input.fire('compositionend'); app.tick(); assert.equal(app.requests.length, 1);
  app.window.GreyhoundMediaSearch.init(); assert.equal(app.requests[0].config.signal.aborted, true);
  app.type('campus'); app.tick(); assert.equal(app.requests.length, 2);
  app.form.isConnected = false; await app.reply(1, [{ value: 'Campus', kind: 'Folder' }]);
  assert.equal(app.list.hidden, true);
});
