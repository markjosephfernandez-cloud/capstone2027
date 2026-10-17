const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const path = require('node:path');
const view = fs.readFileSync(path.join(__dirname, '../../app/Views/workspace/media.php'), 'utf8');
const start = view.indexOf("var modal = document.getElementById('mediaPreviewModal');");
const code = view.slice(start, view.indexOf('var bulkForm', start));

function setup(images = []) {
  const base = 'http://example.com/workspace/file-management';
  class Element {
    constructor() {
      this.dataset = {}; this.listeners = {}; this.attributes = {}; this.isConnected = true;
      this.hiddenClasses = new Set();
      this.classList = { toggle: (name, hidden) => hidden ? this.hiddenClasses.add(name) : this.hiddenClasses.delete(name) }; this.videoWidth = 720;
    }
    addEventListener(name, fn) { this.listeners[name] = fn; }
    getAttribute(name) { return this.attributes[name] || null; }
    removeAttribute(name) {
      delete this.attributes[name];
      if (name === 'data-original-preview-src') delete this.dataset.originalPreviewSrc;
    }
    set src(value) { this.attributes.src = value; }
    get src() { return new URL(this.attributes.src || '', base).href; }
    pause() {}
    load() {}
  }
  const ids = ['mediaPreviewModal', 'mediaPreviewImage', 'mediaPreviewVideo', 'mediaPreviewTitle', 'mediaPreviewStatus', 'mediaPreviewDownload', 'mediaPreviewLogoDownload', 'mediaPreviewPrevious', 'mediaPreviewNext', 'mediaPreviewCount'];
  const elements = Object.fromEntries(ids.map(id => [id, new Element()]));
  const requests = [];
  class ImageLoader extends Element { constructor() { super(); requests.push(this); } }
  vm.runInNewContext(code, { Image: ImageLoader, document: { getElementById: id => elements[id], querySelectorAll: () => images.filter(image => image.dataset.previewKind === 'image') }, window: { location: { href: base } }, URL });
  return {
    ...elements, requests,
    loaded() { requests.at(-1).onload(); },
    failed() { requests.at(-1).onerror(); },
    openButton(button) { elements.mediaPreviewModal.listeners['show.bs.modal']({ relatedTarget: button }); },
    open(kind, src, original = '') {
      elements.mediaPreviewModal.listeners['show.bs.modal']({ relatedTarget: {
        matches: () => true,
        dataset: { previewKind: kind, previewSrc: src, originalPreviewSrc: original, previewName: 'Example', downloadUrl: '/download' }
      } });
    },
    close() { elements.mediaPreviewModal.listeners['hidden.bs.modal'](); }
  };
}

test('a hidden image error cannot replace a video status or load the video into an image', () => {
  const app = setup();
  app.open('image', '/photo');
  const staleError = app.requests.at(-1).onerror;
  app.open('video', '/video-preview');
  staleError();
  assert.equal(app.mediaPreviewImage.getAttribute('src'), null);
  assert.match(app.mediaPreviewStatus.textContent, /Loading video/);
  app.mediaPreviewVideo.listeners.loadeddata();
  assert.match(app.mediaPreviewStatus.textContent, /Video preview. Use/);
  staleError();
  assert.match(app.mediaPreviewStatus.textContent, /Video preview. Use/);
});

test('video failure reports a video error and closing clears both sources', () => {
  const app = setup(); app.open('video', '/video-preview');
  app.mediaPreviewVideo.listeners.error();
  assert.match(app.mediaPreviewStatus.textContent, /Video preview could not be loaded/);
  app.close();
  assert.equal(app.mediaPreviewVideo.getAttribute('src'), null);
  assert.equal(app.mediaPreviewImage.getAttribute('src'), null);
  const status = app.mediaPreviewStatus.textContent;
  app.mediaPreviewVideo.listeners.loadeddata();
  assert.equal(app.mediaPreviewStatus.textContent, status);
});

test('an original image failure is not mislabeled as a watermark failure', () => {
  const app = setup(); app.open('image', '/photo', '/photo');
  app.failed();
  assert.match(app.mediaPreviewStatus.textContent, /Image preview could not be loaded/);
  assert.equal(app.requests.length, 1);
  assert.equal(app.mediaPreviewImage.getAttribute('src'), null);
});

test('a distinct watermarked image falls back once, with no stale video events', () => {
  const app = setup(); app.open('video', '/video'); app.open('image', '/watermarked', '/original');
  app.failed();
  assert.equal(app.requests.at(-1).src, 'http://example.com/original');
  assert.match(app.mediaPreviewStatus.textContent, /Loading original/);
  app.mediaPreviewVideo.listeners.error();
  assert.match(app.mediaPreviewStatus.textContent, /Loading original/);
  app.failed();
  assert.match(app.mediaPreviewStatus.textContent, /Image preview could not be loaded/);
});

function photo(id) {
  return { matches: () => true, dataset: { previewKind:'image', previewSrc:`/photo-${id}`, previewName:`Photo ${id}`, downloadUrl:`/download-${id}`, logoUrl:`/logo-${id}` } };
}

test('image arrows skip duplicate triggers and update titles and downloads without reopening', () => {
  const first = photo(1), second = photo(2), third = photo(3);
  const app = setup([first, first, second, third, second]);
  app.openButton(second); app.loaded();
  assert.equal(app.mediaPreviewCount.textContent, '2 / 3');
  app.mediaPreviewNext.listeners.click(); app.loaded();
  assert.equal(app.mediaPreviewImage.src, 'http://example.com/photo-3');
  assert.equal(app.mediaPreviewTitle.textContent, 'Photo 3');
  assert.equal(app.mediaPreviewDownload.href, '/download-3');
  assert.equal(app.mediaPreviewLogoDownload.href, '/logo-3');
  app.mediaPreviewNext.listeners.click(); app.loaded();
  assert.equal(app.mediaPreviewCount.textContent, '1 / 3');
  app.mediaPreviewPrevious.listeners.click(); app.loaded();
  assert.equal(app.mediaPreviewCount.textContent, '3 / 3');
  app.close();
  app.mediaPreviewNext.listeners.click();
  assert.equal(app.mediaPreviewImage.getAttribute('src'), null);
});

test('arrows hide for single images and videos, and keyboard navigation leaves videos alone', () => {
  const first = photo(1);
  const single = setup([first]);
  single.openButton(first);
  assert.equal(single.mediaPreviewNext.hiddenClasses.has('d-none'), true);
  const app = setup([first, photo(2)]);
  app.openButton(first);
  app.mediaPreviewModal.listeners.keydown({ key:'ArrowRight', preventDefault() {} }); app.loaded();
  assert.equal(app.mediaPreviewCount.textContent, '2 / 2');
  app.open('video', '/video');
  app.mediaPreviewModal.listeners.keydown({ key:'ArrowRight', preventDefault() { assert.fail('Video keyboard controls should remain available'); } });
  assert.equal(app.mediaPreviewNext.hiddenClasses.has('d-none'), true);
  assert.equal(app.mediaPreviewVideo.src, 'http://example.com/video');
});


test('navigation keeps the previous photo and details until the next request succeeds', () => {
  const first = photo(1), second = photo(2);
  const app = setup([first, second]);
  app.openButton(first); app.loaded();
  app.mediaPreviewNext.listeners.click();
  assert.equal(app.mediaPreviewImage.src, 'http://example.com/photo-1');
  assert.equal(app.mediaPreviewTitle.textContent, 'Photo 1');
  assert.equal(app.mediaPreviewCount.textContent, '1 / 2');
  assert.equal(app.mediaPreviewDownload.href, '/download-1');
  assert.match(app.mediaPreviewStatus.textContent, /Loading image/);
  app.loaded();
  assert.equal(app.mediaPreviewImage.src, 'http://example.com/photo-2');
  assert.equal(app.mediaPreviewTitle.textContent, 'Photo 2');
  assert.equal(app.mediaPreviewCount.textContent, '2 / 2');
  assert.equal(app.mediaPreviewDownload.href, '/download-2');
});

test('rapid arrow clicks ignore out-of-order success and failure callbacks', () => {
  const first = photo(1), second = photo(2), third = photo(3);
  const app = setup([first, second, third]);
  app.openButton(first); app.loaded();
  app.mediaPreviewNext.listeners.click();
  const staleLoad = app.requests.at(-1).onload;
  const staleError = app.requests.at(-1).onerror;
  app.mediaPreviewNext.listeners.click(); app.loaded();
  staleLoad(); staleError();
  assert.equal(app.mediaPreviewImage.src, 'http://example.com/photo-3');
  assert.equal(app.mediaPreviewCount.textContent, '3 / 3');
  assert.equal(app.mediaPreviewDownload.href, '/download-3');
  assert.match(app.mediaPreviewStatus.textContent, /Original image preview/);
});

test('closing or replacing the page invalidates pending image requests', () => {
  const app = setup([photo(1), photo(2)]);
  app.openButton(photo(1));
  const staleLoad = app.requests.at(-1).onload;
  const staleError = app.requests.at(-1).onerror;
  app.close(); staleLoad(); staleError();
  assert.equal(app.mediaPreviewImage.getAttribute('src'), null);
  assert.equal(app.mediaPreviewCount.textContent, '');
  assert.equal(app.mediaPreviewStatus.textContent, '');
  app.openButton(photo(2));
  app.mediaPreviewModal.isConnected = false;
  app.loaded();
  assert.equal(app.mediaPreviewImage.getAttribute('src'), null);
});

test('failed images can be skipped without closing and watermark fallback still loads', () => {
  const app = setup([photo(1), photo(2), photo(3)]);
  app.openButton(photo(1)); app.loaded();
  app.mediaPreviewNext.listeners.click(); app.failed();
  assert.equal(app.mediaPreviewImage.getAttribute('src'), null);
  assert.match(app.mediaPreviewStatus.textContent, /could not be loaded/);
  assert.equal(app.mediaPreviewDownload.href, '/download-2');
  app.mediaPreviewNext.listeners.click(); app.loaded();
  assert.equal(app.mediaPreviewImage.src, 'http://example.com/photo-3');
  app.open('image', '/watermarked', '/original'); app.failed(); app.loaded();
  assert.equal(app.mediaPreviewImage.src, 'http://example.com/original');
  assert.match(app.mediaPreviewStatus.textContent, /Watermarked preview was unavailable/);
});
