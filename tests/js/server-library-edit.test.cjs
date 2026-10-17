const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const source = fs.readFileSync('app/Views/workspace/server_library_edit_script.php', 'utf8').replace(/<\/?script>/g, '').replace('<?= csrf_token() ?>', 'csrf_test_name');

function setup() {
  class Element {
    constructor() { this.listeners = {}; this.children = []; this.dataset = {}; this.value = ''; this.disabled = false; this.hidden = false; }
    addEventListener(name, fn) { (this.listeners[name] ||= []).push(fn); }
    fire(name, extra = {}) { const event = { target: this, preventDefault() { this.prevented = true; }, ...extra }; (this.listeners[name] || []).forEach(fn => fn(event)); return event; }
    replaceChildren() { this.children = []; }
    appendChild(node) { this.children.push(node); }
    setAttribute(name, value) { this[name] = value; }
    closest() { return this; }
  }
  const nodes = {};
  const node = id => nodes[id] ||= Object.assign(new Element(), { id });
  const root = node('serverLibrary'), form = node('serverEditForm');
  const boxes = ['Album/photo.jpg', 'Album/other.jpg'].map(value => Object.assign(new Element(), { value, checked: false }));
  const buttons = ['rename', 'move', 'tags', 'trash', 'send', 'post', 'download'].map(action => Object.assign(new Element(), { dataset: { serverEdit: action } }));
  const fields = ['name', 'destination', 'tags', 'department', 'post'].map(name => Object.assign(new Element(), { dataset: { serverField: name }, querySelectorAll: () => [node('field-' + name)] }));
  root.querySelectorAll = selector => selector === '[data-server-select]:checked' ? boxes.filter(box => box.checked) : selector === '[data-server-select]' ? boxes : buttons;
  form.dataset = { editUrl: '/edit', sendUrl: '/send', postUrl: '/post', downloadUrl: '/download' };
  form.reset = () => { node('serverEditTagInput').value = ''; };
  form.querySelectorAll = selector => selector === '[data-server-field]' ? fields : node('serverEditPaths').children;
  form.querySelector = () => node('csrf');
  let shows = 0, hides = 0;
  vm.runInNewContext(source, { document: { getElementById: node, createElement: () => new Element(), addEventListener: (_, fn) => fn() }, bootstrap: { Modal: { getOrCreateInstance: () => ({ show() { shows++; }, hide() { hides++; } }) } } });
  return { node, boxes, form, fields,
    select(count) { boxes.forEach((box, i) => { box.checked = i < count; }); root.fire('change'); },
    open(action, path) { const button = path ? Object.assign(new Element(), { dataset: { serverEdit: action, path } }) : buttons.find(button => button.dataset.serverEdit === action); root.fire('click', { target: button }); },
    get shows() { return shows; }, get hides() { return hides; },
    submit() { return form.fire('submit'); },
  };
}

test('selection actions preserve file paths and hide unrelated required fields', () => {
  const app = setup(); app.select(1); app.open('rename');
  assert.equal(app.form.action, '/edit'); assert.equal(app.form.method, 'post');
  assert.equal(app.node('serverEditName').value, 'photo.jpg');
  assert.equal(app.node('serverEditPaths').children[0].value, 'Album/photo.jpg');
  assert.equal(app.node('field-department').disabled, true);
  assert.equal(app.node('field-name').disabled, false);
  app.select(2); app.open('move');
  assert.equal(app.node('serverEditPaths').children.length, 2);
  assert.equal(app.node('field-destination').disabled, false);
  assert.equal(app.node('field-name').disabled, true);
});

test('tags support Enter, comma, duplicate removal, safe text chips and pending text on Save', () => {
  const app = setup(); app.select(1); app.open('tags');
  const input = app.node('serverEditTagInput');
  input.value = 'Founder'; assert.equal(input.fire('keydown', { key: 'Enter' }).prevented, true);
  input.value = 'founder, University'; input.fire('input');
  assert.equal(app.node('serverEditTags').value, 'Founder, University');
  input.value = '<img src=x>';
  assert.equal(app.submit().prevented, undefined);
  assert.equal(app.node('serverEditTags').value, 'Founder, University, <img src=x>');
  assert.equal(app.node('serverEditTagChips').children[2].textContent, '<img src=x> ×');
  app.node('serverEditTagChips').children[0].fire('click');
  assert.equal(app.node('serverEditTags').value, 'University, <img src=x>');
});

test('empty tags are blocked and Trash is submitted only after the dialog confirmation', () => {
  const app = setup(); app.select(1); app.open('tags');
  assert.equal(app.submit().prevented, true);
  app.open('trash', 'Album');
  assert.match(app.node('serverEditSummary').textContent, /restore them later/);
  assert.equal(app.node('serverEditAction').value, 'trash');
  assert.equal(app.node('serverEditPaths').children[0].value, 'Album');
  assert.equal(app.node('serverEditSubmit').disabled, false);
  app.submit(); assert.equal(app.node('serverEditSubmit').disabled, true);
});

test('download is a read-only GET without a CSRF token; later editing returns to POST', () => {
  const app = setup(); app.select(1); app.open('download');
  assert.equal(app.form.method, 'get'); assert.equal(app.form.action, '/download');
  assert.equal(app.node('csrf').disabled, true);
  app.submit(); assert.equal(app.hides, 1);
  app.open('post');
  assert.equal(app.form.method, 'post'); assert.equal(app.form.action, '/post');
  assert.equal(app.node('csrf').disabled, false);
  assert.equal(app.node('field-post').disabled, false);
});

test('newly loaded selected files participate and oversized review submissions are blocked', () => {
  const app = setup();
  for (let i = 0; i < 20; i++) app.boxes.push({ value: 'new' + i + '.jpg', checked: false });
  app.select(22); app.open('send');
  assert.equal(app.form.action, '/send');
  assert.equal(app.node('serverEditPaths').children.length, 22);
  assert.equal(app.submit().prevented, true);
  assert.match(app.node('serverEditError').textContent, /20/);
});
