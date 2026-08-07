# Greyhound Admin and Mobile Integration Architecture

## Executive decision

Use **one canonical database, `last_fm`, behind the existing versioned CodeIgniter REST API**. The React Native application must never connect to MySQL directly. Migrate the useful mobile-only data from `fm_system` (watch/download history, comments, search analytics, and optional playlists), then retire its duplicated media, album, tag, folder, publication, trash, and user tables.

This gives the system one media ID, one approval state, one deletion state, and one user identity. It also avoids the current synchronization problem: the mobile Express API reads `fm_system.album_files`, while the admin application writes `last_fm.files`; therefore an approved admin upload cannot reliably appear in the mobile app without copying rows and paths between databases.

The existing admin project already provides `/api/v1`, bearer tokens, hashed token storage, role checks, file/folder/tag endpoints, audit logging, CORS handling, and pagination. Extend this API with read-optimized mobile endpoints instead of maintaining the unauthenticated Express server included in the ZIP.

## 1. Database inventory

### `last_fm` — File Management/Admin

| Table | Purpose | Main concerns / relationships to add |
|---|---|---|
| `activity` | Legacy user activity events such as login, scan, create, and folder delete. | Overlaps `audit_logs`; migrate useful history and retire it. Existing FK: `user_id -> user.employee_id`. |
| `ai_event_detections` | One current AI event classification per media item, including confidence, model version, evidence, and review flag. | Add FK `media_id -> files.id ON DELETE CASCADE`; use native JSON for `evidence_json`. |
| `albums` | Album header: name, description, creation time, and cover. | `cover_file_id` currently points to `album_files.id`; target should be `files.id`. Add owner, status, updated time, and stable public ID. |
| `album_files` | A second copy of file metadata for album content. | This duplicates `files`. Replace with junction table `album_media(album_id, media_id, sort_order, featured)` and remove duplicated filename/path/type/size fields. |
| `api_tokens` | Hashed API bearer tokens, expiry, revocation, and last use. | Existing schema lacks an FK in the dump; add `user_employee_id -> user.employee_id ON DELETE CASCADE`. Add refresh-token/device support for mobile. |
| `approvals` | Approval request for a media item/folder/group and publication channel; tracks current stage and final state. | Add FKs to `files`, `folder`, and `user`; add a unique active-request rule and indexes on status/channel/update time. |
| `approval_steps` | Staff/marketing/admin decisions, notes, signature, and version for an approval. | Add FK `approval_id -> approvals.id ON DELETE CASCADE` and reviewer FK to `user`. |
| `audit_logs` | Security-grade operational audit trail across authentication, media, approvals, watermarking, and metadata. | Canonical replacement for `activity`; retain immutable. User ID is currently varchar while employee ID is int—standardize it. |
| `files` | Canonical uploaded media metadata, AI fields, file hashes, folder/uploader, publish flags, deletion state, and download policy. | This should remain the media source of truth. Add MIME type, media kind, public ID, release status, updated/version timestamps, category/department FKs, dimensions/duration, and indexes described below. |
| `file_watermarks` | Watermarked derivative and a snapshot of settings for one media item. | Add FK `media_id -> files.id ON DELETE CASCADE`; optionally support multiple derivatives by removing the one-media unique constraint and adding a variant key. |
| `folder` | Hierarchical logical/storage folders. | Add self-FK `parent_id -> folder.id ON DELETE RESTRICT/SET NULL` and unique `(parent_id, name)`. |
| `migrations` | CodeIgniter migration bookkeeping. | Framework-owned; keep. |
| `notifications` | Role-wide or user-specific workflow notifications with read status. | Add recipient FK where applicable; for mobile, split notification event from per-user delivery/read state. |
| `passports` | Provenance/rights/AI/approval metadata passport for one media item. | Add FK to `files`, plus reviewer/uploader FKs; retain one-to-one unique media constraint. |
| `passport_versions` | Immutable JSON snapshots of passport changes. | Add FK `passport_id -> passports.id ON DELETE CASCADE` and changer FK. |
| `privacy_consents` | Versioned privacy-policy acceptance per employee. | Add FK `employee_id -> user.employee_id ON DELETE CASCADE`. |
| `recent` | Per-user recently accessed file/folder items. | Existing user FK; `filepath` should not be the identity. Add nullable `file_id`/`folder_id` FKs instead. |
| `tag` | Tags assigned to files; unique per `(file_id, tag)`. | Existing file FK is good. Rename to `media_tags`, optionally introduce a `tags` dictionary table for governance. |
| `trashfiles` | Trash event for a soft-deleted canonical file, with actor/reason/time. | Existing FKs to file and user are good. Add unique `file_id` so one active trash record exists per file. |
| `user` | Admin/staff/school/guest identity, role, status, profile, and password hashes. | Canonical identity table. Remove legacy plaintext `password` after every account has `password_hash`. |
| `watermark_settings` | Current global watermark configuration and updater. | Add updater FK; consider scoped settings by department/channel. |

Views:

- `recent_files_view` combines recent/tag/media presentation data. It should be recreated after schema migration and must not be treated as a writable table.
- `view_duplicates` reports duplicate media based on hashes/grouping. Keep as a derived view, not canonical data.

### `fm_system` — current Mobile database

| Table | Purpose | Disposition |
|---|---|---|
| `activity_logs` | File/folder activity by admin employee. | Duplicate of `last_fm.audit_logs`/`activity`; migrate only missing history, then remove. |
| `addfolder` | Simple folder catalogue. | Duplicate/inferior to `last_fm.folder`; remove. |
| `add_tag` | Denormalized tag plus separate image/video/audio path columns. | Duplicate/inferior to `last_fm.tag`; remove. |
| `admin_activity_logs` | Admin CRUD/login audit history. | Duplicate of `audit_logs`; migrate unique history, then remove. |
| `admin_users` | Employee identity used by mobile-side tables. | Same entity as `last_fm.user` but lacks account status, hashes, and timestamps; map by employee ID/email, then remove. |
| `albums` | Album headers with a file pointer. | Same entity as `last_fm.albums`; reconcile IDs/names and migrate to canonical albums. |
| `album_files` | Media records shown by the current mobile Express API. | Same media as `last_fm.files` and duplicates album membership. Map to `files` plus `album_media`, then remove. |
| `categories` | Content category dictionary. | Useful and missing from `last_fm`; migrate as canonical `categories`. |
| `comments` | Anonymous/unowned comments on `published_files`. | Useful mobile feature, but add `user_id`, moderation state, timestamps, and reference canonical `files.id`. |
| `download_history` | Per-device download events with copied file metadata. | Keep the behavior but migrate to canonical `media_downloads`; retain only `media_id`, user/device, bytes, time. |
| `files` | Older/parallel media catalogue with categories, folders, AI fields, publication and album fields. | Same entity as `last_fm.files`; reconcile by content hash/path, fill missing canonical fields, then remove. |
| `folders` | Folder records represented like files. | Same concept as `last_fm.folder`; migrate hierarchy, then remove. |
| `playlists` | User-owned playlist headers. | Keep only if product requires it; add `playlist_media` junction and migrate user FK. |
| `publish` | Minimal duplicate copy of published filename/type/path. | Redundant and unsafe; remove after mapping. |
| `published_files` | Publication event/status linked to `files`. | Preserve history in a new `media_publications` table; do not use it as another media catalogue. |
| `recent` | Per-user recent items. | Same as `last_fm.recent`; merge/deduplicate. |
| `search_logs` | Aggregated search terms/counts. | Optional analytics; migrate with privacy retention/normalization rules. |
| `tag` | File-like tag records and paths. | Same concept as `last_fm.tag`, but lacks a reliable media FK; map then remove. |
| `trash` | First full-copy trash implementation. | Redundant; map unresolved events then remove. |
| `trashfiles` | Second full-copy trash implementation containing nearly all file columns. | Redundant and prone to divergence; use canonical `files.is_deleted` plus `trashfiles` event instead. |
| `watch_history` | Per-device playback events/progress with copied file metadata. | Useful; migrate to canonical `media_views`/`watch_progress`, referencing `files.id`. |

View:

- `view_duplicates` is derived duplicate reporting and overlaps the same view in `last_fm`; retain only one canonical view.

### Direct duplicates and equivalent entities

| `fm_system` | `last_fm` target | Assessment |
|---|---|---|
| `admin_users` | `user` | Same people; `last_fm.user` is richer and should win. |
| `files`, `album_files`, `publish`, `trash`, `trashfiles` | `files` + status/history/junction tables | Five copies/stages of the same media concept. Paths and metadata can diverge. |
| `albums` | `albums` | Same album header concept. |
| `album_files` membership | new `album_media` | Current schemas mix membership with duplicated file records. |
| `folders`, `addfolder` | `folder` | Same hierarchy/catalogue concept with incompatible column shapes. |
| `tag`, `add_tag` | `tag` / new normalized tag tables | Same tagging concept. |
| `recent` | `recent` | Same schema and user behavior. |
| `activity_logs`, `admin_activity_logs` | `audit_logs` | Same audit purpose at different levels of detail. |
| `trash`, `trashfiles` | `files.is_deleted` + `trashfiles` | Same lifecycle state. |
| `published_files`, `publish`, `files.is_published` | approvals + `files` release fields + new `media_publications` | Same publication state represented three ways. |

### Important missing relationships

`last_fm` has the better integrity model, but many logical relations are not enforced. Add foreign keys from AI detection, API tokens, approvals/steps, watermarks, passports/versions, notifications, privacy consents, and folder parentage. `fm_system` is weaker: history tables do not reference canonical files, albums do not securely own their cover, `album_files` has no canonical file FK, `published_files.file_id` is not constrained in the dump, `comments` have no author, playlist membership is absent, trash tables have no source-file relation, and `watch_history`/`download_history.file_id` are not constrained.

## 2. One database versus two databases

| Concern | Option A: one canonical database behind API | Option B: two databases synchronized through APIs/events |
|---|---|---|
| Advantages | Immediate consistency; one media ID and approval state; simpler transactions, deletes, edits, backups, search, and reporting; lowest implementation effort. | Strong bounded-context isolation; mobile load can scale independently; failures can be isolated; useful for separate organizations/regions or very high traffic. |
| Disadvantages | Shared database is a larger blast radius; mobile read traffic must be indexed/cached; schema changes require coordination. | Requires outbox/events, idempotent consumers, mapping IDs, retries, reconciliation, tombstones, monitoring, and conflict rules; eventual consistency is visible to users. |
| Security | Strong when only the CodeIgniter API has DB credentials and endpoints expose approved/public projections. Use least-privilege DB accounts and signed/tokenized media URLs. | Can isolate credentials/data, but synchronization endpoints and replicated personal/media data add attack surface and secret management. |
| Performance | Best initially: no replication delay or cross-service calls. Add Redis/CDN/read replica later without changing ownership. | Mobile reads can be optimized independently, but writes replicate and cache invalidation is harder; duplicate storage increases cost. |
| Scalability | Adequate for this project with pagination, indexes, object storage/CDN, caching, and later read replicas. | Better only once mobile scale or availability requirements justify operational complexity. |
| Maintainability | One migration history and source of truth; fewer failure modes. | Two schemas, migrations, contracts, sync workers, dead-letter queues, and reconciliation jobs. |
| Future expansion | Clean API boundaries allow services/read models to be split later using an outbox. | Supports independent teams/services now, but premature distribution will slow feature work. |

**Recommendation: Option A.** Use one logical database and one API, not direct shared database access. If traffic later warrants separation, create a read-only mobile projection/search index from `last_fm` through an outbox/event stream; do not revive independent authoritative media rows.

## 3. Canonical schema changes

### Keep and extend

- `user`, `api_tokens`, `files`, `folder`, `albums`, `approvals`, `approval_steps`, `ai_event_detections`, `passports`, `passport_versions`, `file_watermarks`, `watermark_settings`, `notifications`, `audit_logs`, `privacy_consents`, `recent`, and `trashfiles`.
- Import `categories`, `comments`, `watch_history`, `download_history`, optional `playlists`, and optional `search_logs` after redesigning their foreign keys.

### Replace

1. Replace `album_files` with:

```sql
CREATE TABLE album_media (
  album_id INT NOT NULL,
  media_id INT NOT NULL,
  sort_order INT NOT NULL DEFAULT 0,
  is_cover TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL,
  PRIMARY KEY (album_id, media_id),
  FOREIGN KEY (album_id) REFERENCES albums(id) ON DELETE CASCADE,
  FOREIGN KEY (media_id) REFERENCES files(id) ON DELETE CASCADE
);
```

2. Add `media_publications(id, media_id, approval_id, channel_id, status, published_by, published_at, withdrawn_at)` so publication history is not overwritten.
3. Add `departments(id, code, name, is_active)` and `channels(id, code, name, department_id, is_active)`; stop deriving channels from album text in the mobile app.
4. Add `media_views(id, media_id, user_id, device_id, progress_seconds, completed, viewed_at)` and `media_downloads(...)`, referencing canonical media rather than copying filename/path/type.
5. Add `device_registrations(id, user_id, device_uuid, platform, push_token, last_seen_at, revoked_at)` for mobile sessions and push notifications.

### Extend `files`

Add:

- `public_id CHAR(26) UNIQUE` (ULID/UUID exposed to clients; do not rely on sequential IDs externally).
- `mime_type VARCHAR(100)` and `media_kind ENUM('image','video','audio','document')`; current dumps mix extensions (`jpg`) and MIME types (`image/jpeg`).
- `release_status ENUM('draft','in_review','approved','published','withdrawn')`.
- `category_id`, `department_id`, and optional `channel_id` FKs.
- `duration_ms`, `width`, `height`, `thumbnail_path`, `stream_path`.
- `metadata_json JSON` for non-queryable extraction details; retain frequently searched AI values in normalized/indexed columns/tables.
- `version INT`, `updated_at`, `deleted_at`, and `published_at`.
- Indexes: `(release_status, media_kind, published_at, id)`, `(is_deleted, last_action_at)`, `(department_id, published_at)`, `(category_id, published_at)`, full-text `(filename, description, tags)`, and unique/length-safe content hash.

### AI metadata rule

Store stable/queryable values in `ai_event_detections` and `passports`: event/classification, confidence, detector version, review state, processing time, and provenance. Store bounding boxes/evidence as JSON with a documented schema. Never overwrite an AI result silently: keep a current record plus immutable run/version records such as `ai_analysis_runs(id, media_id, model_name, model_version, status, result_json, reviewed_by, created_at)`. Human corrections should be separate from raw model output.

## 4. End-to-end publication workflow

```text
Creative Staff uploads image/video
        |
CodeIgniter validates type/size and stores original in private object/file storage
        |
One row is inserted in last_fm.files (release_status = draft)
        |
AI job creates ai_analysis_runs / ai_event_detections and metadata passport
        |
Staff edits metadata, assigns category, department/channel, and album membership
        |
Approval request and stage decisions are recorded in approvals + approval_steps
        |
Final approval atomically sets release_status = published and creates media_publications
        |
Cache is invalidated; optional outbox event sends push notification
        |
React Native requests /api/v1/mobile/home, /shorts, or /albums
        |
API returns only published, non-deleted media and HTTPS media/thumbnail URLs
        |
Image appears in Home/Gallery; video appears in Home/Shorts; album appears in Albums
```

### Placement rules

- **Home feed:** published images, videos, and albums ordered by `published_at` (or a curated rank), filtered by allowed audience/channel. Do not concatenate three separate unpaginated lists as the current app does.
- **Shorts:** published `media_kind = video`; ideally add `format = short` or enforce a duration/aspect-ratio rule. Do not assume every video is a Short.
- **Albums:** `albums` joined through `album_media` to published/non-deleted media. Album visibility should be derived from album status and at least one visible item.
- **Gallery:** published images, independently paginated and filterable.
- **Metadata transfer:** API DTOs expose public IDs, title, description, tags, category, department/channel, dates, rights, dimensions/duration, thumbnail/stream/download URLs, and only safe AI fields. Never expose filesystem paths.

### Edits and deletion

- Metadata edit increments `files.version`, updates `updated_at`, records `audit_logs`, and invalidates API/CDN cache. Mobile uses ETag/`updated_since` and replaces its cached record when the version changes.
- Replacing binary media creates a version/derivative, reruns AI, and normally returns the item to review. Do not change the URL contents indefinitely under the same cache key; use versioned object keys.
- Soft delete sets `is_deleted = 1`, `deleted_at`, and release status `withdrawn` in one transaction. All mobile queries include `is_deleted = 0 AND release_status = 'published'`. A tombstone is returned by `/sync/changes` so offline clients delete cached records/download references.
- Restore does **not** automatically republish unless policy explicitly allows it; require approval/release state confirmation.
- Permanent purge removes derivatives/object-storage files after retention, but retains a minimal immutable audit record.

## 5. REST API design

Base URL: `https://api.example.edu/api/v1`. All responses use `{ "data": ..., "meta": ... }`; errors use `{ "error": "code", "message": "...", "details": {} }`. Use cursor pagination rather than offset for feeds. Authenticated calls send `Authorization: Bearer <access-token>`.

### Endpoint catalogue

| Endpoint | Method | Parameters/body | Response data | Tables |
|---|---|---|---|---|
| `/auth/login` | POST | `{employee_id, password, device_name}` | Tokens, expiry, user summary | `user`, `api_tokens`, `device_registrations`, `audit_logs` |
| `/auth/refresh` | POST | `{refresh_token}` | Rotated access/refresh tokens | token/session tables |
| `/auth/logout` | POST | Bearer token + optional device ID | Revocation confirmation | `api_tokens`, device sessions, `audit_logs` |
| `/mobile/home` | GET | `cursor`, `limit`, `channel`, `category` | Mixed typed feed items + next cursor | `files`, `media_publications`, `albums`, `album_media`, categories/channels |
| `/mobile/shorts` | GET | `cursor`, `limit`, `channel` | Published short-video DTOs | `files`, `media_publications`, AI/metadata tables |
| `/albums` | GET | `cursor`, `limit`, `channel`, `category` | Album summaries and cover/count | `albums`, `album_media`, `files` |
| `/albums/{publicId}` | GET | `cursor`, `limit` | Album details and paged visible media | same |
| `/gallery` | GET | `cursor`, `limit`, `type=image`, filters | Image/media cards | `files`, publication, category/channel/tag tables |
| `/search` | GET | `q`, `types[]`, `category`, `department`, `cursor`, `limit` | Ranked media/album/category results | `files`, albums, tags, AI detection, optional search log |
| `/categories` | GET | `include_counts` | Active categories and visible counts | `categories`, `files` |
| `/departments` | GET | none or `include_channels` | Departments/channels | `departments`, `channels` |
| `/me` | GET | Bearer token | Current profile/preferences | `user`, optional profile/preferences |
| `/me` | PATCH | Editable profile fields | Updated profile | `user`, `audit_logs` |
| `/notifications` | GET | `cursor`, `limit`, `unread_only` | User notification deliveries | notifications/deliveries |
| `/notifications/{id}/read` | PATCH | `{is_read:true}` | Updated read state | notification deliveries |
| `/media/{publicId}` | GET | none | Full safe metadata DTO | `files`, tags, passport-safe fields, category/channel |
| `/media/{publicId}/stream` | GET | Range headers | Binary/range stream or short signed URL | `files`, storage; record view separately |
| `/media/{publicId}/download` | GET | Bearer token | Binary/signed URL if downloadable | `files`, `media_downloads`, `audit_logs` |
| `/media/{publicId}/views` | POST | `{progress_seconds, completed, device_id}` | `204` | `media_views` |
| `/sync/changes` | GET | `since`, `cursor`, `limit` | Upserts plus deleted public-ID tombstones | `files`, albums, change/outbox table |

Existing admin CRUD endpoints (`/files`, `/folders`, `/tags`, `/recent`, `/audit-logs`) should remain for authorized admin/staff clients. Mobile feed endpoints must enforce publication visibility server-side rather than returning all active files.

### Representative JSON

Login request and response:

```json
{
  "employee_id": 20250001,
  "password": "user-entered-password",
  "device_name": "Ivan's Android"
}
```

```json
{
  "data": {
    "access_token": "opaque-short-lived-token",
    "refresh_token": "rotating-refresh-token",
    "token_type": "Bearer",
    "expires_at": "2026-08-04T12:00:00Z",
    "user": {
      "id": 20250001,
      "name": "Example User",
      "role": "school_user",
      "department": {"id": 4, "code": "CCS", "name": "College of Computer Studies"}
    }
  }
}
```

Home feed response:

```json
{
  "data": [
    {
      "type": "media",
      "id": "01JXYZ...",
      "media_kind": "image",
      "title": "Academic Opening 2026",
      "description": "Opening ceremony",
      "thumbnail_url": "https://cdn.example.edu/media/01JXYZ/v3/thumb.webp",
      "stream_url": "https://cdn.example.edu/media/01JXYZ/v3/display.webp",
      "downloadable": true,
      "published_at": "2026-08-04T09:30:00Z",
      "version": 3,
      "category": {"id": 2, "name": "Events"},
      "department": {"id": 4, "code": "CCS", "name": "College of Computer Studies"},
      "tags": ["opening", "students"],
      "ai": {"event": "academic_opening", "confidence": 94.2, "reviewed": true}
    }
  ],
  "meta": {"next_cursor": "eyJwdWJsaXNoZWRfYXQiOi...", "has_more": true}
}
```

Album detail response:

```json
{
  "data": {
    "id": "01JALBUM...",
    "name": "Academic Opening 2026",
    "description": "Official event collection",
    "cover_url": "https://cdn.example.edu/media/cover.webp",
    "items": [
      {"id": "01JMEDIA...", "media_kind": "video", "title": "Opening remarks", "thumbnail_url": "https://cdn.example.edu/t.webp", "stream_url": "https://cdn.example.edu/v.m3u8", "version": 1}
    ]
  },
  "meta": {"next_cursor": null, "has_more": false}
}
```

Sync response for edits/deletes:

```json
{
  "data": {
    "upserts": [{"id": "01JMEDIA...", "version": 4, "title": "Corrected title", "updated_at": "2026-08-04T10:10:00Z"}],
    "deletions": [{"id": "01JOLD...", "deleted_at": "2026-08-04T10:09:00Z"}]
  },
  "meta": {"next_sync_token": "2026-08-04T10:10:00Z:9182"}
}
```

## 6. Authentication architecture

Use **one users table** (`last_fm.user`) for both web and mobile identities. Separate user tables create password drift, deactivation delays, duplicate employee identities, and inconsistent roles.

- Web continues session-cookie + CSRF authentication.
- Mobile uses the same credential verification service but receives short-lived opaque bearer access tokens plus rotating refresh tokens stored in Expo SecureStore/Keychain, not AsyncStorage.
- Store only token hashes server-side. Revoke all sessions when an account is disabled/password-reset; support per-device logout.
- Use role/permission checks (`admin`, `staff`, `school_user`, `guest`) and audience/channel policies. A mobile user never gains admin rights merely by knowing an endpoint.
- Rate-limit login and search, lock/slow repeated failures, require HTTPS, configure a strict CORS policy for web clients, and do not expose DB credentials or server paths.
- If public guest browsing is required, provide deliberately public read endpoints limited to published content; do not fake login by navigating past the form as the current app does.

## 7. Final architecture

```text
                       React Native + Expo App
                    (SecureStore tokens, API only)
                                |
                              HTTPS
                                |
                    CDN / reverse proxy / rate limit
                       |                    |
              thumbnails/streams       REST /api/v1
                       |                    |
             Object/media storage     CodeIgniter 4 API
                                            |
             +------------------------------+---------------------------+
             |              |               |             |             |
          Auth/RBAC     Feed queries    Approval flow   AI workers   Audit/outbox
             |              |               |             |             |
             +------------------------------+---------------------------+
                                            |
                                    last_fm (canonical)
                       +--------------------+--------------------+
                       |                    |                    |
                 media/catalogue      workflow/provenance   mobile behavior
                 files, albums,       approvals, AI,        views, downloads,
                 tags, categories     passports, audit      comments, devices

             Admin Web Application (same CodeIgniter deployment)
                 sessions + CSRF, writes canonical records

             fm_system: migration source only -> archived read-only -> retired
             ZIP Express server: development reference only -> retired
```

## 8. Migration sequence

1. Back up both databases and media storage; record row counts and hashes.
2. Freeze writes to `fm_system` during the final cutover (or dual-record via an outbox during a staged migration).
3. Add target categories/departments/channels, canonical IDs, album junction, publication history, mobile behavior, devices, and missing FKs/indexes to `last_fm` through CodeIgniter migrations.
4. Reconcile users by employee ID, then verified email; never overwrite a valid `password_hash` with legacy plaintext.
5. Reconcile media primarily by strong content hash, then normalized storage path + size; never filename alone. Produce an old-ID to canonical-ID mapping table.
6. Merge metadata field-by-field, retaining provenance and resolving conflicts manually where hashes/paths disagree.
7. Migrate albums and membership using the media ID map; migrate categories, publication history, histories, comments, and optional playlists/search analytics.
8. Validate missing files on disk, orphan FKs, album counts, published counts, duplicates, and sample API payloads.
9. Extend CodeIgniter mobile endpoints; modify the Expo app to use `/api/v1`, real login, bearer headers, and public IDs. Remove hard-coded IP calls still present in `components/movieList.js` and `components/trendingMovies.js`.
10. Run parallel read comparison, then switch the mobile base URL. Make `fm_system` read-only for a retention window before archival/removal.

## 9. Risks found in the current mobile implementation

- Login ignores both entered fields and immediately opens the app; there is no real authentication.
- Express enables broad CORS and exposes `/media` directly from a local filesystem root.
- API and generated media URLs depend on a LAN address/configured laptop, unsuitable for production.
- Mobile reads only featured `album_files`, not the admin's canonical `files` approval state.
- Search downloads all albums and searches locally; this will become slow and memory-heavy.
- Home fetches the same `/albums` payload three times through three helper calls.
- Channel assignment is inferred from album text rather than a database relationship.
- All videos become Shorts regardless of duration/aspect ratio/editorial intent.
- Watch/download history trusts arbitrary device and file IDs and duplicates mutable media fields.
- Some components still contain the old hard-coded `http://172.16.152.47:3000` URL.

These are architectural reasons to consolidate behind the existing CodeIgniter API rather than connect the admin database to the current Express process with another set of ad-hoc queries.
