const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const code = fs.readFileSync(path.join(__dirname, '../../public/assets/js/chunk-uploads.js'), 'utf8');

function setup(size) {
  const window = { crypto: require('node:crypto').webcrypto, setTimeout: callback => callback() };
  vm.runInNewContext(code, { window, Uint8Array });
  return { send: window.GreyhoundChunkUploads.send,
    file: { name: 'large.mp4', size, slice(start, end) { return { start, end, size: end - start }; } } };
}
const success = payload => ({ response: { ok: true }, payload: { ok: true, ...payload } });

test('a 3 GB file uses bounded requests and only completes after server confirmation', async () => {
  const app = setup(3 * 1024 ** 3 + 7);
  const requests = [];
  const result = await app.send(app.file, {
    chunkBytes: 4 * 1024 ** 2, state: {}, metadata: ['folder-7'],
    async sendPart(part, fields) {
      requests.push({ part, fields });
      return success(part.end === app.file.size ? { complete: true, uploaded: 1 } : { complete: false, nextOffset: part.end });
    }
  });
  assert.equal(result.payload.uploaded, 1);
  assert.equal(requests.length, 769);
  assert.equal(requests.reduce((sum, request) => sum + request.part.size, 0), app.file.size);
  assert.ok(requests.every(request => request.part.size <= 4 * 1024 ** 2));
  assert.equal(new Set(requests.map(request => request.fields.chunk_id)).size, 1);
  assert.equal(requests[0].fields.file_name, app.file.name);
  assert.equal(requests[0].fields.file_size, String(app.file.size));
});

test('network retries reuse the same chunk ID and offset, including the final part', async () => {
  const app = setup(10);
  const offsets = []; let failed = false;
  await app.send(app.file, {
    chunkBytes: 6, state: {}, metadata: [],
    async sendPart(part, fields) {
      offsets.push([fields.chunk_id, fields.chunk_offset]);
      if (part.end === 10 && !failed) { failed = true; throw new Error('Connection lost'); }
      return success(part.end === 10 ? { complete: true } : { nextOffset: part.end });
    }
  });
  assert.equal(offsets.length, 3);
  assert.deepEqual(offsets[1], offsets[2]);
});

test('retrying a failed transfer resumes at the last confirmed position', async () => {
  const app = setup(10); const state = {}; let offline = true;
  const offsets = [];
  const options = { chunkBytes: 6, state, metadata: [], async sendPart(part) {
    offsets.push(part.start);
    if (part.start === 6 && offline) throw new Error('Offline');
    return success(part.end === 10 ? { complete: true } : { nextOffset: part.end });
  } };
  await assert.rejects(app.send(app.file, options), /Offline/);
  offline = false;
  await app.send(app.file, options);
  assert.equal(offsets.filter(offset => offset === 0).length, 1);
});

test('small files keep the existing single-request upload', async () => {
  const app = setup(3);
  const result = await app.send(app.file, { chunkBytes: 6, sendPart(file, fields) {
    assert.equal(file, app.file); assert.equal(fields, null);
    return success({ uploaded: 1 });
  } });
  assert.equal(result.payload.uploaded, 1);
});

test('server rejection stops upload instead of sending remaining chunks', async () => {
  const app = setup(10); let requests = 0;
  const result = await app.send(app.file, { chunkBytes: 4, state: {}, metadata: [], sendPart() {
    requests++; return { response: { ok: false }, payload: { ok: false, message: 'Disk full' } };
  } });
  assert.equal(requests, 1);
  assert.equal(result.payload.message, 'Disk full');
});

test('cancelling an active request stops retries and subsequent parts', async () => {
  const app = setup(10); const controller = new AbortController(); let calls = 0;
  await assert.rejects(app.send(app.file, { chunkBytes: 4, state: {}, metadata: [], signal: controller.signal,
    sendPart() { calls++; controller.abort(); return Promise.reject(new Error('Fetch aborted')); }
  }), { name: 'AbortError' });
  assert.equal(calls, 1);
});

test('cancelling between parts prevents the next request', async () => {
  const app = setup(10); const controller = new AbortController(); let calls = 0;
  await assert.rejects(app.send(app.file, { chunkBytes: 4, state: {}, metadata: [], signal: controller.signal,
    sendPart(part) { calls++; return success({ nextOffset: part.end }); },
    onProgress() { controller.abort(); }
  }), { name: 'AbortError' });
  assert.equal(calls, 1);
});

test('an already cancelled transfer sends no request, including small files', async () => {
  const app = setup(3); const controller = new AbortController(); controller.abort();
  await assert.rejects(app.send(app.file, { chunkBytes: 4, signal: controller.signal,
    sendPart() { assert.fail('No upload should start'); }
  }), { name: 'AbortError' });
});

test('cancelling during retry delay clears the timer and does not retry', async () => {
  let waiting, cleared = false, calls = 0;
  const window = { crypto: require('node:crypto').webcrypto,
    setTimeout(callback) { waiting = callback; return 7; },
    clearTimeout(id) { assert.equal(id, 7); cleared = true; }
  };
  vm.runInNewContext(code, { window, Uint8Array });
  const controller = new AbortController(); const app = setup(10);
  const result = window.GreyhoundChunkUploads.send(app.file, { chunkBytes: 4, state: {}, metadata: [], signal: controller.signal,
    sendPart() { calls++; return Promise.reject(new Error('Network error')); }
  });
  await new Promise(resolve => setImmediate(resolve));
  assert.equal(typeof waiting, 'function');
  controller.abort();
  await assert.rejects(result, { name: 'AbortError' });
  assert.equal(cleared, true);
  assert.equal(calls, 1);
});
