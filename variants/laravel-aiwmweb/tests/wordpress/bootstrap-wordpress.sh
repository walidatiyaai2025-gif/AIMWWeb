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
SERVER_LOG="/tmp/aimw-wordpress-server.log"

curl -fsSL https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar -o "$WP_CLI"
chmod +x "$WP_CLI"
rm -rf "$WP_PATH"
mkdir -p "$WP_PATH"

wp() {
  php "$WP_CLI" --path="$WP_PATH" --allow-root "$@"
}

rest_curl() {
  curl --fail --silent --show-error --retry 5 --retry-delay 1 --retry-all-errors "$@"
}

wp core download --quiet
wp config create --dbname="$DB_NAME" --dbuser="$DB_USER" --dbpass="$DB_PASSWORD" --dbhost="$DB_HOST" --skip-check --quiet
# WordPress intentionally disables Application Password authentication over plain HTTP
# unless the environment is local. The disposable CI instance is loopback-only.
wp config set WP_ENVIRONMENT_TYPE local --quiet
wp core install --url="$WP_URL" --title="AIMW Acceptance" --admin_user=admin --admin_password='Acceptance-Only-Strong-Password-257!' --admin_email=acceptance@example.invalid --skip-email --quiet

php -S "127.0.0.1:${WP_PORT}" -t "$WP_PATH" > "$SERVER_LOG" 2>&1 &
server_pid=$!
cleanup() {
  rc=$?
  if [[ "$rc" -ne 0 && -f "$SERVER_LOG" ]]; then
    echo '--- WordPress PHP server diagnostics ---' >&2
    tail -n 200 "$SERVER_LOG" >&2 || true
  fi
  kill "$server_pid" 2>/dev/null || true
  exit "$rc"
}
trap cleanup EXIT

ready=0
for _ in $(seq 1 30); do
  if curl -fsS "$WP_URL/?rest_route=/" >/tmp/wp-index.json; then
    ready=1
    break
  fi
  sleep 1
done
if [[ "$ready" -ne 1 ]]; then
  echo 'WordPress REST index did not become ready.' >&2
  exit 1
fi

python3 - <<'PY'
import json
from pathlib import Path
payload = json.loads(Path('/tmp/wp-index.json').read_text())
assert 'namespaces' in payload, 'WordPress REST index missing namespaces'
print('WORDPRESS_REST_INDEX=PASS')
PY

post_id="$(wp post create --post_title='AIMW acceptance original' --post_content='real wordpress integration fixture' --post_status=publish --porcelain)"
app_password="$(wp user application-password create admin aimw-e2e --porcelain)"

rest_curl "$WP_URL/?rest_route=/wp/v2/posts/${post_id}" > /tmp/wp-post-before.json
python3 - <<'PY'
import json
from pathlib import Path
payload = json.loads(Path('/tmp/wp-post-before.json').read_text())
assert payload['title']['rendered'] == 'AIMW acceptance original'
print('WORDPRESS_CONTENT_READ=PASS')
PY

rest_curl -u "admin:${app_password}" \
  -X POST \
  --data-urlencode 'title=AIMW acceptance verified' \
  "$WP_URL/?rest_route=/wp/v2/posts/${post_id}" > /tmp/wp-mutation.json

rest_curl "$WP_URL/?rest_route=/wp/v2/posts/${post_id}" > /tmp/wp-post-after.json
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

variant_dir="${GITHUB_WORKSPACE:-$(pwd)}/variants/laravel-aiwmweb"
plugin_source="$variant_dir/connector/aimw-connector"
package_script="$variant_dir/runtime/scripts/package-connector.sh"
artifact_dir="$variant_dir/runtime/artifacts"
if [[ ! -f "$plugin_source/aimw-connector.php" ]]; then
  echo "CONNECTOR_RUNTIME_PRESENT=NO"
  echo "CONNECTOR_E2E=BLOCKED_RUNTIME"
  echo "WORDPRESS_NATIVE_REST_E2E=PASS"
  exit 0
fi
if [[ ! -x "$package_script" ]]; then
  echo "Canonical Connector packager is unavailable: $package_script" >&2
  exit 1
fi

echo "CONNECTOR_RUNTIME_PRESENT=YES"
find "$plugin_source" -type f -name '*.php' -print0 \
  | xargs -0 -n1 php -l >/tmp/aimw-connector-php-lint.log

echo "CONNECTOR_PHP_LINT=PASS"
rm -rf "$artifact_dir"
mkdir -p "$artifact_dir"
"$package_script" "$artifact_dir" >/tmp/aimw-connector-package.log
cat /tmp/aimw-connector-package.log
connector_zip="$(sed -n 's/^CONNECTOR_ZIP=//p' /tmp/aimw-connector-package.log | tail -n1)"
expected_version="$(sed -n 's/^CONNECTOR_VERSION=//p' /tmp/aimw-connector-package.log | tail -n1)"
if [[ -z "$connector_zip" || ! -f "$connector_zip" || -z "$expected_version" ]]; then
  echo 'Connector packager did not emit a usable ZIP/version contract.' >&2
  exit 1
fi
wp plugin install "$connector_zip" --force --activate --quiet
wp plugin is-active aimw-connector

echo "CONNECTOR_PACKAGE_INSTALL=PASS"
plugin_version="$(wp plugin get aimw-connector --field=version)"
if [[ "$plugin_version" != "$expected_version" ]]; then
  echo "AIMW Connector version mismatch: packaged=$expected_version installed=$plugin_version" >&2
  exit 1
fi

schema_version="$(wp option get aimw_connector_schema_version)"
if [[ "$schema_version" != "2" ]]; then
  echo "Unexpected AIMW Connector schema version: $schema_version" >&2
  exit 1
fi

wp eval '
$config = get_option("aimw_connector", []);
if (($config["local_enabled"] ?? null) !== true) {
    fwrite(STDERR, "Connector activation did not enable local runtime.\n");
    exit(1);
}
global $wpdb;
$table = $wpdb->prefix."aimw_connector_history";
$found = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table));
if ($found !== $table) {
    fwrite(STDERR, "Connector history table was not migrated.\n");
    exit(1);
}
' >/dev/null

echo "CONNECTOR_ACTIVATION=PASS"
echo "CONNECTOR_SCHEMA_V2=PASS"

rest_curl "$WP_URL/?rest_route=/" > /tmp/wp-index-connector.json
python3 - <<'PY'
import json
from pathlib import Path
payload = json.loads(Path('/tmp/wp-index-connector.json').read_text())
assert 'aimw/v1' in payload.get('namespaces', []), 'AIMW Connector REST namespace not registered'
print('CONNECTOR_REST_NAMESPACE=PASS')
PY

echo "CONNECTOR_E2E=PASS"
echo "WORDPRESS_NATIVE_REST_E2E=PASS"
