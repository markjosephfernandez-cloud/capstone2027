   # Greyhound CodeIgniter 4 API

   For a repository-wide, AI-friendly description of the backend, mobile app,
   AI classifier, data model, workflows, risks, and planning constraints, see
   [docs/AI_PROJECT_CONTEXT.md](docs/AI_PROJECT_CONTEXT.md).

   This is the CodeIgniter 4 migration of the Greyhound backend. The original
   flat-PHP application is preserved under `legacy/` as a reference until its UI
   is migrated to this API.

   ## Run locally

   1. Import `legacy/database/last_fm (2).sql` if the `last_fm` database is not
      already present. Also import `legacy/database/2026_addon_migration.sql` if
      the passport, watermark,
      approval, and audit modules are needed.
   2. Copy `env.example` to `.env` and set the database and mounted media root.
      The local `.env` supplied for this workspace already targets XAMPP's
      `last_fm` database.
   3. Run the safe, additive migration:

      ```powershell
      php spark migrate
      ```

   4. Start the application:

      ```powershell
      php spark serve
      ```

      For Apache, point the virtual host's document root at `public/`, never at
      this project directory.

   The storage root defaults to `writable/uploads` only for local development.
   Use a mounted, non-public media directory in production and set
   `greyhound.legacyStorageRoots` while existing `files.filepath` records still
   refer to the legacy mount.

   ## API contract

   All API responses are JSON and use the `/api/v1` prefix. Obtain a bearer token
   with `POST /api/v1/auth/login`, then send it as:

   ```http
   Authorization: Bearer <access_token>
   ```

   `GET /api/v1/health` and `POST /api/v1/auth/login` are the only public routes.
   The role stored in `user.role` governs writes: `admin` and `staff` may manage
   media; only `admin` may read audit logs. A successful login upgrades a legacy
   plaintext password into `user.password_hash`; plaintext values are never
   returned by the API.

   | Area | Routes |
   | --- | --- |
   | Auth | `POST /auth/login`, `GET /auth/me`, `POST /auth/logout` |
   | Files | `GET, POST /files`, `GET, PATCH /files/{id}`, `POST /files/{id}/rename`, `/move`, `/trash`, `/restore`, `GET /download` |
   | Folders | `GET, POST /folders` |
   | Tags | `GET /tags`, `PUT /files/{id}/tags` |
   | Activity | `GET /recent`, `GET /audit-logs` (admin) |

   See [docs/api.md](docs/api.md) for request examples and response shapes.

   ## Migration notes

   `CreateApiFoundation` is deliberately backwards-compatible. It adds API-token
   storage, a password-hash field, the missing metadata fields used by existing
   scripts (`files.tags`, `files.downloadable`, `folder.path`), and indexes. It
   does not alter or delete existing media rows. Existing absolute legacy paths
   are never sent to clients and are only served when they resolve under a
   configured storage root.

   ## Greyhound Hub 2.0 operational features

   The CI4 web application now provides the following upload-time workflow:

   1. Event classification for **Dal-uy Events**, **Kasadyaan Celebrations**, and
      **EXPO Activities**. The supplied local baseline uses transparent filename,
      description, and tag evidence; results below 90% are flagged for review.
   2. A metadata passport for every new upload, including origin, rights, event
      result, approval state, and a SHA-256 integrity seal. Administrators can use
      **Metadata Passports → Generate missing passports** for pre-existing media.
   3. Meaningful action auditing (authentication, upload, download, deletion,
      metadata processing, watermarking, approvals, and privacy acknowledgement)
      plus an in-app data-privacy reminder for users who have not acknowledged the
      current notice.
   4. A non-destructive FU Creatives watermark derivative for designated JPEG,
      PNG, and WebP uploads. Administrators can exclude an individual image at
      upload time.
   5. Automatic video-release workflows: **Creative Staff → Marketing →
      Administrator**. Each decision is recorded with notes and a SHA-256 sign-off
      value.
   6. Indexed active-media listings and CodeIgniter 4 search routes.

   ### Connecting a trained event model

   Set `greyhound.eventModelEndpoint` in `.env` to an HTTPS inference endpoint
   after the university-event dataset has been trained and validated. Greyhound
   sends a multipart `POST` containing `media` and `media_id`. The endpoint must
   return JSON in this shape:

   ```json
   {
   "event_name": "Kasadyaan Celebrations",
   "confidence_score": 94.6,
   "model_version": "fu-events-v1",
   "evidence": [{"type": "model", "value": "event visual features"}]
   }
   ```

   `event_name` must be one of the three configured events and confidence must be
   between 0 and 100. If the endpoint is unavailable, upload remains available and
   the local reviewable baseline is used instead.

   The current integrity seal is **not a formal C2PA manifest**. To claim C2PA
   compliance, provide a trusted certificate and signing service, then connect it
   to the passport-generation service. The interface deliberately labels these
   records “C2PA signing pending” until that is done.
