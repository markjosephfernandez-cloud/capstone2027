# Multi-user performance review — 22 September 2026

## Main finding

The strongest observed bottleneck is synchronous Server Library thumbnail handling.
Normal pages and remote media requests share the PHP development-server processes.
When slow thumbnails occupy those processes, other users' page requests can queue.
The earlier change from one process to multiple workers increases concurrency, but
does not isolate slow media work or bound thumbnail demand across users.

This was a read-only inspection of application code, existing request captures,
server logs, process statistics, and database metadata/query plans. No application
code, database records, indexes, server settings, or media were changed. No load
test, user impersonation, upload, transfer, or NAS scan was performed.

## Recorded timings

The retained development-toolbar captures available during inspection contained
the following samples from 22 September. These are application processing times
after PHP starts a request, not browser end-to-end times; they exclude time spent
waiting for an available PHP process. The samples are limited and not a benchmark.

| Request | Captures | Median | Maximum |
| --- | ---: | ---: | ---: |
| Local Library page | 3 | 31.6 ms | 33.2 ms |
| Dashboard | 2 | 19.2 ms | 21.1 ms |
| Server Library page | 4 | 270.0 ms | 862.3 ms |
| Server folder counts | 31 | 101.2 ms | 2,494.4 ms |
| Server thumbnail | 22 | 22,984.3 ms | 24,484.1 ms |
| Server connection status | 14 | 10.1 ms | 28.8 ms |

All 22 captured media requests had `thumbnail=1`; none were downloads. Their start
times ranged from 21:23:04 to 21:24:37 Asia/Manila. Database query time within these
requests had a median of 2.22 ms and a maximum of 3.94 ms. Nearly all elapsed time
was inside the media controller. The current instrumentation cannot distinguish
remote path resolution, MIME detection, source reads, cache lookup, decoding, or
video extraction within that controller. It does not establish a NAS bandwidth
measurement or prove that all requests were cache misses.

The server log also records long accepted-to-close connection intervals during
this period. Those intervals are not treated as exact application execution times.

## Why additional users make it worse

1. `scripts/serve-local.sh` is active on port 8080 with four child workers plus the
   PHP parent, which also handles requests. All five processes share the listener.
2. `app/Views/workspace/server_library_files.php:10` uses native lazy image loading.
   There is no application-wide thumbnail concurrency limit. Several visible
   images, tabs, or users can request thumbnails simultaneously.
3. `app/Controllers/Web/ServerLibraryController.php:103` resolves the remote path,
   reads its MIME type, obtains or generates the thumbnail, and checks source
   availability before responding. This all happens within a page-serving worker.
4. `app/Services/ThumbnailService.php:14` already caches derived images locally,
   but source metadata is read before using the cache. The controller also reads
   MIME information before asking for the cached derivative. Cached derivatives
   therefore do not completely remove remote filesystem work.
5. `app/Services/VideoPreviewService.php:27` can wait for a poster-generation lock
   and run FFmpeg inside the request. Full Local Library video conversion can also
   wait or transcode synchronously. These are additional worker-occupancy risks;
   the retained thumbnail timings do not identify which decoder was responsible.

Cancellation stops browser work and new requests. It cannot immediately interrupt
an operating-system filesystem call already executing inside PHP. The ten-second
cooperative budget applies to listings and counts, not thumbnail generation.

## Secondary findings

- The database connected in 1.7 ms during inspection. Its process list showed no
  long-running application query at that instant. Its global slow-query counter
  was zero, which alone is not proof that all database work is fast.
- Notifications have only a primary-key index. The unread-count query plan scans
  approximately 1,237 rows, and the header repeats notification queries on page
  renders. Composite indexes matching recipient/read state and recipient/date
  are candidates as usage grows. No index was created during this review.
- Local Library loads all active folder metadata and recomputes summaries per
  page. The inspected local dataset is small (approximately 107 file rows and four
  folder rows), so this is not the measured primary bottleneck today.
- The app is configured for development. The shell PHP configuration has CLI
  OPcache disabled. Development collectors and repeated compilation add overhead,
  but the recorded bootstrap/filter timings do not explain the 23-second media
  requests.
- Upload processing and copy/send remain synchronous and retain the user's
  session during substantial work. A long-held session lock serializes requests
  from that same browser session, not every user's session. Waiting requests can
  nevertheless consume the shared workers. Server browsing/media endpoints
  already close the session before filesystem processing.
- This host has 8 GiB RAM and roughly 33 GiB available disk space. Memory
  compression and historical swapping were present. PHP/MySQL CPU readings were
  low at the inspection instant; a one-second sample of one worker was idle in
  the server event loop. This snapshot does not establish CPU or memory as the
  cause of the earlier delays.

## Recommended order of work

1. Instrument thumbnail stages to separate mount/path checks, MIME detection,
   cache hits, source decoding, and video extraction. Record durations and status
   without logging credentials or personal file paths.
2. Reduce preview demand and reserve capacity for ordinary pages: cap browser
   thumbnail concurrency, deduplicate generation, and move slow generation to a
   bounded background queue or separately limited media worker pool. A browser
   limit alone is insufficient when many users are active.
3. Make cached thumbnail retrieval cheap while preserving authentication, source
   access checks, and the existing offline-source rules. Do not remove security
   checks simply to improve timings.
4. For sustained multi-user hosting, configure a supported production web server
   and appropriate PHP workers/OPcache, with separate limits for media jobs.
   Increasing the development worker count alone can increase memory and NAS
   contention without fixing the underlying slow reads.
5. Add notification indexes through a reviewed migration and revisit repeated
   aggregates/session duration. Measure real page timings afterward.

No safe maximum user count can be derived from these samples. Establish that with
a controlled test covering independent user sessions, cached/uncached media,
uploads, and ordinary navigation after the media bottleneck is addressed.

PHP documents the built-in server and its multi-worker mode as development/testing
features, not production hosting: [PHP built-in web server manual](https://www.php.net/manual/en/features.commandline.webserver.php).
