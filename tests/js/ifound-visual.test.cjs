const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');

const script = fs.readFileSync('app/Views/workspace/ifound_visual_script.php', 'utf8')
    .replace(/<\/?script>/g, '')
    .replace(/<\?= json_encode\(site_url\('([^']+)'\)\) \?>/g, (_, path) => JSON.stringify('/' + path))
    .replace(/<\?= json_encode\(csrf_token\(\)\) \?>/g, '"csrf_test"');

test('iFound scans selected images with fresh CSRF tokens and reports partial results', async () => {
    let click;
    const button = {dataset: {}, isConnected: true, addEventListener: (_, listener) => { click = listener; }};
    const links = [];
    const status = {textContent: '', appendChild: link => links.push(link)};
    const rows = [1, 2].map(id => ({dataset: {fileId: String(id), fileName: `Image ${id}`}, querySelector: () => ({checked: true})}));
    const calls = [];
    const document = {
        getElementById: id => id === 'ifoundVisualAnalyze' ? button : status,
        querySelectorAll: () => rows, querySelector: () => ({}), createElement: () => ({}),
    };
    const context = {document, window: {location: {href: '/workspace/ifound'}}, FormData, AbortController, setTimeout, clearTimeout,
        fetch: async (url, options) => {
            calls.push({url, options});
            return {ok: true, redirected: false, json: async () => url.endsWith('/token')
                ? {csrfTokenName: 'csrf_name', csrfToken: 'fresh-token'}
                : {result: {objects: {status: 'complete'}, expressions: {status: 'unavailable'}, duplicates: {status: 'complete'}}}};
        },
    };
    vm.runInNewContext(script, context);
    await click();
    assert.equal(calls.length, 4);
    assert.equal(calls[1].options.body.get('csrf_name'), 'fresh-token');
    assert.equal(calls[1].options.body.get('file_id'), '1');
    assert.equal(calls[3].options.body.get('file_id'), '2');
    assert.match(status.textContent, /2 images scanned.*2 with incomplete/);
    assert.equal(context.window.greyhoundVisualBusy, false);
    assert.equal(button.disabled, false);
    assert.equal(links[0].href, '/workspace/ifound');
});

test('iFound displays a busy-image error returned by the server', async () => {
    let click;
    const button = {dataset: {}, isConnected: true, addEventListener: (_, listener) => { click = listener; }};
    const status = {textContent: '', appendChild() {}};
    const row = {dataset: {fileId: '7', fileName: 'photo.jpg'}, querySelector: () => ({checked: true})};
    const context = {document: {
        getElementById: id => id === 'ifoundVisualAnalyze' ? button : status,
        querySelectorAll: () => [row], querySelector: () => ({}), createElement: () => ({}),
    }, window: {location: {href: '/workspace/ifound'}}, FormData, AbortController, setTimeout, clearTimeout,
        fetch: async url => ({ok: url.endsWith('/token'), redirected: false,
            json: async () => url.endsWith('/token') ? {csrfTokenName:'csrf',csrfToken:'token'}
                : {message:'This image is already being analyzed. Wait for that scan to finish, then retry.'}}),
    };
    vm.runInNewContext(script, context);
    await click();
    assert.match(status.textContent, /1 failed/);
    assert.match(status.textContent, /photo.jpg: This image is already being analyzed/);
    assert.equal(button.disabled, false);
});
