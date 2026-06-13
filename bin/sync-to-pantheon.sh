#!/usr/bin/env bash
set -euo pipefail

PLUGIN_SLUG="csa-wp-user-tracker"
ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
PANTHEON_REPO="${PANTHEON_REPO:-/Users/hui/Documents/codex/berkeley lab /csa-esnet}"
COMMIT=0
PUSH=0

for arg in "$@"; do
  case "${arg}" in
    --commit) COMMIT=1 ;;
    --push) PUSH=1 ;;
    *)
      echo "Unknown argument: ${arg}" >&2
      echo "Usage: bin/sync-to-pantheon.sh [--commit] [--push]" >&2
      exit 1
      ;;
  esac
done

TARGET_DIR="${PANTHEON_REPO}/wp-content/plugins/${PLUGIN_SLUG}"

mkdir -p "${TARGET_DIR}"
rsync -a --delete \
  --exclude ".git" \
  --exclude ".github" \
  --exclude ".gitignore" \
  --exclude "bin" \
  --exclude "dist" \
  --exclude "pantheon" \
  --exclude ".DS_Store" \
  "${ROOT_DIR}/" "${TARGET_DIR}/"

git -C "${PANTHEON_REPO}" status --short -- "${TARGET_DIR}"

if [[ "${COMMIT}" == "1" ]]; then
  git -C "${PANTHEON_REPO}" add "wp-content/plugins/${PLUGIN_SLUG}"
  if git -C "${PANTHEON_REPO}" diff --cached --quiet; then
    echo "No Pantheon plugin changes to commit."
  else
    VERSION="$(grep -E '^[[:space:]]*\\*[[:space:]]*Version:' "${ROOT_DIR}/${PLUGIN_SLUG}.php" | sed -E 's/.*Version:[[:space:]]*//')"
    git -C "${PANTHEON_REPO}" commit -m "Update CSA WP User Tracker to ${VERSION}"
  fi
fi

if [[ "${PUSH}" == "1" ]]; then
  git -C "${PANTHEON_REPO}" push origin master
fi
