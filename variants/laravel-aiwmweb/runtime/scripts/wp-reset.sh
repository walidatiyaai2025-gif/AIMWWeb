#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
RUNTIME_DIR="$(cd "${SCRIPT_DIR}/.." && pwd)"
VARIANT_DIR="$(cd "${RUNTIME_DIR}/.." && pwd)"
COMPOSE_FILE="${RUNTIME_DIR}/docker-compose.yml"
ENV_FILE="${RUNTIME_ENV_FILE:-${RUNTIME_DIR}/.env}"
PROJECT_NAME="${COMPOSE_PROJECT_NAME:-laravel-aiwmweb}"

if [[ ! -f "${ENV_FILE}" ]]; then
    echo "Runtime env file not found: ${ENV_FILE}" >&2
    exit 64
fi

set -a
# shellcheck disable=SC1090
source "${ENV_FILE}"
set +a

: "${WORDPRESS_ADMIN_USER:?WORDPRESS_ADMIN_USER is required}"
: "${WORDPRESS_ADMIN_PASSWORD:?WORDPRESS_ADMIN_PASSWORD is required}"
: "${WORDPRESS_ADMIN_EMAIL:?WORDPRESS_ADMIN_EMAIL is required}"

compose=(docker compose --project-name "${PROJECT_NAME}" --env-file "${ENV_FILE}" -f "${COMPOSE_FILE}" --profile wordpress)

"${compose[@]}" rm -sf wordpress wpcli wordpress-db >/dev/null 2>&1 || true
docker volume rm -f "${PROJECT_NAME}_wordpress_data" "${PROJECT_NAME}_wordpress_db_data" >/dev/null 2>&1 || true
"${compose[@]}" up -d wordpress-db wordpress

WORDPRESS_URL="${WORDPRESS_URL:-http://localhost:${WORDPRESS_PORT:-8081}}"
for _ in $(seq 1 60); do
    if curl --fail --silent "${WORDPRESS_URL%/}/wp-admin/install.php" >/dev/null 2>&1; then
        break
    fi
    sleep 2
done
curl --fail --silent "${WORDPRESS_URL%/}/wp-admin/install.php" >/dev/null

"${compose[@]}" run --rm wpcli core install \
    --url="${WORDPRESS_URL}" \
    --title='Laravel AIWMWeb Disposable WordPress' \
    --admin_user="${WORDPRESS_ADMIN_USER}" \
    --admin_password="${WORDPRESS_ADMIN_PASSWORD}" \
    --admin_email="${WORDPRESS_ADMIN_EMAIL}" \
    --skip-email >/dev/null

POST_ID="$("${compose[@]}" run --rm wpcli post create \
    --post_type=post \
    --post_status=publish \
    --post_title='AIMW Runtime Probe' \
    --porcelain)"

CONNECTOR_READY=0
if [[ -f "${VARIANT_DIR}/connector/aimw-connector/aimw-connector.php" ]]; then
    "${SCRIPT_DIR}/package-connector.sh" "${RUNTIME_DIR}/artifacts" >/tmp/aimw-connector-package.log
    CONNECTOR_ZIP="$(find "${RUNTIME_DIR}/artifacts" -maxdepth 1 -type f -name 'AIMW-Connector-*.zip' -printf '%f\n' | sort | tail -n1)"
    "${compose[@]}" run --rm wpcli plugin install "/artifacts/${CONNECTOR_ZIP}" --activate >/dev/null
    CONNECTOR_READY=1
    echo 'WORDPRESS_CONNECTOR_INSTALL=PASS'
else
    echo 'WORDPRESS_CONNECTOR_INSTALL=BLOCKED_SOURCE'
    if [[ "${AIMW_REQUIRE_CONNECTOR:-0}" = "1" ]]; then
        exit 3
    fi
fi

REST_PAYLOAD="$(curl --fail --silent --show-error "${WORDPRESS_URL%/}/?rest_route=/wp/v2/posts/${POST_ID}")"
printf '%s' "${REST_PAYLOAD}" | grep -F 'AIMW Runtime Probe' >/dev/null

echo 'WORDPRESS_INSTALL=PASS'
echo 'WORDPRESS_CONTENT_OPERATION=PASS'

if [[ "${CONNECTOR_READY}" = "1" && -n "${AIMW_PAIRING_TOKEN:-}" ]]; then
    APP_PASSWORD="$("${compose[@]}" run --rm wpcli user application-password create \
        "${WORDPRESS_ADMIN_USER}" aimw-runtime-ci --porcelain)"
    PAIR_RESPONSE="$(curl --fail --silent --show-error --user "${WORDPRESS_ADMIN_USER}:${APP_PASSWORD}" \
        -H 'Content-Type: application/json' \
        --data "{\"platform_url\":\"${AIMW_PLATFORM_URL:-http://web}\",\"pairing_token\":\"${AIMW_PAIRING_TOKEN}\"}" \
        "${WORDPRESS_URL%/}/?rest_route=/aimw/v1/pair")"
    printf '%s' "${PAIR_RESPONSE}" | grep -F '"paired":true' >/dev/null
    echo 'WORDPRESS_CONNECTOR_PAIRING=PASS'
elif [[ "${CONNECTOR_READY}" = "1" ]]; then
    echo 'WORDPRESS_CONNECTOR_PAIRING=BLOCKED_PAIRING_TOKEN'
else
    echo 'WORDPRESS_CONNECTOR_PAIRING=BLOCKED_SOURCE'
fi
