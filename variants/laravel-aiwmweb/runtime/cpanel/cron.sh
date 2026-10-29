#!/usr/bin/env bash
set -euo pipefail
# Configure these through the hosting panel, never by editing application code.
: "${AIMW_APP_ROOT:?Set the private application directory}"
: "${AIMW_PHP_BIN:?Set the PHP 8.3+ CLI path}"
cd -- "$AIMW_APP_ROOT"
command -v flock >/dev/null
command -v timeout >/dev/null
# Every invocation shares exactly this nonblocking outer process lock.
exec 9>storage/framework/cpanel-cron.lock
flock -n 9 || exit 0
# Timeout kills the entire bounded tick; provider=30 < worker=45 < outer=110.
timeout --signal=TERM --kill-after=5s 110s "$AIMW_PHP_BIN" -d memory_limit=192M artisan cpanel:tick --no-interaction
