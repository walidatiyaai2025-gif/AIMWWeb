#!/usr/bin/env bash
# GitHub Actions ships only a production-signed bundle to private cPanel space.
set -euo pipefail
umask 077

: "${AIWM_DEPLOY_HOST:?Configure AIWM_DEPLOY_HOST in GitHub Secrets}"
: "${AIWM_DEPLOY_SSH_PRIVATE_KEY:?Configure AIWM_DEPLOY_SSH_PRIVATE_KEY}"
: "${AIWM_DEPLOY_KNOWN_HOSTS:?Configure AIWM_DEPLOY_KNOWN_HOSTS}"
: "${AIWM_DEPLOY_USER:?Configure AIWM_DEPLOY_USER GitHub variable}"
: "${AIWM_DEPLOY_SITE_ROOT:?Configure AIWM_DEPLOY_SITE_ROOT GitHub variable}"
: "${AIWM_DEPLOY_PORT:?Configure AIWM_DEPLOY_PORT GitHub variable}"
: "${SOURCE_SHA:?Missing verified Git source SHA}"
: "${BUILD_VERSION:?Missing verified SemVer}"
: "${SIGNED_BUNDLE:?Missing production signed bundle}"
: "${RUNNER_SCRIPT:?Missing signed update runner}"
AIWM_DEPLOY_PHP_BIN="${AIWM_DEPLOY_PHP_BIN:-php}"

if [[ ! "$SOURCE_SHA" =~ ^[a-f0-9]{40}$ ||
      ! "$BUILD_VERSION" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ||
      ! "$AIWM_DEPLOY_HOST" =~ ^[A-Za-z0-9._-]+$ ||
      ! "$AIWM_DEPLOY_USER" =~ ^[A-Za-z0-9_-]+$ ||
      ! "$AIWM_DEPLOY_PORT" =~ ^[0-9]{1,5}$ ||
      "$AIWM_DEPLOY_PORT" -lt 1 || "$AIWM_DEPLOY_PORT" -gt 65535 ||
      ! "$AIWM_DEPLOY_SITE_ROOT" =~ ^/[A-Za-z0-9_./-]+$ ||
      "$AIWM_DEPLOY_SITE_ROOT" == *"/../"* ||
      "$AIWM_DEPLOY_SITE_ROOT" == *"/./"* ||
      ! "$AIWM_DEPLOY_PHP_BIN" =~ ^[A-Za-z0-9_./-]+$ ]]; then
    echo "BLOCKED: invalid SSH deployment parameters" >&2
    exit 2
fi

test -s "$SIGNED_BUNDLE" && test -s "$RUNNER_SCRIPT"
work="$(mktemp -d)"
trap 'rm -rf "$work"' EXIT
mkdir -p "$work/.ssh"
chmod 700 "$work/.ssh"
printf '%s\n' "$AIWM_DEPLOY_SSH_PRIVATE_KEY" > "$work/.ssh/id_ed25519"
printf '%s\n' "$AIWM_DEPLOY_KNOWN_HOSTS" > "$work/.ssh/known_hosts"
chmod 600 "$work/.ssh/id_ed25519" "$work/.ssh/known_hosts"
host_id="$AIWM_DEPLOY_HOST"
if [[ "$AIWM_DEPLOY_PORT" != 22 ]]; then host_id="[$AIWM_DEPLOY_HOST]:$AIWM_DEPLOY_PORT"; fi
ssh-keygen -F "$host_id" -f "$work/.ssh/known_hosts" >/dev/null || {
    echo "BLOCKED: pinned SSH host fingerprint absent" >&2
    exit 3
}
ssh_opts=(-p "$AIWM_DEPLOY_PORT" -i "$work/.ssh/id_ed25519"
    -o BatchMode=yes -o IdentitiesOnly=yes -o StrictHostKeyChecking=yes
    -o UserKnownHostsFile="$work/.ssh/known_hosts"
    -o ConnectTimeout=15 -o ForwardAgent=no -o ClearAllForwardings=yes)
scp_opts=(-P "$AIWM_DEPLOY_PORT" -i "$work/.ssh/id_ed25519"
    -o BatchMode=yes -o IdentitiesOnly=yes -o StrictHostKeyChecking=yes
    -o UserKnownHostsFile="$work/.ssh/known_hosts"
    -o ConnectTimeout=15)
host="$AIWM_DEPLOY_USER@$AIWM_DEPLOY_HOST"
incoming="$AIWM_DEPLOY_SITE_ROOT/app/storage/app/system-updates/incoming"
# Parameters above are validated to exclude shell metacharacters.
ssh "${ssh_opts[@]}" "$host" "test -f '$AIWM_DEPLOY_SITE_ROOT/app/.env' && test -f '$AIWM_DEPLOY_SITE_ROOT/app/artisan' && mkdir -p '$incoming' && chmod 700 '$incoming'"
scp "${scp_opts[@]}" "$SIGNED_BUNDLE" "$host:$incoming/$SOURCE_SHA.zip"
scp "${scp_opts[@]}" "$RUNNER_SCRIPT" "$host:$incoming/$SOURCE_SHA-install.php"
ssh "${ssh_opts[@]}" "$host" \
    "bash -se -- '$AIWM_DEPLOY_SITE_ROOT' '$SOURCE_SHA' '$BUILD_VERSION' '$AIWM_DEPLOY_PHP_BIN'" <<'REMOTE'
set -euo pipefail
umask 077
root="$1"
sha="$2"
version="$3"
php_bin="$4"
stage="$root/app/storage/app/system-updates/incoming"
bundle="$stage/$sha.zip"
runner="$stage/$sha-install.php"
trap 'rm -f "$bundle" "$runner"' EXIT
test -s "$bundle" && test -s "$runner" && test -s "$root/app/.env"
"$php_bin" -r 'if (PHP_VERSION_ID < 80300 || !extension_loaded("sodium") || !extension_loaded("zip") || !extension_loaded("pdo_mysql")) {fwrite(STDERR, "BLOCKED: unsupported production PHP runtime\n");exit(2);}'
"$php_bin" "$runner" "$root/app" "$bundle" "$sha" "$version"
REMOTE
curl --fail --silent --show-error --max-time 30 --retry 3 --retry-delay 2 \
    "https://aiwm.walidatiya.com/health/live" >/dev/null
echo "SSH_SIGNED_UPDATE_DEPLOYED=$BUILD_VERSION SHA=$SOURCE_SHA"
