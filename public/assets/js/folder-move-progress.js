(function () {
    'use strict';
    if (window.GreyhoundFolderMove) return;
    let panel = null, busy = false, timer = null;
    function element(tag, text, className) {
        const node = document.createElement(tag);
        if (text) node.textContent = text;
        if (className) node.className = className;
        return node;
    }
    window.GreyhoundFolderMove = {
        isBusy: () => busy,
        beforeReplace(body) { if (panel) body.appendChild(panel); },
        start(names, destination) {
            if (busy) return null;
            if (panel) panel.remove();
            busy = true;
            panel = element('section', '', 'bg-white border rounded-4 shadow p-3');
            panel.id = 'folderMoveProgress';
            panel.hidden = true;
            panel.style.cssText = 'position:fixed;bottom:20px;right:20px;width:360px;max-width:calc(100vw - 40px);z-index:1080';
            panel.setAttribute('aria-label', 'Folder move progress');
            const header = element('div', '', 'd-flex align-items-center gap-2');
            const spinner = element('span', '', 'spinner-border spinner-border-sm text-primary');
            spinner.setAttribute('aria-hidden', 'true');
            const title = element('strong', 'Moving folders', 'flex-grow-1');
            const collapse = element('button', '−', 'btn btn-light btn-sm');
            collapse.type = 'button'; collapse.setAttribute('aria-label', 'Minimize move details');
            collapse.setAttribute('aria-expanded', 'true');
            const close = element('button', '', 'btn-close');
            close.type = 'button'; close.hidden = true; close.style.display = 'none'; close.setAttribute('aria-label', 'Dismiss move status');
            const details = element('div', '', 'mt-2');
            details.id = 'folderMoveProgressDetails';
            collapse.setAttribute('aria-controls', details.id);
            const message = element('p', 'Moving folders and their contents…', 'small mb-2');
            message.setAttribute('role', 'status'); message.setAttribute('aria-live', 'polite');
            const target = element('p', 'Destination: ' + (destination || 'Root Library'), 'small text-break mb-2');
            const list = element('ul', '', 'small mb-0 ps-3');
            list.style.cssText = 'max-height:180px;overflow:auto';
            names.forEach(name => list.appendChild(element('li', name, 'text-break mb-1')));
            details.append(target, list);
            collapse.addEventListener('click', () => {
                details.hidden = !details.hidden;
                collapse.textContent = details.hidden ? '+' : '−';
                collapse.setAttribute('aria-expanded', String(!details.hidden));
                collapse.setAttribute('aria-label', details.hidden ? 'Expand move details' : 'Minimize move details');
            });
            close.addEventListener('click', () => { if (!busy) { panel.remove(); panel = null; } });
            header.append(spinner, title, collapse, close);
            panel.append(header, message, details);
            document.body.appendChild(panel);
            timer = window.setTimeout(() => { panel.hidden = false; }, 1000);
            let finished = false;
            return function finish(ok, text) {
                if (finished) return;
                finished = true; busy = false; window.clearTimeout(timer);
                panel.hidden = false; spinner.hidden = true; spinner.style.display = 'none'; close.hidden = false; close.style.display = '';
                title.textContent = ok ? 'Move completed' : 'Move needs attention';
                message.textContent = text;
                message.className = 'small mt-2 mb-2 ' + (ok ? 'text-success' : 'text-danger');
            };
        }
    };
}());
