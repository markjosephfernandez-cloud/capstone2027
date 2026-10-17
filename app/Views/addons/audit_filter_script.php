<script>
document.addEventListener('DOMContentLoaded', function () {
    var page = document.querySelector('.audit-page, .admin-center');
    if (!page) return;
    var pickers = [];
    page.querySelectorAll('#auditModule, #auditAction, #adminRoleFilter, #adminStatusFilter').forEach(function (select) {
        if (select.hidden) return;
        var name = {module: 'modules', action: 'actions', role: 'roles', status: 'statuses'}[select.name];
        var searchable = select.name !== 'role' && select.name !== 'status';
        var picker = document.createElement('details');
        picker.className = 'audit-filter-picker';
        var summary = document.createElement('summary');
        summary.className = 'form-select';
        summary.id = select.id + 'Picker';
        summary.setAttribute('aria-label', 'Filter by ' + name);
        var menu = document.createElement('div');
        menu.className = 'audit-filter-menu';
        var search = document.createElement('input');
        search.type = 'search';
        search.className = 'form-control';
        search.placeholder = 'Search ' + name + '…';
        search.setAttribute('aria-label', 'Search ' + name);
        search.autocomplete = 'off';
        var options = document.createElement('div');
        options.className = 'audit-filter-options';
        var empty = document.createElement('p');
        empty.className = 'small text-muted m-2';
        empty.setAttribute('role', 'status');
        empty.textContent = 'No matching ' + name + '.';
        empty.hidden = true;
        var buttons = Array.from(select.options).map(function (option) {
            var button = document.createElement('button');
            button.type = 'button';
            button.className = 'audit-filter-option';
            button.textContent = option.textContent.trim();
            button.addEventListener('pointerdown', function (event) { event.preventDefault(); });
            button.addEventListener('click', function () {
                select.value = option.value;
                sync();
                close(true);
                select.dispatchEvent(new Event('change', { bubbles: true }));
            });
            options.appendChild(button);
            return button;
        });
        function sync() {
            var selected = select.options[select.selectedIndex];
            summary.textContent = selected ? selected.textContent.trim() : 'All ' + name;
            buttons.forEach(function (button, index) {
                button.setAttribute('aria-pressed', select.options[index].selected ? 'true' : 'false');
            });
        }
        function close(focus) {
            picker.open = false;
            if (focus) summary.focus();
        }
        function filter() {
            var query = search.value.trim().toLocaleLowerCase();
            buttons.forEach(function (button) {
                button.hidden = !button.textContent.toLocaleLowerCase().includes(query);
            });
            empty.hidden = buttons.some(function (button) { return !button.hidden; });
        }
        search.addEventListener('input', function (event) {
            event.stopPropagation(); // Searching options must not submit the audit form.
            filter();
        });
        ['compositionstart', 'compositionend'].forEach(function (type) {
            search.addEventListener(type, function (event) { event.stopPropagation(); });
        });
        search.addEventListener('keydown', function (event) {
            if (event.key === 'Enter') event.preventDefault();
        });
        picker.addEventListener('keydown', function (event) {
            if (event.key === 'Escape') {
                event.preventDefault(); event.stopPropagation(); close(true);
            }
            if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
                event.preventDefault();
                var visible = buttons.filter(function (button) { return !button.hidden; });
                var index = visible.indexOf(document.activeElement);
                var next = index < 0 ? (event.key === 'ArrowDown' ? 0 : visible.length - 1) : index + (event.key === 'ArrowDown' ? 1 : -1);
                if (visible.length) visible[(next + visible.length) % visible.length].focus();
            }
        });
        picker.addEventListener('toggle', function () {
            if (!picker.open) return;
            pickers.forEach(function (other) { if (other !== picker) other.open = false; });
            search.value = '';
            filter();
            if (searchable) search.focus();
            else (buttons[select.selectedIndex] || buttons[0])?.focus();
        });
        picker.addEventListener('focusout', function (event) {
            if (event.relatedTarget && !picker.contains(event.relatedTarget)) close(false);
        });
        select.addEventListener('change', sync);
        if (searchable) menu.append(search, options, empty);
        else menu.append(options);
        picker.append(summary, menu);
        select.after(picker);
        select.hidden = true; // Retain the original name/value for GET and AJAX filtering.
        var label = page.querySelector('label[for="' + select.id + '"]');
        if (label) label.htmlFor = summary.id;
        pickers.push(picker);
        sync();
    });
    page.addEventListener('click', function (event) {
        pickers.forEach(function (picker) { if (!picker.contains(event.target)) picker.open = false; });
    });
});
</script>
