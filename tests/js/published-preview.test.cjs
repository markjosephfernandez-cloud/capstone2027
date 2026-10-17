const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const path = require('node:path');
const view = fs.readFileSync(path.join(__dirname, '../../app/Views/workspace/published_files.php'), 'utf8');
const script = view.slice(view.indexOf('<script>') + 8, view.indexOf('</script>'));
const photo = id => ({dataset:{previewKind:'image', previewSrc:`/photo-${id}`, previewName:`Photo ${id}`}});
function setup(buttons = [photo(1), photo(2), photo(3)]) {
  const elements = {};
  const requests = [];
  class Element {
    constructor() {
      this.listeners = {}; this.hidden = new Set(); this.isConnected = true; this.videoWidth = 720; this.pauses = 0;
      this.classList = {toggle: (name, hide) => hide ? this.hidden.add(name) : this.hidden.delete(name)};
    }
    addEventListener(name, fn) { this.listeners[name] = fn; }
    getAttribute(name) { return this[name] || null; }
    removeAttribute(name) { delete this[name]; }
    pause() { this.pauses++; }
    load() {}
  }
  class ImageLoader { constructor() { requests.push(this); } }
  vm.runInNewContext(script, {Image:ImageLoader, document:{
    getElementById: id => elements[id] ||= new Element(),
    querySelectorAll: () => buttons.filter(button => button.dataset.previewKind === 'image'),
    addEventListener: (_name, fn) => fn()
  }});
  return {elements, requests,
    open: button => elements.publishedPreviewModal.listeners['show.bs.modal']({relatedTarget:button}),
    next: () => elements.publishedPreviewNext.listeners.click(),
    previous: () => elements.publishedPreviewPrevious.listeners.click(),
    loaded: () => requests.at(-1).onload(), failed: () => requests.at(-1).onerror(),
    close: () => elements.publishedPreviewModal.listeners['hidden.bs.modal']()
  };
}

test('Published preview removes download actions and browses unique images in page order', () => {
  assert.doesNotMatch(view, /previewDownloadBtn|previewLogoBtn|data-download-url|data-logo-url/);
  const app = setup([photo(1),photo(2),photo(2),photo(3)]), e = app.elements;
  app.open(photo(2)); app.loaded();
  assert.equal(e.publishedPreviewCount.textContent, '2 / 3');
  app.next(); app.loaded();
  assert.equal(e.previewImageTarget.src, '/photo-3');
  app.next(); app.loaded();
  assert.equal(e.publishedPreviewCount.textContent, '1 / 3');
  app.previous(); app.loaded();
  assert.equal(e.publishedPreviewTitle.textContent, 'Photo 3');
  e.publishedPreviewModal.listeners.keydown({key:'ArrowLeft', preventDefault(){}}); app.loaded();
  assert.equal(e.previewImageTarget.src, '/photo-2');
});

test('Published keeps the displayed photo while loading and ignores outdated results', () => {
  const app = setup(), e = app.elements;
  app.open(photo(1)); app.loaded(); app.next();
  assert.equal(e.previewImageTarget.src, '/photo-1');
  assert.equal(e.publishedPreviewTitle.textContent, 'Photo 1');
  const staleLoad = app.requests.at(-1).onload, staleError = app.requests.at(-1).onerror;
  app.next(); app.loaded(); staleLoad(); staleError();
  assert.equal(e.previewImageTarget.src, '/photo-3');
  assert.equal(e.publishedPreviewCount.textContent, '3 / 3');
  assert.equal(e.publishedPreviewStatus.textContent, '');
});

test('Published recovers from failed images and invalidates requests on close and page replacement', () => {
  const app = setup(), e = app.elements;
  app.open(photo(1)); app.failed();
  assert.match(e.publishedPreviewStatus.textContent, /could not be loaded/);
  app.next(); app.loaded();
  assert.equal(e.previewImageTarget.src, '/photo-2');
  app.next();
  const staleLoad = app.requests.at(-1).onload, staleError = app.requests.at(-1).onerror;
  app.close(); staleLoad(); staleError(); app.next();
  assert.equal(e.previewImageTarget.src, undefined);
  assert.equal(e.publishedPreviewStatus.textContent, '');
  app.open(photo(1)); e.publishedPreviewModal.isConnected = false; app.loaded();
  assert.equal(e.previewImageTarget.src, undefined);
});

test('Published hides arrows for single images and does not interfere with video controls', () => {
  const app = setup([photo(1)]), e = app.elements;
  app.open(photo(1));
  assert.equal(e.publishedPreviewNext.hidden.has('d-none'), true);
  const staleLoad = app.requests.at(-1).onload;
  app.open({dataset:{previewKind:'video', previewSrc:'/movie', previewName:'Movie'}});
  staleLoad();
  assert.equal(e.previewImageTarget.src, undefined);
  assert.equal(e.previewVideoTarget.src, '/movie');
  assert.equal(e.publishedPreviewPrevious.hidden.has('d-none'), true);
  e.publishedPreviewModal.listeners.keydown({key:'ArrowRight', preventDefault(){assert.fail('Video keyboard controls must remain available');}});
  e.previewVideoTarget.listeners.error();
  assert.match(e.publishedPreviewStatus.textContent, /Video preview could not be loaded/);
  app.close();
  assert.equal(e.previewVideoTarget.src, undefined);
  assert.ok(e.previewVideoTarget.pauses > 0);
  const empty = setup([]); empty.next();
  assert.equal(empty.requests.length, 0);
});
