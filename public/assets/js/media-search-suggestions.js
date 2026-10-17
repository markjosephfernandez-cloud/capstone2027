(function () {
  'use strict';
  var cleanup;
  window.GreyhoundMediaSearch = {
    init: function () {
      if (cleanup) cleanup();
      var input = document.getElementById('mediaSearch');
      var list = document.getElementById('mediaSearchSuggestions');
      var status = document.getElementById('mediaSearchStatus');
      var form = document.getElementById('mediaFilterForm');
      var clearButton = document.getElementById('mediaSearchClear');
      if (!input || !list || !form) return;
      var timer, controller, generation = 0, selected = -1, options = [], composing = false;
      var defaultHelp = 'Choose a suggestion, press Enter, or click Apply filters to search.';
      var listeners = [];
      function listen(node, name, fn) { node.addEventListener(name, fn); listeners.push(function () { node.removeEventListener(name, fn); }); }
      function cancel() {
        clearTimeout(timer); generation++;
        if (controller) controller.abort();
        controller = null;
      }
      function close() {
        cancel(); list.hidden = true; selected = -1; options = [];
        list.replaceChildren(); input.setAttribute('aria-expanded', 'false'); input.removeAttribute('aria-activedescendant');
        status.textContent = defaultHelp;
      }
      function choose(value) {
        input.value = value; syncClearButton(); close(); form.requestSubmit();
      }
      function syncClearButton() { if (clearButton) clearButton.hidden = input.value.length === 0; }
      function highlight(index) {
        selected = index;
        options.forEach(function (option, i) { option.setAttribute('aria-selected', String(i === selected)); });
        input.setAttribute('aria-activedescendant', options[index].id);
        options[index].scrollIntoView({ block: 'nearest' });
      }
      async function load(run, query) {
        if (run !== generation || !form.isConnected) return;
        var request = new AbortController(); controller = request;
        var timeout = setTimeout(function () { request.abort(); }, 8000);
        status.textContent = 'Finding suggestions…';
        try {
          var url = new URL(input.dataset.suggestionsUrl, window.location.href);
          url.searchParams.set('q', query);
          ['type', 'stage', 'distribution', 'folder'].forEach(function (name) {
            var field = form.elements.namedItem(name);
            if (field && field.value) url.searchParams.set(name, field.value);
          });
          var response = await fetch(url.toString(), { credentials: 'same-origin', cache: 'no-store', signal: request.signal, headers: { Accept: 'application/json' } });
          if (!response.ok || response.redirected) throw new Error('Suggestions unavailable');
          var data = await response.json();
          if (run !== generation || !form.isConnected || document.activeElement !== input) return;
          var values = Array.isArray(data.suggestions) ? data.suggestions.slice(0, 8) : [];
          values.forEach(function (item) {
            if (typeof item.value !== 'string' || !item.value.trim()) return;
            var option = document.createElement('button');
            option.type = 'button'; option.tabIndex = -1; option.className = 'media-suggestion';
            option.id = 'mediaSuggestion-' + options.length;
            option.dataset.value = item.value;
            option.setAttribute('role', 'option'); option.setAttribute('aria-selected', 'false');
            var label = document.createElement('span'); label.textContent = item.value;
            var kind = document.createElement('small'); kind.textContent = item.kind || '';
            option.append(label, kind);
            option.addEventListener('pointerdown', function (event) { event.preventDefault(); });
            option.addEventListener('click', function () { choose(item.value); });
            options.push(option); list.appendChild(option);
          });
          list.hidden = !options.length;
          input.setAttribute('aria-expanded', String(options.length > 0));
          status.textContent = options.length ? options.length + ' suggestions. Use arrow keys and Enter, or click a suggestion.' : 'No suggestions. Press Enter or click Apply filters to search.';
        } catch (error) {
          if (run === generation && form.isConnected && document.activeElement === input) {
            status.textContent = 'Suggestions unavailable. Press Enter or click Apply filters to search.';
          }
        } finally {
          clearTimeout(timeout);
          if (controller === request) controller = null;
        }
      }
      function schedule() {
        close();
        var query = input.value.trim();
        if (composing || query.length < 2) return;
        var run = generation;
        timer = setTimeout(function () { load(run, query); }, 300);
      }
      syncClearButton();
      if (clearButton) listen(clearButton, 'click', function () {
        input.value = ''; composing = false; syncClearButton(); close(); input.focus();
      });
      listen(input, 'input', function (event) { syncClearButton(); if (!event.isComposing) schedule(); });
      listen(input, 'compositionstart', function () { composing = true; close(); });
      listen(input, 'compositionend', function () { composing = false; schedule(); });
      listen(input, 'focus', schedule);
      listen(input, 'blur', close);
      listen(input, 'keydown', function (event) {
        if (composing || event.isComposing) return;
        if ((event.key === 'ArrowDown' || event.key === 'ArrowUp') && options.length) {
          event.preventDefault();
          highlight(selected < 0 ? (event.key === 'ArrowDown' ? 0 : options.length - 1) : (selected + (event.key === 'ArrowDown' ? 1 : -1) + options.length) % options.length);
        } else if (event.key === 'Enter' && selected >= 0 && !list.hidden) {
          event.preventDefault(); choose(options[selected].dataset.value);
        } else if (event.key === 'Escape' || event.key === 'Tab') close();
      });
      listen(form, 'submit', close);
      // Changing other filters invalidates suggestions but does not run a search.
      listen(form, 'change', function (event) { if (event.target !== input) close(); });
      cleanup = function () { close(); listeners.forEach(function (remove) { remove(); }); };
    }
  };
  window.addEventListener('pagehide', function () { if (cleanup) cleanup(); });
  window.addEventListener('pageshow', function (event) { if (event.persisted) window.GreyhoundMediaSearch.init(); });
}());
