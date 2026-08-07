l# Greyhound Hub: Complete AI Project Context

> Purpose: give an AI assistant enough reliable context to analyze, discuss, and plan changes to this repository without first reverse-engineering the whole project. This is a code-informed snapshot dated 2026-08-05. When this document conflicts with executable code, migrations, or configuration, the code is authoritative.

## 1. Project in one paragraph

Greyhound Hub is Foundation University's digital asset management and publishing system. It combines a CodeIgniter 4 web administration application and versioned REST API, an Expo/React Native media-browsing app, and a local Python/TensorFlow event-classification service. Staff upload and organize institutional images, videos, audio, and documents; the backend creates provenance passports, applies optional branded watermarks, runs AI event classification, sends publishable media through staged approval, maintains audit and privacy records, and exposes approved content to the mobile app. A previous flat-PHP application and a previous Express/MySQL mobile server remain in the repository as migration references, not as the desired production architecture.

## 2. Vocabulary and product intent

- **Greyhound Hub**: the overall digital asset management product.
- **Admin/web app**: the server-rendered CodeIgniter interface used by administrators and staff.
- **API**: the CodeIgniter JSON interface under `/api/v1`.
- **Mobile app**: the Expo/React Native client in `greyhound-app-2025-main/`.
- **AI service**: the local ResNet-50 HTTP inference process in `greyhound-hub-2.0/`.
- **Media/file**: the canonical database record in `files` and its storage object.
- **Folder**: a hierarchical logical/storage container.
- **Passport**: provenance, rights, AI, approval, and integrity metadata associated with a media item. It is not currently a formally signed C2PA manifest.
- **Approval**: a staged publication request. Video follows Creative Staff -> Marketing -> Administrator. Other media may use a shorter publication workflow.
- **Channel**: a publication audience/category such as Creative or a department channel; this is distinct from a storage folder.
- **Active media**: not soft-deleted. **Published media** is additionally approved and visible through mobile/public feed rules.

The architectural goal is one canonical database (`last_fm`), one user identity, one media ID, and one publication/deletion state, all accessed by clients through the CodeIgniter application. Direct mobile-to-MySQL access and the old Express API are not target architecture.

## 3. Repository map and authority

| Path | Responsibility | Status / authority |
| --- | --- | --- |
| `app/` | CodeIgniter controllers, services, models, migrations, configuration, views, filters, commands | Active canonical backend |
| `public/` | Web document root, front controller, static assets | Active; Apache must point here |
| `writable/` | Runtime cache, logs, sessions, uploads, generated artifacts | Runtime data; do not treat as source |
| `tests/` | PHPUnit tests and support code | Active but currently sparse |
| `docs/` | Architecture and project documentation | Informational; distinguish proposals from code |
| `greyhound-app-2025-main/` | Expo 54 / React Native 0.81 mobile app; also contains old Node servers | Mobile client active; embedded Express servers are legacy/reference |
| `greyhound-hub-2.0/` | TensorFlow training, testing, inference server, models, dataset | Active optional AI subsystem |
| `legacy/` | Original flat-PHP application, SQL dumps, migration/reference scripts | Reference/migration source only |
| `Department Logo/` | FU, CCS, CBA, CHM, and CN watermark logos | Source assets |
| `.env` | Local secrets and machine-specific configuration | Never copy into prompts/docs/commits |
| `env.example` | Safe configuration template | Canonical setup reference |

The root `README.md` is a concise operational overview. `docs/mobile-integration-architecture.md` is a detailed consolidation proposal: many observations are current, but its suggested public IDs, refresh tokens, sync/outbox, albums redesign, and extended behavior schema must not be assumed implemented unless migrations/code confirm them.

## 4. System topology

```text
Admin browser
  | session cookie + CSRF
  v
CodeIgniter 4 web controllers/views --------+
                                             |
Expo mobile app                              | business services
  | HTTPS JSON + bearer token                |
  v                                          v
CodeIgniter /api/v1 -------------------- last_fm MySQL
  |                                          |
  +---- controlled media reads ---------- media storage root
  |
  +---- multipart image request ----> Python AI service :5001
                                         |
                                         +--> Keras ResNet-50 model
```

Production assumptions: TLS terminates at the web server/reverse proxy; only `public/` is web-accessible; media is preferably mounted outside the public tree; database and filesystem paths are never returned directly; the Python service binds to loopback unless deliberately deployed otherwise.

## 5. Technology stack

### Backend

- PHP `^8.2`, CodeIgniter `^4.7`, Composer.
- MySQL/MariaDB through CodeIgniter's database layer.
- Server-rendered PHP views, session authentication, CSRF on web mutations.
- Opaque bearer API tokens stored as hashes, with expiry/revocation.
- GD and/or configured image tooling for watermark derivatives; ZIP support is used for bulk download.
- PHPUnit `^10.5.16` for tests.

### Mobile

- Expo `~54.0.36`, React `19.1`, React Native `0.81.5`.
- React Navigation 6 (native stack and bottom tabs).
- `expo-secure-store` for access tokens; AsyncStorage is used for non-secret local state/configuration.
- `expo-video`, media library, file system, image picker, thumbnails, SVG, and common UI dependencies.
- The API base URL is user/configuration controlled through `utils/apiConfig.js`; avoid adding hard-coded LAN addresses.

### AI

- Python, TensorFlow/Keras, NumPy; training utilities also expect OpenCV, Matplotlib, scikit-learn, pandas, and Pillow.
- ResNet-50 transfer-learning architecture, 224x224 input, softmax event classes.
- Standard-library `ThreadingHTTPServer`; no Flask dependency.

## 6. Backend architecture

### Request layers

1. `app/Config/Routes.php` is the complete explicit route registry; auto-routing is disabled.
2. Filters enforce API CORS/authentication and inactive-account rejection; web POST routes declare the CSRF filter.
3. Controllers parse requests, authorize actors, invoke services/models, and select JSON responses or PHP views.
4. Services contain reusable rules for credentials, tokens, storage, audit, consent, trash, approvals, passports, AI detection, and watermarking.
5. Models wrap the core `user`, `files`, `folder`, `tag`, `recent`, and `api_tokens` tables.
6. Migrations are additive compatibility layers over the imported legacy database.

### Core services

| Service | Responsibility |
| --- | --- |
| `CredentialsService` | Validate password; supports legacy credentials and upgrades successful plaintext authentication to `password_hash` |
| `TokenService` | Issue opaque access tokens, store hashes, authenticate bearer values, revoke tokens, return safe user payloads |
| `StorageService` | Normalize safe relative paths, constrain resolution to configured roots, store/rename/move uploads, avoid filename collisions |
| `AuditService` | Write actor/request/module/action/target operational records |
| `ConsentService` | Determine and record acceptance of the current privacy notice |
| `EventDetectionService` | Call trained model when possible, fall back to metadata/filename evidence, merge automatic tags, persist detection |
| `PassportService` | Generate/backfill/review passports and immutable versions; calculate SHA-256 content integrity |
| `WatermarkService` | Resolve department logos/settings and generate persistent or on-demand non-destructive derivatives |
| `ApprovalService` | Start individual/group workflows, enforce reviewer stage/role, record signed decisions, publish on completion |
| `TrashService` | Apply retention rules and permanently remove managed artifacts plus dependent database records |

### Web feature areas

- Login/logout and dashboard.
- File management: nested folders, uploads, filters/listing, previews, downloads, rename/move, bulk ZIP download, bulk trash.
- Recent activity and iFound/search view.
- Trash: retention display, restore, bulk restore, permanent delete, bulk permanent delete.
- Metadata passports: list, backfill missing, AI reanalysis, human review.
- Watermarking: settings, logo previews, rebuild defaults, download with selected logo.
- Approvals: single, folder, and bulk requests; stage decisions; privileged direct/bulk publish.
- Audit logs.
- Administrator control center: user creation/profile changes, activate/deactivate, password resets, token revocation, consent reminders, expired-trash purge.
- Some sidebar feature names route to a generic pending-feature page and are not implemented modules.

### Roles and access

- `admin`: full administration, audit access, final approval/publishing, destructive maintenance.
- `staff`: media management and relevant workflow actions.
- `school_user`: mobile/end-user identity and permitted public/personal interactions.
- `guest`: conceptual/public browsing role where routes deliberately permit it.

Exact enforcement lives in controllers and filters. Do not infer permission solely from navigation visibility. Accounts can be deactivated; deactivation and password reset should invalidate tokens. Web uses sessions; API uses bearer tokens. Public mobile feed/read routes return only publication-safe media.

## 7. HTTP interfaces implemented now

All API JSON uses the `/api/v1` prefix. `ApiController` standardizes success as a `data` value with optional `meta`; failures contain a stable error code/message and may include details. Page size is bounded server-side. Send `Authorization: Bearer <token>` on protected routes.

### Public API routes

- `GET /health`
- `POST /auth/login`
- `OPTIONS` preflight routes
- `GET /mobile/home`
- `GET /mobile/shorts`
- `GET /mobile/channels`
- `GET /mobile/search?q=...`
- `GET /mobile/media/{id}/preview`
- `GET /mobile/media/{id}/stream`
- `GET /mobile/media/{id}/comments`
- `GET /mobile/profile/avatar/{employeeId}`

Although routes are public, media controllers still reject deleted, draft, withdrawn, wrong-kind, or otherwise non-published content. Stream supports range semantics where applicable; preview is image-oriented.

### Authenticated mobile routes

- `GET /auth/me`, `POST /auth/logout`
- `GET /mobile/media/{id}/engagement`
- `POST /mobile/media/{id}/like`
- `POST /mobile/media/{id}/comments`
- `GET|POST /mobile/channels/{channel}/subscription`
- `GET /mobile/media/{id}/download`
- `GET|POST /mobile/search/history`
- `GET|PATCH /mobile/profile`
- `POST /mobile/profile/avatar`

### Authenticated management API routes

- Files: list/upload/show/update/rename/move/trash/restore/download under `/files`.
- Folders: list/create under `/folders`.
- Tags: list and replace a file's tags.
- Activity: `/recent`; admin-only `/audit-logs`.

The management API is not a complete mirror of every server-rendered admin action. Approval, passport, watermark, user-administration, and permanent-delete operations are primarily web routes today.

### Login contract

The login request takes an employee ID and password. A successful response includes an opaque access token, expiration, and a safe user object. Store the access token in SecureStore and never log it. The current backend implements access tokens; refresh-token/device-session architecture described in the planning document is a recommendation, not an implemented guarantee.

## 8. Data model

The legacy SQL dump establishes the baseline schema; CodeIgniter migrations extend it. Run migrations in timestamp order and inspect the database after importing the legacy dump. Principal tables are:

| Domain | Tables | Meaning |
| --- | --- | --- |
| Identity/security | `user`, `api_tokens`, `privacy_consents` | employee identities, roles/status, password hashes, bearer token hashes, policy acceptance |
| Media/catalog | `files`, `folder`, `tag`, `recent` | canonical media metadata/path/state, nested folders, tags, recent access |
| AI/provenance | `ai_event_detections`, `passports`, `passport_versions` | prediction/evidence/review state and versioned provenance/integrity |
| Publishing | `approvals`, `approval_steps`, `notifications` | grouped or individual staged decisions, channels, notifications |
| Derivatives | `file_watermarks` plus watermark settings | generated branded versions without overwriting originals |
| Audit | `audit_logs` (and legacy `activity`) | canonical operational/security history; legacy activity is transitional |
| Mobile community | likes, comments, subscriptions, search history, profile/avatar support created by the 2026-08-05 migration | end-user engagement and personalization |
| Legacy mobile | `albums`, `album_files` and `fm_system` equivalents | duplicated historical model; consolidation target, not preferred source of truth |

Important `files` concepts include ID, filename, safe stored filepath, MIME/file type, size, folder/uploader, description/tags, downloadable flag, deletion state/time, publication channel/status/time, and fields used by AI/passports. Treat the actual migrations/models as the exact column authority.

### Migration history and intent

- `2026-07-31 CreateApiFoundation`: token storage, password hash and missing media/folder API fields/indexes.
- `2026-08-01 AddGreyhoundTwoOperationalFeatures`: AI detection, passports/versions, watermark records/settings, approvals/steps, notifications, privacy consent, audit and media metadata needed by operational modules.
- `2026-08-02 AddPublicationChannels`: publication-channel and visibility lifecycle.
- `2026-08-03 AddNestedFolders`: parent relationships and hierarchy support.
- `2026-08-03 GroupApprovalRequests`: folder/bulk grouped approvals.
- `2026-08-03 SetDefaultLogoWatermark`, `EnsureVisibleLogoWatermark`: watermark defaults/fixes.
- `2026-08-03 AddPassportReviewFields`: human review metadata.
- `2026-08-03 AddUserAdministrationFields`: profile, status, and administration support.
- `2026-08-04 AddMobilePublishingFields`: mobile-facing publication metadata.
- `2026-08-04 AddMimeTypeToFiles`: reliable MIME/media-kind behavior.
- `2026-08-05 CreateMobileCommunityTables`: engagement, comments, subscriptions, search/profile community data.

### Invariants for planning and implementation

- `files` in `last_fm` is the canonical media catalog.
- Never expose an absolute storage/database path to a client.
- Resolve stored paths only within configured storage/legacy roots.
- Soft deletion must hide content from normal and mobile queries.
- Publication visibility is enforced server-side, not trusted to clients.
- Originals are preserved; watermarks are derivatives.
- Audit/passport versions are historical evidence and should be append-oriented.
- AI confidence is advisory; uncertain outputs require review and uploads must survive model failure.
- Passwords and bearer tokens must never be stored or returned plaintext. The only legacy plaintext password handling is one-way upgrade after successful verification.

## 9. Key workflows

### Upload

1. An authenticated authorized actor uploads one or more files, optionally into a nested folder.
2. `StorageService` sanitizes names, validates the relative destination, avoids collisions, and writes the original.
3. A `files` record is created with uploader, description/tags, MIME/kind, download and publication metadata.
4. Post-upload automation attempts AI event classification and persists detection/evidence/tags.
5. A metadata passport and SHA-256 integrity value are generated.
6. Eligible Creative/department images receive a configured watermark derivative unless explicitly excluded.
7. A video can enter the staged approval workflow; other media can be submitted/published through publication controls.
8. Meaningful actions are audited. AI or derivative failure should be reported/reviewable without losing the original upload.

### Approval and publication

Requests may target a file, selected files, or a folder group and carry a publication channel. Each stage decision stores reviewer, decision, notes, timestamp, version/signature hash. Review authority depends on the active stage. Completion updates publication state so content becomes eligible for the mobile feed; rejection/withdrawal must keep it hidden.

### Trash lifecycle

Trash is soft deletion with a configured retention interval. Authorized actors may restore before expiry. Permanent deletion removes only paths known to be managed artifacts (original/derivatives) and cleans dependent rows transactionally where possible. The `purge:trash` command and admin maintenance action remove expired items in bounded batches. Never recursively delete a broad storage directory.

### Passport and AI review

Passports can be generated for missing historical items. AI-missing media can be reanalyzed through the web action or `media:reanalyze`. Human review can correct/confirm event and rights metadata and creates a version snapshot. The integrity hash demonstrates content equality but the application accurately labels formal C2PA signing as pending.

### Mobile consumption

The client loads public home/short/channel/search payloads, then uses authenticated calls for likes, comments, subscriptions, downloads, search history, and profile changes. The backend generates client URLs and filters publication state. Downloads honor the media's `downloadable` policy. Mobile must not construct server filesystem URLs.

## 10. Mobile application

`App.js` loads `navigation/appNavigation.js`. The root stack contains Splash, Login, a four-tab main shell, detail/search/settings/history screens, and compatibility routes. Main tabs are Home, Shorts/Videos, Subscriptions/Albums, and Profile.

Important locations:

- `api/server.js`: shared API transport and response adapters. Despite its name, this is client code.
- `utils/apiConfig.js`: base URL loading/normalization.
- `utils/auth.js`: token/auth helpers.
- `utils/deviceId.js`: installation/device identifier for legacy-compatible behavior.
- `screens/`: feature screens.
- `components/`: reusable cards, headers, lists, comments, banners, and empty/loading states.
- `constants/channels.js`: channel normalization/mapping.
- root `server.js`, `server2.js`: old Express/MySQL media servers; do not extend for new canonical features.

Some helpers retain comments or functions for old `/albums` and `/history/*` behavior. Current feed helpers adapt `/mobile/home` results into album-like groupings for UI compatibility. Before planning mobile changes, trace the actual screen import and endpoint call; do not assume every legacy helper is reachable. Errors currently become generic thrown messages, so future planning should consider typed errors, authentication expiry, retry/offline state, and centralized observability.

## 11. AI event-classification subsystem

`greyhound-hub-2.0/api_server.py` loads `greyhound_events_model.keras`, reconstructing the training architecture before loading weights to bridge Keras serialization-version differences. It exposes:

- `GET http://127.0.0.1:5001/health`
- `POST http://127.0.0.1:5001/predict` as multipart form data with a `media` part

Current model class order is fixed as `dal-uy`, `graduation`, `kasadyaan`, mapped to display names. Changing order without retraining breaks predictions. Confidence below the default 80% returns `event_name: null` while retaining predicted class probabilities as evidence. Environment variables `GREYHOUND_AI_HOST`, `GREYHOUND_AI_PORT`, and `GREYHOUND_AI_MIN_CONFIDENCE` configure the service.

The CodeIgniter integration endpoint/timeout and allowed event names live in `app/Config/GreyhoundAi.php` and environment configuration. Documentation elsewhere mentions EXPO, but the deployed Python model shown in code has three output neurons and does **not** currently include EXPO. Treat this as a known model/product mismatch. Adding a class requires a correctly split dataset, retraining, deploying a matching model, updating the fixed inference architecture/class mapping, and aligning backend accepted labels.

The service has no authentication, request-size control, or production process supervisor in its own implementation; keep it loopback/internal behind the backend. Image decoding failures return 422; unexpected inference failures return 500. Backend fallback behavior is intentional.

## 12. Configuration and local operation

Never include real `.env` values in AI context. Required categories from `env.example`/config include:

- application base URL and environment;
- database hostname/name/user/password/port;
- writable storage root and any explicitly allowed legacy storage roots;
- API token lifetime;
- CORS allowed origins;
- privacy notice version;
- trash retention days;
- AI endpoint, timeout, and minimum confidence;
- watermark enablement, logo, opacity, scale, position, padding, and renderer/CLI details.

### Backend setup

1. Install PHP 8.2+, Composer dependencies, MySQL/MariaDB, and required PHP extensions.
2. Import `legacy/database/last_fm (2).sql` for a fresh compatible baseline. Import `legacy/database/2026_addon_migration.sql` only where required by the baseline/setup instructions; migrations remain the forward schema authority.
3. Copy `env.example` to `.env` and supply local values. Do not commit it.
4. Run `php spark migrate`.
5. Run `php spark serve` for development, or configure Apache with `public/` as document root.
6. Optionally start the AI service before image upload. Backend upload must still work when it is absent.

Typical checks:

```powershell
composer install
php spark migrate
php spark serve
composer test
php spark routes
php spark ai:reanalyze-media
php spark trash:purge --limit 500
```

Confirm exact command names/options with `php spark list`/`php spark help <command>` before automation.

### Mobile setup

```powershell
cd greyhound-app-2025-main
npm install
npm start
```

Set the app's API URL to the CodeIgniter `/api/v1` base reachable from the emulator/device. `127.0.0.1` on a physical device is the phone itself, not the development PC. Android emulator and LAN testing need an appropriate host address; production requires HTTPS.

### AI setup

Use a Python version compatible with the pinned/installed TensorFlow build, create a virtual environment, install TensorFlow and documented scientific dependencies, then run `python api_server.py` from `greyhound-hub-2.0/`. Model files are large Git LFS assets. Verify `/health` before relying on trained inference.

## 13. Testing and validation

Current automated coverage is limited: `tests/unit/HealthTest.php` plus CodeIgniter sample tests/support. Any material plan should budget for tests rather than assuming coverage exists.

High-value backend tests:

- credential upgrade, inactive accounts, token expiry/revocation, and role boundaries;
- path traversal, allowed roots, filename collision, range requests, download rules;
- upload rollback/failure and AI unavailable/low-confidence branches;
- publication filtering across every public endpoint;
- approval stage transitions, concurrent/double decisions, group requests;
- trash restore/expiry/permanent deletion without deleting unmanaged paths;
- passport hash/version behavior and watermark non-destruction;
- pagination, search filters, CORS, CSRF, and response contracts.

High-value mobile tests: auth persistence/expiry, endpoint adapters, empty/error/loading states, navigation, download permission, and URL configuration. AI validation should report per-class precision/recall/confusion matrix on a held-out deduplicated set, not training accuracy alone.

## 14. Security and privacy boundaries

- Serve only `public/`; never expose project root, `.env`, `writable`, SQL dumps, model datasets, or legacy scripts.
- Require HTTPS in production and configure CORS to explicit origins.
- Keep session/CSRF web auth and bearer API auth conceptually separate.
- Validate MIME from content/server inspection, extension, size, and authorization; do not trust client values.
- Use prepared query builders/models and output escaping in views.
- Keep originals and storage roots non-public; stream through authorized code or short-lived signed URLs if added later.
- Store only password/token hashes. Redact credentials, tokens, paths, personal data, and request bodies from logs.
- Treat employee IDs, profile data, comments, search history, downloads, consent, and audit records as personal/institutional data with retention and access policies.
- The repository contains SQL dumps and large datasets/models. Review them before sharing the repository or sending files to third-party AI systems.
- AI classification must remain assistive, explainable through evidence, and human-reviewable; it should not silently publish content.

## 15. Known gaps, inconsistencies, and technical debt

1. The canonical-data consolidation is incomplete historically: legacy `fm_system`, duplicated album/media structures, and old Express endpoints remain.
2. Existing architecture prose includes proposed endpoints/schema that exceed current implementation. Always verify against routes and migrations.
3. Mobile compatibility adapters still use album terminology and retain old history helpers/comments.
4. Current Python inference supports three classes, while broader product text sometimes names EXPO as a fourth/alternative event.
5. Confidence thresholds differ across older prose (80% versus 90%); runtime configuration/code is authoritative.
6. Passport integrity is SHA-256 metadata, not trusted C2PA signing.
7. Automated tests are far below the risk surface.
8. API management coverage does not yet expose every web capability.
9. Public numeric database IDs are used by current mobile routes; stable opaque public IDs are a proposed evolution.
10. Refresh tokens, per-device sessions, robust offline sync/outbox, CDN/object storage, and publication history are architectural recommendations unless code is added.
11. The legacy folder and SQL naming contains spaces and unusual filenames; scripts must quote paths.
12. Large Keras assets use Git LFS; Git operations can fail when LFS permissions/downloads are unavailable.

## 16. Planning guidance for an AI

Before proposing a change:

1. State whether it affects the active backend, active mobile client, AI subsystem, or legacy/reference code.
2. Inspect `Routes.php`, relevant controller/service/model, latest migrations, and the consuming screen. Do not plan from this document alone.
3. Classify the idea as current defect, incremental enhancement, schema migration, or target-architecture work.
4. Preserve backward compatibility with imported `last_fm` data and existing stored paths.
5. Include data migration/backfill, rollback, authorization, privacy, audit, and storage implications.
6. For schema work, use a new additive CodeIgniter migration; never edit an already-deployed migration as the only solution.
7. For mobile API changes, specify route authentication, request/response examples, publication filters, pagination, error states, and client adoption.
8. For uploads/AI/watermarks, design partial-failure and retry behavior so originals are not lost or duplicated.
9. For deletion, enumerate exact database rows and physical artifacts and make the operation idempotent and bounded.
10. Add proportional automated tests and a manual verification checklist.

Recommended sequence for major consolidation work:

1. Inventory production schemas/media and back them up.
2. Make `last_fm.files` and CodeIgniter unmistakably canonical.
3. Add missing relational constraints/indexes and stable identity fields through migrations.
4. Reconcile/migrate users and media by employee ID and strong content hash, never filename alone.
5. Migrate albums/behavior with explicit old-to-new ID maps and validation reports.
6. Complete CodeIgniter mobile endpoints and switch every screen to the shared client.
7. Compare reads during cutover; make old stores read-only, then archive after a retention window.

## 17. Definition of done for typical changes

A backend feature is done when its route, authorization, validation, service/domain behavior, migration/backfill (if needed), audit behavior, error contract, tests, and documentation agree. A mobile feature is done when it uses the shared configurable API client, handles loading/empty/error/auth-expiry states, has no hard-coded host, and works on a real device. An AI feature is done when labels and output schema align end-to-end, held-out evaluation is recorded, low-confidence/model-down behavior is safe, and deployment/version rollback is defined.

## 18. Fast source index

- Routes: `app/Config/Routes.php`
- Runtime configs: `app/Config/Storage.php`, `GreyhoundAi.php`, `Cors.php`, `Filters.php`, `Security.php`, `Session.php`, `Database.php`
- Web controllers: `app/Controllers/Web/`
- API controllers: `app/Controllers/Api/`
- Domain services: `app/Services/`
- Database evolution: `app/Database/Migrations/`
- Server-rendered UI: `app/Views/`
- Mobile navigation/screens: `greyhound-app-2025-main/navigation/`, `screens/`
- Mobile API/config/auth: `greyhound-app-2025-main/api/server.js`, `utils/`
- AI inference/training: `greyhound-hub-2.0/api_server.py`, `train_model.py`, `test_model.py`
- Legacy schema/source: `legacy/database/`, `legacy/`
- Detailed consolidation proposal: `docs/mobile-integration-architecture.md`

## 19. Suggested prompt to accompany this file

> Treat `AI_PROJECT_CONTEXT.md` as orientation, not proof that every proposed feature exists. When planning, separate current implementation from target architecture, cite the repository files that establish current behavior, identify assumptions, and produce phased changes including schema migration, API contract, authorization, failure handling, tests, data validation, rollback, and mobile adoption. Do not expose secrets or recommend direct client database access.
