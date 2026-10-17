const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const view = fs.readFileSync(require('node:path').join(__dirname, '../../app/Views/workspace/ifound.php'), 'utf8');
const start = view.indexOf('(function () {\n    var rows =');
const code = view.slice(start, view.indexOf('</script>', start));

function setup() {
  class Element {
    constructor() {
      this.listeners = {}; this.dataset = {}; this.children = []; this.value = ''; this.selectors = {};
      this.classList = { toggle() {} };
    }
    addEventListener(name, callback) { this.listeners[name] = callback; }
    setAttribute(name, value) { this[name] = value; }
    appendChild(child) { child.parent = this; this.children.push(child); }
    replaceChildren() { this.children = []; this.textContent = ''; }
    remove() { this.parent.children = this.parent.children.filter(child => child !== this); }
    querySelector(selector) { return this.selectors[selector] ||= new Element(); }
    querySelectorAll(selector) { return this.children.filter(child => selector === '[data-review-image-id]' ? child.dataset.reviewImageId : child.name === 'file_ids[]'); }
    setCustomValidity(message) { this.validationMessage = message; }
    reportValidity() { this.reported = true; }
    focus() {}
  }
  const elements = {};
  const get = id => elements[id] ||= new Element();
  const rows = [1, 2, 3, 4].map(id => {
    const row = new Element();
    row.dataset = { fileId: String(id), fileName: `Photo ${id}`, tags: id === 2 ? 'Founder, Campus' : 'Campus', previewSrc: `/photo-${id}` };
    row.querySelector('[data-ifound-select]').checked = true;
    return row;
  });
  get('ifoundReviewEvent').options = [{ value: '' }];
  vm.runInNewContext(code, { document: { getElementById: get, querySelectorAll: selector => selector === '[data-ifound-item]' ? rows : [], createElement: () => new Element() }, bootstrap: { Modal: { getInstance: () => ({ hide() {} }) } } });
  const open = () => get('ifoundReviewModal').listeners['show.bs.modal']();
  const click = id => get(id).listeners.click();
  const select = id => get('ifoundReviewImages').children.find(card => card.dataset.reviewImageId === String(id)).listeners.click({ target: { closest: () => null } });
  const type = value => { get('ifoundReviewTags').value = value; get('ifoundReviewTags').listeners.input(); };
  const press = key => get('ifoundReviewTags').listeners.keydown({ key, preventDefault() {} });
  const payload = () => Object.fromEntries(get('ifoundReviewFileInputs').children.filter(input => input.name.startsWith('review_tags')).map(input => [input.name, input.value]));
  open();
  return { get, open, click, select, type, press, payload };
}

test('tags stay with their image while selecting images and browsing pages', () => {
  const app = setup();
  app.type('Founder');
  app.press('Enter');
  assert.equal(app.get('ifoundReviewTags').value, '');
  app.select(2);
  assert.equal(app.get('ifoundReviewTags').value, '');
  app.type('Library');
  app.click('ifoundNext');
  assert.equal(app.get('ifoundReviewTags').value, '');
  app.type('Group photo');
  app.click('ifoundPrevious');
  assert.equal(app.get('ifoundReviewTags').value, '');
  app.select(1);
  assert.equal(app.get('ifoundReviewTags').value, '');
  assert(app.get('ifoundExistingTags').children.some(chip => chip.textContent === 'Founder'));
  assert.deepEqual(app.payload(), { 'review_tags[1]': 'Founder', 'review_tags[2]': 'Library', 'review_tags[3]': '', 'review_tags[4]': 'Group photo' });
});

test('removing an image removes its tags from submission and reopening clears drafts', () => {
  const app = setup();
  app.type('Founder');
  app.press('Enter');
  app.get('ifoundReviewImages').children[0].querySelector('.ifound-remove-image').listeners.click();
  assert.equal(app.payload()['review_tags[1]'], undefined);
  app.type('New tag');
  app.open();
  assert.equal(app.get('ifoundReviewTags').value, '');
  assert(Object.values(app.payload()).every(value => value === ''));
});

test('invalid tags on another image prevent saving and focus the affected image', () => {
  const app = setup();
  app.type('x'.repeat(51));
  app.click('ifoundNext');
  let prevented = false;
  app.get('ifoundReviewForm').listeners.submit({ preventDefault() { prevented = true; } });
  assert.equal(prevented, true);
  assert.match(app.get('ifoundReviewTags').validationMessage, /50 characters/);
  assert.equal(app.get('ifoundReviewTags').reported, true);
  assert.match(app.get('ifoundTagImageName').textContent, /Image 1 of 4/);
  app.type('Founder');
  prevented = false;
  app.get('ifoundReviewForm').listeners.submit({ preventDefault() { prevented = true; } });
  assert.equal(prevented, false);
});

test('Enter and comma add trimmed tags, ignore duplicates, and support removing new tags', () => {
  const app = setup();
  app.type('  Group   photo  ');
  app.press('Enter');
  assert.equal(app.get('ifoundReviewTags').value, '');
  app.type('Founder');
  app.press(',');
  app.type(' founder, CAMPUS, Group photo, Library, library ');
  assert.equal(app.get('ifoundReviewTags').value, '');
  assert.equal(app.payload()['review_tags[1]'], 'Group photo, Founder, Library');
  const chip = app.get('ifoundExistingTags').children.find(chip => chip.textContent === 'Founder');
  chip.children[0].listeners.click();
  assert.equal(app.payload()['review_tags[1]'], 'Group photo, Library');
  app.type('x'.repeat(51));
  app.press('Enter');
  assert.match(app.get('ifoundReviewTags').validationMessage, /50 characters/);
  assert.equal(app.get('ifoundReviewTags').value.length, 51);
  assert(!app.get('ifoundExistingTags').children.some(chip => chip.textContent === 'x'.repeat(51)));
  assert(!view.includes('ifoundAddFounder'));
});
