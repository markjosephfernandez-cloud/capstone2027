(function () {
    'use strict';

    // File objects and their request controller belong to the tab, not a page render.
    var sessions = new Map();
    var notices = new Map();
    var library = function (root) { return root && root.dataset && root.dataset.library || 'local'; };
    window.GreyhoundUploadSession = {
        register: function (options) {
            sessions.set(library(options.root), options);
        },
        restore: function (root) {
            var session = sessions.get(library(root));
            if (!session || session.root !== root) return false;
            session.bindPickers();
            return true;
        },
        beforeReplace: function (body) {
            notices.clear();
            var slot = body.querySelector('#uploadWorkspace');
            sessions.forEach(function (session, key) {
                if (!session.shouldRetain()) {
                    session.destroy(); sessions.delete(key); return;
                }
                var matches = slot && library(slot) === key;
                session.root.hidden = !matches;
                session.root.id = matches ? 'uploadWorkspace' : 'uploadWorkspace-' + key;
                if (matches) slot.replaceWith(session.root);
                else {
                    body.appendChild(session.root);
                    var notice = document.createElement('div');
                    notice.className = 'alert alert-info d-flex align-items-center gap-3 m-3';
                    notice.setAttribute('role', 'status');
                    notice.style.cssText = 'position:fixed;bottom:' + (notices.size * 76) + 'px;right:0;z-index:1080;max-width:calc(100vw - 2rem)';
                    var label = document.createElement('span');
                    var link = document.createElement('a');
                    link.href = session.url;
                    link.className = 'btn btn-sm btn-primary text-nowrap';
                    link.textContent = 'View ' + key + ' upload';
                    notice.append(label, link); body.appendChild(notice);
                    notices.set(key, notice);
                }
            });
            this.update();
        },
        update: function () {
            notices.forEach(function (notice, key) {
                var session = sessions.get(key);
                notice.firstChild.textContent = session.isBusy()
                    ? 'Your upload is continuing in the background.'
                    : session.shouldRetain() ? 'Your upload selection is saved. Open it to continue.' : 'Your upload has finished.';
            });
        }
    };

    window.addEventListener('beforeunload', function (event) {
        if (Array.from(sessions.values()).some(function (session) { return session.isBusy(); })) {
            event.preventDefault();
            event.returnValue = '';
        }
    });
})();
