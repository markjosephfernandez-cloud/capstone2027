(function () {
  'use strict';

  // Only preview copies are resized. The upload queue retains the original Files.
  async function thumbnail(file) {
    var source;
    var sourceUrl;
    try {
      if (window.createImageBitmap) {
        try {
          source = await window.createImageBitmap(file, { resizeWidth: 320, resizeQuality: 'low', imageOrientation: 'from-image' });
        } catch (error) { /* Try the browser's image decoder below. */ }
      }
      if (!source) {
        sourceUrl = window.URL.createObjectURL(file);
        source = await new Promise(function (resolve, reject) {
          var image = new Image();
          image.onload = function () { resolve(image); };
          image.onerror = function () { reject(new Error('Preview unavailable')); };
          image.src = sourceUrl;
        });
      }
      var width = source.naturalWidth || source.width;
      var height = source.naturalHeight || source.height;
      var scale = Math.min(1, 320 / Math.max(width, height));
      var canvas = document.createElement('canvas');
      canvas.width = Math.max(1, Math.round(width * scale));
      canvas.height = Math.max(1, Math.round(height * scale));
      var context = canvas.getContext('2d');
      context.drawImage(source, 0, 0, canvas.width, canvas.height);
      return await new Promise(function (resolve, reject) {
        canvas.toBlob(function (blob) {
          if (blob) { resolve(blob); }
          else { reject(new Error('Preview unavailable')); }
        }, 'image/webp', 0.75);
      });
    } finally {
      if (source && source.close) { source.close(); }
      if (sourceUrl) { window.URL.revokeObjectURL(sourceUrl); }
    }
  }

  window.GreyhoundUploadPreviews = {
    create: function (root) {
      var jobs = new Map();
      var targets = new WeakMap();
      var running = 0;
      var destroyed = false;
      var observer = window.IntersectionObserver ? new IntersectionObserver(function (entries) {
        entries.forEach(function (entry) {
          var job = targets.get(entry.target);
          if (job) { job.visible = entry.isIntersecting; }
        });
        pump();
      }, { root: root, rootMargin: '120px 0px' }) : null;

      function remove(id) {
        var job = jobs.get(id);
        if (!job) { return; }
        job.cancelled = true;
        if (observer) { observer.unobserve(job.target); }
        if (job.url) { window.URL.revokeObjectURL(job.url); }
        targets.delete(job.target);
        jobs.delete(id);
      }

      async function render(job) {
        running += 1;
        job.started = true;
        try {
          var blob = await thumbnail(job.file);
          if (job.cancelled || destroyed) { return; }
          var image = document.createElement('img');
          image.alt = job.file.name;
          image.decoding = 'async';
          job.url = window.URL.createObjectURL(blob);
          image.addEventListener('error', function () {
            if (!job.cancelled) { remove(job.id); job.onError(); }
          }, { once: true });
          image.src = job.url;
          job.target.replaceChildren(image);
        } catch (error) {
          if (!job.cancelled && !destroyed) { remove(job.id); job.onError(); }
        } finally {
          running -= 1;
          pump();
        }
      }

      function pump() {
        if (destroyed) { return; }
        // Bound large image decoding even when hundreds of files are selected.
        for (var job of jobs.values()) {
          if (running >= 2) { break; }
          if (job.visible && !job.started) { render(job); }
        }
      }

      function clear() { Array.from(jobs.keys()).forEach(remove); }

      return {
        add: function (id, file, target, onError) {
          if (destroyed || jobs.has(id)) { return; }
          var placeholder = document.createElement('span');
          placeholder.className = 'upload-review-fallback';
          placeholder.textContent = 'Loading preview…';
          target.replaceChildren(placeholder);
          var job = { id: id, file: file, target: target, onError: onError, visible: !observer, started: false, cancelled: false };
          jobs.set(id, job);
          targets.set(target, job);
          if (observer) { observer.observe(target); }
          else { pump(); }
        },
        remove: remove,
        clear: clear,
        destroy: function () {
          destroyed = true;
          clear();
          if (observer) { observer.disconnect(); }
        }
      };
    }
  };
})();
