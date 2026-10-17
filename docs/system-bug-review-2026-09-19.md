# System bug review — 2026-09-19

## Confirmed issues fixed

1. **Super Admin could not rename files through the file API.** The rename action
   omitted `super_admin` from its allowed roles while the other file-management
   actions included it. The action now allows Super Admin, Admin, and Staff.
   Department and Writer remain denied. A regression test reproduced the 403
   before the correction and verifies the intended role boundary afterward.
2. **iFound tried to create a disconnected network inbox locally.** The configured
   inbox `/Volumes/creative` did not exist, and the logs recorded three permission
   failures from starting scans. A missing external inbox now produces a clear
   reconnect message without attempting to create the directory. Local scan
   inboxes can still be created. Regression tests cover both cases.

## Verification

- Full PHP suite: **187 tests, 1,041 assertions passed**.
- JavaScript workflow suite: **60 tests passed**. Covers preview navigation,
  filters, uploads, cancellation, and thumbnail behavior.
- PHP syntax: **182 application files passed**; changed PHP files were also
  checked after the fixes.
- Mobile source syntax: **73 JavaScript/TypeScript files parsed successfully**
  using the installed Babel parser, excluding dependencies and generated trees.
- Live HTTP checks: login 200; API health 200 with database `ready`; unauthenticated
  File Management and Distribution redirected to login; unauthenticated file API
  returned 401.

The PHP suite includes coverage for department copy independence, authorization,
Trash and restoration, distribution thumbnails, pagination, publishing, uploads,
watermarks, storage paths, and media streaming. Recent logs were reviewed and
test-generated errors were distinguished from application failures.

## Remaining dependency and limits

The network share for `/Volumes/creative` must be mounted again before iFound can
scan its contents. This audit did not connect external storage or change its
configured location.

Automated checks and code review do not establish that every workflow is free of
bugs. This pass did not include a signed-in browser walkthrough, a mobile-device
run, sustained load testing, or destructive operations on live media. No live
media or transaction records were deleted as part of this audit.
