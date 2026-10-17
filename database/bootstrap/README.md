# Fresh database setup

These SQL files contain schema and default application settings, not historical users or media.

1. Create and select a new empty MySQL/MariaDB database.
2. Import `base-schema.sql`, then `addons.sql` into it.
3. Configure that database in the application's `.env`.
4. Run `php spark migrate` to apply the application's migration history.
5. Provision an initial administrator account with a password hash before signing in. No default administrator or password is included here.

Existing installations should run pending migrations without reimporting the baseline. The temporary passport `approval_status` column exists only to support historical migration ordering and is removed by a later migration.

The complete retired source, original SQL dumps, and local modifications are preserved in the local, git-ignored `writable/backups/legacy-source-20260910/` archive. This archive is outside the application's `public/` document root.
