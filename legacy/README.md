# Legacy flat-PHP archive

This folder contains the original Greyhound application exactly as it existed
before the CodeIgniter 4 reorganization. It is retained for reference while
its UI features are migrated to the CI4 API at the project root.

- `database/` contains the original SQL dumps and add-on migration.
- The root PHP scripts, `actions/`, `assets/`, `photos/`, and AI files remain
  here; they are no longer part of the active application entry point.
- `.venv/` and `venv/` are the original local Python environments.

Do not delete this folder until every needed legacy page and AI workflow has
been replaced and tested in the CI4 application.
