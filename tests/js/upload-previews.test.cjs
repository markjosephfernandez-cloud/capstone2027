const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const path = require('node:path');
const script = fs.readFileSync(path.join(__dirname, '../../public/assets/js/upload-previews.js'), 'utf8');
const flush = () => new Promise(resolve => setImmediate(resolve));

function setup({ observer = true, bitmap = true, decodeError = false } = {}) {
  const decodes = [], draws = [], urls = [], revoked = [];
  let visibility, closed = 0, disconnected = false;
  class Element {
    constructor(tag) { this.tag = tag; this.children = []; this.listeners = {}; }
    replaceChildren(...children) { this.children = children; }
    addEventListener(event, fn) { this.listeners[event] = fn; }
  }
  class Observer {
    constructor(callback) { visibility = callback; }
    observe() {}
    unobserve() {}
    disconnect() { disconnected = true; }
  }
  class Image {
    constructor() { this.naturalWidth = 6000; this.naturalHeight = 4000; }
    set src(value) { queueMicrotask(() => decodeError ? this.onerror() : this.onload()); }
  }
  const document = {
    createElement(tag) {
      const element = new Element(tag);
      if (tag === 'canvas') {
        element.getContext = () => ({ drawImage(...args) { draws.push(args); } });
        element.toBlob = callback => callback({ type: 'image/webp', width: element.width, height: element.height });
      }
      return element;
    }
  };
  const window = {
    URL: {
      createObjectURL(blob) { const url = 'blob:' + urls.length; urls.push({ blob, url }); return url; },
      revokeObjectURL(url) { revoked.push(url); }
    }
  };
  if (observer) window.IntersectionObserver = Observer;
  if (bitmap) window.createImageBitmap = (file, options) => new Promise(resolve => {
    decodes.push({ file, options, finish() { resolve({ width: 320, height: 480, close() { closed++; } }); } });
  });
  vm.runInNewContext(script, { window, document, IntersectionObserver: Observer, Image });
  const manager = window.GreyhoundUploadPreviews.create(new Element('ul'));
  const cards = [];
  const errors = [];
  function add(id) {
    const target = new Element('div');
    const file = Object.freeze({ name: id + '.jpg', size: 4000000, type: 'image/jpeg' });
    cards[id] = target;
    manager.add(id, file, target, () => errors.push(id));
    return file;
  }
  return { manager, add, cards, decodes, draws, urls, revoked, errors,
    visible(ids, value = true) { visibility(ids.map(id => ({ target: cards[id], isIntersecting: value }))); },
    get closed() { return closed; }, get disconnected() { return disconnected; }
  };
}

test('329-file batch decodes visible cards only, at most two concurrently, and caches small previews', async () => {
  const app = setup();
  for (let id = 0; id < 329; id++) app.add(id);
  assert.equal(app.decodes.length, 0);
  app.visible([0, 1, 2]);
  assert.equal(app.decodes.length, 2);
  assert.equal(app.decodes[0].options.resizeWidth, 320);
  app.decodes[0].finish(); await flush();
  assert.equal(app.decodes.length, 3);
  app.decodes[1].finish(); app.decodes[2].finish(); await flush();
  assert.equal(app.urls.length, 3);
  assert.equal(app.closed, 3);
  for (const entry of app.urls) {
    assert.equal(entry.blob.type, 'image/webp');
    assert.ok(Math.max(entry.blob.width, entry.blob.height) <= 320);
    assert.equal(entry.blob.size, undefined); // The original 4 MB File is never used as the displayed URL.
  }
  assert.equal(app.cards[0].children[0].tag, 'img');
  assert.equal(app.cards[0].children[0].alt, '0.jpg');
  app.visible([0, 1, 2]);
  assert.equal(app.decodes.length, 3);
  app.manager.destroy();
  assert.equal(app.revoked.length, 3);
});

test('scrolling skips waiting offscreen cards; clearing during decode cannot resurrect previews', async () => {
  const app = setup();
  for (let id = 0; id < 5; id++) app.add(id);
  app.visible([0, 1, 2]);
  app.visible([2], false);
  app.visible([4]);
  app.manager.remove(0);
  app.decodes[0].finish(); await flush();
  assert.equal(app.decodes[2].file.name, '4.jpg');
  assert.equal(app.urls.length, 0);
  app.manager.clear();
  app.decodes[1].finish(); app.decodes[2].finish(); await flush();
  assert.equal(app.urls.length, 0);
  assert.equal(app.closed, 3);
  app.add(5); app.visible([5]);
  app.manager.destroy();
  app.decodes[3].finish(); await flush();
  assert.equal(app.urls.length, 0);
  assert.equal(app.disconnected, true);
});

test('fallback decoder produces small previews and releases the original-file URL', async () => {
  const app = setup({ observer: false, bitmap: false });
  const original = app.add(0);
  await flush();
  assert.equal(app.urls[0].blob, original);
  assert.equal(app.urls[1].blob.width, 320);
  assert.equal(app.urls[1].blob.height, 213);
  assert.equal(app.cards[0].children[0].src, app.urls[1].url);
  assert.deepEqual(app.revoked, [app.urls[0].url]);
  app.manager.remove(0);
  assert.deepEqual(app.revoked, app.urls.map(entry => entry.url));
});

test('unsupported images report a fallback and release resources', async () => {
  const app = setup({ observer: false, bitmap: false, decodeError: true });
  app.add(0); await flush();
  assert.deepEqual(app.errors, [0]);
  assert.deepEqual(app.revoked, [app.urls[0].url]);
  app.manager.destroy();
});
