(function () {
  'use strict';
  var instances = new Map(), nextId = 0;
  function attach(input, source, apply) {
    if (!input || instances.has(input)) return;
    var list = document.createElement('div'), status = document.createElement('span');
    list.id = 'search-options-' + (++nextId); list.hidden = true;
    list.className = 'list-group shadow'; list.setAttribute('role', 'listbox');
    Object.assign(list.style, {position:'fixed', zIndex:'1090', maxHeight:'260px', overflowY:'auto'});
    status.className = 'visually-hidden'; status.id = list.id + '-status'; status.setAttribute('role','status');
    input.parentElement.append(list, status);
    input.setAttribute('autocomplete','off'); input.setAttribute('role','combobox');
    input.setAttribute('aria-autocomplete','list'); input.setAttribute('aria-expanded','false');
    input.setAttribute('aria-controls',list.id); input.setAttribute('aria-describedby',status.id);
    var timer, request, generation = 0, selected = -1, options = [], composing = false, listeners = [];
    function listen(node, event, fn, capture) { node.addEventListener(event, fn, capture); listeners.push(function () { node.removeEventListener(event, fn, capture); }); }
    function close() {
      clearTimeout(timer); generation++; if (request) request.abort(); request = null;
      list.hidden = true; list.replaceChildren(); options = []; selected = -1;
      input.setAttribute('aria-expanded','false'); input.removeAttribute('aria-activedescendant');
    }
    function commit(value) { input.value = value; close(); apply(value); }
    function position() { var r = input.getBoundingClientRect(); list.style.left = r.left + 'px'; list.style.top = r.bottom + 'px'; list.style.width = r.width + 'px'; }
    async function suggest() {
      close();
      var term = input.value.trim();
      if (composing || term.length < 2) return;
      var run = generation;
      timer = setTimeout(async function () {
        var timeout;
        try {
          request = new AbortController(); var current = request;
          timeout = setTimeout(function () { current.abort(); }, 8000);
          var items = await source(term, current.signal);
          if (run !== generation || !input.isConnected || document.activeElement !== input) return;
          var seen = new Set();
          items.slice(0, 8).forEach(function (item) {
            var value = typeof item === 'string' ? item : item.value;
            if (!value || seen.has(value)) return; seen.add(value);
            var button = document.createElement('button'); button.type = 'button'; button.tabIndex = -1;
            button.className = 'list-group-item list-group-item-action text-start';
            button.textContent = item.label || value; button.dataset.value = value;
            button.id = list.id + '-' + options.length; button.setAttribute('role','option'); button.setAttribute('aria-selected','false');
            button.addEventListener('pointerdown', function (event) { event.preventDefault(); });
            button.addEventListener('click', function () { commit(value); });
            options.push(button); list.appendChild(button);
          });
          position(); list.hidden = options.length === 0; input.setAttribute('aria-expanded', String(options.length > 0));
          status.textContent = options.length ? 'Choose a suggestion or press Enter to search.' : 'No suggestions. Press Enter to search.';
        } catch (error) {
          if (run === generation) status.textContent = 'Suggestions unavailable. Press Enter to search.';
        } finally { clearTimeout(timeout); }
      }, 250);
    }
    listen(input,'input', function (event) { if (!event.isComposing) suggest(); });
    listen(input,'focus',suggest);
    listen(input,'blur',close);
    listen(input,'compositionstart',function () { composing = true; close(); });
    listen(input,'compositionend',function () { composing = false; suggest(); });
    listen(input,'keydown',function (event) {
      if (composing || event.isComposing) return;
      if (event.key === 'Enter') { event.preventDefault(); event.stopPropagation(); commit(selected >= 0 ? options[selected].dataset.value : input.value); }
      else if ((event.key === 'ArrowDown' || event.key === 'ArrowUp') && options.length) {
        event.preventDefault(); selected = selected < 0 ? (event.key === 'ArrowDown' ? 0 : options.length - 1) : (selected + (event.key === 'ArrowDown' ? 1 : -1) + options.length) % options.length;
        options.forEach(function (option, i) { option.setAttribute('aria-selected',String(i === selected)); option.classList.toggle('active',i === selected); });
        input.setAttribute('aria-activedescendant',options[selected].id); options[selected].scrollIntoView({block:'nearest'});
      } else if (event.key === 'Escape' || event.key === 'Tab') close();
    });
    if (input.form) listen(input.form,'submit',close);
    listen(window,'resize',close); listen(window,'scroll',function (event) { if (!list.contains(event.target)) close(); },true);
    var cleanup = function () { close(); listeners.forEach(function (remove) { remove(); }); list.remove(); status.remove(); };
    cleanup.close = close; instances.set(input,cleanup);
  }
  async function remote(url, signal) {
    var response = await fetch(url.toString(), {credentials:'same-origin',cache:'no-store',signal:signal,headers:{Accept:'application/json'}});
    if (!response.ok || response.redirected) throw new Error('Unavailable');
    var data = await response.json(); return Array.isArray(data.suggestions) ? data.suggestions : [];
  }
  function init() {
    instances.forEach(function (cleanup, input) { if (!input.isConnected) { cleanup(); instances.delete(input); } });
    document.querySelectorAll('[data-search-suggestions]').forEach(function (input) {
      attach(input, function (term, signal) {
        var url = new URL(input.dataset.searchSuggestions, window.location.href); url.searchParams.set('q',term);
        ['folder','type'].forEach(function (name) { var field = input.form && input.form.elements.namedItem(name); if (field && field.value) url.searchParams.set(name,field.value); });
        return remote(url,signal);
      }, function () { input.form.requestSubmit(); });
    });
  }
  window.GreyhoundSearch = {attach:attach, init:init, remote:remote};
  document.addEventListener('DOMContentLoaded',init);
  window.addEventListener('pagehide',function () { instances.forEach(function (cleanup) { cleanup.close(); }); });
  window.addEventListener('pageshow',function (event) { if (event.persisted) init(); });
}());
