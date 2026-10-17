const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const path = require('node:path');
const source = fs.readFileSync(path.join(__dirname, '../../public/assets/js/ajax-app.js'), 'utf8');
const view = fs.readFileSync(path.join(__dirname, '../../app/Views/workspace/media.php'), 'utf8');
const init = view.slice(view.indexOf('<main')).match(/<script>([\s\S]*?)<\/script>/)[1];
const flush = () => new Promise(resolve => setImmediate(resolve));

test('full reloads still restore saved layouts before rendering', () => {
  for (const saved of ['list', 'grid', null, 'invalid']) {
    const workspace = { dataset: {} };
    vm.runInNewContext(init, {
      document: { getElementById: () => workspace },
      window: { localStorage: { getItem: () => saved, setItem() {} } }
    });
    assert.equal(workspace.dataset.mediaLayout, saved === 'list' ? 'list' : 'grid');
  }
});

function setup(layout = 'list', storageBlocked = false, liveSearch = /<form\b[^>]*id="mediaFilterForm"[^>]*data-ajax-live-search/.test(view)) {
  const events = {}, timers = new Map(), requests = [], attachments = [], scrolls = [];
  let nextTimer = 0, current = { dataset: { mediaLayout: layout } };
  class Element {
    constructor() { this.dataset = {}; this.isConnected = true; this.classList = { add() {}, toggle() {} }; }
    setAttribute() {}
    removeAttribute() {}
    appendChild() {}
    matches() { return false; }
  }
  class Form extends Element {
    constructor(id = 'mediaFilterForm') { super(); this.id = id; }
    getAttribute(name) { return { method: 'get', 'data-ajax-live-delay': '400' }[name] || null; }
    matches(selector) { return (liveSearch && selector === '[data-ajax-live-search]') || (this.preserveScroll && selector === '[data-ajax-preserve-scroll]'); }
    querySelector() { return null; }
    requestSubmit() { submit(this); }
  }
  class Input extends Element {
    constructor(form) { super(); this.form = form; }
    closest() { return liveSearch ? this.form : null; }
    matches() { return true; }
  }
  class Anchor extends Element {
    getAttribute(name) { return name === 'href' ? this.href : null; }
    hasAttribute(name) { return name === 'data-search-clear' || name === 'data-ajax-preserve-scroll'; }
  }
  const progress = new Element();
  const form = new Form();
  const document = {
    activeElement: null,
    head: new Element(),
    addEventListener(type, callback) { events[type] = callback; },
    getElementById(id) { return id === 'main' ? current : progress; },
    querySelector(selector) { return selector === '.media-workspace[data-media-layout]' ? current : null; },
    createElement() { return new Element(); },
    importNode(body) { return body; }
  };
  function makeBody(main, label) {
    return {
      querySelector() { return main; },
      querySelectorAll() { return [{ textContent: init, remove() {} }]; },
      replaceWith(body) {
        attachments.push({ layout: body.main.dataset.mediaLayout, label: body.label });
        current = body.main;
        document.body = body;
        form.isConnected = false;
      },
      main, label
    };
  }
  document.body = makeBody(current, 'initial');
  const window = {
    location: { href: 'http://example.test/workspace/file-management', origin: 'http://example.test' },
    scrollY: 480,
    history: { replaceState() {}, pushState() {} },
    addEventListener() {},
    requestAnimationFrame(fn) { fn(); },
    setTimeout(fn) { const id = ++nextTimer; timers.set(id, fn); return id; },
    clearTimeout(id) { timers.delete(id); },
    scrollTo(x, y) { scrolls.push(y); },
    localStorage: { getItem() { if (storageBlocked) throw Error('Blocked'); return 'grid'; }, setItem() {} }
  };
  function FormData() { this.forEach = callback => callback('photo', 'q'); }
  function DOMParser() {
    this.parseFromString = label => {
      const main = { dataset: {} };
      return { body: makeBody(main, label), querySelector: () => main, title: 'File Management' };
    };
  }
  const fetch = (url, options) => new Promise(resolve => requests.push({ url: String(url), resolve, signal: options.signal }));
  Object.assign(window, { fetch, FormData, DOMParser });
  vm.runInNewContext(source, { window, document, fetch, FormData, DOMParser, URL, Element, HTMLFormElement: Form, HTMLAnchorElement: Anchor, AbortController, Event });
  function submit(target = form) {
    events.submit({ target, preventDefault() {}, defaultPrevented: false });
  }
  return {
    form, requests, attachments, scrolls, window,
    get layout() { return current.dataset.mediaLayout; },
    set layout(value) { current.dataset.mediaLayout = value; },
    submit,
    submitOther() { submit(new Form('otherSearch')); },
    type() { events.input({ target: new Input(form) }); },
    resetField(route, value = '') {
      const input = new Input(form), link = new Anchor();
      input.value = value;
      link.href = 'http://example.test' + route;
      link.hidden = value.length === 0;
      const wrapper = { querySelector: selector => selector === '[data-search-clear]' ? link : input };
      input.closest = selector => selector === '[data-search-reset]' ? wrapper : null;
      link.closest = selector => selector === 'a' ? link : selector === '[data-search-reset]' ? wrapper : null;
      return { input, link,
        type(value) { input.value = value; events.input({ target: input }); },
        clear() { events.click({ target: link, button: 0, preventDefault() {}, defaultPrevented: false }); }
      };
    },
    tick() { const pending = [...timers.values()]; timers.clear(); pending.forEach(fn => fn()); },
    respond(index, html) { requests[index].resolve({ ok: true, headers: { get: () => 'text/html' }, text: () => Promise.resolve(html) }); }
  };
}

test('connection and admin health requests stop before the next page fetch', async () => {
  const app = setup();
  const stopped = [];
  app.window.greyhoundServerLibraryCleanup = () => stopped.push(['connection', app.requests.length]);
  app.window.greyhoundAdminHealthCleanup = () => stopped.push(['health', app.requests.length]);
  const pending = app.window.GreyhoundAjax.navigate('/workspace/file-management');
  assert.deepEqual(stopped, [['connection', 0], ['health', 0]]);
  assert.equal(app.window.greyhoundServerLibraryCleanup, null);
  assert.equal(app.window.greyhoundAdminHealthCleanup, null);
  app.respond(0, 'next'); await pending;
  assert.equal(stopped.length, 2);
});

test('Server Library reads are stopped before navigation fetch starts, and cancelled pages cannot replace the current page',async()=>{
  const app=setup();let requestCountAtPause;
  app.window.greyhoundServerLibraryBeforeNavigate=()=>{requestCountAtPause=app.requests.length;};
  const pending=app.window.GreyhoundAjax.navigate('/workspace/file-management');
  assert.equal(requestCountAtPause,0);
  assert.equal(app.requests.length,1);
  app.window.GreyhoundAjax.cancel();
  assert.equal(app.requests[0].signal.aborted,true);
  app.respond(0,'too late');await pending;
  assert.equal(app.attachments.length,0);
});

for (const layout of ['list', 'grid']) {
  test(`filters attach ${layout} layout before initialization and preserve scroll`, async () => {
    const app = setup(layout, true);
    app.submit();
    app.respond(0, 'filtered');
    await flush();
    assert.deepEqual(app.attachments, [{ layout, label: 'filtered' }]);
    assert.equal(app.layout, layout);
    assert.deepEqual(app.scrolls, [480]);
  });
}

test('a layout changed while filtering is retained when the response arrives', async () => {
  const app = setup('grid');
  app.submit();
  app.layout = 'list';
  app.respond(0, 'filtered');
  await flush();
  assert.equal(app.attachments[0].layout, 'list');
  assert.equal(app.layout, 'list');
});

for (const [label, viewPath, formPattern] of [
  ['Local Library', 'workspace/media.php', /<form\b[^>]*id="mediaFilterForm"[^>]*>/],
  ['iFound', 'workspace/ifound.php', /<form\b[^>]*method="get"[^>]*>/],
  ['Metadata Passports', 'addons/passports.php', /<form\b[^>]*class="passport-search[^>]*>/],
]) {
  test(`${label} waits for Enter or Search rather than searching while typing or clearing`, () => {
    const markup = fs.readFileSync(path.join(__dirname, '../../app/Views', viewPath), 'utf8');
    const form = markup.match(formPattern);
    assert.ok(form, 'Search form is present');
    const app = setup('list', false, form[0].includes('data-ajax-live-search'));
    app.type();
    app.tick();
    assert.equal(app.requests.length, 0);
    app.type(); // Editing or deleting text by keyboard still waits for submission.
    app.tick();
    assert.equal(app.requests.length, 0);
    app.submit();
    app.tick();
    assert.equal(app.requests.length, 1);
  });
}

for (const route of ['/workspace/ifound', '/workspace/passports']) {
  test(`${route} shows X while typing and restores the unfiltered first page on click`, async () => {
    const app = setup('list', false, false);
    app.window.location.href = 'http://example.test' + route + '?q=old&page=7';
    const field = app.resetField(route);
    assert.equal(field.link.hidden, true);
    field.type('BS Computer Science'); app.tick();
    assert.equal(field.link.hidden, false);
    assert.equal(app.requests.length, 0);
    field.type('');
    assert.equal(field.link.hidden, true);
    assert.equal(app.requests.length, 0);
    field.type('BS Computer Science');
    field.clear();
    assert.equal(field.input.value, '');
    assert.equal(field.link.hidden, true);
    assert.equal(app.requests.length, 1);
    assert.equal(app.requests[0].url, 'http://example.test' + route);
    app.respond(0, 'unfiltered'); await flush();
    assert.equal(app.attachments[0].label, 'unfiltered');
    assert.deepEqual(app.scrolls, [480]);
    // Delegated handlers also work on the controls from the replacement page.
    const replacement = app.resetField(route, 'Another image');
    assert.equal(replacement.link.hidden, false);
    replacement.clear();
    assert.equal(app.requests.length, 2);
    assert.equal(app.requests[1].url, 'http://example.test' + route);
  });
}

test('manual AJAX search keeps the search page scroll position without enabling live search', async () => {
  const app = setup('list', false, false);
  app.form.id = 'passportSearchForm';
  app.form.preserveScroll = true;
  app.type(); app.tick();
  assert.equal(app.requests.length, 0);
  app.submit();
  assert.equal(app.requests.length, 1);
  app.respond(0, 'search results'); await flush();
  assert.deepEqual(app.scrolls, [480]);
  assert.equal(app.attachments[0].label, 'search results');
});

test('formerly live search waits for explicit submission and preserves scroll', async () => {
  const app = setup('list', false, true);
  app.type();
  app.tick();
  assert.equal(app.requests.length, 0);
  app.submit();
  assert.equal(app.requests.length, 1);
  app.type();
  app.respond(0, 'filtered');
  await flush();
  app.tick();
  assert.equal(app.requests.length, 1);
  assert.deepEqual(app.scrolls, [480]);
});

test('an older response finishing its body after newer results cannot replace them', async () => {
  const app = setup();
  let finishOldBody;
  app.submit();
  app.requests[0].resolve({ ok: true, headers: { get: () => 'text/html' }, text: () => new Promise(resolve => { finishOldBody = resolve; }) });
  await flush();
  app.submit();
  app.respond(1, 'newer');
  await flush();
  finishOldBody('older');
  await flush();
  assert.deepEqual(app.attachments.map(item => item.label), ['newer']);
});

test('unrelated GET forms retain their existing scroll-to-top behavior', async () => {
  const app = setup();
  app.submitOther();
  app.respond(0, 'other');
  await flush();
  assert.deepEqual(app.scrolls, [0]);
});

test('file-type menu applies the selected type immediately, including the native fallback', () => {
  const filterView = fs.readFileSync(path.join(__dirname, '../../app/Views/components/media_filter_script.php'), 'utf8');
  const start = filterView.indexOf("        var typeSelect = document.getElementById('mediaType');");
  const dropdownScript = filterView.slice(start, filterView.lastIndexOf("});"));
  let closed = 0, focused = 0;
  const submittedTypes = [];
  const options = [['', 'All file types'], ['jpg', 'JPG image'], ['mp4', 'MP4 video']].map(([value, label]) => ({
    dataset: { mediaType: value }, attributes: {},
    addEventListener(event, handler) { this[event] = handler; },
    querySelector() { return { textContent: label }; },
    setAttribute(name, value) { this.attributes[name] = value; },
  }));
  const elements = {
    mediaType: {
      value: '', hidden: false,
      addEventListener(event, handler) { this[event] = handler; },
      form: { requestSubmit() { submittedTypes.push(elements.mediaType.value); } },
    },
    mediaTypeDropdown: { hidden: true, querySelectorAll: () => options },
    mediaTypeToggle: { attributes: {}, setAttribute(name, value) { this.attributes[name] = value; }, focus() { focused++; } },
    mediaTypeLabel: { textContent: 'All file types' },
  };
  const bootstrap = { Dropdown: { getOrCreateInstance: () => ({ hide() { closed++; } }) } };
  vm.runInNewContext(dropdownScript, { window: { bootstrap }, bootstrap, document: { getElementById: id => elements[id] } });
  assert.equal(elements.mediaType.hidden, true);
  assert.equal(elements.mediaTypeDropdown.hidden, false);
  options[2].click();
  assert.equal(elements.mediaType.value, 'mp4');
  assert.equal(elements.mediaTypeLabel.textContent, 'MP4 video');
  assert.equal(elements.mediaTypeToggle.attributes['aria-label'], 'File type: MP4 video');
  assert.deepEqual(options.map(option => option.attributes['aria-checked']), ['false', 'false', 'true']);
  assert.equal(closed, 1); assert.equal(focused, 1);
  assert.deepEqual(submittedTypes, ['mp4']);
  options[0].click(); assert.equal(elements.mediaType.value, '');
  assert.deepEqual(submittedTypes, ['mp4', '']);
  // The native select remains usable if the menu library is unavailable.
  elements.mediaType.hidden = false; elements.mediaTypeDropdown.hidden = true;
  vm.runInNewContext(dropdownScript, { window: {}, document: { getElementById: id => elements[id] } });
  assert.equal(elements.mediaType.hidden, false); assert.equal(elements.mediaTypeDropdown.hidden, true);
  elements.mediaType.value = 'jpg';
  elements.mediaType.change();
  assert.deepEqual(submittedTypes, ['mp4', '', 'jpg']);
});
