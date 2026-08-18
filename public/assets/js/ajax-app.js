/**
 * Greyhound progressive AJAX navigation.
 *
 * Existing controllers continue to validate, authorize, set flash messages,
 * and redirect normally. This layer follows those redirects and replaces the
 * rendered page without a full browser refresh.
 */
(function () {
  'use strict';

  if (!window.fetch || !window.FormData || !window.DOMParser || !window.history) {
    return;
  }

  var requestSequence = 0;
  var activeController = null;
  var lastSubmitter = null;
  var uiStyleId = 'greyhound-ajax-style';
  var progressId = 'greyhound-ajax-progress';
  var messageId = 'greyhound-ajax-message';
  var liveSearchTimers = new WeakMap();
  var composingSearchForms = new WeakSet();
  var ajaxMutationRoutes = [
    /\/admin\/users(?:\/\d+\/(?:update|toggle|reset-password|revoke-tokens))?\/?$/i,
    /\/admin\/maintenance\/(?:consent-reminders|purge-expired-trash)\/?$/i,
    /\/privacy\/consent\/?$/i,
    /\/workspace\/file-management\/folders(?:\/\d+\/(?:rename|delete))?\/?$/i,
    /\/workspace\/file-management\/(?:watermark-auto|tags|duplicates\/restore|bulk-trash|post)\/?$/i,
    /\/workspace\/file-management\/\d+\/trash\/?$/i,
    /\/workspace\/ifound\/\d+\/review\/?$/i,
    /\/workspace\/trash\/(?:\d+\/(?:restore|delete)|bulk-(?:restore|delete))\/?$/i,
    /\/workspace\/passports\/(?:backfill|analyze)\/?$/i,
    /\/workspace\/watermarking\/(?:settings|rebuild)\/?$/i,
    /\/workspace\/distribution\/(?:requests|direct-send|requests\/\d+\/(?:transmit|complete))\/?$/i,
    /\/notifications\/(?:read-all|\d+\/read)\/?$/i
  ];

  function installStyles() {
    if (document.getElementById(uiStyleId)) {
      return;
    }

    var style = document.createElement('style');
    style.id = uiStyleId;
    style.textContent =
      '#greyhound-ajax-progress{position:fixed;z-index:10900;top:0;left:0;width:0;height:3px;opacity:0;background:#0d6efd;box-shadow:0 0 10px rgba(13,110,253,.65);transition:width .2s ease,opacity .2s ease}' +
      '#greyhound-ajax-progress.is-loading{width:72%;opacity:1}' +
      '#greyhound-ajax-progress.is-finishing{width:100%;opacity:0}' +
      '#greyhound-ajax-message{position:fixed;z-index:10910;right:1rem;bottom:1rem;max-width:min(420px,calc(100vw - 2rem));padding:.8rem 1rem;border-radius:.5rem;color:#fff;background:#842029;box-shadow:0 .5rem 1.5rem rgba(0,0,0,.2)}' +
      'form[aria-busy="true"] button[type="submit"]{cursor:wait}';
    document.head.appendChild(style);
  }

  function ensureUi() {
    installStyles();
    var progress = document.getElementById(progressId);
    if (!progress) {
      progress = document.createElement('div');
      progress.id = progressId;
      progress.setAttribute('role', 'progressbar');
      progress.setAttribute('aria-label', 'Loading page');
      document.body.appendChild(progress);
    }
    return progress;
  }

  function startProgress() {
    var progress = ensureUi();
    progress.className = '';
    window.requestAnimationFrame(function () {
      progress.classList.add('is-loading');
    });
  }

  function finishProgress() {
    var progress = ensureUi();
    progress.className = 'is-finishing';
    window.setTimeout(function () {
      var current = document.getElementById(progressId);
      if (current) {
        current.className = '';
      }
    }, 240);
  }

  function showMessage(message) {
    var existing = document.getElementById(messageId);
    if (existing) {
      existing.remove();
    }
    var alert = document.createElement('div');
    alert.id = messageId;
    alert.setAttribute('role', 'alert');
    alert.textContent = message;
    document.body.appendChild(alert);
    window.setTimeout(function () {
      if (alert.parentNode) {
        alert.remove();
      }
    }, 6000);
  }

  function actionUrl(form, submitter) {
    var action = submitter && submitter.getAttribute('formaction');
    return new URL(action || form.getAttribute('action') || window.location.href, window.location.href);
  }

  function formMethod(form, submitter) {
    var method = submitter && submitter.getAttribute('formmethod');
    return (method || form.getAttribute('method') || 'get').toUpperCase();
  }

  function hasNativeResponse(url) {
    return /\/api\//i.test(url.pathname) ||
      /\/(?:[^/]*(?:download|preview)|report|stream)(?:\/|$)/i.test(url.pathname);
  }

  function isAuthenticationTransition(url) {
    return /\/(?:login|logout)\/?$/i.test(url.pathname);
  }

  function isAuditedAjaxMutation(url) {
    return ajaxMutationRoutes.some(function (pattern) {
      return pattern.test(url.pathname);
    });
  }

  function mayAjaxForm(form, submitter) {
    if (!(form instanceof HTMLFormElement) || form.id === 'batchUploadForm') {
      return false;
    }
    if (form.matches('[data-ajax="false"], [data-no-ajax]')) {
      return false;
    }
    if (form.getAttribute('method') === 'dialog') {
      return false;
    }
    var target = (submitter && submitter.getAttribute('formtarget')) || form.getAttribute('target');
    if (target && target.toLowerCase() !== '_self') {
      return false;
    }
    var url = actionUrl(form, submitter);
    if (url.origin !== window.location.origin || hasNativeResponse(url) || isAuthenticationTransition(url)) {
      return false;
    }
    var method = formMethod(form, submitter);
    return method === 'GET' || form.matches('[data-ajax="true"]') || isAuditedAjaxMutation(url);
  }

  function mayAjaxLink(link, event) {
    if (!(link instanceof HTMLAnchorElement) || event.defaultPrevented || event.button !== 0) {
      return false;
    }
    if (event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) {
      return false;
    }
    if (link.matches('[download], [data-ajax="false"], [data-no-ajax]')) {
      return false;
    }
    var target = link.getAttribute('target');
    if (target && target.toLowerCase() !== '_self') {
      return false;
    }
    var href = link.getAttribute('href');
    if (!href || href === '#' || href.charAt(0) === '#') {
      return false;
    }
    var url = new URL(link.href, window.location.href);
    return url.origin === window.location.origin && !hasNativeResponse(url) && !isAuthenticationTransition(url);
  }

  function addSubmitter(formData, submitter) {
    if (!submitter || !submitter.name || submitter.disabled) {
      return;
    }
    formData.append(submitter.name, submitter.value || '');
  }

  function buildGetUrl(url, form, submitter) {
    var formData = new FormData(form);
    addSubmitter(formData, submitter);
    url.search = '';
    formData.forEach(function (value, key) {
      if (typeof value === 'string') {
        url.searchParams.append(key, value);
      }
    });
    return url;
  }

  function executePageScripts(scripts) {
    if (!scripts.length) {
      return;
    }

    var nativeAddEventListener = document.addEventListener;
    var readyCallbacks = [];
    document.addEventListener = function (type, listener, options) {
      if (type === 'DOMContentLoaded') {
        readyCallbacks.push(listener);
        return;
      }
      return nativeAddEventListener.call(document, type, listener, options);
    };

    try {
      scripts.forEach(function (code, index) {
        if (code.trim()) {
          (0, eval)(code + '\n//# sourceURL=greyhound-ajax-page-' + index + '.js');
        }
      });
    } finally {
      document.addEventListener = nativeAddEventListener;
    }

    readyCallbacks.forEach(function (listener) {
      if (typeof listener === 'function') {
        listener.call(document, new Event('DOMContentLoaded'));
      } else if (listener && typeof listener.handleEvent === 'function') {
        listener.handleEvent(new Event('DOMContentLoaded'));
      }
    });
  }

  function replacePage(html, destination, options) {
    var parsed = new DOMParser().parseFromString(html, 'text/html');
    if (!parsed.body || !parsed.querySelector('#main')) {
      throw new Error('The server returned a page that cannot be displayed inside the application.');
    }

    var scripts = [];
    parsed.body.querySelectorAll('script').forEach(function (script) {
      if (!script.src && (!script.type || script.type === 'text/javascript' || script.type === 'application/javascript')) {
        scripts.push(script.textContent || '');
      }
      script.remove();
    });

    var importedBody = document.importNode(parsed.body, true);
    document.body.replaceWith(importedBody);
    if (parsed.title) {
      document.title = parsed.title;
    }

    if (options.history === 'push') {
      window.history.pushState({ greyhoundAjax: true }, '', destination.href);
    } else if (options.history === 'replace') {
      window.history.replaceState({ greyhoundAjax: true }, '', destination.href);
    }

    ensureUi();
    if (typeof window.GreyhoundMainInit === 'function') {
      window.GreyhoundMainInit();
    }
    executePageScripts(scripts);

    if (options.preserveScroll) {
      window.scrollTo(0, options.scrollY || 0);
    } else if (destination.hash) {
      var target = document.getElementById(decodeURIComponent(destination.hash.slice(1)));
      if (target) {
        target.scrollIntoView();
      }
    } else {
      window.scrollTo(0, 0);
    }

    var focusTarget = options.focusId ? document.getElementById(options.focusId) : null;
    if (!focusTarget && options.focusName) {
      focusTarget = document.querySelector('[name="' + options.focusName.replace(/"/g, '\\"') + '"]');
    }
    if (focusTarget && typeof focusTarget.focus === 'function') {
      focusTarget.focus({ preventScroll: true });
      if (typeof focusTarget.setSelectionRange === 'function') {
        var caret = Math.min(Number(options.focusCaret) || focusTarget.value.length, focusTarget.value.length);
        focusTarget.setSelectionRange(caret, caret);
      }
    }
  }

  async function load(requestUrl, requestOptions, pageOptions) {
    var sequence = ++requestSequence;
    if (activeController) {
      activeController.abort();
    }
    activeController = typeof AbortController !== 'undefined' ? new AbortController() : null;
    if (activeController) {
      requestOptions.signal = activeController.signal;
    }

    requestOptions.credentials = 'same-origin';
    requestOptions.headers = Object.assign({
      'Accept': 'text/html,application/xhtml+xml',
      'X-Requested-With': 'XMLHttpRequest'
    }, requestOptions.headers || {});

    startProgress();
    try {
      var response = await fetch(requestUrl.href, requestOptions);
      if (sequence !== requestSequence) {
        return;
      }
      if (!response.ok) {
        throw new Error('The server could not complete this action (HTTP ' + response.status + ').');
      }
      var contentType = response.headers.get('content-type') || '';
      if (!contentType.includes('text/html') && !contentType.includes('application/xhtml+xml')) {
        throw new Error('The requested action returned an unsupported response.');
      }
      var html = await response.text();
      var destination = new URL(response.url || requestUrl.href, window.location.href);
      if (!destination.hash && requestUrl.hash) {
        destination.hash = requestUrl.hash;
      }
      if (isAuthenticationTransition(destination)) {
        window.location.assign(destination.href);
        return;
      }
      replacePage(html, destination, pageOptions);
    } catch (error) {
      if (error && error.name === 'AbortError') {
        return;
      }
      showMessage(error && error.message ? error.message : 'The action could not be completed. Check your connection and try again.');
      throw error;
    } finally {
      if (sequence === requestSequence) {
        activeController = null;
        finishProgress();
      }
    }
  }

  document.addEventListener('click', function (event) {
    var submitButton = event.target.closest('button[type="submit"], input[type="submit"], input[type="image"]');
    if (submitButton && submitButton.form) {
      lastSubmitter = submitButton;
      return;
    }

    var link = event.target.closest('a');
    if (!mayAjaxLink(link, event)) {
      return;
    }
    event.preventDefault();
    var url = new URL(link.href, window.location.href);
    load(url, { method: 'GET' }, { history: 'push', preserveScroll: false }).catch(function () {});
  });

  document.addEventListener('submit', function (event) {
    if (event.defaultPrevented) {
      return;
    }
    var form = event.target;
    var submitter = event.submitter || (lastSubmitter && lastSubmitter.form === form ? lastSubmitter : null);
    lastSubmitter = null;
    if (!mayAjaxForm(form, submitter)) {
      return;
    }

    event.preventDefault();
    var method = formMethod(form, submitter);
    var url = actionUrl(form, submitter);
    var request = { method: method };
    var scrollY = window.scrollY;
    var activeElement = document.activeElement;
    var restoreLiveSearchFocus = method === 'GET' && form.matches('[data-ajax-live-search]') && activeElement && form.contains(activeElement);

    if (method === 'GET') {
      url = buildGetUrl(url, form, submitter);
    } else {
      var formData = new FormData(form);
      addSubmitter(formData, submitter);
      request.body = formData;
    }

    form.setAttribute('aria-busy', 'true');
    if (submitter) {
      submitter.disabled = true;
    }

    load(url, request, {
      history: method === 'GET' ? 'push' : 'replace',
      preserveScroll: method !== 'GET',
      scrollY: scrollY,
      focusId: restoreLiveSearchFocus ? activeElement.id : '',
      focusName: restoreLiveSearchFocus ? activeElement.getAttribute('name') : '',
      focusCaret: restoreLiveSearchFocus && typeof activeElement.selectionStart === 'number' ? activeElement.selectionStart : null
    }).catch(function () {
      form.removeAttribute('aria-busy');
      if (submitter) {
        submitter.disabled = false;
      }
    });
  });

  function liveSearchForm(target) {
    if (!(target instanceof Element)) {
      return null;
    }
    var form = target.closest('form[data-ajax-live-search]');
    return form instanceof HTMLFormElement && formMethod(form, null) === 'GET' ? form : null;
  }

  function updateLiveSearchStatus(form, message, searching) {
    var status = form.querySelector('[data-ajax-search-status]');
    if (!status) {
      return;
    }
    status.textContent = message;
    status.classList.toggle('is-searching', searching);
  }

  function scheduleLiveSearch(form, immediate) {
    var previousTimer = liveSearchTimers.get(form);
    if (previousTimer) {
      window.clearTimeout(previousTimer);
    }
    var requestedDelay = Number(form.getAttribute('data-ajax-live-delay'));
    var delay = immediate ? 0 : (Number.isFinite(requestedDelay) && requestedDelay >= 150 ? requestedDelay : 450);
    updateLiveSearchStatus(form, delay === 0 ? 'Updating results…' : 'Waiting for you to finish typing…', true);
    var timer = window.setTimeout(function () {
      liveSearchTimers.delete(form);
      updateLiveSearchStatus(form, 'Searching…', true);
      if (typeof form.requestSubmit === 'function') {
        form.requestSubmit();
      } else {
        form.submit();
      }
    }, delay);
    liveSearchTimers.set(form, timer);
  }

  document.addEventListener('compositionstart', function (event) {
    var form = liveSearchForm(event.target);
    if (form) {
      composingSearchForms.add(form);
    }
  });

  document.addEventListener('compositionend', function (event) {
    var form = liveSearchForm(event.target);
    if (form) {
      composingSearchForms.delete(form);
      scheduleLiveSearch(form, false);
    }
  });

  document.addEventListener('input', function (event) {
    var form = liveSearchForm(event.target);
    if (!form || composingSearchForms.has(form) || !event.target.matches('input[type="search"], input[type="text"], input:not([type])')) {
      return;
    }
    scheduleLiveSearch(form, false);
  });

  document.addEventListener('change', function (event) {
    var form = liveSearchForm(event.target);
    if (!form || !event.target.matches('select, input[type="checkbox"], input[type="radio"]')) {
      return;
    }
    scheduleLiveSearch(form, true);
  });

  window.addEventListener('popstate', function () {
    var url = new URL(window.location.href);
    load(url, { method: 'GET' }, { history: 'none', preserveScroll: false }).catch(function () {});
  });

  window.history.replaceState({ greyhoundAjax: true }, '', window.location.href);
  ensureUi();

  window.GreyhoundAjax = {
    refresh: function (options) {
      options = options || {};
      var url = new URL(window.location.href);
      return load(url, { method: 'GET' }, {
        history: 'replace',
        preserveScroll: options.preserveScroll !== false,
        scrollY: window.scrollY
      });
    },
    navigate: function (url) {
      var destination = new URL(url, window.location.href);
      return load(destination, { method: 'GET' }, { history: 'push', preserveScroll: false });
    }
  };
})();
