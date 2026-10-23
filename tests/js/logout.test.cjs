const {test} = require('node:test');
const assert = require('node:assert/strict');
const vm = require('node:vm');
const fs = require('node:fs');
const script = fs.readFileSync('public/assets/js/logout.js', 'utf8');

function setup(fetch) {
  const input = {name: 'csrf', value: 'stale'};
  const button = {disabled: false};
  const submissions = [], alerts = [];
  const form = {
    dataset: {logoutTokenUrl: '/logout/token'}, matches: () => true,
    querySelector: selector => selector.startsWith('input') ? input : button
  };
  let handler;
  const context = {
    window: {alert: message => alerts.push(message)},
    document: {addEventListener: (type, fn) => { handler = fn; }}, fetch,
    HTMLFormElement: {prototype: {submit() { submissions.push({...input}); }}}
  };
  vm.runInNewContext(script, context);
  return {input, button, submissions, alerts, context,
    submit: () => handler({target: form, preventDefault() {}})};
}

test('logout replaces the stale token before native submission', async () => {
  const app = setup(async (url, options) => {
    assert.equal(url, '/logout/token');
    assert.equal(options.cache, 'no-store');
    assert.equal(options.credentials, 'same-origin');
    return {ok: true, json: async () => ({csrfTokenName: 'csrf', csrfToken: 'fresh'})};
  });
  await app.submit();
  assert.deepEqual(app.submissions, [{name: 'csrf', value: 'fresh'}]);
  assert.equal(app.alerts.length, 0);
});

test('failed token refresh prevents stale submission and allows retry', async () => {
  const app = setup(async () => ({ok: false}));
  await app.submit();
  assert.equal(app.submissions.length, 0);
  assert.equal(app.button.disabled, false);
  await app.submit();
  assert.equal(app.alerts.length, 2);
});

test('repeated clicks only submit logout once', async () => {
  let resolve;
  const app = setup(() => new Promise(done => { resolve = done; }));
  const pending = app.submit();
  await app.submit();
  resolve({ok: true, json: async () => ({csrfTokenName: 'csrf', csrfToken: 'fresh'})});
  await pending;
  assert.equal(app.submissions.length, 1);
});
