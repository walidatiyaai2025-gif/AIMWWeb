#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
VARIANT_DIR="$(cd "${SCRIPT_DIR}/../.." && pwd)"
REPO_ROOT="$(git -C "${VARIANT_DIR}" rev-parse --show-toplevel)"
SOURCE_DIR="${VARIANT_DIR}/connector/aimw-connector"
OUTPUT_DIR="${1:-${VARIANT_DIR}/runtime/artifacts}"
PLUGIN_FILE="${SOURCE_DIR}/aimw-connector.php"

mkdir -p "${OUTPUT_DIR}"

if [[ ! -f "${PLUGIN_FILE}" ]]; then
    echo 'CONNECTOR_ARTIFACT=BLOCKED_SOURCE'
    echo "Expected connector source: ${SOURCE_DIR}" >&2
    exit 3
fi

SOURCE_SHA="$(git -C "${REPO_ROOT}" rev-parse HEAD)"
SHORT_SHA="${SOURCE_SHA:0:12}"
VERSION="$(sed -nE 's/^ \* Version:[[:space:]]*([^[:space:]]+).*/\1/p' "${PLUGIN_FILE}" | head -n1)"
VERSION="${VERSION:-0.0.0-unknown}"
BUILD_UTC="$(date -u +'%Y-%m-%dT%H:%M:%SZ')"
ZIP_NAME="AIMW-Connector-${VERSION}-${SHORT_SHA}.zip"
ZIP_PATH="${OUTPUT_DIR}/${ZIP_NAME}"
STAGE="$(mktemp -d)"
trap 'rm -rf "${STAGE}"' EXIT

mkdir -p "${STAGE}/aimw-connector"
cp -a "${SOURCE_DIR}/." "${STAGE}/aimw-connector/"
cat > "${STAGE}/aimw-connector/AIMW-CONNECTOR-MANIFEST.json" <<JSON
{
  "artifact": "AIMW Connector",
  "plugin_version": "${VERSION}",
  "source_sha": "${SOURCE_SHA}",
  "source_path": "variants/laravel-aiwmweb/connector/aimw-connector",
  "built_utc": "${BUILD_UTC}"
}
JSON

rm -f "${ZIP_PATH}"
(
    cd "${STAGE}"
    zip -qr "${ZIP_PATH}" aimw-connector
)

echo 'CONNECTOR_ARTIFACT=PASS'
echo "CONNECTOR_VERSION=${VERSION}"
echo "CONNECTOR_SOURCE_SHA=${SOURCE_SHA}"
echo "CONNECTOR_ZIP=${ZIP_PATH}"
