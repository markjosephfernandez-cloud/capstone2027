const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const script = fs.readFileSync(path.join(__dirname, '../../public/assets/js/action-confirmation.js'), 'utf8');

function setup(kind = 'trash', onForm = false) {
  class Element {
    constructor() { this.dataset = {}; this.listeners = {}; this.isConnected = true; this.disabled = false; }
    addEventListener(name, fn, options = {}) { (this.listeners[name] ||= []).push({ fn, once: options.once }); }
    removeEventListener(name, fn) { this.listeners[name] = (this.listeners[name] || []).filter(listener => listener.fn !== fn); }
    fire(name, event = {}) {
      for (const listener of [...(this.listeners[name] || [])]) {
        if (listener.once) this.removeEventListener(name, listener.fn);
        listener.fn(event);
      }
    }
    focus() { this.focused = true; }
  }
  const document = new Element();
  const nodes = Object.fromEntries(['Modal', 'Accept', 'Cancel', 'Heading', 'Message', 'Warning'].map(name => ['actionConfirmation' + name, new Element()]));
  document.getElementById = id => nodes[id];
  const requests = [];
  class Form extends Element {
    constructor() { super(); this.fields = { 'file_ids[]': ['11', '12'], csrf: 'token' }; }
    requestSubmit(submitter) {
      const event = { target: this, submitter, defaultPrevented: false, preventDefault() { this.defaultPrevented = true; } };
      document.fire('submit', event);
    }
  }
  let shown = 0;
  const modal = { show() { shown++; nodes.actionConfirmationModal.fire('shown.bs.modal'); }, hide() { nodes.actionConfirmationModal.fire('hidden.bs.modal'); } };
  vm.runInNewContext(script, { document, HTMLFormElement: Form, window: { bootstrap: { Modal: { getOrCreateInstance: () => modal } } } });
  // Represents the later AJAX listener: cancelled submissions must never reach the server.
  document.addEventListener('submit', event => {
    if (!event.defaultPrevented) requests.push({ form: event.target, submitter: event.submitter, fields: event.target.fields, action: event.submitter?.formaction || event.target.action });
  });
  const form = new Form(), button = new Element();
  form.action = '/bulk-download';
  button.formaction = kind === 'trash' ? '/bulk-trash' : '/bulk-delete';
  const source = onForm ? form : button;
  source.dataset.confirmKind = kind;
  source.dataset.confirmMessage = 'Confirm <example> "file"?';
  return { form, button, requests, nodes, get shown() { return shown; },
    submit: () => form.requestSubmit(button),
    confirm: () => nodes.actionConfirmationAccept.fire('click'),
    dismiss: () => modal.hide(),
    newForm: () => new Form(),
    invalidSubmit: () => document.fire('submit', { target: form, submitter: button, defaultPrevented: true })
  };
}

for (const kind of ['trash', 'delete']) {
  test(`${kind} waits for confirmation and keeps the original submitter, selected IDs and CSRF`, () => {
    const app = setup(kind);
    app.submit();
    assert.equal(app.requests.length, 0);
    assert.equal(app.shown, 1);
    assert.equal(app.nodes.actionConfirmationCancel.focused, true);
    assert.equal(app.nodes.actionConfirmationMessage.textContent, 'Confirm <example> "file"?');
    assert.equal(app.nodes.actionConfirmationAccept.textContent, kind === 'trash' ? 'Move to Trash' : 'Delete Permanently');
    if (kind === 'delete') assert.match(app.nodes.actionConfirmationWarning.textContent, /cannot be undone/);
    app.confirm(); app.confirm();
    assert.equal(app.requests.length, 1);
    assert.equal(app.requests[0].submitter, app.button);
    assert.equal(app.requests[0].action, kind === 'trash' ? '/bulk-trash' : '/bulk-delete');
    assert.deepEqual(app.requests[0].fields, { 'file_ids[]': ['11', '12'], csrf: 'token' });
  });
}

test('Cancel, close, Escape and backdrop dismissal never submit or leave a confirmation approval', () => {
  const app = setup('delete', true);
  for (let i = 0; i < 4; i++) {
    app.submit(); app.dismiss(); app.confirm();
    assert.equal(app.requests.length, 0);
  }
  assert.equal(app.shown, 4);
  app.submit(); app.confirm();
  assert.equal(app.requests.length, 1);
});

test('repeated submits open one popup, and navigation cannot submit a detached form', () => {
  const app = setup();
  app.submit(); app.submit();
  assert.equal(app.shown, 1);
  app.form.isConnected = false;
  app.confirm();
  assert.equal(app.requests.length, 0);
  const form = app.newForm();
  form.dataset.confirmKind = 'delete';
  form.action = '/folder-delete';
  form.requestSubmit();
  assert.equal(app.shown, 2);
  app.confirm();
  assert.equal(app.requests.length, 1);
  assert.equal(app.requests[0].action, '/folder-delete');
});

test('invalid selection stays blocked; unrelated restore/download forms do not open a popup', () => {
  const app = setup();
  app.invalidSubmit();
  assert.equal(app.shown, 0);
  assert.equal(app.requests.length, 0);
  delete app.button.dataset.confirmKind;
  app.submit();
  assert.equal(app.shown, 0);
  assert.equal(app.requests.length, 1);
});
