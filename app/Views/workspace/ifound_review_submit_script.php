<script>
(function () {
    var form = document.getElementById('ifoundReviewForm');
    var status = document.getElementById('ifoundReviewStatus');
    var button = document.getElementById('ifoundSaveReview');
    if (!form || !status || !button) return;
    var saving = false;
    async function send(url, options) {
        var controller = new AbortController();
        var timeout = setTimeout(function () { controller.abort(); }, 60000);
        try {
            return await fetch(url, Object.assign({credentials:'same-origin',cache:'no-store',signal:controller.signal,headers:{Accept:'application/json','X-Requested-With':'XMLHttpRequest'}},options));
        } finally { clearTimeout(timeout); }
    }
    form.addEventListener('submit', async function (event) {
        // The existing tag validator runs first; never bypass its errors.
        if (event.defaultPrevented) return;
        event.preventDefault();
        if (saving) return;
        saving = true; button.disabled = true; status.hidden = false;
        status.className = 'mx-3 alert alert-info'; status.textContent = 'Saving review…';
        var data = new FormData(form);
        try {
            var result;
            for (var attempt = 0; attempt < 2; attempt++) {
                var tokenResponse = await send(<?= json_encode(site_url('workspace/ifound/scan/token')) ?>);
                if (!tokenResponse.ok || tokenResponse.redirected) throw new Error('Your session may have expired. Sign in again, then retry.');
                var token = await tokenResponse.json();
                if (!token.ok || !token.csrfToken) throw new Error(token.message || 'Unable to refresh the security token.');
                var tokenName = token.csrfTokenName || <?= json_encode(csrf_token()) ?>;
                data.set(tokenName, token.csrfToken);
                var input = form.elements.namedItem(tokenName); if (input) input.value = token.csrfToken;
                var response = await send(form.action, {method:'POST',body:data});
                // A concurrent POST may rotate the cookie again; retry a rejected token once.
                if (response.status === 403 && attempt === 0) continue;
                if (response.redirected) throw new Error('Your session or access changed. Refresh and sign in again if needed.');
                if (!(response.headers.get('Content-Type') || '').includes('application/json')) throw new Error('The review was not confirmed. Your selections are preserved; please try again.');
                result = await response.json();
                if (!response.ok || !result.ok) throw new Error(result.message || 'The review could not be saved.');
                break;
            }
            if (!result || !result.ok) throw new Error('The security token changed. Please try saving again.');
            status.className = 'mx-3 alert alert-success'; status.textContent = result.message;
            var url = new URL(<?= json_encode(site_url('workspace/ifound')) ?>, window.location.href);
            var term = data.get('return_q'); if (term) url.searchParams.set('q',term);
            window.location.assign(url.toString());
        } catch (error) {
            status.className = 'mx-3 alert alert-danger';
            status.textContent = error.name === 'AbortError' ? 'Saving took too long to confirm. Your selections are preserved. Check results before retrying.' : error.message;
        } finally { saving = false; button.disabled = false; }
    });
})();
</script>
