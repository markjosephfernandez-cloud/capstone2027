<script>
(function () {
    var tokenName = <?= json_encode(csrf_token()) ?>;
    var token = <?= json_encode(csrf_hash()) ?>;
    var queue = Promise.resolve();
    window.greyhoundRecordViewed = function (button) {
        if (!button) return;
        var source = button.dataset.originalPreviewSrc || button.dataset.previewSrc;
        if (!source) return;
        var url = new URL(source, location.href);
        if (url.origin !== location.origin || !/\/workspace\/(server-library\/items\/[^/]+|file-management\/\d+)\/(preview|video-preview)$/.test(url.pathname)) return;
        url.pathname = url.pathname.replace(/\/(preview|video-preview)$/, '/viewed');
        url.search = '';
        queue = queue.catch(function () {}).then(function () {
            var input = document.getElementsByName(tokenName)[0];
            var body = new FormData();
            body.append(tokenName, input ? input.value : token);
            return fetch(url.href, {method:'POST',body:body,credentials:'same-origin',headers:{'X-Requested-With':'XMLHttpRequest'}})
                .then(function (response) { return response.ok ? response.json() : null; })
                .then(function (data) {
                    if (!data || !data.csrfToken) return;
                    token = data.csrfToken;
                    document.getElementsByName(tokenName).forEach(function (input) { input.value = token; });
                });
        }).catch(function () {});
    };
})();
</script>
