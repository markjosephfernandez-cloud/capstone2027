# Database usage audit — 2026-10-20

Read-only review of the application-configured `greyhound_integration_test` database at `127.0.0.1:3307`. No tables, columns, rows, migrations, or database settings were changed by this audit. This report is separate from earlier stale-file repairs.

## Result

- Inspected **43 base tables and 5 views**, containing **420 base-table columns and 49 view output columns**.
- Every table/view has a current source reference. This includes framework, maintenance, and compatibility code; it does not mean every object is used in everyday requests.
- **No table is established as safe to drop immediately.** `duplicate_media` and `file_watermarks` are the strongest table-retirement candidates, but existing code still consumes them.
- Four `files` columns are strong candidates for a removal review: `pose`, `bounding_boxes`, `duplicate_of`, and `duplicate_count`.
- Empty tables are not unused: mobile comments, downloads, likes, search history, recent activity, and Trash all have implemented consumers.

## Strongest column candidates

The data checks below cover all **912** `files` rows, including deleted records. Counts are observations at audit time, not permanent properties.

| Column | Current application evidence | Data profile | Removal dependency |
|---|---|---|---|
| `files.pose` | Only declared in `FileModel::$allowedFields`; no explicit runtime reader/writer found | 912 NULL, no populated values | Remove model allowance and review historical/bootstrap schema and consumers |
| `files.bounding_boxes` | Only declared in the model allowlist | 912 NULL, no populated values | Same compatibility review |
| `files.duplicate_count` | Only declared in the model allowlist | All 912 values are 0 | Historical `AddDuplicateCounter` migration; default 0 |
| `files.duplicate_of` | Only declared in the model allowlist | 912 NULL, no populated values | **Foreign key and index `fk_files_duplicate_of` referencing `files.id`** must be handled explicitly |

Source: [FileModel.php](../app/Models/FileModel.php). The current file API uses an explicit response payload and editable-field allowlist; these four fields are absent from those lists ([FileController.php](../app/Controllers/Api/FileController.php)). No corresponding exact-name references were found in the checked mobile or Python AI source.

These are candidates, not proof that an external database consumer never uses them. A future authorized cleanup should use a new CodeIgniter migration, preserve recovery data/schema, update model declarations and fresh-install compatibility, and run backend checks. No such migration was created or run here.

## Legacy tables requiring coordinated retirement

| Table | Exact rows | Remaining use | Assessment |
|---|---:|---|---|
| `duplicate_media` | 265 | `TrashService` reads pending duplicate paths and deletes related rows; `CentralMigrationService` migrates/validates stored paths | No current normal upload producer or duplicate-review UI consumer found in the checked runtime source. Retirement candidate, still used by maintenance/cleanup. |
| `file_watermarks` | 1 | `TrashService` reads `watermarked_path` and removes related rows during permanent deletion | Legacy watermark cleanup remains active. Do not confuse this with the actively used `watermark_settings` table. |

Evidence: [TrashService.php](../app/Services/TrashService.php:590), [CentralMigrationService.php](../app/Services/CentralMigrationService.php:31). Removing either table without reviewing its paths could strand legacy files or lose historical metadata. The audit did not delete or reconcile their historical rows.

Additional column candidates within these legacy tables:

| Columns | Runtime evidence | Existing data |
|---|---|---|
| `duplicate_media.match_type`, `similarity_score`, `detection_scope` | No explicit exact-name runtime reference found; defined by historical migrations | Each populated in all 265 rows |
| `file_watermarks.applied_by`, `applied_at`, `settings_snapshot` | No explicit exact-name runtime reference found | Each populated in the one historical record |

These are weaker removal candidates than the four empty/default-only `files` columns because they preserve historical information. Review them with the parent table rather than dropping them piecemeal.

## Fields and tables that should not be classified as unused

- `passport_versions.changed_at` has no explicit runtime assignment, but the database supplies `CURRENT_TIMESTAMP` when PassportService creates version rows. All **956** version records have a value. It is an implicit write and useful history, not an unused field ([PassportService.php](../app/Services/PassportService.php:166)).
- `migrations` is the CodeIgniter migration ledger: **42 records**. `central_storage_migration` has **422 records** used by migration verification and cleanup. Maintenance-only usage is still usage.
- `vw_media_processing_status.size_bytes` and `needs_attention` have no direct PHP field reference, but `vw_department_media_summary` consumes them. Dropping them would break the dependent view.
- View outputs `vw_media_processing_status.uploader_id`, `analysis_attempts`, `vw_department_media_summary.total_storage_mb`, and `latest_upload_at` are not explicitly displayed by the current report templates. The report models fetch whole rows. These are ordinary computed view columns, not stored table data; trimming them is not a meaningful storage cleanup.
- All five views have current dashboard/report consumers. The old September audit predates these views and many newer tables and should not be used as the current inventory.

## Complete table/view inventory

Counts below are exact `COUNT(*)` results for base tables, collected sequentially during this audit. Background work can change them. View row counts were not needed. A source reference demonstrates a dependency, not frequency of use. “Runtime” includes web/API operations and background/CLI jobs.

| Object | Type | Rows | Classification | Example dependency |
|---|---|---:|---|---|
| `ai_event_detections` | Table | 888 | Runtime | [app/Services/PassportService.php](../app/Services/PassportService.php#L25) |
| `api_tokens` | Table | 207 | Runtime | [app/Models/ApiTokenModel.php](../app/Models/ApiTokenModel.php#L9) |
| `audit_logs` | Table | 13,345 | Runtime | [app/Controllers/Api/AuditLogController.php](../app/Controllers/Api/AuditLogController.php#L13) |
| `central_storage_migration` | Table | 422 | Migration/cleanup ledger | [app/Services/CentralMigrationService.php](../app/Services/CentralMigrationService.php#L142) |
| `department_files` | Table | 211 | Runtime | [app/Controllers/Api/FileController.php](../app/Controllers/Api/FileController.php#L438) |
| `department_folders` | Table | 15 | Runtime | [app/Controllers/Api/FileController.php](../app/Controllers/Api/FileController.php#L29) |
| `department_recent` | Table | 5 | Runtime | [app/Controllers/Web/WorkspaceController.php](../app/Controllers/Web/WorkspaceController.php#L1432) |
| `distribution_actions` | Table | 35 | Runtime | [app/Services/DistributionService.php](../app/Services/DistributionService.php#L404) |
| `duplicate_media` | Table | 265 | Legacy maintenance/cleanup | [app/Services/TrashService.php](../app/Services/TrashService.php#L605) |
| `file_watermarks` | Table | 1 | Legacy maintenance/cleanup | [app/Services/TrashService.php](../app/Services/TrashService.php#L590) |
| `files` | Table | 912 | Runtime | [app/Models/FileModel.php](../app/Models/FileModel.php#L9) |
| `folder` | Table | 66 | Runtime | [app/Models/FolderModel.php](../app/Models/FolderModel.php#L9) |
| `media_assignment_folders` | Table | 4 | Runtime | [app/Services/CentralMigrationService.php](../app/Services/CentralMigrationService.php#L32) |
| `media_assignments` | Table | 211 | Runtime | [app/Services/DepartmentMediaRecoveryService.php](../app/Services/DepartmentMediaRecoveryService.php#L77) |
| `media_requests` | Table | 10 | Runtime | [app/Services/ServerLibraryTransferService.php](../app/Services/ServerLibraryTransferService.php#L97) |
| `media_visual_analysis` | Table | 30 | Runtime | [app/Services/VisualMediaAnalysisService.php](../app/Services/VisualMediaAnalysisService.php#L36) |
| `migrations` | Table | 42 | Framework ledger | [app/Config/Migrations.php](../app/Config/Migrations.php#L16) |
| `mobile_channel_subscriptions` | Table | 2 | Runtime | [app/Controllers/Api/MobileFeedController.php](../app/Controllers/Api/MobileFeedController.php#L88) |
| `mobile_comments` | Table | 0 | Runtime | [app/Controllers/Api/MobileFeedController.php](../app/Controllers/Api/MobileFeedController.php#L267) |
| `mobile_download_history` | Table | 0 | Runtime | [app/Controllers/Api/MobileHistoryController.php](../app/Controllers/Api/MobileHistoryController.php#L54) |
| `mobile_featured` | Table | 1 | Runtime | [app/Controllers/Api/MobileFeaturedController.php](../app/Controllers/Api/MobileFeaturedController.php#L16) |
| `mobile_media_likes` | Table | 0 | Runtime | [app/Controllers/Api/MobileFeedController.php](../app/Controllers/Api/MobileFeedController.php#L49) |
| `mobile_posts` | Table | 62 | Runtime | [app/Controllers/Api/MobilePostController.php](../app/Controllers/Api/MobilePostController.php#L18) |
| `mobile_profiles` | Table | 1 | Runtime | [app/Controllers/Api/MobileProfileController.php](../app/Controllers/Api/MobileProfileController.php#L55) |
| `mobile_search_history` | Table | 0 | Runtime | [app/Controllers/Api/MobileSearchController.php](../app/Controllers/Api/MobileSearchController.php#L11) |
| `mobile_watch_history` | Table | 7 | Runtime | [app/Controllers/Api/MobileHistoryController.php](../app/Controllers/Api/MobileHistoryController.php#L24) |
| `notifications` | Table | 2,447 | Runtime | [app/Services/PassportService.php](../app/Services/PassportService.php#L176) |
| `passport_versions` | Table | 956 | Runtime | [app/Services/PassportService.php](../app/Services/PassportService.php#L166) |
| `passports` | Table | 888 | Runtime | [app/Services/PassportService.php](../app/Services/PassportService.php#L18) |
| `privacy_consents` | Table | 11 | Runtime | [app/Services/ConsentService.php](../app/Services/ConsentService.php#L17) |
| `recent` | Table | 0 | Runtime | [app/Models/RecentModel.php](../app/Models/RecentModel.php#L9) |
| `server_analysis_jobs` | Table | 660 | Runtime | [app/Services/CentralMediaService.php](../app/Services/CentralMediaService.php#L110) |
| `server_library_index` | Table | 1,120,904 | Runtime | [app/Models/ServerLibraryIndexModel.php](../app/Models/ServerLibraryIndexModel.php#L8) |
| `server_library_scan_queue` | Table | 15,535 | Runtime | [app/Services/ServerLibraryEditor.php](../app/Services/ServerLibraryEditor.php#L451) |
| `server_library_sources` | Table | 1 | Runtime | [app/Services/ServerLibraryCatalog.php](../app/Services/ServerLibraryCatalog.php#L26) |
| `server_media` | Table | 1,430,495 | Runtime | [app/Services/CentralMigrationService.php](../app/Services/CentralMigrationService.php#L151) |
| `server_metadata` | Table | 1 | Runtime | [app/Services/ServerLibraryEditor.php](../app/Services/ServerLibraryEditor.php#L21) |
| `server_recent` | Table | 268 | Runtime | [app/Services/CentralMediaService.php](../app/Services/CentralMediaService.php#L102) |
| `server_upload_audit_batches` | Table | 1 | Runtime | [app/Services/ServerUploadAuditService.php](../app/Services/ServerUploadAuditService.php#L26) |
| `tag` | Table | 3,400 | Runtime | [app/Models/TagModel.php](../app/Models/TagModel.php#L9) |
| `trashfiles` | Table | 0 | Runtime | [app/Services/CentralMigrationService.php](../app/Services/CentralMigrationService.php#L32) |
| `user` | Table | 10 | Runtime | [app/Models/UserModel.php](../app/Models/UserModel.php#L9) |
| `vw_dashboard_active_department_files` | View | — | Report/dashboard view | [app/Services/DashboardReadService.php](../app/Services/DashboardReadService.php#L24) |
| `vw_dashboard_active_files` | View | — | Report/dashboard view | [app/Services/DashboardReadService.php](../app/Services/DashboardReadService.php#L29) |
| `vw_dashboard_department_requests` | View | — | Report/dashboard view | [app/Services/DashboardReadService.php](../app/Services/DashboardReadService.php#L114) |
| `vw_department_media_summary` | View | — | Report/dashboard view | [app/Models/MediaReportModel.php](../app/Models/MediaReportModel.php#L28) |
| `vw_media_processing_status` | View | — | Report/dashboard view | [app/Models/MediaReportModel.php](../app/Models/MediaReportModel.php#L13) |
| `watermark_settings` | Table | 1 | Runtime | [app/Controllers/Web/ServerLibraryActionsController.php](../app/Controllers/Web/ServerLibraryActionsController.php#L154) |

## Method and limitations

1. Inspected each object's columns using `php spark db:table <name> --metadata`. The generic `db:table --show` command exhausted PHP's 128 MB memory limit while enumerating table contents; it was not retried with a higher limit. A read-only table-name inventory and per-object metadata reads avoided bulk row loading.
2. Searched 347 source files across application PHP/views/configuration, first-party browser JavaScript, Expo mobile source, entry points, scripts, and Python AI source. Migrations/bootstrap SQL were reviewed separately as historical/schema evidence. Vendor code, backups, generated build assets, and tests were not counted as evidence of a production feature consumer. The configured CI migration table was checked explicitly.
3. Compared exact table/column identifiers, investigated missing/model-only matches, inspected database defaults, and reviewed the five live view definitions, 18 foreign-key column relationships, and index metadata. No application-schema triggers were listed. A column name occurring elsewhere does not prove that the particular table's column is used; this is candidate triage, not a complete static SQL dataflow proof.
4. Counted base-table rows and profiled candidate fields with aggregate queries. No user credentials or row contents are included in this report. Empty/default-only data alone was not treated as deletion authorization.
5. Stored-routine inspection is **incomplete**: MariaDB reported `Column count of mysql.proc is wrong. Expected 21, found 20. Created with MariaDB 100108, now running 100428`. Scheduled-event inspection was also blocked: `Cannot proceed, because event scheduler is disabled`. These are unresolved metadata-inspection limitations; no server upgrade or settings change was attempted. A separate database-maintenance review is appropriate before destructive schema cleanup.
6. Historical query logs, external clients, deployed copies different from this workspace, and dynamically named/whole-row consumers were not exhaustively traced. No schema removal is certified safe from this audit alone.

No application code was changed for this audit, so PHPUnit was not rerun. Findings were verified through read-only schema, source, dependency, and aggregate-data checks.
