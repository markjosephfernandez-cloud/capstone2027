# Storage Paths, Database Paths, and Deletion

Department deliveries now have independent database records and survive admin
deletion. See [Independent department library](independent-department-library.md)
for the required migration and backfill. Department delivery paths must not be
collected as deletable artifacts of an admin original.

This document describes the Greyhound media-storage correction completed on
August 25, 2026. It explains where uploaded files are physically stored, what is
recorded in the database, how paths remain portable across Windows and macOS,
and when physical files are deleted.

## Executive summary

The storage system now follows one rule:

> The filesystem uses the computer's real absolute path, while the database
> stores a portable path relative to the project.

For example, the same file has two representations:

```text
Physical Windows path:
C:\xampp\htdocs\greyhound-admin-2025-final_greyhound_admin_2025\writable\uploads\1DX21532.jpg

Database value:
writable/uploads/1DX21532.jpg
```

The physical file is located in the project-level `writable/uploads` directory,
not in `public/writable/uploads`. A permanent delete now removes the actual file
and its managed derivatives from the computer before deleting their database
records. Moving an item to Trash remains recoverable and does not remove the
physical file.

## Why this change was required

The previous behavior mixed three different concepts:

- a relative `.env` value such as `writable/uploads`;
- the web application's current directory, which can be `public/`; and
- an absolute Windows path saved directly in the database.

That caused two visible problems:

1. Some uploads were resolved under `public/writable/uploads` because a web
   request started from the `public/` directory.
2. Database rows stored values beginning with `C:\xampp\htdocs\...`, tying
   those records to one Windows computer.

An absolute Windows path cannot be reused after transferring the application to
a Mac because the drive letter and directory layout do not exist there. It also
exposes server-specific directory information in application data.

The correction separates the responsibilities: `.env` and `StorageService`
determine the physical directory for the current computer, while database
columns store stable values such as `writable/uploads/photo.jpg`.

## Required invariants

All current and future storage code must preserve these rules:

1. Relative storage configuration is resolved from CodeIgniter's `ROOTPATH`,
   never from the process working directory or `FCPATH`.
2. New database values use forward slashes and begin with either
   `writable/uploads` or `writable/Department`.
3. Filesystem operations use a resolved absolute path obtained from
   `StorageService`.
4. No client-supplied absolute path is trusted.
5. An existing target must be proven to be inside an allowed storage root
   before it is opened, renamed, moved, or deleted.
6. Moving to Trash keeps the file. Permanent deletion removes physical managed
   artifacts and their database records.

## Storage configuration

The active storage settings come from `.env`:

```ini
greyhound.storageRoot = 'writable/uploads'
greyhound.departmentStorageRoot = 'writable/Department'
```

Relative values are resolved from the project root, not from the `public/`
front-controller directory. With this repository on Windows, the normal upload
root therefore resolves to:

```text
C:\xampp\htdocs\greyhound-admin-2025-final_greyhound_admin_2025\writable\uploads
```

The application must not store managed uploads under `public/writable`. That
incorrect storage tree was removed during the correction.

The iFound scanner reads from `greyhound.scanSourceRoot`. When that setting is
not present, its source defaults to `writable/scan-inbox`. It copies accepted
files into `writable/uploads/iFound Scanned Files`; it does not remove the
source inbox files.

## Physical path versus database path

The physical path is the real location on the current computer. For example:

```text
C:\xampp\htdocs\greyhound-admin-2025-final_greyhound_admin_2025\writable\uploads\1DX21532.jpg
```

The database stores only a portable, project-relative path:

```text
writable/uploads/1DX21532.jpg
```

Forward slashes are intentional. PHP accepts them on Windows, and the same
database value also works on macOS and Linux. `StorageService` converts the
portable value into the correct configured physical path whenever it opens,
downloads, renames, moves, previews, watermarks, or deletes a file.

Common database values include:

| Stored item | Database value example |
| --- | --- |
| Root upload | `writable/uploads/photo.jpg` |
| Folder upload | `writable/uploads/Events/photo.jpg` |
| Duplicate queue copy | `writable/uploads/Duplicate Queue/photo.jpg` |
| iFound scan copy | `writable/uploads/iFound Scanned Files/photo.jpg` |
| Watermark derivative | `writable/uploads/Watermark/WM_123_photo.jpg` |
| Department delivery | `writable/Department/CCS/Events/photo.jpg` |

Portable paths are written for new web uploads, API uploads, duplicates,
scanner imports, watermarks, department deliveries, file renames, file moves,
and folder renames.

### Logical path mapping

| Database prefix | Physical root selected at runtime |
| --- | --- |
| `writable/uploads` | `greyhound.storageRoot` |
| `writable/Department` | `greyhound.departmentStorageRoot` |

## Important code changes

### Storage configuration: `app/Config/Storage.php`

`root()` and `departmentRoot()` use `configuredProjectStorageRoot()`. The method
accepts an absolute configured path when an administrator deliberately provides
one, but resolves every relative setting against `ROOTPATH`.

The essential implementation is:

```php
public function root(): string
{
    return $this->configuredProjectStorageRoot(
        'greyhound.storageRoot',
        'writable/uploads'
    );
}

private function configuredProjectStorageRoot(
    string $environmentKey,
    string $default
): string {
    $path = trim((string) env($environmentKey, $default));
    if ($path === '') {
        $path = $default;
    }

    if ($this->isAbsolutePath($path)) {
        return rtrim($path, "\\/");
    }

    return rtrim(ROOTPATH, "\\/") . DIRECTORY_SEPARATOR
        . str_replace('/', DIRECTORY_SEPARATOR, ltrim($path, '/'));
}
```

This removes dependence on whether Apache starts a request from `public/` or a
CLI command starts from the repository root.

### Path boundary: `app/Services/StorageService.php`

This service is the boundary between portable database values and physical
filesystem paths.

| Method | Responsibility |
| --- | --- |
| `root()` | Creates and resolves the main configured storage root |
| `departmentRoot()` | Creates and resolves the department storage root |
| `storeUpload()` | Validates and stores a browser/API upload |
| `storeServerCopy()` | Copies a server-side file into main storage |
| `storeDepartmentCopy()` | Copies a file into department storage |
| `databasePath()` | Converts a trusted physical path to a portable DB value |
| `resolveDatabaseFile()` | Converts a DB value to an existing managed file |
| `resolveDatabaseDirectory()` | Converts a DB value to a managed directory |
| `deleteDatabaseFile()` | Safely removes a DB-referenced physical file |
| `renameDatabaseFile()` | Resolves and renames a managed file |
| `moveDatabaseFile()` | Resolves and moves a managed file |

`databasePath()` uses these mappings:

```php
$roots = [
    ['root' => $this->root(), 'prefix' => 'writable/uploads'],
    ['root' => $this->departmentRoot(), 'prefix' => 'writable/Department'],
];
```

If the physical path is under one of those roots, the root is replaced with its
portable prefix and directory separators are converted to `/`. A path outside
managed storage is rejected.

`resolveDatabaseFile()` and `resolveDatabaseDirectory()` perform the reverse
conversion. They accept the portable format and can still inspect legacy
absolute values while old data is corrected. The final path is resolved with
`realpath()` and checked against the allowed roots. Merely having text that
starts with `writable/uploads` is not enough to pass the security check.

Store methods intentionally return an absolute path internally. The caller can
use it for hashing, metadata generation, and rollback. Before an insert or
update, the caller converts it with:

```php
$databasePath = service('storage')->databasePath($stored['filepath']);
```

### Upload controllers

`app/Controllers/Web/WorkspaceController.php` converts normal uploads and
Duplicate Queue copies with `databasePath()` before insertion. Folder rename
logic also converts the old and new physical directory prefixes to portable
database prefixes before updating affected rows.

`app/Controllers/Api/FileController.php` applies the same rule to API uploads,
duplicate records, file renames, and file moves. The normal API insert was also
given its missing `filepath` value. Absolute old and new paths remain local
variables only when a filesystem rollback may be required.

Representative insert code:

```php
'filepath' => service('storage')->databasePath($stored['filepath']),
```

### Scanner, watermark, and distribution services

`app/Services/ServerScanService.php` stores scanner imports as
`writable/uploads/<scan destination>/<filename>`. The absolute destination is
used for fingerprinting and rollback, while the external source stays intact.

`app/Services/WatermarkService.php` converts every persistent watermark output
before saving `file_watermarks.watermarked_path`. Temporary download/cache paths
are not database records and remain physical paths.

`app/Services/DistributionService.php` converts department delivery files and
directories before saving `delivery_filepath` or `delivery_path`. Its in-memory
rollback list remains absolute so a failed delivery can remove files created in
that operation.

### Permanent deletion: `app/Services/TrashService.php`

This service centralizes permanent file and folder removal:

| Method | Responsibility |
| --- | --- |
| `permanentlyRemoveMedia()` | Deletes artifacts, related rows, and records an audit event |
| `permanentlyRemoveFolder()` | Deletes a trashed tree's files, directories, and folder rows |
| `managedArtifactPaths()` | Collects every owned physical artifact reference |
| `deletePhysicalArtifacts()` | Routes each path through safe storage deletion |
| `deleteMediaDatabaseRows()` | Deletes dependent records transactionally |

The operation order is deliberate:

```php
$artifactPaths = $this->managedArtifactPaths($file);
$this->deletePhysicalArtifacts($artifactPaths);
$this->deleteMediaDatabaseRows($file);
```

The database cannot report successful permanent deletion while an existing
managed file could not be removed. Missing files are idempotent, so cleanup can
continue if a file is already absent. An existing file outside managed storage
is rejected rather than unlinked.

### Trash user-interface warnings

`app/Views/workspace/trash.php` and
`app/Views/workspace/trash_folder.php` warn that permanent deletion also removes
physical files. This distinguishes it from the recoverable Move to Trash action.

## Database fields covered

The path handling applies to these records when the corresponding table exists:

| Table | Column | Purpose |
| --- | --- | --- |
| `files` | `filepath` | Main managed media |
| `duplicate_media` | `filepath` | Duplicate Queue copies |
| `file_watermarks` | `watermarked_path` | Persistent watermark derivatives |
| `media_assignments` | `delivery_filepath` | Department delivery copies |
| `media_assignment_folders` | `delivery_path` | Department delivery directories |
| `tag` | `filepath` | Legacy tag-to-file reference |
| `recent` | `filepath` | Legacy recent-item reference |

Application responses may expose a safe storage-relative value, but they must
not expose or depend on a Windows drive letter.

## Upload behavior

For a normal upload named `photo.jpg`:

1. The physical file is written under the configured upload root.
2. The service verifies that the resulting file remains inside managed storage.
3. The database receives `writable/uploads/photo.jpg`.
4. Optional processing creates event metadata, a passport, and a portable
   watermark path.

If a selected system folder is `Events`, the physical file is written under
`writable/uploads/Events`, and the database receives
`writable/uploads/Events/photo.jpg`.

## End-to-end path flows

### Normal upload

```text
Browser or API upload
  -> validate MIME type, size, and filename
  -> write file to the configured physical root
  -> use the absolute path for immediate processing
  -> databasePath() creates writable/uploads/... value
  -> insert the portable value into files.filepath
```

For `photo.jpg` uploaded at the root:

- Physical file:
  `C:\xampp\htdocs\greyhound-admin-2025-final_greyhound_admin_2025\writable\uploads\photo.jpg`
- Database value: `writable/uploads/photo.jpg`

For `photo.jpg` uploaded into `Events`:

- Physical file: `...\writable\uploads\Events\photo.jpg`
- Database value: `writable/uploads/Events/photo.jpg`

### Read, preview, or download

```text
files.filepath
  -> resolveDatabaseFile()
  -> map the logical prefix to the configured root
  -> realpath() and allowed-root validation
  -> open the verified physical file
```

### Rename or move

```text
portable old database value
  -> resolve physical source
  -> rename or move physical file
  -> databasePath() converts the new location
  -> update database with the new portable value
```

### Duplicate upload

A pending duplicate owns a separate physical copy under `Duplicate Queue`. Its
row stores a portable path. If it remains pending when the original is
permanently removed, that queue copy is removed too. A restored duplicate is
excluded because it has become a separately owned `files` record.

## Trash and permanent deletion

Greyhound uses a two-stage deletion workflow:

1. **Move to Trash** is recoverable. The original image or video moves to
   `writable/uploads/Trashed/<filename>`. The database marks it deleted,
   updates `files.filepath` for Trash previews, and remembers its original path
   in `trashfiles.original_filepath`. Files are stored directly in `Trashed`. A filename suffix is added only
   when a name is already occupied; restoration uses the saved original name.
2. **Delete permanently** removes the managed physical artifacts and then
   removes their database records. This operation cannot be undone.

**Restore** moves the physical file back to its saved path, recreating missing
parent directories. If another file occupies that path, restoration fails and
the item stays in Trash. Folder restoration keeps the existing naming behavior
when a replacement folder has the same name. File and database changes roll
back together if a move or database write fails. Single, bulk, API, and folder
actions use the same service.

Apply the schema addition with `php spark migrate`. Existing Trash items remain
restorable; to relocate their originals and flatten older numbered Trash subfolders
while preserving deletion dates and original paths, run
`php spark trash:backfill-storage --dry-run`, then
`php spark trash:backfill-storage`. Unavailable physical files are reported and
their records are left unchanged. Existing automatic retention still applies.

Permanent deletion covers:

- the original managed file;
- persistent watermark derivatives;
- department delivery copies;
- pending Duplicate Queue copies associated with the original; and
- empty managed directories belonging to a permanently deleted folder tree.

It also removes the corresponding dependent records when their tables exist,
including passports and versions, watermark records, AI detections, tag and
recent references, album links, department assignments, pending duplicate
records, Trash records, and the main file row.

Permanent folder deletion processes every trashed file in the folder tree,
removes empty managed directories from deepest to shallowest, and then removes
the folder records.

Every existing path is resolved and checked against the configured storage
roots before deletion. An existing path outside those roots is rejected. A
physical file that is already absent is treated as already removed. If an
existing managed file cannot be deleted, the operation reports an error instead
of silently claiming success.

## Migration performed

The correction included the following one-time data work:

- 11 files (approximately 24.9 MB) were copied from the incorrect
  `public/writable` tree to the intended project-level `writable` tree.
- Every copied file was verified with SHA-256 before the obsolete copy was
  removed.
- No destination filename collisions were found.
- 18 database references were first changed from `public\writable` to the
  intended physical root.
- Those 18 absolute references were then converted to portable relative values:
  3 `files.filepath` rows, 3 `file_watermarks.watermarked_path` rows, and 12
  `tag.filepath` rows.
- Existing `duplicate_media.filepath` values were already relative.
- Verification found no remaining absolute managed paths in the covered path
  fields.

### Physical-file migration procedure

1. The incorrect `public/writable` tree was inventoried.
2. The intended project-level `writable` destination was checked for filename
   collisions; none were found.
3. The 11 files were copied to matching locations under project `writable`.
4. Every source/destination pair was compared using SHA-256.
5. Only after successful verification was the obsolete tree removed. Windows
   permissions required an approved elevated cleanup for the remaining entries.

### Database migration procedure

1. References beginning with the incorrect `public\writable` location were
   changed to the intended project-level location.
2. Managed absolute values were converted to portable forward-slash paths in a
   database transaction.
3. Updated rows were counted and all covered fields were checked again for
   remaining absolute managed paths.

| Database field | Rows converted |
| --- | ---: |
| `files.filepath` | 3 |
| `file_watermarks.watermarked_path` | 3 |
| `tag.filepath` | 12 |
| **Total** | **18** |

No uploaded media was intentionally discarded during this migration.

## Known pre-existing Duplicate Queue inconsistency

There are 265 old `duplicate_media` rows that refer to relative paths under
`writable/uploads/Duplicate Queue`, but their physical files were already
missing. This path work did not delete the files, delete the rows, or recreate
their content.

Those rows need a separate product/data decision: remove stale pending records,
retain them for audit history, or reconcile them from a backup. Original file
content cannot be reconstructed from a pathname alone.

## Moving the project to macOS

To transfer Greyhound to a Mac:

1. Copy the complete project, including `writable/uploads` and
   `writable/Department`.
2. Export and import the database.
3. Configure the Mac `.env` storage settings. The relative defaults can remain:

   ```ini
   greyhound.storageRoot = 'writable/uploads'
   greyhound.departmentStorageRoot = 'writable/Department'
   ```

4. Give the web-server account read/write permission to the required `writable`
   directories.
5. Configure `greyhound.scanSourceRoot` if the Mac uses an external scan inbox.
6. Run the application migrations and storage tests.
7. Upload, preview, rename, move to Trash, restore, and permanently delete a
   disposable test file.

No database path rewrite is required because values such as
`writable/uploads/photo.jpg` contain no Windows drive letter.

If external mounted storage is required later, an absolute path may be supplied
in that computer's `.env`. The database must still keep logical
`writable/uploads/...` or `writable/Department/...` values.

## Operational checks

After deployment, confirm:

- `.env` points to the intended roots.
- The web-server account can create and delete a disposable file in each root.
- A new `files.filepath` begins with `writable/uploads/`, not a drive letter.
- A department delivery path begins with `writable/Department/`.
- `public/writable` is not recreated.
- Backups include both the database and the physical `writable` content.

Example SQL checks can be adapted to the active database engine:

```sql
SELECT id, filepath
FROM files
WHERE filepath LIKE 'C:\\%'
   OR filepath LIKE '/%';

SELECT id, filepath
FROM files
WHERE filepath NOT LIKE 'writable/uploads/%';
```

An empty result from the first query is expected. Review the second result
before changing anything because legacy or deliberately external data may need
special handling.

## Verification

The focused storage, scanner, watermark, folder, Trash, and deletion suite
passes 21 tests with 79 assertions. Its coverage includes:

- relative `.env` roots resolving from the project root;
- portable DB paths resolving to physical managed files;
- department paths mapping to the department root;
- deletion removing a managed file;
- repeated deletion of an absent file remaining safe;
- deletion refusing an existing file outside managed roots; and
- scanner imports storing portable paths without removing source files.

The full suite result at the time of the change was 50 passing tests and one
unrelated existing failure:
`DashboardViewTest` expects the old heading `Media Upload Trend`, while the view
renders `Media Uploads`.

The main regression tests are:

- `tests/unit/StorageConfigPathTest.php`
- `tests/unit/DepartmentDeliveryStorageTest.php`
- `tests/feature/ServerScanFeatureTest.php`

## Complete implementation file map

| File | Important change |
| --- | --- |
| `.env` | Defines relative main and department storage roots |
| `app/Config/Storage.php` | Resolves relative configuration from `ROOTPATH` |
| `app/Services/StorageService.php` | Maps paths and protects filesystem operations |
| `app/Services/TrashService.php` | Deletes owned artifacts and dependent rows |
| `app/Services/ServerScanService.php` | Saves portable scanner destination paths |
| `app/Services/WatermarkService.php` | Saves portable persistent watermark paths |
| `app/Services/DistributionService.php` | Saves portable department delivery paths |
| `app/Controllers/Web/WorkspaceController.php` | Converts web upload and folder-rename paths |
| `app/Controllers/Api/FileController.php` | Converts API upload, rename, and move paths |
| `app/Views/workspace/trash.php` | Warns that permanent deletion removes files |
| `app/Views/workspace/trash_folder.php` | Warns for permanent folder deletion |
| `tests/unit/StorageConfigPathTest.php` | Tests project-root configuration resolution |
| `tests/unit/DepartmentDeliveryStorageTest.php` | Tests mapping and safe deletion |
| `tests/feature/ServerScanFeatureTest.php` | Tests scan paths and source preservation |

## Maintenance guidance

New code must not concatenate a database `filepath` directly into an arbitrary
filesystem path. It must not save the direct result of `realpath()`, `ROOTPATH`,
`WRITEPATH`, or an upload move into a persistent path column. Use:

```php
// Before writing to the database
$portablePath = service('storage')->databasePath($physicalPath);

// Before reading or operating on a database-referenced file
$physicalPath = service('storage')->resolveDatabaseFile($portablePath);

// Before permanently deleting a database-referenced file
service('storage')->deleteDatabaseFile($portablePath);
```

This keeps storage portable and preserves the allowed-root security boundary.
