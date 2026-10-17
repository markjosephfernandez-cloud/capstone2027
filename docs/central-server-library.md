# Central Server Library

The `greyhound.centralLibrary` configuration activates server-only media storage.
The existing `greyhound.scanSourceRoot` remains the source root displayed as
**Root Library**; the migration does not wrap or rename existing NAS folders.

- Source media stays under that configured share.
- Department copies live under `DEPARTMENT/{department}/{selected name}`.
  Selecting `Event/Photos` copies `Photos` and its contents, without `Event`.
- Local media imported during cutover lives under `Imported media`.
- Existing file IDs, publication links, department IDs, review history, and
  filenames are retained. No new display-title behavior or multi-department
  sending was added.
- Thumbnails, video derivatives, logs, and static watermark-logo assets remain
  application assets/caches; they are not another media library.

## Database and jobs

`server_media` provides stable identities for source paths and bridges existing
`files` and `folder` records. `server_metadata` stores the existing server
metadata contract in the database. `server_recent` and `department_recent`
record each user's opened media without making copies.

Uploads save and register the original and its metadata in one operation, then
queue `server_analysis_jobs`. The worker performs iFound classification and
passport generation independently of the upload request. Low-confidence results
use `Unclassified Event`. Original job results are retained separately from
later manual corrections.

Image jobs use `greyhound-hub-2.0/convnext_event_classifier.pth` through
`greyhound.eventModelEndpoint = 'http://127.0.0.1:5001/predict'` in `.env`.
An unavailable or different model leaves the job failed and retryable rather
than completing it with a filename keyword guess. Uploads remain saved. After
three failed attempts, use `--retry-failed` once the AI service is available.

On this Mac, install the ConvNeXt service alongside the analysis worker:

```sh
cp deploy/com.greyhound.convnext-ai.plist ~/Library/LaunchAgents/
launchctl bootstrap "gui/$(id -u)" ~/Library/LaunchAgents/com.greyhound.convnext-ai.plist
curl --fail http://127.0.0.1:5001/health
```

The service starts at login and restarts if it exits. Its log is
`writable/logs/convnext-ai.log`. Stop any manually started AI process before
installing it. Adapt the Python and workspace paths in the plist on another
installation. The existing worker checks queued uploads every minute; completed
results appear in File Management metadata and iFound. Scores below 80% remain
unclassified for review. This checkpoint classifies images only.

Run a batch and inspect or retry the queue:

```sh
php spark server-library:analyze
php spark server-library:analyze --status
php spark server-library:analyze --retry-failed
```

`deploy/com.greyhound.server-library-analysis.plist` schedules a batch every
minute on this Mac. Adapt its PHP and workspace paths for another installation.
The existing catalog indexer continues to index the source share and excludes
`DEPARTMENT` from source browsing. Successful writes update the catalog directly.

## Distribution

A pending delivery is copied and verified on the server before its assignment is
recorded. The department reviews that copy. Completion authorizes the existing
copy; it does not copy it again. Department metadata and storage remain independent
of later source changes or deletion.

An incomplete transaction requires feedback. Resubmitting corrections preserves
previous pending copies under the department's hidden `.delivery-history`
directory and records a new cycle. Previous feedback/actions remain in the
database. Other destination-name conflicts are rejected without overwriting data.
A failed request removes newly created copies and restores previous pending copies.

## Migration and recovery

Stop web writes during cutover. Back up the database, then use CodeIgniter
migrations:

```sh
php spark migrate
php spark server-library:migrate-storage
php spark server-library:migrate-storage --apply
```

The migration checks missing paths and conflicts before copying, verifies file
contents with SHA-256, preserves record IDs, and records old/new paths in
`central_storage_migration`. It also imports unreferenced media originals while
leaving application logo assets in place. Originals are never deleted.
Matching destination files can be reused when resuming. SMB shares that cannot
publish a hard link use an exclusive destination stream instead.

For this installation the owner explicitly excluded 265 missing duplicate-queue
entries. The applied command includes `--exclude-missing-duplicates`; those
records remain unchanged and their missing files are not recreated.

Do not set `greyhound.centralLibrary = true` until the migration and verification
succeed. Retain the local files as recovery copies, not as an active library.
Do not delete them merely because the migration command completed. The retained
database backup and migration log are under `writable/backups/`.

For rollback, stop writes and restore the pre-cutover database backup together
with the former configuration. Keep the server files and any post-cutover uploads
until they have been reconciled; do not remove them during rollback. The database
backup excludes the rebuildable catalog index and queue.

## Cutover result — 2026-10-01

Centralized storage is enabled. The completed migration checked 687 references,
verified 422, excluded the owner's 265 missing duplicate entries, and reported
zero missing-file or destination conflicts. It verified about 1.83 GB across
referenced and unreferenced imports. Regular and department media have zero
remaining local database paths. Local originals and the database/configuration
backups are retained. The web server was not restarted.

The SMB server returned `Resource busy` when deleting 271 generated migration
temporary files, including the first interrupted attempt. These hidden files
are not used by any media record. Their exact paths are recorded in
`writable/cache/central-migration-cleanup.json`. The analysis worker retries at
most 25 per run, rejects original filenames and paths outside the migrated
areas, and records progress. Cleanup still depends on the server releasing the
locks. No force-unmount or deletion of originals was attempted.

Validation includes migration repeatability and ID retention, direct upload and
AI queuing, stable identities through rename/Trash/filename reuse, selected-root
distribution, rollback and resubmission, empty folders, independent department
copies, per-user Recent, CSRF behavior, and rendering without the Local Library
tab. The full suite retains the six failures present before this change.
