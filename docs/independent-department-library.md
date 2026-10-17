# Independent department library

Completed department deliveries now own `department_files` records and a snapshot
of their folder hierarchy in `department_folders`. Department File Management,
previews, downloads, recent files, dashboard counts, and file API reads use these
records, scoped to the signed-in department.

`source_media_id`, `source_assignment_id`, and `source_folder_id` record provenance;
they are not cascading foreign keys or requirements for continued access. File
and folder IDs in department views belong to the department tables, so saved links
using old admin IDs must be reopened from the department library.

Admin Trash and permanent deletion affect the admin original and its derivatives.
They do not remove department files, folder metadata, or completed delivery
history. Each department record has its own `is_deleted` and `deleted_at` fields.
Backfill never reactivates a deleted department record. Existing role permissions
are unchanged; this change does not grant department users admin deletion tools.

## Deployment and existing deliveries

Run in order, before making the updated application available:

```sh
php spark migrate
php spark department:separate-library
```

The command snapshots completed assignments with available source metadata. Normal
deliveries retain their existing department storage path. Legacy recovery records
that share the admin original's physical path get a separate physical copy. A
shared legacy original already in admin Trash can be copied without restoring it.
Each migration transaction compensates newly created files on failure.

Repeated runs do not duplicate deliveries. Reports under
`writable/recovery/independent-departments-*.json` record the resulting records and
unavailable assignment IDs. Missing source metadata or delivery paths are reported;
missing physical files stop capture rather than create an unusable record. Neither
procedure can reconstruct missing image bytes. Keep database and both storage-root
backups together. The old `department:recover-media` command is blocked once these
tables exist because it creates records in the shared admin library.

New transaction authorization captures the independent record within the same
database transaction as the completed assignment. If capture fails, authorization
rolls back and its newly created physical copies are removed.

## Validation

`DepartmentLibraryLifecycleTest` covers surviving admin Trash and permanent
deletion, detaching shared legacy files (including trashed files), folder survival,
department isolation, and repeat backfills without resurrecting deleted copies.
`WorkspaceThumbnailResponseTest` exercises department preview access after the
original database record is removed, rejects another department's IDs and deleted
copies, and checks that admin endpoints still resolve admin IDs.
