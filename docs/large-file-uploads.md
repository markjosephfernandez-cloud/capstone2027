# Large files in File Management

`greyhound.maxUploadBytes = 0` removes the application per-file size limit.
A positive value remains available for deployments that need a cap. This
storage setting also applies to API storage and iFound copies.

Start the local PHP server with `scripts/serve-local.sh`; it sets
`upload_max_filesize=0` and `post_max_size=0` before PHP parses requests,
removing both multipart upload size caps. Restart an existing local server
to apply these startup options. Apache/XAMPP receives the same PHP settings
from `public/.htaccess`, which also sets `LimitRequestBody 0` for this app.
These project overrides leave the machine-wide PHP configuration unchanged.

`Config\Services::incomingrequest()` also handles the unlimited POST setting
for non-multipart requests. The installed CodeIgniter version otherwise leaves
`php://input` as the body when `post_max_size=0`, breaking JSON API requests
such as mobile login. Multipart uploads retain their existing handling.

File Management sends large files in sequential multipart requests of at most
4 MiB. The upload-token endpoint adjusts this part size downward when PHP's
`upload_max_filesize` or `post_max_size` is lower. PHP configuration does not
need to allow the entire file in a single HTTP request. Direct API clients and
native multipart submissions still follow their server request limits.

The existing upload route authenticates and checks CSRF on each part. Partial
files live in `writable/upload-chunks`, outside public storage. Completion uses
the existing file-type checks, folder hierarchy, duplicate detection, audit,
and metadata processing. Parts are copied through streams rather than loading
the whole file into PHP memory.

The browser retries interrupted part requests and retains confirmed progress
while the page stays open. Completion receipts prevent processing a final part
twice when its response is lost. In-app navigation (including iFound and browser
Back/Forward) keeps the upload queue, destination, progress, and Cancel controls
alive in the same tab. Other pages show a background upload notice with a link
back to the upload. Completion refreshes the library only when it is still
visible and no other navigation is underway.

Closing or reloading the tab still interrupts transfers; the browser warns
while an upload is active. Confirming a reload starts a new selection.
Expired partial files and receipts are reclaimed after 24 hours during future
upload-token requests, up to 20 per request. Small lock files remain to keep
concurrent requests synchronized.

Cancel upload remains available while preparing or transferring a batch. It
aborts the active browser request and stops the remaining queue. A separate
authenticated, CSRF-protected cancellation request removes unfinished chunks
and records a cancellation marker so late parts cannot restart that upload.
Completed files remain saved; a file already being processed may finish. The
page reports that case and leaves remaining selections available to restart.

Available disk space, filesystem limits, and server/connection timeouts still
determine practical capacity. Proxy request-body limits must accommodate the
part size plus multipart overhead. Uploading and processing very large media
will still take time.

Validation includes a real 101 MiB assembly and storage test with a SHA-256
comparison, plus a simulated 3 GiB browser transfer and retry tests.
