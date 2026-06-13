#!/usr/bin/env bash
set -euo pipefail

PLUGIN_SLUG="csa-wp-user-tracker"
ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
DIST_DIR="${ROOT_DIR}/dist"
BUILD_DIR="${DIST_DIR}/build"

command -v zip >/dev/null || {
  echo "zip is required" >&2
  exit 1
}

rm -rf "${BUILD_DIR}"
mkdir -p "${BUILD_DIR}/${PLUGIN_SLUG}" "${DIST_DIR}"

rsync -a --delete \
  --exclude ".git" \
  --exclude ".github" \
  --exclude ".gitignore" \
  --exclude "bin" \
  --exclude "dist" \
  --exclude ".DS_Store" \
  "${ROOT_DIR}/" "${BUILD_DIR}/${PLUGIN_SLUG}/"

rm -f "${DIST_DIR}/${PLUGIN_SLUG}.zip"
(
  cd "${BUILD_DIR}"
  zip -qr "../${PLUGIN_SLUG}.zip" "${PLUGIN_SLUG}"
)

echo "${DIST_DIR}/${PLUGIN_SLUG}.zip"
