#!/usr/bin/env bash
set -euo pipefail

WP_PATH="${WP_PATH:-/tmp/aimw-wordpress}"
WP_PORT="${WP_PORT:-8090}"
WP_URL="http://127.0.0.1:${WP_PORT}"
WP_CLI="/tmp/wp-cli.phar"
DB_HOST="${WP_DB_HOST:-127.0.0.1}"
DB_NAME="${WP_DB_NAME:-wordpress}"
DB_USER="${WP_DB_USER:-root}"
DB_PASSWORD="${WP_DB_PASSWORD:-root}"

curl -fsSL https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar -o "$WP_CLI"
chmod +x "$WP_CLI"
rm -rf "$WP_PATH"
mkdir -p "$WP_PATH"

wp() {
  php "$WP_CLI" --path="$WP_PATH" --allow-root "$@"
}

wp core download --quiet
wp config create --dbname="$DB_NAME" --dbuser="$DB_USER" --dbpass="$DB_PASSWORD" --dbhost="$DB_HOST" --skip-check --quiet
# WordPress intentionally disables Application Password authentication over plain HTTP
# unless the environment is local. The disposable CI instance is loopback-only.
wp config set WP_ENVIRONMENT_TYPE local --quiet
wp core install --url="$WP_URL" --title="AIMW Acceptance" --admin_user=admin --admin_password='Acceptance-Only-Strong-Password-257!' --admin_email=acceptance@example.invalid --skip-email --quiet

php -S "127.0.0.1:${WP_PORT}" -t "$WP_PATH" > /tmp/aimw-wordpress-server.log 2>&1 &
server_pid=$!
trap 'kill "$server_pid" 2>/dev/null || true' EXIT

for _ in $(seq 1 30); do
  if curl -fsS "$WP_URL/?rest_route=/" >/tmp/wp-index.json; then
    break
  fi
  sleep 1
done

python3 - <<'PY'
import json
from pathlib import Path
payload = json.loads(Path('/tmp/wp-index.json').read_text())
assert 'namespaces' in payload, 'WordPress REST index missing namespaces'
print('WORDPRESS_REST_INDEX=PASS')
PY

post_id="$(wp post create --post_title='AIMW acceptance original' --post_content='real wordpress integration fixture' --post_status=publish --porcelain)"
app_password="$(wp user application-password create admin aimw-e2e --porcelain)"

curl -fsS "$WP_URL/?rest_route=/wp/v2/posts/${post_id}" > /tmp/wp-post-before.json
python3 - <<'PY'
import json
from pathlib import Path
payload = json.loads(Path('/tmp/wp-post-before.json').read_text())
assert payload['title']['rendered'] == 'AIMW acceptance original'
print('WORDPRESS_CONTENT_READ=PASS')
PY

curl -fsS -u "admin:${app_password}" \
  -X POST \
  --data-urlencode 'title=AIMW acceptance verified' \
  "$WP_URL/?rest_route=/wp/v2/posts/${post_id}" > /tmp/wp-mutation.json

curl -fsS "$WP_URL/?rest_route=/wp/v2/posts/${post_id}" > /tmp/wp-post-after.json
python3 - <<'PY'
import json
from pathlib import Path
mutation = json.loads(Path('/tmp/wp-mutation.json').read_text())
after = json.loads(Path('/tmp/wp-post-after.json').read_text())
assert mutation['title']['rendered'] == 'AIMW acceptance verified'
assert after['title']['rendered'] == 'AIMW acceptance verified'
print('WORDPRESS_AUTHENTICATED_MUTATION=PASS')
print('WORDPRESS_AUTHORITATIVE_REREAD=PASS')
PY

connector_dir="${GITHUB_WORKSPACE:-$(pwd)}/variants/laravel-aiwmweb/connector"
if [[ -d "$connector_dir" ]] && find "$connector_dir" -maxdepth 3 -type f -name '*.php' -print -quit | grep -q .; then
  echo "CONNECTOR_RUNTIME_PRESENT=YES"
  echo "CONNECTOR_E2E=REQUIRES_PLUGIN_MANIFEST_SPECIFIC_INSTALL_STEP"
  exit 2
fi

echo "CONNECTOR_RUNTIME_PRESENT=NO"
echo "CONNECTOR_E2E=BLOCKED_RUNTIME"
echo "WORDPRESS_NATIVE_REST_E2E=PASS"
