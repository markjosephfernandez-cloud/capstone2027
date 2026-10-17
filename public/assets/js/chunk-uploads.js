(function (window) {
    'use strict';

    function checkCancelled(signal) {
        if (signal && signal.aborted) {
            var error = new Error('Upload cancelled.');
            error.name = 'AbortError';
            throw error;
        }
    }

    function retryDelay(milliseconds, signal) {
        checkCancelled(signal);
        return new Promise(function (resolve, reject) {
            var onAbort = function () {
                window.clearTimeout(timer);
                try { checkCancelled(signal); } catch (error) { reject(error); }
            };
            var timer = window.setTimeout(function () {
                if (signal) signal.removeEventListener('abort', onAbort);
                resolve();
            }, milliseconds);
            if (signal) signal.addEventListener('abort', onAbort, { once: true });
        });
    }

    window.GreyhoundChunkUploads = {
        send: async function (file, options) {
            checkCancelled(options.signal);
            var chunkBytes = options.chunkBytes;
            if (!Number.isSafeInteger(chunkBytes) || chunkBytes < 1) throw new Error('Invalid upload part size.');
            if (!Number.isSafeInteger(file.size)) throw new Error('The browser cannot read this file size.');
            if (file.size <= chunkBytes) return options.sendPart(file, null);

            var state = options.state;
            var signature = JSON.stringify(options.metadata);
            if (!state.id || state.signature !== signature) {
                state.id = Array.from(window.crypto.getRandomValues(new Uint8Array(16)), function (byte) { return byte.toString(16).padStart(2, '0'); }).join('');
                state.offset = 0;
                state.signature = signature;
            }
            while (state.offset < file.size) {
                checkCancelled(options.signal);
                var end = Math.min(state.offset + chunkBytes, file.size);
                var part = file.slice(state.offset, end);
                var details = { chunk_upload: '1', chunk_id: state.id, file_name: file.name, file_size: String(file.size), chunk_offset: String(state.offset) };
                var result;
                for (var attempt = 0; attempt < 3; attempt++) {
                    try {
                        checkCancelled(options.signal);
                        result = await options.sendPart(part, details);
                        break;
                    } catch (error) {
                        checkCancelled(options.signal);
                        if (error.name === 'AbortError') throw error;
                        if (attempt === 2) throw error;
                        await retryDelay(300 * (attempt + 1), options.signal);
                    }
                }
                if (!result.response.ok || !result.payload.ok || result.payload.complete === true) return result;
                if (result.payload.nextOffset !== end || end === file.size) throw new Error('Upload progress could not be confirmed. Try this file again.');
                state.offset = end;
                checkCancelled(options.signal);
                if (options.onProgress) options.onProgress(end, file.size);
            }
            throw new Error('The file upload did not finish.');
        }
    };
})(window);
