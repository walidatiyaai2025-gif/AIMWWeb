#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
VARIANT_DIR="$(cd "${SCRIPT_DIR}/../.." && pwd)"
REPO_ROOT="$(git -C "${VARIANT_DIR}" rev-parse --show-toplevel)"
OUTPUT="${1:-${VARIANT_DIR}/runtime/artifacts/runtime-manifest.json}"
mkdir -p "$(dirname "${OUTPUT}")"

SOURCE_SHA="$(git -C "${REPO_ROOT}" rev-parse HEAD)"
GENERATED_UTC="$(date -u +'%Y-%m-%dT%H:%M:%SZ')"
CONNECTOR_ZIP="$(find "${VARIANT_DIR}/runtime/artifacts" -maxdepth 1 -type f -name 'AIMW-Connector-*.zip' -printf '%f\n' 2>/dev/null | sort | tail -n1 || true)"

cat > "${OUTPUT}" <<JSON
{
  "product": "Laravel AIWMWeb",
  "source_sha": "${SOURCE_SHA}",
  "generated_utc": "${GENERATED_UTC}",
  "runtime": {
    "php_image": "php:8.4.24-fpm-trixie",
    "nginx_image": "nginx:1.31.4-alpine3.24",
    "mysql_image": "mysql:8.4.11",
    "redis_image": "redis:7.4.11-alpine3.21",
    "wordpress_image": "wordpress:7.0.4-php8.3-apache",
    "wordpress_db_image": "mysql:8.0.46"
  },
  "connector_zip": "${CONNECTOR_ZIP:-BLOCKED_SOURCE}",
  "health_live": "/health/live",
  "health_ready": "/health/ready"
}
JSON

echo "RUNTIME_MANIFEST=${OUTPUT}"
