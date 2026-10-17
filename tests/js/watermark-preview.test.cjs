const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const view = fs.readFileSync(path.join(__dirname, '../../app/Views/addons/watermarking.php'), 'utf8');
const start = view.indexOf('    const imageModalElement =');
const code = view.slice(start, view.indexOf('    /* -------------------------------------------------------\n     * Watermark Preview Modal', start));

function setup(count = 3) {
  class Element {
    constructor() {
      this.listeners = {}; this.dataset = {}; this.style = {visibility:'hidden'}; this.className = '';
      this.isConnected = true;
      this.classList = {
        contains: name => this.className.split(' ').includes(name),
        toggle: (name, enabled) => {
          const classes = new Set(this.className.split(' ').filter(Boolean));
          enabled ? classes.add(name) : classes.delete(name);
          this.className = [...classes].join(' ');
        },
      };
    }
    addEventListener(name, callback) { this.listeners[name] = callback; }
    getAttribute(name) { return this[name] || null; }
    removeAttribute(name) { delete this[name]; }
    closest() { return this.item || null; }
    focus() { this.focused = true; }
  }
  const ids = ['imagePreviewModal', 'imagePreviewRaw', 'imagePreviewLogo', 'imagePreviewTitle', 'imagePreviewCount', 'imagePreviewStatus', 'imagePreviewPrevious', 'imagePreviewNext'];
  const elements = Object.fromEntries(ids.map(id => [id, new Element()]));
  const buttons = Array.from({ length: count * 2 }, (_, index) => {
    const id = index % count + 1;
    const button = new Element();
    button.dataset = { previewSrc: `/photo-${id}`, previewTitle: `Photo ${id}` };
    button.item = new Element();
    button.item.dataset.imageId = String(id);
    if (id === 2) button.item.className = 'logo-applied';
    button.item.querySelector = () => ({ src: '/custom-logo', className: 'item-logo bottom_left', style: { opacity: '0.5', maxWidth: '4%' } });
    return button;
  });
  let opens = 0;
  const requests = [];
  class ImageLoader { constructor() { requests.push(this); } }
  vm.runInNewContext(code, {
    Image:ImageLoader,
    document: { getElementById: id => elements[id], querySelectorAll: () => buttons },
    bootstrap: { Modal: class { show() { opens++; } } },
  });
  return { elements, buttons, requests, loaded: () => requests.at(-1).onload(), failed: () => requests.at(-1).onerror(), opens: () => opens, click: id => elements[id].listeners.click() };
}

test('preview arrows browse unique images in page order and wrap at both ends', () => {
  const app = setup();
  app.buttons[0].listeners.click(); app.loaded();
  assert.equal(app.elements.imagePreviewCount.textContent, '1 / 3');
  app.click('imagePreviewNext'); app.loaded();
  assert.equal(app.elements.imagePreviewRaw.src, '/photo-2');
  app.click('imagePreviewNext'); app.loaded();
  app.click('imagePreviewNext'); app.loaded();
  assert.equal(app.elements.imagePreviewRaw.src, '/photo-1');
  app.click('imagePreviewPrevious'); app.loaded();
  assert.equal(app.elements.imagePreviewTitle.textContent, 'Photo 3');
  assert.equal(app.elements.imagePreviewCount.textContent, '3 / 3');
  assert.equal(app.opens(), 1);
});

test('opening from either view starts on that image and preserves its applied watermark', () => {
  const app = setup();
  app.buttons[4].listeners.click(); app.loaded();
  const logo = app.elements.imagePreviewLogo;
  assert.equal(app.elements.imagePreviewRaw.src, '/photo-2');
  assert.equal(logo.src, '/custom-logo');
  assert.equal(logo.className, 'preview-watermark bottom_left');
  assert.equal(logo.style.opacity, '0.5');
  assert.equal(logo.style.maxWidth, '4%');
  app.click('imagePreviewNext'); app.loaded();
  assert.equal(logo.className, 'preview-watermark d-none');
  assert.equal(logo.src, undefined);
  app.buttons[1].listeners.click(); app.loaded();
  assert.equal(app.elements.imagePreviewCount.textContent, '2 / 3');
});

test('keyboard arrows navigate the open preview and single images hide the buttons', () => {
  const app = setup();
  app.buttons[0].listeners.click(); app.loaded();
  let prevented = false;
  app.elements.imagePreviewModal.listeners.keydown({key:'ArrowRight', preventDefault(){ prevented = true; }}); app.loaded();
  assert.equal(prevented, true);
  assert.equal(app.elements.imagePreviewCount.textContent, '2 / 3');
  const single = setup(1);
  single.buttons[0].listeners.click();
  assert.equal(single.elements.imagePreviewPrevious.classList.contains('d-none'), true);
  assert.equal(single.elements.imagePreviewNext.classList.contains('d-none'), true);
  assert.equal(single.elements.imagePreviewCount.textContent, '1 / 1');
});

test('closing the watermark preview clears it and prevents hidden navigation', () => {
  const app = setup();
  app.buttons[1].listeners.click(); app.loaded();
  app.elements.imagePreviewModal.listeners['hidden.bs.modal']();
  assert.equal(app.buttons[1].focused, true);
  app.click('imagePreviewNext');
  assert.equal(app.elements.imagePreviewRaw.src, undefined);
  assert.equal(app.elements.imagePreviewLogo.src, undefined);
  app.buttons[2].listeners.click(); app.loaded();
  assert.equal(app.elements.imagePreviewCount.textContent, '3 / 3');
});

test('watermark preview reports failures and navigation recovers on the next image', () => {
  const app = setup();
  app.buttons[1].listeners.click();
  assert.equal(app.elements.imagePreviewRaw.style.visibility, 'hidden');
  assert.equal(app.elements.imagePreviewLogo.style.visibility, 'hidden');
  app.failed();
  assert.match(app.elements.imagePreviewStatus.textContent, /could not be loaded/);
  app.click('imagePreviewNext');
  assert.match(app.elements.imagePreviewStatus.textContent, /Loading/);
  app.loaded();
  assert.equal(app.elements.imagePreviewRaw.style.visibility, 'visible');
  assert.equal(app.elements.imagePreviewStatus.textContent, '');
});


test('watermark gallery retains the displayed photo and logo until the next photo loads', () => {
  const app = setup();
  app.buttons[1].listeners.click(); app.loaded();
  app.click('imagePreviewNext');
  assert.equal(app.elements.imagePreviewRaw.src, '/photo-2');
  assert.equal(app.elements.imagePreviewLogo.src, '/custom-logo');
  assert.equal(app.elements.imagePreviewTitle.textContent, 'Photo 2');
  assert.equal(app.elements.imagePreviewCount.textContent, '2 / 3');
  assert.equal(app.elements.imagePreviewRaw.style.visibility, 'visible');
  const staleLoad = app.requests.at(-1).onload, staleError = app.requests.at(-1).onerror;
  app.click('imagePreviewNext'); app.loaded();
  staleLoad(); staleError();
  assert.equal(app.elements.imagePreviewRaw.src, '/photo-1');
  assert.equal(app.elements.imagePreviewCount.textContent, '1 / 3');
  assert.equal(app.elements.imagePreviewLogo.className, 'preview-watermark d-none');
  assert.equal(app.elements.imagePreviewStatus.textContent, '');
});

test('watermark gallery ignores pending callbacks after closing or navigating away', () => {
  const app = setup();
  app.buttons[1].listeners.click();
  const staleLoad = app.requests.at(-1).onload, staleError = app.requests.at(-1).onerror;
  app.elements.imagePreviewModal.listeners['hidden.bs.modal']();
  staleLoad(); staleError();
  assert.equal(app.elements.imagePreviewRaw.src, undefined);
  assert.equal(app.elements.imagePreviewLogo.src, undefined);
  assert.equal(app.elements.imagePreviewStatus.textContent, '');
  app.buttons[0].listeners.click(); app.loaded();
  app.click('imagePreviewNext');
  app.elements.imagePreviewModal.isConnected = false;
  app.loaded(); app.failed();
  assert.equal(app.elements.imagePreviewRaw.src, '/photo-1');
});
