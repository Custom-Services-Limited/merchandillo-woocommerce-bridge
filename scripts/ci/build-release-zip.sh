#!/usr/bin/env bash

set -euo pipefail

VERSION="${1:-}"
PLUGIN_SLUG="merchandillo-woocommerce-bridge"

if [[ -z "${VERSION}" ]]; then
    echo "Usage: $0 <version>" >&2
    exit 1
fi

if ! [[ "${VERSION}" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]]; then
    echo "Invalid version '${VERSION}'. Expected semver format X.Y.Z." >&2
    exit 1
fi

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "${SCRIPT_DIR}/../.." && pwd)"
DIST_DIR="${REPO_ROOT}/dist"
STAGE_DIR="$(mktemp -d)"
PLUGIN_DIR="${STAGE_DIR}/${PLUGIN_SLUG}"
OUTPUT_ZIP="${DIST_DIR}/${PLUGIN_SLUG}-${VERSION}.zip"

trap 'rm -rf "${STAGE_DIR}"' EXIT

mkdir -p "${DIST_DIR}" "${PLUGIN_DIR}"

for path in \
    "merchandillo-woocommerce-bridge.php" \
    "includes" \
    "assets"
do
    if [[ ! -e "${REPO_ROOT}/${path}" ]]; then
        echo "Missing required path: ${path}" >&2
        exit 1
    fi

    cp -R "${REPO_ROOT}/${path}" "${PLUGIN_DIR}/"
done

rm -f "${OUTPUT_ZIP}"

(
    cd "${STAGE_DIR}"
    zip -r "${OUTPUT_ZIP}" "${PLUGIN_SLUG}" >/dev/null
)

echo "Created release ZIP at ${OUTPUT_ZIP}"
