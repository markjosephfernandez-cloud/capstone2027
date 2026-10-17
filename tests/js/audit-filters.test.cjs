const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const code = fs.readFileSync('app/Views/addons/audit_filter_script.php', 'utf8').replace(/<\/?script>/g, '');
function setup(selected = '', names = ['module', 'action']) {
    let document;
    const element = () => ({ children: [], listeners: {}, attributes: {}, value: '',
        addEventListener(type, callback) { (this.listeners[type] ||= []).push(callback); },
        fire(type, extra = {}) { const event = { target: this, preventDefault() { this.prevented = true; }, stopPropagation() { this.stopped = true; }, ...extra }; (this.listeners[type] || []).forEach(fn => fn(event)); return event; },
        setAttribute(key, value) { this.attributes[key] = value; },
        append(...children) { this.children.push(...children); }, appendChild(child) { this.append(child); },
        after(picker) { this.picker = picker; }, focus() { document.activeElement = this; },
        contains(node) { return node === this || this.children.some(child => child.contains(node)); },
        dispatchEvent(event) { this.events ||= []; this.events.push(event); this.fire(event.type); }
    });
    const selects = names.map(name => {
        const select = element(); select.name = name; select.id = {module:'auditModule',action:'auditAction',role:'adminRoleFilter',status:'adminStatusFilter'}[name];
        select.options = {module:['','auth','upload'],action:['','login','logout'],role:['','super_admin','admin'],status:['','active','inactive']}[name].map(value => ({ value, textContent: value || 'All ' + (name === 'status' ? 'statuses' : name + 's'), get selected() { return select.value === value; } }));
        select.value = selected;
        Object.defineProperty(select, 'selectedIndex', { get() { return Math.max(0, this.options.findIndex(option => option.value === this.value)); } });
        return select;
    });
    const page = element(); page.querySelectorAll = () => selects; page.querySelector = () => null;
    document = { querySelector: () => page, createElement: element, addEventListener(type, callback) { callback(); } };
    vm.runInNewContext(code, { document, Event });
    return { selects, page, document, parts(index = 0) { const picker = selects[index].picker; const [summary, menu] = picker.children; const [search, options, empty] = menu.children.length === 1 ? [null, menu.children[0], null] : menu.children; return { picker, summary, search, buttons: options.children, empty }; } };
}
test('both dropdowns retain original values and dispatch AJAX changes on selection', () => {
    const app = setup();
    app.selects.forEach((select, index) => {
        const parts = app.parts(index);
        assert.equal(select.hidden, true);
        parts.buttons[1].fire('click');
        assert.equal(select.value, index === 0 ? 'auth' : 'login');
        assert.equal(parts.summary.textContent, select.value);
        assert.equal(parts.buttons[1].attributes['aria-pressed'], 'true');
        assert.equal(select.events[0].type, 'change');
        assert.equal(select.events[0].bubbles, true);
        parts.buttons[0].fire('click');
        assert.equal(select.value, '');
    });
});
test('option search stays local, preserves selection and handles empty matches', () => {
    const app = setup('auth'); const parts = app.parts();
    assert.equal(parts.summary.textContent, 'auth');
    parts.search.value = ' UP '; const event = parts.search.fire('input');
    assert.equal(event.stopped, true);
    assert.deepEqual(parts.buttons.map(button => button.hidden), [true, true, false]);
    assert.equal(app.selects[0].value, 'auth');
    assert.equal(app.selects[0].events, undefined);
    parts.search.value = 'missing'; parts.search.fire('input');
    assert.equal(parts.empty.hidden, false);
    assert.equal(parts.search.fire('keydown', { key: 'Enter' }).prevented, true);
    assert.equal(parts.search.fire('compositionend').stopped, true);
});
test('opening resets search, closes the other menu and supports arrows and Escape', () => {
    const app = setup(); const parts = app.parts();
    app.parts(1).picker.open = true;
    parts.picker.open = true; parts.picker.fire('toggle');
    assert.equal(app.parts(1).picker.open, false);
    assert.equal(app.document.activeElement, parts.search);
    parts.picker.fire('keydown', { key: 'ArrowDown' });
    assert.equal(app.document.activeElement, parts.buttons[0]);
    parts.picker.fire('keydown', { key: 'Escape' });
    assert.equal(parts.picker.open, false);
    assert.equal(app.document.activeElement, parts.summary);
});

test('admin role and status menus retain values and apply changes', () => {
    const app = setup('', ['role','status']);
    assert.equal(app.parts(0).summary.textContent, 'All roles');
    assert.equal(app.parts(1).summary.textContent, 'All statuses');
    app.parts(0).buttons[1].fire('click');
    app.parts(1).buttons[2].fire('click');
    assert.equal(app.selects[0].value, 'super_admin');
    assert.equal(app.selects[1].value, 'inactive');
    assert.equal(app.selects[0].events[0].type, 'change');
    assert.equal(app.selects[1].events[0].bubbles, true);
});
test('touch blur does not dismiss an admin option before its click', () => {
    const app = setup('', ['role','status']); const parts = app.parts();
    parts.picker.open = true;
    parts.picker.fire('focusout', {relatedTarget:null});
    assert.equal(parts.picker.open, true);
    assert.equal(parts.buttons[1].fire('pointerdown').prevented, true);
    parts.buttons[1].fire('click');
    assert.equal(app.selects[0].value, 'super_admin');
    assert.equal(parts.picker.open, false);
});
