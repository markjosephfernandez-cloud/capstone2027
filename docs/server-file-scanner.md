# iFound Server File Scanner: Setup, Restrictions, and Editing Guide

This document describes the **Scan Files** feature on **Workspace → iFound AI**. It documents the code as currently implemented, including configuration, access restrictions, file validation, duplicate handling, import behavior, and safe ways to change it.

## 1. What the scanner does

The scanner imports files that already exist in a server directory or mounted network share. It does not upload them through the browser.

When an authorized user clicks **Scan Files**, the application:

1. Opens the directory configured by `greyhound.scanSourceRoot`.
2. Searches it and its subdirectories breadth-first.
3. Ignores hidden/system paths, links, unreadable files, unsupported MIME types, oversized files, and content already in the active library.
4. Selects up to `greyhound.scanBatchSize` eligible files.
5. Creates a scan job owned by the employee who started it.
6. Copies one file per browser request into managed storage.
7. Preserves the source subfolder structure beneath `greyhound.scanDestination`.
8. Creates the corresponding File Management folder and database record.
9. Calculates a SHA-256 content hash and, for supported images, an image-similarity fingerprint.
10. Runs iFound event classification.
11. Records scan activity in the audit log.

The original file is **copied, not moved or deleted**. If database registration or iFound processing fails, the newly created destination copy is removed and the database transaction is rolled back. The source remains untouched.

## 2. Current project configuration

Scanner settings belong in the project `.env` file. This installation currently uses:

```ini
greyhound.storageRoot = '/Applications/XAMPP/xamppfiles/htdocs/greyhound-admin-2025-final_greyhound_admin_2025/writable/uploads'
greyhound.scanSourceRoot = '/Volumes/creative'
greyhound.scannerEndpoint = 'afp://10.99.10.40/creative'
greyhound.scanDestination = 'iFound Scanned Files'
greyhound.maxScanFiles = 10000
greyhound.scanBatchSize = 10
greyhound.maxUploadBytes = 0
```

`scannerEndpoint` is only an informational label displayed in the interface. PHP does **not** connect to the AFP address. macOS must first mount that share at `/Volumes/creative`, which is the directory the scanner actually reads.

Do not commit `.env`; use `env.example` as the shareable template.

## 3. Setup instructions

### Local directory

1. Create a source inbox outside the managed destination when possible. For example:

   ```text
   /Users/Shared/greyhound-scan-inbox
   ```

2. Ensure the account running Apache/PHP can read the inbox and traverse its parent directories.
3. Set `.env`:

   ```ini
   greyhound.scanSourceRoot = '/Users/Shared/greyhound-scan-inbox'
   greyhound.scannerEndpoint = ''
   greyhound.scanDestination = 'iFound Scanned Files'
   greyhound.maxScanFiles = 10000
   greyhound.scanBatchSize = 10
   greyhound.maxUploadBytes = 0
   ```

4. Restart Apache or the `php spark serve` process after changing `.env` so no cached configuration remains.
5. Sign in as Super Administrator, Administrator, or Creative Staff.
6. Open **Workspace → iFound AI**, confirm the displayed inbox and destination, then click **Scan Files**.

For a local inbox, the scanner can create a missing directory with mode `0775`. A missing external inbox (a configured endpoint, `/Volumes/` path, or UNC path) is rejected rather than replaced with an empty local directory. Mount the share and grant PHP read access before scanning. The separate [Server Library browser](server-library.md) never creates its source directory.

### macOS AFP/network share

1. In Finder choose **Go → Connect to Server**.
2. Connect to the AFP address, for example `afp://10.99.10.40/creative`.
3. Verify the resulting local mount exists, for example:

   ```bash
   ls -la /Volumes/creative
   ```

4. Configure the local mounted path—not the AFP URL—as `scanSourceRoot`:

   ```ini
   greyhound.scanSourceRoot = '/Volumes/creative'
   greyhound.scannerEndpoint = 'afp://10.99.10.40/creative'
   ```

5. Give the XAMPP/Apache or Terminal process access to network/removable volumes in **System Settings → Privacy & Security** if macOS denies access.

The mount must be available whenever a scan runs. Mounting the share in one user session does not guarantee that the Apache process can read it.

### Windows or Windows network share

Use a local directory or a network share mounted as a drive/path that the PHP service account can read:

```ini
greyhound.scanSourceRoot = 'C:/media/greyhound-scan-inbox'
```

For a mapped network drive, remember that a drive mapped for an interactive user may not be visible to Apache running as a service. Run Apache under an account with share permission or use a service-visible mounted path.

## 4. Configuration reference

| Setting | Default | Enforced behavior |
| --- | --- | --- |
| `greyhound.scanSourceRoot` | `writable/scan-inbox` | Physical local/mounted directory searched recursively. Trailing slashes are removed. |
| `greyhound.scannerEndpoint` | empty | Display-only origin label; it is not opened by the scanner. |
| `greyhound.scanDestination` | `iFound Scanned Files` | Relative folder created beneath `greyhound.storageRoot`. It must pass safe relative-path validation. |
| `greyhound.scanBatchSize` | `10` | Eligible files imported per scan action. Values are clamped to `1–1000`. |
| `greyhound.maxScanFiles` | `10000` | Maximum ordinary files inspected while searching for the next batch. Values are clamped to `1–50000`. |
| `greyhound.maxUploadBytes` | `0` | Optional maximum bytes per file. Zero means no application size limit; positive values enforce a limit. |
| `greyhound.storageRoot` | `writable/uploads` | Managed destination root. Relative values resolve from the project root. |

`maxScanFiles` is an inspection safety ceiling, not the number imported. For example, a scan can inspect as many as 10,000 ordinary files to find a batch of 10 eligible new files. If it cannot find a batch within the ceiling, narrow the source or raise the ceiling within its 50,000 hard limit.

## 5. File-type restrictions

The scanner determines type from file content with PHP `mime_content_type()`. Renaming an unsupported file to `.jpg` does not make it valid.

Allowed MIME types are:

| Category | Allowed MIME types |
| --- | --- |
| Images | `image/jpeg`, `image/png`, `image/gif`, `image/webp` |
| Video | `video/mp4`, `video/quicktime`, `video/x-msvideo`, `video/x-matroska`, `video/webm`, `video/x-m4v` |
| Audio | `audio/mpeg`, `audio/wav`, `audio/x-wav`, `audio/ogg` |
| Documents | `application/pdf`, `application/msword`, `application/vnd.openxmlformats-officedocument.wordprocessingml.document`, `application/vnd.ms-excel`, `application/vnd.openxmlformats-officedocument.spreadsheetml.sheet` |

Unsupported and oversized files are silently left in the source inbox and are not included in the scan job counters.

## 6. Access and security restrictions

- A signed-in user and a valid positive `employee_id` are required.
- The user must have `files.manage`. Current permitted roles are `super_admin`, `admin`, and `staff` (Creative Staff). Writer and Department roles cannot open or run the scanner.
- Start and step endpoints are POST requests protected by CodeIgniter CSRF validation. The UI refreshes the CSRF token when necessary.
- Each job receives a cryptographically random 32-character hexadecimal ID.
- A job is bound to the employee who started it; another employee cannot continue it.
- Clients never provide source paths. Job files contain only server-discovered relative paths.
- Symbolic links are skipped.
- Every resolved item must remain inside the configured source root, preventing traversal outside the inbox.
- Destination paths must be relative. `.` and `..`, null bytes, absolute paths, empty segments, and invalid folder names are rejected.
- Folder segments must start with a Unicode letter or number, may contain letters, numbers, spaces, commas, periods, underscores, parentheses, and hyphens, and may be at most 120 characters.
- If the source and managed storage roots are the same, the configured destination subtree is ignored to prevent the scanner from scanning its own copies.
- Scan responses disable browser/proxy caching.
- Only the first 10 processing errors are retained in job state, and the UI shows the most recent three.

Scan job state is stored in:

```text
writable/cache/ifound-scans/<job-id>.json
```

These files contain the initiating employee ID, source root, relative file list, counters, limited errors, and timestamps. They should remain runtime-only and must not be publicly served.

## 7. Files and paths that are ignored

The discovery phase skips:

- any file or directory path segment beginning with `.`;
- `__MACOSX` directories;
- `Thumbs.db` and `desktop.ini` (case-insensitive);
- symbolic links;
- paths whose resolved location is outside the source root;
- non-files and unreadable files;
- unsupported MIME types;
- files larger than `maxUploadBytes`, when a positive limit is configured;
- active-library duplicates identified by SHA-256; and
- the destination subtree when source and storage are the same directory.

Deleted/trash database records do not prevent re-import: duplicate checks only compare files where `is_deleted = 0`.

## 8. Duplicate and filename behavior

Exact duplicates are detected using SHA-256 values stored in `content_hash` or `filehash`. The scan checks hashes during discovery and checks again immediately before copying, which protects against concurrent imports.

If two different files have the same filename, managed storage does not overwrite the first. `StorageService` generates an available filename. The database display title is based on the final stored filename without its extension, limited to 150 characters.

Source subfolders are reproduced under the destination. For example:

```text
Source:      /Volumes/creative/Graduation/2026/IMG_001.jpg
Destination: <storageRoot>/iFound Scanned Files/Graduation/2026/IMG_001.jpg
```

## 9. Imported database values

Each successful copy creates a `files` record with, among other values:

- uploader/employee set to the user who started the scan;
- `description` set to `Copied from the Server Scan Inbox by iFound.`;
- detected MIME type, extension, byte size, and media kind;
- source modification time as `datecreated`;
- current time as `dateupload`;
- `downloadable = 1`;
- `release_status = draft`;
- `storage_stage = raw`;
- `new_scanned = 1`;
- the scan job ID in `scan_id`;
- SHA-256 in both hash columns; and
- `last_action = uploaded`.

The classifier is then called through `eventDetection->classifyAndStore()` before the database transaction commits.

## 10. How to edit the scanner

### Change only paths or operational limits

Edit `.env`; do not change PHP source code:

```ini
greyhound.scanSourceRoot = '/new/mounted/inbox'
greyhound.scanDestination = 'Imported Server Media'
greyhound.scanBatchSize = 25
greyhound.maxScanFiles = 20000
greyhound.maxUploadBytes = 209715200
```

The destination is a relative folder under `storageRoot`, not an absolute path. Keep the source and destination separate when possible.

### Add or remove an allowed type

Edit `allowedMimeTypes()` in `app/Config/Storage.php`. Add the server-detected MIME value, not only an extension. The same allowlist is shared by browser uploads and server-side scanned copies, so a change affects both features.

Example:

```php
public function allowedMimeTypes(): array
{
    return [
        // existing entries...
        'image/tiff',
    ];
}
```

Confirm the MIME reported by the host before adding it:

```bash
php -r "echo mime_content_type('/path/to/example.tif'), PHP_EOL;"
```

Only allow formats the application can safely preview/process. Adding a MIME type does not automatically add thumbnailing, watermarking, image fingerprinting, or AI-model support.

### Change hard safety bounds

The configurable clamps are in `app/Config/Storage.php`:

```php
return max(1, min(50000, ...)); // maxScanFiles
return max(1, min(1000, ...));  // scanBatchSize
```

Raise these carefully. Large values increase request duration, filesystem work, hashing, database access, and memory used by the scan-job file.

### Change ignored filenames/directories

Edit `shouldIgnoreSourcePath()` in `app/Services/ServerScanService.php`. Add case-insensitive system names to its list or add a narrowly scoped rule. Keep hidden paths ignored unless there is a specific reviewed requirement.

### Change role access

Scanner endpoints check the `files.manage` permission. Edit `app/Libraries/RolePolicy.php` only if the application’s wider file-management policy should change. Adding `files.manage` grants more than scanning, so create a dedicated permission such as `files.scan` if scanner-only access is required.

### Change import metadata or processing

Edit `importFile()` in `app/Services/ServerScanService.php`. This controls the `files` database values and the iFound classification call. Keep file copy, database transaction, rollback, and destination cleanup consistent so partial failures do not leave unregistered files.

### Change the interface or request flow

- UI and progress JavaScript: `app/Views/workspace/ifound.php`
- controller authorization and JSON responses: `app/Controllers/Web/WorkspaceController.php`
- scan routes and CSRF filters: `app/Config/Routes.php`
- service registration: `app/Config/Services.php`

The current UI starts one job and repeatedly calls the step endpoint until `complete` is true. One file is processed per step request, reducing the risk of one long web request timing out.

## 11. Verification after editing

Run the focused tests:

```bash
php vendor/bin/phpunit tests/unit/ServerScanFeatureTest.php tests/unit/StorageConfigPathTest.php
```

Then perform a manual test with a temporary inbox containing:

1. one supported file;
2. one unsupported file;
3. one oversized file if practical;
4. a copy of content already in File Management;
5. a nested supported file; and
6. a hidden/system path.

Confirm that only eligible new content is copied, the nested folder is preserved, the original remains, progress completes, classification appears, and audit records are created.

## 12. Troubleshooting

### “Server Scan Inbox is not mounted or unavailable”

The configured directory does not resolve. Mount the network share, correct `scanSourceRoot`, and restart the PHP/Apache process.

### “Server Scan Inbox cannot be read”

The PHP process lacks permission. Check filesystem/share permissions and macOS Privacy & Security access for XAMPP/Apache or Terminal.

### “No new scan batch was found within the first … source files”

The inspection ceiling was reached before enough eligible new files were found. Remove/archive irrelevant source files, narrow the source root, or increase `maxScanFiles` up to 50,000.

### Scan reports zero files

All discovered files may be unsupported, oversized, unreadable, ignored system files, symlinks, or SHA-256 duplicates of active library files.

### A file remains in the inbox after import

This is expected. The scanner is copy-only. The source inbox must be curated or archived separately; repeated scans skip already-imported content by hash.

### A scan stops midway

Click **Try Again** to start a new scan. Already committed files are skipped by hash. The source files remain safe. Review `writable/logs/` for the server-side exception and `writable/cache/ifound-scans/` for job counters.

## 13. Implementation map

| Responsibility | File |
| --- | --- |
| Environment defaults/example | `env.example` |
| Paths, limits, MIME allowlist | `app/Config/Storage.php` |
| Discovery, job state, duplicate checks, imports | `app/Services/ServerScanService.php` |
| Safe copying, filename/path validation | `app/Services/StorageService.php` |
| Routes and CSRF requirement | `app/Config/Routes.php` |
| Authentication, permission checks, audit calls | `app/Controllers/Web/WorkspaceController.php` |
| Role permissions | `app/Libraries/RolePolicy.php` |
| Scan UI and progress loop | `app/Views/workspace/ifound.php` |
| Focused tests | `tests/unit/ServerScanFeatureTest.php`, `tests/unit/StorageConfigPathTest.php` |

### Mini file manager selection (October 8, 2026)

Direct Send, iFound and Metadata Passports label bulk selection **Select all on
page** / **Unselect all on page** and provide **Clear selection**. Page selection
preserves selections made on other pages or in other folders. Continue is disabled
when nothing is selected; selection counts are announced to assistive technology.

The iFound and passport scan pickers no longer impose an image-selection cap or
a 200-folder expansion cap. Folder expansion deduplicates image paths and applies
a timeout per browse request, not to the entire expansion. Large previews show
only the first 100 thumbnails, but retain and process every selected image.
iFound submits bounded scan batches and checks AI progress in groups of at most
100 IDs. Passport scans continue to process one image per request. Keep the tab
open during scanning. These selection changes do not change Direct Send's
separate 500-file transmission limit or configured per-file upload limits.
