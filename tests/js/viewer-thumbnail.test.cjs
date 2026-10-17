const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const root = path.join(__dirname, '../../greyhound-app-2025-main');
const code = fs.readFileSync(path.join(root, 'utils/mediaThumbnail.js'), 'utf8').replace('export function', 'function');
const scope = {};
vm.runInNewContext(code, scope);
const thumbnail = scope.mediaThumbnailUrl;

test('viewer uses supplied poster images without requesting the video stream', () => {
  assert.equal(thumbnail({ media_kind: 'video', file_url: '/stream', thumbnail_url: '/poster.jpg' }), '/poster.jpg');
  assert.equal(thumbnail({ media_kind: 'image', file_url: '/original.jpg', thumbnail_url: '/small.jpg' }), '/small.jpg');
});

test('older saved videos derive the published poster endpoint with the correct API host', () => {
  assert.equal(thumbnail({ media_kind: 'video', file_url: 'http://localhost:8083/api/v1/mobile/media/26093/stream#t=0.1' }), 'http://localhost:8083/api/v1/mobile/media/26093/thumbnail');
  assert.equal(thumbnail({ stream_url: 'https://example.com/subdir/api/v1/mobile/media/7/stream?v=2' }, true), 'https://example.com/subdir/api/v1/mobile/media/7/thumbnail?v=2');
});

test('unknown videos use a fallback instead of loading video bytes into an image', () => {
  assert.equal(thumbnail({ media_kind: 'video', file_url: '/video.mp4' }), null);
  assert.equal(thumbnail({ file_url: '/photo.jpg' }, false), '/photo.jpg');
  assert.equal(thumbnail({ media_kind: 'video' }), null);
});
