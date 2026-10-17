# Capstone database views

Implemented October 6, 2026 in `greyhound_integration_test` using migration
`app/Database/Migrations/2026-10-06-133138_AddMediaReportViews.php`.
Deploy with `php spark migrate`. The definitions below document the migration;
do not execute them again against an installation where the views already exist.

## View 1: Operational — Media Processing Status

`vw_media_processing_status` joins active `files` with the uploader in `user`,
the latest `passports` record, the latest active `server_media` identity, and
its `server_analysis_jobs` record. One row represents one active central-library
file. Staff can identify pending/failed jobs, missing passports, and review flags.
`not_queued` means no linked server job; it does not prove that legacy AI work
never ran. A missing passport has `has_passport = 0`, independently of AI status.

UI: **Metadata Passports → Media Processing Status**. Shows 20 files per page,
newest upload first, under the page's existing Creative Staff access rules.

```sql
CREATE VIEW `vw_media_processing_status` AS
            SELECT f.id AS file_id, f.filename, f.display_title, f.filetype,
                f.size AS size_bytes, f.dateupload AS uploaded_at,
                f.employee_id AS uploader_id, COALESCE(u.name, 'Unknown uploader') AS uploader_name,
                COALESCE(NULLIF(TRIM(p.department), ''), NULLIF(TRIM(u.department), ''), 'Unassigned') AS department,
                COALESCE(NULLIF(f.classification, ''), 'Unclassified Event') AS classification,
                COALESCE(j.status, 'not_queued') AS analysis_status,
                COALESCE(j.attempts, 0) AS analysis_attempts,
                CASE WHEN p.id IS NULL THEN 0 ELSE 1 END AS has_passport,
                p.passport_uid, COALESCE(p.needs_review, 0) AS needs_review,
                CASE WHEN j.status IN ('pending', 'failed') OR p.id IS NULL OR p.needs_review = 1
                    THEN 1 ELSE 0 END AS needs_attention
            FROM `files` f
            LEFT JOIN `user` u ON u.employee_id = f.employee_id
            LEFT JOIN `passports` p ON p.id = (SELECT MAX(p2.id) FROM `passports` p2 WHERE p2.media_id = f.id)
            LEFT JOIN `server_media` m ON m.id = (SELECT MAX(m2.id) FROM `server_media` m2 WHERE m2.file_id = f.id AND m2.is_deleted = 0)
            LEFT JOIN `server_analysis_jobs` j ON j.media_id = m.id
            WHERE f.is_deleted = 0;
```

Test:

```sql
SELECT * FROM vw_media_processing_status
ORDER BY uploaded_at DESC, file_id DESC
LIMIT 20;
```

## View 2: Summary — Department Media Summary

`vw_department_media_summary` groups the operational view by department and
calculates file counts, storage, passport coverage, pending/failed analysis,
and the number of files needing attention. Each file is counted once even if it
has several issues. Department uses the latest passport's department, falling
back to the uploader's current department and then `Unassigned`.

Scope: registered central-library files, excluding trash. This is not a count
of every indexed file on the server or of separate department delivery copies.
Storage sums file sizes; it is not physical disk usage. `total_storage_mb` uses
1,048,576 bytes per unit (MiB). Departments without active files have no row.

UI: **Super Admin → Admin Control Center → Department Media Summary**.

```sql
CREATE VIEW `vw_department_media_summary` AS
            SELECT department, COUNT(*) AS total_files,
                COALESCE(SUM(size_bytes), 0) AS total_storage_bytes,
                ROUND(COALESCE(SUM(size_bytes), 0) / 1048576.0, 2) AS total_storage_mb,
                SUM(has_passport) AS files_with_passport,
                SUM(CASE WHEN has_passport = 0 THEN 1 ELSE 0 END) AS files_missing_passport,
                ROUND(100.0 * SUM(has_passport) / COUNT(*), 2) AS passport_coverage_percent,
                SUM(CASE WHEN analysis_status = 'pending' THEN 1 ELSE 0 END) AS pending_analysis,
                SUM(CASE WHEN analysis_status = 'failed' THEN 1 ELSE 0 END) AS failed_analysis,
                SUM(needs_attention) AS files_needing_attention,
                MAX(uploaded_at) AS latest_upload_at
            FROM `vw_media_processing_status`
            GROUP BY department;
```

Test:

```sql
SELECT * FROM vw_department_media_summary
ORDER BY total_files DESC, department;
```

## Verification

Applied with `php spark migrate` and tested both views with SELECT on the live
database at approximately 9:34 PM PHT, October 6, 2026:

- 304 active original file records, 304 operational rows, 304 distinct file IDs.
- Department totals: Creative Department 247 files; Super Department 57 files.
- Summary storage exactly matched original active file sizes: 2,645,649,775 bytes.
- The latest `image.jpg` had completed analysis and an available passport.

These are a snapshot: views read current data on each query, and background
processing can change the results. Reload the UI report to see current values.
In phpMyAdmin, expand the database and open either view to browse its rows.

No original records are changed by either view. Migration rollback drops only
the two views, summary first. Controllers read reports through Query Builder
in `app/Models/MediaReportModel.php`; database views do not enforce application
roles, so authorization remains in the existing page controllers.

Tests: `php vendor/bin/phpunit tests/MediaReportViewsTest.php tests/SidebarNavigationTest.php tests/unit/PassportViewTest.php tests/unit/RolePolicyTest.php --no-coverage`.
Ten tests and 90 assertions passed, covering joins, deduplication, trash
exclusion, department fallback, coverage calculations, rendered reports,
sidebar behavior and access rules. Browser layout has not been verified.
