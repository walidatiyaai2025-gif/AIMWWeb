#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
VARIANT_DIR="$(cd "${SCRIPT_DIR}/../.." && pwd)"
BACKEND_DIR="${VARIANT_DIR}/backend"

: "${APP_KEY:?APP_KEY must be injected before deployment}"

cd "${BACKEND_DIR}"
composer install --no-dev --prefer-dist --no-interaction --no-progress --optimize-autoloader

if [[ -f package-lock.json ]]; then
    npm ci --no-audit --no-fund
else
    npm install --ignore-scripts --no-audit --no-fund
fi
npm run build

if [[ "${SKIP_MIGRATIONS:-0}" != "1" ]]; then
    php artisan migrate --force
fi

php artisan config:cache
php artisan view:cache
php artisan schedule:run --no-interaction
php artisan queue:restart

if [[ -n "${HEALTH_READY_URL:-}" ]]; then
    curl --fail --silent --show-error --retry 12 --retry-all-errors --retry-delay 5 \
        "${HEALTH_READY_URL%/}/health/ready" >/dev/null
fi

echo 'DEPLOYMENT_FLOW=PASS'
