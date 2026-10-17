const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const source = fs.readFileSync('app/Views/components/media_upload_script.php', 'utf8');
const code = source.slice(source.indexOf('        var folderPicker ='), source.indexOf('        if (folderPicker && destinationInput.dataset.foldersUrl)'));
function setup() {
    const element = () => ({ listeners: {}, addEventListener(type, fn) { this.listeners[type] = fn; },
        setAttribute(key, value) { this[key] = value; }, focus() { this.focused = true; } });
    const summary = element(), search = element(), label = element(), empty = element(), picker = element();
    search.value = '';
    picker.querySelector = selector => ({ summary, '[data-upload-folder-label]': label, 'input[type="search"]': search, '[role="status"]': empty })[selector];
    picker.contains = target => [summary, search, select, label, empty].includes(target);
    const select = element(); select.dataset = {};
    select.options = ['Root library', 'Audio', 'ENVATO'].map(textContent => ({ textContent }));
    select.selectedIndex = 2;
    const document = element();
    const context = vm.createContext({ uploadWorkspace: { querySelector: () => picker }, destinationInput: select,
        document, previewController: new AbortController() });
    vm.runInContext(code, context);
    return { summary, search, label, empty, picker, select, document, context };
}
test('search preserves the destination and reopening restores all folders', () => {
    const app = setup();
    assert.equal(app.label.textContent, 'ENVATO');
    app.search.value = ' AuD ';
    app.search.listeners.input();
    assert.deepEqual(app.select.options.map(option => option.hidden), [true, false, true]);
    assert.equal(app.select.selectedIndex, 2);
    app.search.value = 'missing'; app.search.listeners.input();
    assert.equal(app.empty.hidden, false);
    app.picker.open = true; app.picker.listeners.toggle();
    assert.ok(app.select.options.every(option => !option.hidden));
    assert.equal(app.search.focused, true);
});
test('selection updates the label and the picker is disabled during upload', () => {
    const app = setup();
    app.select.selectedIndex = 1; app.select.listeners.change();
    assert.equal(app.label.textContent, 'Audio');
    app.picker.open = true;
    app.select.disabled = true; app.context.syncFolderPicker();
    assert.equal(app.picker.open, false);
    assert.equal(app.summary['aria-disabled'], 'true');
    let prevented = false;
    app.summary.listeners.click({ preventDefault() { prevented = true; } });
    assert.equal(prevented, true);
});
test('Escape and outside clicks dismiss the picker; Enter in search never submits', () => {
    const app = setup();
    app.picker.open = true;
    app.picker.listeners.keydown({ key: 'Escape', preventDefault() {}, stopPropagation() {} });
    assert.equal(app.picker.open, false);
    assert.equal(app.summary.focused, true);
    app.picker.open = true;
    app.document.listeners.click({ target: {} });
    assert.equal(app.picker.open, false);
    let prevented = false;
    app.search.listeners.keydown({ key: 'Enter', preventDefault() { prevented = true; } });
    assert.equal(prevented, true);
});


test('local folder picker stays open on touch blur but closes when focus moves outside', () => {
    const app = setup();
    app.picker.open = true;
    app.picker.listeners.focusout({ relatedTarget: null });
    assert.equal(app.picker.open, true);
    app.picker.listeners.focusout({ relatedTarget: app.search });
    assert.equal(app.picker.open, true);
    app.picker.listeners.focusout({ relatedTarget: {} });
    assert.equal(app.picker.open, false);
});
