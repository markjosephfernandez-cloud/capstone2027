const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const view = fs.readFileSync(path.join(__dirname, '../../app/Views/workspace/recent.php'), 'utf8');
const script = view.slice(view.indexOf('<script>') + 8, view.indexOf('</script>'));

function setup(count) {
  const ids = ['recentPreviewModal', 'recentPreviewImage', 'recentPreviewTitle', 'recentPreviewCount', 'recentPreviewStatus', 'recentPreviewPrevious', 'recentPreviewNext'];
  const elements = Object.fromEntries(ids.map(id => {
    const element = { listeners:{}, style:{visibility:'hidden'}, isConnected:true, getAttribute(name) { return this[name] || null; }, hidden:new Set(), addEventListener(name, fn) { this.listeners[name] = fn; }, removeAttribute(name) { delete this[name]; } };
    element.classList = { toggle: (name, hidden) => hidden ? element.hidden.add(name) : element.hidden.delete(name) };
    return [id, element];
  }));
  const buttons = Array.from({length:count}, (_, index) => ({dataset:{previewSrc:`/photo-${index + 1}`, previewName:`Photo ${index + 1}`}}));
  const requests = [];
  class ImageLoader { constructor() { requests.push(this); } }
  vm.runInNewContext(script, {Image:ImageLoader, document:{getElementById:id => elements[id], querySelectorAll:() => buttons, addEventListener:(_name, callback) => callback()}});
  return {...elements, requests, loaded() { requests.at(-1).onload(); }, failed() { requests.at(-1).onerror(); }, open(index) { elements.recentPreviewModal.listeners['show.bs.modal']({relatedTarget:buttons[index]}); }};
}

test('Recent preview opens the selected image and cycles in displayed order', () => {
  const app = setup(3);
  app.open(1); app.loaded();
  assert.equal(app.recentPreviewCount.textContent, '2 / 3');
  app.recentPreviewNext.listeners.click(); app.loaded();
  assert.equal(app.recentPreviewImage.src, '/photo-3');
  assert.equal(app.recentPreviewTitle.textContent, 'Photo 3');
  app.recentPreviewNext.listeners.click(); app.loaded();
  assert.equal(app.recentPreviewCount.textContent, '1 / 3');
  app.recentPreviewModal.listeners.keydown({key:'ArrowLeft', preventDefault(){}}); app.loaded();
  assert.equal(app.recentPreviewImage.src, '/photo-3');
  app.recentPreviewModal.listeners['hidden.bs.modal']();
  app.recentPreviewPrevious.listeners.click();
  assert.equal(app.recentPreviewImage.src, undefined);
});

test('Recent hides navigation for a single image and handles empty pages', () => {
  const app = setup(1);
  app.open(0);
  assert.equal(app.recentPreviewPrevious.hidden.has('d-none'), true);
  assert.equal(app.recentPreviewNext.hidden.has('d-none'), true);
  const empty = setup(0);
  empty.recentPreviewNext.listeners.click();
  assert.equal(empty.recentPreviewImage.src, undefined);
});

test('Recent reports a failed image and recovers without closing the popup', () => {
  const app = setup(3);
  app.open(0);
  assert.equal(app.recentPreviewImage.style.visibility, 'hidden');
  app.failed();
  assert.match(app.recentPreviewStatus.textContent, /could not be loaded/);
  app.recentPreviewNext.listeners.click();
  assert.match(app.recentPreviewStatus.textContent, /Loading/);
  const staleError = app.requests.at(-1).onerror;
  app.loaded();
  assert.equal(app.recentPreviewImage.style.visibility, 'visible');
  assert.equal(app.recentPreviewStatus.textContent, '');
  app.recentPreviewModal.listeners['hidden.bs.modal']();
  staleError();
  assert.equal(app.recentPreviewStatus.textContent, '');
});


test('Recent holds the current photo and details while loading and ignores older results', () => {
  const app = setup(3);
  app.open(0); app.loaded();
  app.recentPreviewNext.listeners.click();
  assert.equal(app.recentPreviewImage.src, '/photo-1');
  assert.equal(app.recentPreviewImage.style.visibility, 'visible');
  assert.equal(app.recentPreviewTitle.textContent, 'Photo 1');
  assert.equal(app.recentPreviewCount.textContent, '1 / 3');
  const staleLoad = app.requests.at(-1).onload, staleError = app.requests.at(-1).onerror;
  app.recentPreviewNext.listeners.click(); app.loaded();
  staleLoad(); staleError();
  assert.equal(app.recentPreviewImage.src, '/photo-3');
  assert.equal(app.recentPreviewTitle.textContent, 'Photo 3');
  assert.equal(app.recentPreviewCount.textContent, '3 / 3');
  assert.equal(app.recentPreviewStatus.textContent, '');
});

test('Recent invalidates pending loads when closed or removed during page navigation', () => {
  const app = setup(3);
  app.open(0);
  const staleLoad = app.requests.at(-1).onload;
  app.recentPreviewModal.listeners['hidden.bs.modal'](); staleLoad();
  assert.equal(app.recentPreviewImage.src, undefined);
  app.open(1); app.loaded();
  assert.equal(app.recentPreviewImage.src, '/photo-2');
  app.recentPreviewNext.listeners.click();
  app.recentPreviewModal.isConnected = false;
  app.loaded(); app.failed();
  assert.equal(app.recentPreviewImage.src, '/photo-2');
});
