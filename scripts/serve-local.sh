#!/bin/sh
# Local macOS/Linux server with concurrent PHP workers, on the existing LAN URL.
set -eu
cd "$(dirname "$0")/.."
export PHP_CLI_SERVER_WORKERS="${PHP_CLI_SERVER_WORKERS:-4}"
exec php -d upload_max_filesize=0 -d post_max_size=0 -S "${GREYHOUND_LISTEN:-0.0.0.0:8080}" -t public vendor/codeigniter4/framework/system/rewrite.php

















