# Database usage audit — 2026-09-10

## Retirement update

Following user authorization on 2026-09-10, migration `2026-09-10-000001` removed `activity`, `recent_files_view`, and `view_duplicates` after verifying their recovery SQL backup. The database now has **28 base tables, no views, and 29 applied migrations**. The original 19 activity rows are preserved in `writable/backups/legacy-objects-greyhound_integration_test-20260910-133055-b3494d9b.sql`.

The `legacy/` code directory was moved intact to `writable/backups/legacy-source-20260910/`. Fresh setup files now live in `database/bootstrap/`. The stale duplicate/distribution records below were not changed. The remainder of this document preserves the pre-cleanup audit findings.

Inspected the application-configured MySQL database `greyhound_integration_test`. This was a read-only audit; no database rows, schema, or migrations were changed.

## Findings

- 29 base tables and 2 views were inspected. 27 base tables have current PHP references; `migrations` is used by CodeIgniter. The remaining base table, `activity`, is referenced by legacy code.
- No current PHP database queries were found for `activity`, `recent_files_view`, or `view_duplicates`. This is evidence for a cleanup review, not proof that an external client never uses them.
- Empty mobile, recent, and Trash tables are connected to implemented features. Empty does not mean unused.
- All 28 repository migration classes match the applied migration records. All allowed fields declared in the six application models exist in their corresponding tables.
- Stale relationships are present, especially duplicate records and distribution assignments referencing missing media. These are data integrity issues in used tables, not evidence that the tables can be removed.

## Table and view inventory

| Object | Type | Rows | Usage | Example source |
|---|---|---:|---|---|
| `activity` | Table | 19 | Legacy only | `Legacy PHP only` |
| `ai_event_detections` | Table | 13 | Used | `app/Controllers/Web/WorkspaceController.php:1554` |
| `api_tokens` | Table | 113 | Used | `app/Controllers/Web/AdminController.php:250` |
| `audit_logs` | Table | 10,279 | Used | `app/Controllers/Api/AuditLogController.php:13` |
| `distribution_actions` | Table | 489 | Used | `app/Controllers/Web/DistributionController.php:58` |
| `duplicate_media` | Table | 265 | Used | `app/Controllers/Api/FileController.php:122` |
| `file_watermarks` | Table | 1 | Compatibility read/cleanup | `app/Controllers/Web/WorkspaceController.php:2225` |
| `files` | Table | 10 | Used | `app/Commands/BackfillTrashStorageCommand.php:19` |
| `folder` | Table | 4 | Used | `app/Controllers/Web/AddonsController.php:107` |
| `media_assignment_folders` | Table | 3 | Used | `app/Controllers/Web/WorkspaceController.php:41` |
| `media_assignments` | Table | 89 | Used | `app/Controllers/Api/FileController.php:24` |
| `media_requests` | Table | 20 | Used | `app/Controllers/Web/AddonsController.php:475` |
| `migrations` | Table | 28 | Framework | `CodeIgniter migration framework` |
| `mobile_channel_subscriptions` | Table | 2 | Used | `app/Controllers/Api/MobileFeedController.php:80` |
| `mobile_comments` | Table | 0 | Used, currently empty | `app/Controllers/Api/MobileFeedController.php:198` |
| `mobile_download_history` | Table | 0 | Used, currently empty | `app/Controllers/Api/MobileHistoryController.php:49` |
| `mobile_media_likes` | Table | 0 | Used, currently empty | `app/Controllers/Api/MobileFeedController.php:197` |
| `mobile_profiles` | Table | 0 | Used, currently empty | `app/Controllers/Api/MobileInteractionController.php:70` |
| `mobile_search_history` | Table | 0 | Used, currently empty | `app/Controllers/Api/MobileSearchController.php:11` |
| `mobile_watch_history` | Table | 0 | Used, currently empty | `app/Controllers/Api/MobileHistoryController.php:106` |
| `notifications` | Table | 1,244 | Used | `app/Controllers/Web/AdminController.php:185` |
| `passport_versions` | Table | 3 | Used | `app/Services/PassportService.php:135` |
| `passports` | Table | 3 | Used | `app/Controllers/Web/AddonsController.php:19` |
| `privacy_consents` | Table | 8 | Used | `app/Controllers/Web/AdminController.php:185` |
| `recent` | Table | 0 | Used, currently empty | `app/Controllers/Api/RecentController.php:13` |
| `recent_files_view` | View | 0 | No reference found | `None found` |
| `tag` | Table | 40 | Used | `app/Commands/CleanMediaTagsCommand.php:54` |
| `trashfiles` | Table | 0 | Used, currently empty | `app/Commands/BackfillTrashStorageCommand.php:19` |
| `user` | Table | 7 | Used | `app/Controllers/Api/MobileInteractionController.php:69` |
| `view_duplicates` | View | 0 | No reference found | `None found` |
| `watermark_settings` | Table | 1 | Used | `app/Controllers/Web/AddonsController.php:235` |

## Missing referenced records

Counts below exclude NULL references. A single row may appear in multiple checks, so these counts must not be added together. Historical distribution and consent records may need retention; this audit does not authorize deleting them.

| Relationship | Rows whose parent record is absent |
|---|---:|
| `ai_event_detections.media_id -> files.id` | 3 |
| `duplicate_media.original_file_id -> files.id` | 265 |
| `file_watermarks.media_id -> files.id` | 1 |
| `passports.media_id -> files.id` | 3 |
| `passport_versions.passport_id -> passports.id` | 0 |
| `media_assignments.media_id -> files.id` | 89 |
| `media_assignments.request_id -> media_requests.id` | 8 |
| `media_assignment_folders.folder_id -> folder.id` | 3 |
| `media_assignment_folders.request_id -> media_requests.id` | 0 |
| `folder.parent_id -> folder.id` | 0 |
| `folder.trash_root_id -> folder.id` | 0 |
| `api_tokens.user_employee_id -> user.employee_id` | 2 |
| `privacy_consents.employee_id -> user.employee_id` | 1 |
| `trashfiles.folder_root_id -> folder.id` | 0 |
| `files.folder_id -> folder.id` | 0 |
| `tag.file_id -> files.id` | 0 |

- All 265 duplicate records with a missing original have `status = pending`. The current duplicate listing joins to existing files, so these records do not appear in its normal queue.
- Of the 89 assignments pointing to missing media, 74 are `completed` and 15 are `sent`. Eight assignments also reference absent requests; those counts can overlap.
- All 3 passport records point to missing media. Their 3 version records still point to existing passports.
- Three AI detections, one saved watermark, three folder assignments, two API tokens, and one consent record also have missing parent references in the checks above.
- Many addon tables lack database foreign keys. That permits stale references if a deletion or import bypasses application cleanup. The audit does not establish which action originally produced these rows.

## Column usage and compatibility

All model-declared allowed columns are present. A complete proof that every column is read or written is not possible from string searches: controllers use `SELECT *`, serialize whole rows, and build queries dynamically.

`files.pose` and `files.bounding_boxes` appear only in the current model allowlist in the inspected PHP code, and both are empty for all 10 current files. They are candidates for a compatibility review, not confirmed safe-to-remove columns. `files.gesture` and `files.emotion` are also empty, but iFound search explicitly queries them. `passports.qr_payload` is written by PassportService even if not displayed by the current screen.

`album_files` is absent but referenced inside a `tableExists()` guard in legacy-compatible permanent-delete cleanup. It is not a missing required table.

## Suggested next work

1. Review stale pending duplicates and any corresponding physical copies, then prepare a separately reviewable cleanup.
2. Review the missing media behind outstanding (`sent`) assignments and decide how to preserve completed distribution history.
3. Review legacy activity/view consumers before removing any database objects.
4. Add suitable integrity enforcement or cleanup rules after existing data has been reconciled.

## Scope and limits

Inventory and row counts reflect the database at audit time. Usage was checked against PHP models, controllers, services, commands and migrations, with additional legacy PHP searches. Relationship checks covered 16 explicit links; this is not an exhaustive foreign-key or query trace audit. No external database clients, stored-routine consumers, or historical query logs were inspected.
