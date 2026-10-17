<script>
document.addEventListener('DOMContentLoaded', function () {
        var uploadWorkspace = document.getElementById('uploadWorkspace');
        if (!uploadWorkspace) return;
        var uploadSession = window.GreyhoundUploadSession;
        if (uploadSession && uploadSession.restore(uploadWorkspace)) { return; }
        var uploadForm = uploadWorkspace.querySelector('#batchUploadForm');
        if (!uploadForm) { return; }

        var filesInput = uploadWorkspace.querySelector('#files');
        var folderInput = uploadWorkspace.querySelector('#folder_files');
        var uploadPanel = uploadWorkspace.querySelector('#uploadPanel');
        var reviewList = uploadWorkspace.querySelector('#uploadReviewList');
        var selectionSummary = uploadWorkspace.querySelector('#uploadSelectionSummary');
        var pickerButtons = Array.from(document.querySelectorAll('[data-upload-picker]')).filter(function (button) { var owner = button.closest('[data-library]'); return !owner || owner === uploadWorkspace; });
        var cancelButtons = uploadWorkspace.querySelectorAll('[data-upload-cancel]');
        var selectedFiles = [];
        var previews = window.GreyhoundUploadPreviews.create(reviewList);
        var releasePreview = function (id) { previews.remove(id); };
        var releaseAllPreviews = function () { previews.clear(); };
        var previewController = new AbortController();
        window.addEventListener('pagehide', function (event) {
            if (!event.persisted) { previews.destroy(); }
        }, { signal: previewController.signal });
        var nextSelectionId = 0;
        var uploading = false;
        var cancelRequested = false;
        var uploadController = null;
        var progress = uploadWorkspace.querySelector('#uploadProgress');
        var progressLabel = uploadWorkspace.querySelector('#uploadProgressLabel');
        var progressCount = uploadWorkspace.querySelector('#uploadProgressCount');
        var progressBar = uploadWorkspace.querySelector('#uploadProgressBar');
        var submitButton = uploadWorkspace.querySelector('#uploadSubmit');
        var uploadBatchInput = uploadWorkspace.querySelector('#uploadBatchId');
        var destinationInput = uploadForm.querySelector('#folder_id');
        var folderPicker = uploadWorkspace.querySelector('[data-upload-folder-picker]');
        var syncFolderPicker = function () {};
        var folderNavigator = null;
        if (folderPicker && !destinationInput.dataset.foldersUrl) {
            var folderSummary = folderPicker.querySelector('summary');
            var folderLabel = folderPicker.querySelector('[data-upload-folder-label]');
            var folderSearch = folderPicker.querySelector('input[type="search"]');
            var folderEmpty = folderPicker.querySelector('[role="status"]');
            var filterFolders = function () {
                var query = folderSearch.value.trim().toLocaleLowerCase();
                var matches = 0;
                Array.from(destinationInput.options).forEach(function (option) {
                    option.hidden = !option.textContent.toLocaleLowerCase().includes(query);
                    if (!option.hidden) matches++;
                });
                folderEmpty.hidden = matches > 0;
            };
            syncFolderPicker = function () {
                var selected = destinationInput.options[destinationInput.selectedIndex];
                folderLabel.textContent = selected ? selected.textContent : 'Root library';
                folderSummary.setAttribute('aria-disabled', destinationInput.disabled ? 'true' : 'false');
                if (destinationInput.disabled) folderPicker.open = false;
            };
            var closeFolderPicker = function () {
                folderPicker.open = false;
                folderSummary.focus();
            };
            folderSummary.addEventListener('click', function (event) {
                if (destinationInput.disabled) event.preventDefault();
            });
            folderPicker.addEventListener('toggle', function () {
                if (!folderPicker.open) return;
                if (destinationInput.disabled) { folderPicker.open = false; return; }
                folderSearch.value = '';
                filterFolders();
                destinationInput.scrollTop = 0;
                folderSearch.focus();
            });
            folderSearch.addEventListener('input', filterFolders);
            folderSearch.addEventListener('keydown', function (event) {
                if (event.key === 'Enter') event.preventDefault();
                if (event.key === 'ArrowDown') { event.preventDefault(); destinationInput.focus(); }
            });
            destinationInput.addEventListener('change', syncFolderPicker);
            destinationInput.addEventListener('click', function (event) {
                if (event.target.tagName === 'OPTION') closeFolderPicker();
            });
            destinationInput.addEventListener('keydown', function (event) {
                if (event.key === 'Enter') { event.preventDefault(); closeFolderPicker(); }
            });
            folderPicker.addEventListener('keydown', function (event) {
                if (event.key === 'Escape') { event.preventDefault(); event.stopPropagation(); closeFolderPicker(); }
            });
            folderPicker.addEventListener('focusout', function (event) {
                if (event.relatedTarget && !folderPicker.contains(event.relatedTarget)) folderPicker.open = false;
            });
            document.addEventListener('click', function (event) {
                if (!folderPicker.contains(event.target)) folderPicker.open = false;
            }, { signal: previewController.signal });
            syncFolderPicker();
        }
        if (folderPicker && destinationInput.dataset.foldersUrl) {
            folderNavigator = window.GreyhoundUploadFolders(uploadForm, folderPicker, destinationInput, previewController.signal, function (busy) {
                submitButton.disabled = busy || uploading || selectedFiles.length === 0;
            });
            syncFolderPicker = folderNavigator.sync;
        }
        var csrfInput = uploadForm.querySelector('input[name="<?= esc(csrf_token()) ?>"]');
        var csrfToken = csrfInput ? csrfInput.value : '';
        var maxUploadBytes = Number(uploadForm.dataset.maxUploadBytes) || 0;
        var uploadChunkBytes = 4 * 1024 * 1024;
        var formatSize = function (bytes) {
            if (bytes < 1024) { return bytes + ' B'; }
            if (bytes < 1048576) { return (bytes / 1024).toFixed(1) + ' KB'; }
            if (bytes < 1073741824) { return (bytes / 1048576).toFixed(1) + ' MB'; }
            return (bytes / 1073741824).toFixed(1) + ' GB';
        };
        var setUploading = function (busy) {
            uploading = busy;
            uploadForm.setAttribute('aria-busy', busy ? 'true' : 'false');
            pickerButtons.forEach(function (button) { button.disabled = busy; });
            cancelButtons.forEach(function (button) { button.disabled = busy && cancelRequested; });
            reviewList.querySelectorAll('button').forEach(function (button) { button.disabled = busy; });
            destinationInput.disabled = busy;
            syncFolderPicker();
            if (uploadSession) uploadSession.update();
            submitButton.disabled = busy || selectedFiles.length === 0;
        };
        var createReviewCard = function (item) {
            var row = document.createElement('li');
            row.className = 'upload-review-item';
            var preview = document.createElement('div');
            preview.className = 'upload-review-preview';
            var fileType = item.file.type || '';
            var isImage = fileType.indexOf('image/') === 0 || /\.(jpe?g|png|gif|webp|bmp|avif|heic|heif)$/i.test(item.file.name);
            var showFallback = function (unavailable) {
                var icon = document.createElement('i');
                icon.className = 'bi ' + (isImage ? 'bi-image' : fileType.indexOf('video/') === 0 ? 'bi-film' : fileType.indexOf('audio/') === 0 ? 'bi-music-note-beamed' : 'bi-file-earmark');
                icon.setAttribute('aria-hidden', 'true');
                var label = document.createElement('span');
                label.className = 'upload-review-fallback';
                label.textContent = unavailable ? 'Preview unavailable' : fileType.indexOf('video/') === 0 ? 'Video' : fileType.indexOf('audio/') === 0 ? 'Audio' : 'Document';
                preview.replaceChildren(icon, label);
            };
            if (isImage) {
                previews.add(item.id, item.file, preview, function () { showFallback(true); });
            } else { showFallback(false); }
            var name = document.createElement('span');
            name.className = 'upload-review-name';
            name.textContent = item.file.name;
            name.title = item.relativePath || item.file.name;
            var footer = document.createElement('div');
            footer.className = 'upload-review-footer';
            var size = document.createElement('span');
            size.className = 'upload-review-size';
            size.textContent = formatSize(item.file.size);
            var remove = document.createElement('button');
            remove.type = 'button';
            remove.className = 'btn btn-sm btn-light upload-review-remove';
            remove.dataset.removeUpload = String(item.id);
            remove.setAttribute('aria-label', 'Remove ' + (item.relativePath || item.file.name));
            remove.textContent = 'Remove';
            footer.append(size, remove);
            row.append(preview, name, footer);
            return row;
        };
        var updateSelection = function () {
            var scrollPosition = reviewList.scrollTop;
            reviewList.replaceChildren();
            var totalBytes = 0;
            var fragment = document.createDocumentFragment();
            selectedFiles.forEach(function (item) {
                totalBytes += item.file.size;
                // Reuse cards so removing a file does not reload every other thumbnail.
                if (!item.reviewCard) { item.reviewCard = createReviewCard(item); }
                fragment.appendChild(item.reviewCard);
            });
            reviewList.appendChild(fragment);
            reviewList.scrollTop = scrollPosition;
            selectionSummary.textContent = selectedFiles.length + ' file' + (selectedFiles.length === 1 ? '' : 's') + ' selected · ' + formatSize(totalBytes) + ' · Ready to upload';
            uploadPanel.classList.toggle('d-none', selectedFiles.length === 0);
            setUploading(uploading);
        };
        var clearSelection = function () {
            if (uploading) { return; }
            releaseAllPreviews();
            selectedFiles = [];
            filesInput.value = '';
            folderInput.value = '';
            uploadBatchInput.value = '';
            progress.classList.add('d-none');
            updateSelection();
            document.getElementById('mediaNewButton').focus();
        };
        var boundPickers = new WeakSet();
        var bindPickers = function () {
            if (uploadWorkspace.hidden) return;
            pickerButtons = Array.from(document.querySelectorAll('[data-upload-picker]')).filter(function (button) { var owner = button.closest('[data-library]'); return !owner || owner === uploadWorkspace; });
            pickerButtons.forEach(function (button) {
                button.disabled = uploading;
                if (boundPickers.has(button)) return;
                boundPickers.add(button);
                button.addEventListener('click', function () {
                    if (uploading || uploadWorkspace.hidden) { return; }
                    // Open synchronously from the click so the browser permits the picker.
                    (button.dataset.uploadPicker === 'folder' ? folderInput : filesInput).click();
                });
            });
        };
        bindPickers();
        if (uploadSession) uploadSession.register({
            root: uploadWorkspace,
            url: window.location.href,
            isBusy: function () { return uploading; },
            shouldRetain: function () { return uploading || selectedFiles.length > 0; },
            bindPickers: bindPickers,
            destroy: function () { previewController.abort(); previews.destroy(); }
        });
        [filesInput, folderInput].forEach(function (input) {
            input.addEventListener('change', function () {
                if (uploading || input.files.length === 0) { return; }
                Array.prototype.forEach.call(input.files, function (file) {
                    selectedFiles.push({ id: ++nextSelectionId, file: file, relativePath: input === folderInput ? (file.webkitRelativePath || file.name) : '' });
                });
                // Keep the review queue independent of the picker; adding or cancelling
                // another selection must not replace files already waiting to upload.
                input.value = '';
                progress.classList.add('d-none');
                updateSelection();
                uploadPanel.scrollIntoView({ block: 'nearest' });
                uploadWorkspace.querySelector('#uploadReviewTitle').focus({ preventScroll: true });
            });
        });
        cancelButtons.forEach(function (button) { button.addEventListener('click', function () {
            if (!uploading) { clearSelection(); return; }
            if (cancelRequested) return;
            cancelRequested = true;
            progressLabel.textContent = 'Cancelling upload…';
            progressBar.classList.remove('progress-bar-animated');
            setUploading(true);
            if (uploadController) uploadController.abort();
        }); });
        reviewList.addEventListener('click', function (event) {
            var button = event.target.closest('[data-remove-upload]');
            if (!button || uploading) { return; }
            var removedId = Number(button.dataset.removeUpload);
            var removedIndex = selectedFiles.findIndex(function (item) { return item.id === removedId; });
            releasePreview(removedId);
            selectedFiles = selectedFiles.filter(function (item) { return item.id !== removedId; });
            progress.classList.add('d-none');
            updateSelection();
            if (selectedFiles.length === 0) { document.getElementById('mediaNewButton').focus(); }
            else { reviewList.querySelectorAll('button')[Math.min(removedIndex, selectedFiles.length - 1)].focus({ preventScroll: true }); }
        });
        updateSelection();

        var refreshUploadToken = async function (signal) {
            var separator = uploadForm.dataset.tokenUrl.indexOf('?') === -1 ? '?' : '&';
            var response = await fetch(uploadForm.dataset.tokenUrl + separator + '_=' + Date.now(), {
                method: 'GET',
                signal: signal,
                credentials: 'same-origin',
                cache: 'no-store',
                headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
            });
            var payload = await response.json();
            if (!response.ok || !payload.ok || !payload.csrfToken) {
                throw new Error(payload.message || 'The upload security token could not be refreshed.');
            }
            csrfToken = payload.csrfToken;
            if (typeof payload.maxUploadBytes === 'number') { maxUploadBytes = payload.maxUploadBytes; }
            if (payload.chunkBytes > 0) { uploadChunkBytes = Number(payload.chunkBytes); }
            if (csrfInput) {
                if (payload.csrfTokenName) { csrfInput.name = payload.csrfTokenName; }
                csrfInput.value = csrfToken;
            }
        };

        uploadForm.addEventListener('submit', async function (event) {
            if (typeof folderNavigator !== 'undefined' && folderNavigator && folderNavigator.busy()) { event.preventDefault(); return; }
            event.preventDefault();
            if (uploading || selectedFiles.length === 0) { return; }
            var queuedFiles = selectedFiles.slice();
            cancelRequested = false;
            uploadController = new AbortController();
            setUploading(true);

            var destinationFolder = destinationInput.value;
            var uploadBatchId = uploadBatchInput.value || (window.crypto && typeof window.crypto.randomUUID === 'function'
                ? window.crypto.randomUUID().replace(/-/g, '')
                : Date.now().toString(36) + Math.random().toString(36).slice(2));
            uploadBatchInput.value = uploadBatchId;
            var completed = 0;
            var successful = 0;
            var errors = [];
            var activeItem = null;
            var finishCancellation = async function () {
                var uncertain = activeItem && (!activeItem.chunkState || !activeItem.chunkState.id);
                var cleanupFailed = false;
                var unfinished = selectedFiles.slice();
                for (var pending of unfinished) {
                    if (!pending.chunkState || !pending.chunkState.id) continue;
                    try {
                        var cleanupPayload;
                        for (var retry = 0; retry < 2; retry++) {
                            // Cancellation uses a fresh request, independent of the aborted transfer.
                            await refreshUploadToken();
                            var cancelData = new FormData();
                            cancelData.append('cancel_upload', '1');
                            cancelData.append('chunk_id', pending.chunkState.id);
                            if (csrfInput) cancelData.append(csrfInput.name, csrfToken);
                            var cleanupResponse = await fetch(uploadForm.action, {
                                method: 'POST', body: cancelData, credentials: 'same-origin',
                                headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
                            });
                            cleanupPayload = await cleanupResponse.json();
                            if (cleanupResponse.status === 403 && retry === 0) continue;
                            if (!cleanupResponse.ok || !cleanupPayload.ok) throw new Error('Cleanup failed');
                            break;
                        }
                        if (cleanupPayload.complete && cleanupPayload.result && cleanupPayload.result.ok) {
                            successful += cleanupPayload.result.uploaded || 1;
                            selectedFiles = selectedFiles.filter(function (item) { return item.id !== pending.id; });
                        }
                        if (cleanupPayload.processing) uncertain = true;
                    } catch (error) { cleanupFailed = true; }
                    pending.chunkState = {};
                }
                queuedFiles.forEach(function (item) { if (selectedFiles.indexOf(item) === -1) releasePreview(item.id); });
                uploadController = null;
                setUploading(false);
                updateSelection();
                uploadPanel.classList.remove('d-none');
                progress.classList.remove('d-none');
                progressBar.classList.remove('progress-bar-animated', 'bg-danger');
                progressCount.textContent = successful + ' confirmed uploaded · ' + selectedFiles.length + ' remaining';
                progressLabel.textContent = 'Upload cancelled. Completed files are kept.'
                    + (uncertain ? ' The last file may still finish saving; check the library before retrying it.' : '')
                    + (cleanupFailed ? ' Temporary upload cleanup could not be confirmed; expired parts will be cleared later.' : '');
                selectionSummary.textContent = 'Upload cancelled. You can start the remaining files again or clear the selection.';
            };
            progress.classList.remove('d-none');
            progressBar.classList.remove('bg-danger');
            progressBar.classList.add('progress-bar-animated');
            progressBar.style.width = '0%';
            submitButton.disabled = true;

            try {
                await refreshUploadToken(uploadController.signal);
            } catch (tokenError) {
                if (cancelRequested) { await finishCancellation(); return; }
                progressBar.classList.remove('progress-bar-animated');
                progressBar.classList.add('bg-danger');
                progressLabel.textContent = tokenError.message || 'The upload could not start securely.';
                progressCount.textContent = '0 uploaded';
                setUploading(false);
                return;
            }

            var sendPart = async function (item, part, details, mayRetry) {
                var formData = new FormData();
                formData.append('file', part, item.file.name);
                if (details) Object.keys(details).forEach(function (key) { formData.append(key, details[key]); });
                formData.append('folder_id', destinationFolder);
                formData.append('relative_path', item.relativePath);
                formData.append('upload_batch_id', uploadBatchId);
                item.auditTotal = item.auditTotal || queuedFiles.length;
                formData.append('audit_total', String(item.auditTotal));
                formData.append('audit_item_id', String(item.id));
                if (csrfInput) { formData.append(csrfInput.name, csrfToken); }

                var response = await fetch(uploadForm.action, {
                    method: 'POST',
                    body: formData,
                    signal: uploadController.signal,
                    credentials: 'same-origin',
                    headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
                });
                var payload = null;
                try { payload = await response.json(); } catch (parseError) {
                    if (cancelRequested) throw parseError;
                    payload = {};
                }

                if (response.status === 403 && mayRetry !== false) {
                    await refreshUploadToken(uploadController.signal);
                    return sendPart(item, part, details, false);
                }
                if (payload.csrfToken && csrfInput) {
                    csrfToken = payload.csrfToken;
                    csrfInput.value = csrfToken;
                }
                return { response: response, payload: payload };
            };

            var sendUpload = function (item) {
                item.chunkState = item.chunkState || {};
                return window.GreyhoundChunkUploads.send(item.file, {
                    chunkBytes: uploadChunkBytes,
                    signal: uploadController.signal,
                    state: item.chunkState,
                    metadata: [destinationFolder, item.relativePath, uploadBatchId],
                    sendPart: function (part, details) { return sendPart(item, part, details, true); },
                    onProgress: function (sent, total) {
                        progressLabel.textContent = 'Uploading ' + item.file.name + ' · ' + Math.round(sent / total * 100) + '%';
                        progressBar.style.width = Math.round((completed + sent / total) / queuedFiles.length * 100) + '%';
                    }
                });
            };

            for (var index = 0; index < queuedFiles.length; index += 1) {
                if (cancelRequested) break;
                var item = queuedFiles[index];
                activeItem = item;
                progressLabel.textContent = 'Uploading and processing ' + item.file.name;
                progressCount.textContent = (index + 1) + ' of ' + queuedFiles.length;
                progressBar.style.width = Math.round((index / queuedFiles.length) * 100) + '%';

                try {
                    if (maxUploadBytes > 0 && item.file.size > maxUploadBytes) {
                        var actualMb = (item.file.size / 1048576).toFixed(1);
                        var maximumMb = (maxUploadBytes / 1048576).toFixed(0);
                        throw new Error(item.file.name + ' is ' + actualMb + ' MB; the maximum is ' + maximumMb + ' MB.');
                    }
                    var result = await sendUpload(item, true);
                    var response = result.response;
                    var payload = result.payload;
                    if (response.ok && payload.ok) {
                        successful += payload.uploaded || 1;
                        selectedFiles = selectedFiles.filter(function (selected) { return selected.id !== item.id; });
                    } else {
                        errors.push((payload.errors && payload.errors[0]) || payload.message || item.file.name + ' could not be uploaded.');
                    }
                } catch (error) {
                    if (cancelRequested) break;
                    errors.push(error.message || item.file.name + ' could not be uploaded. Check your connection and try again.');
                }
                activeItem = null;
                completed += 1;
                progressBar.style.width = Math.round((completed / queuedFiles.length) * 100) + '%';
            }

            if (cancelRequested) { await finishCancellation(); return; }
            uploadController = null;
            cancelButtons.forEach(function (button) { button.disabled = true; });
            progressBar.classList.remove('progress-bar-animated');
            progressCount.textContent = successful + ' uploaded, ' + errors.length + ' skipped';
            progressLabel.textContent = errors.length === 0
                ? 'Upload complete. Updating your media library...'
                : 'Some files could not be uploaded. ' + errors.slice(0, 2).join(' ');
            if (errors.length === 0) {
                releaseAllPreviews();
                uploadBatchInput.value = '';
                setUploading(false);
                updateSelection();
                uploadPanel.classList.remove('d-none');
                selectionSummary.textContent = successful + ' file(s) uploaded successfully.';
                window.setTimeout(function () {
                    // Do not refresh iFound or discard a new selection made after completion.
                    if (!uploadWorkspace.isConnected || uploadWorkspace.hidden || uploading || selectedFiles.length > 0) return;
                    if (window.GreyhoundAjax) {
                        window.GreyhoundAjax.refresh({ preserveScroll: true, ifIdle: true }).catch(function () {});
                    } else {
                        window.location.assign(window.location.href);
                    }
                }, 900);
            } else {
                queuedFiles.forEach(function (item) { if (selectedFiles.indexOf(item) === -1) { releasePreview(item.id); } });
                setUploading(false);
                updateSelection();
                selectionSummary.textContent = selectedFiles.length + ' file(s) need attention. Remove them or try uploading again.';
                progressBar.classList.add('bg-danger');
            }
        });
});
</script>
