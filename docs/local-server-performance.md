# Local server performance

On macOS/Linux, start the application with:

```sh
sh scripts/serve-local.sh
```

This preserves port 8080 and the existing LAN address, and starts four PHP
workers so thumbnail generation and downloads can run alongside page requests.
Stop the old `php spark serve` process before starting this command. Do not run
both on the same port. Keep the command running in a terminal; Ctrl-C stops it.

Override the listen address or worker count when needed:

```sh
GREYHOUND_LISTEN=127.0.0.1:8081 PHP_CLI_SERVER_WORKERS=4 sh scripts/serve-local.sh
```

This remains a local development server. A production installation should serve
`public/` through Apache or nginx/PHP-FPM. PHP development-server workers are not
supported on Windows; use Apache there.

File Management returns at most 20 files per page, including search results.
Page links retain the selected folder and filters. Sorting uses upload date and
file ID so equal timestamps do not cause repeated or skipped files between pages.
“Select all” selects files on the current page.

Thumbnail and media-stream responses already release the PHP session lock after
access checks. Single-file downloads now also release it after recording download
activity and before transferring bytes. Authentication remains required for every
protected media request. Originals and image quality are unchanged.
