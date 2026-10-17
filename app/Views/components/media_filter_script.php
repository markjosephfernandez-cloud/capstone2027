<script>
document.addEventListener('DOMContentLoaded', function () {
        var typeSelect = document.getElementById('mediaType');
        var typeDropdown = document.getElementById('mediaTypeDropdown');
        var typeToggle = document.getElementById('mediaTypeToggle');
        var applyFileType = function () {
            if (typeSelect?.form) typeSelect.form.requestSubmit();
        };
        if (typeSelect) typeSelect.addEventListener('change', applyFileType);
        if (typeSelect && typeDropdown && window.bootstrap?.Dropdown) {
            var typeLabel = document.getElementById('mediaTypeLabel');
            var typeOptions = typeDropdown.querySelectorAll('[data-media-type]');
            typeSelect.hidden = true;
            typeDropdown.hidden = false;
            typeOptions.forEach(function (option) {
                option.addEventListener('click', function () {
                    typeSelect.value = option.dataset.mediaType;
                    typeLabel.textContent = option.querySelector('span').textContent;
                    typeToggle.setAttribute('aria-label', 'File type: ' + typeLabel.textContent);
                    typeOptions.forEach(function (item) { item.setAttribute('aria-checked', String(item === option)); });
                    bootstrap.Dropdown.getOrCreateInstance(typeToggle).hide();
                    typeToggle.focus();
                    applyFileType();
                });
            });
        }
        window.GreyhoundMediaSearch?.init();
});
</script>
