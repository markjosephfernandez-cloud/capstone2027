(function () {
    'use strict';
    function init() {
        if (window.greyhoundServerLibraryCleanup) window.greyhoundServerLibraryCleanup();
        var panel = document.getElementById('serverStorageStatus');
        if (!panel) return;
        var connection = document.getElementById('serverConnection');
        var access = document.getElementById('serverAccess');
        var help = document.getElementById('serverConnectionHelp');
        var refresh = document.getElementById('serverRefreshConnection');
        var newButton = document.getElementById('mediaNewButton');
        var controller, timer, disposed = false;
        var connected = panel.dataset.connected === '1';
        var writable = panel.dataset.writable === '1';
        var workspace = document.getElementById('main');
        var catalogMode = workspace && workspace.dataset && workspace.dataset.catalog === '1';
        var verified = !catalogMode;
        function badge(node, text, kind) {
            node.textContent = text;
            node.className = 'badge text-bg-' + kind;
        }
        function cleanup() {
            disposed = true;
            window.clearTimeout(timer);
            if (controller) controller.abort();
            document.removeEventListener('submit', guardWrite, true);
            document.removeEventListener('click', guardPicker, true);
            window.removeEventListener('pagehide', cleanup);
            if (window.greyhoundServerLibraryCleanup === cleanup) window.greyhoundServerLibraryCleanup = null;
        }
        function explainWrite() {
            help.textContent = connected
                ? 'Writing is unavailable. Connect with a server account that has read/write access, then refresh the connection.'
                : 'Connect the server share, then refresh the connection.';
            var notice = document.getElementById('serverWriteError');
            if (notice) { notice.textContent = help.textContent; notice.hidden = false; }
        }
        function guardPicker(event) {
            if (verified && !writable && event.target.closest('[data-upload-picker], [data-bs-target="#createFolderModal"], [data-bs-target="#renameFolderModal"], [data-bs-target="#manualTaggingModal"]')) {
                event.preventDefault(); event.stopImmediatePropagation(); explainWrite();
            }
        }
        function guardWrite(event) {
            var form = event.target;
            var submitter = event.submitter;
            var target = (submitter && submitter.getAttribute('formaction')) || form.action || '';
            var path = new URL(target, window.location.href).pathname;
            if (verified && !writable && /\/server-library\/(?:upload|tags|bulk-trash|edit|folders\/|items\/[^/]+\/trash|duplicates\/restore)/.test(path)) {
                event.preventDefault(); event.stopImmediatePropagation(); explainWrite();
            }
        }
        async function check(manual) {
            if (disposed || !panel.isConnected || controller) return;
            window.clearTimeout(timer);
            var workspace = document.getElementById('main');
            if (!catalogMode && !manual && workspace && workspace.dataset && workspace.dataset.searchPending === '1') {
                // Each search response already checks the share. Give the scan
                // priority instead of queueing another read behind it.
                timer = window.setTimeout(check, 15000);
                return;
            }
            controller = new AbortController();
            var current = controller;
            var timeout = window.setTimeout(function () { current.abort(); }, 8000);
            refresh.disabled = true;
            badge(connection, 'Checking…', 'secondary');
            connection.setAttribute('aria-label', 'Server connection: Checking');
            try {
                var response = await fetch(panel.dataset.statusUrl, {credentials:'same-origin', cache:'no-store', signal:current.signal, headers:{Accept:'application/json'}});
                if (!response.ok || response.redirected) throw new Error('Connection could not be checked');
                var data = await response.json();
                if (typeof data.connected !== 'boolean' || typeof data.readable !== 'boolean' || typeof data.writable !== 'boolean') throw new Error('Invalid connection status');
                if (disposed || !panel.isConnected) return;
                var reconnected = !connected && data.connected;
                verified = true;
                connected = data.connected;
                writable = data.connected && data.readable && data.writable;
                panel.dataset.connected = connected ? '1' : '0';
                panel.dataset.writable = writable ? '1' : '0';
                badge(connection, connected ? 'Connected' : 'Disconnected', connected ? 'success' : 'danger');
                connection.setAttribute('aria-label', 'Server connection: ' + connection.textContent);
                badge(access, 'Access: ' + (!connected || !data.readable ? 'Unavailable' : writable ? 'Read & write' : 'Read only'), writable ? 'success' : 'secondary');
                help.textContent = !connected ? 'Connect the server share, then refresh the connection.'
                    : !data.readable ? 'The server is connected, but this folder cannot be read.'
                    : !writable ? 'The server share does not currently allow writing in this folder.'
                    : !data.can_upload && !data.can_manage ? 'The share allows read/write. Your application account has read-only access.'
                    : 'Files are read from and saved to the server share.';
                if (newButton) newButton.disabled = !data.can_upload;
                if (reconnected && !catalogMode) {
                    if (window.GreyhoundAjax) window.GreyhoundAjax.refresh({preserveScroll:true, ifIdle:true}).catch(function () {});
                    else window.location.reload();
                }
            } catch (error) {
                if (disposed || !panel.isConnected) return;
                // A timed-out status request does not prove the share is offline.
                // Keep catalog actions available until access is actually verified.
                if (!catalogMode) {
                    verified = true;
                    writable = false;
                }
                badge(connection, 'Unable to check', 'warning');
                connection.setAttribute('aria-label', 'Server connection: Unable to check');
                badge(access, 'Access: Unverified', 'secondary');
                help.textContent = 'The connection check did not finish. Refresh the connection to try again.';
                if (newButton && !catalogMode) newButton.disabled = true;
            } finally {
                window.clearTimeout(timeout);
                controller = null;
                if (!disposed && panel.isConnected) {
                    refresh.disabled = false;
                    if (!catalogMode) timer = window.setTimeout(check, 15000);
                }
            }
        }
        refresh.addEventListener('click', function () { check(true); });
        document.addEventListener('submit', guardWrite, true);
        document.addEventListener('click', guardPicker, true);
        window.addEventListener('pagehide', cleanup);
        window.greyhoundServerLibraryCleanup = cleanup;
        check();
    }
    document.addEventListener('DOMContentLoaded', init);
}());
