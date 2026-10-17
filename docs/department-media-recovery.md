# Recovering department images with missing database links

> Historical procedure: after installing the independent department library,
> this command is blocked. See [Independent department library](independent-department-library.md)
> for the current migration and deletion behavior. The shared-record behavior
> described below applies only before that migration.

Department File Management lists active `files` records with a completed `media_assignments` entry for the signed-in department. Files on disk alone do not appear. On 2026-09-19, historic assignments referenced missing IDs while department copies remained in storage.

Run `php spark department:recover-media` to inspect the designated storage directories for departments configured on department accounts. Run `php spark department:recover-media --apply` only when recovery of those existing copies is intended. This command restores visibility of copies already in department storage; it is not the normal submission/review workflow.

The command validates image MIME types and SHA-256 hashes. A verified matching active original gets a new assignment pointing to the existing department copy. Otherwise, it registers a separate file record under `Recovered department images / <department>`, retaining subfolders. It does not copy, move, alter, or delete images. Deleted candidates, ambiguous matches, symbolic links, and storage directories without a configured department are not imported. Conflicting department-to-directory mappings stop the operation.

Recovery assignments use method `recovery`, system sender ID `0`, and no fabricated request or approving user. Newly registered files identify their recovery in the description; their metadata recovery date is not represented as the original upload date. Broken historical assignments are retained, not reassigned to guessed files.

Database inserts are transactional. A manifest in `writable/recovery/department-*.json` records the input paths and hashes, and the exact new file, folder, and assignment IDs. If rollback is necessary, first verify the command committed successfully and inspect subsequent activity; remove only the manifest's new assignments and otherwise-unused new metadata records in a database transaction. Never remove physical images for this metadata rollback. Retain manifests with database backups, outside the public web root.

Repeat runs report already linked copies as `existing` and do not duplicate records. A lock prevents concurrent invocations of this recovery command. Verify after recovery that the expected department can list/preview its own images and another department cannot access them.
