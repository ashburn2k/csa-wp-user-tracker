#!/usr/bin/env bash
set -euo pipefail

GITHUB_REPO="${GITHUB_REPO:-ashburn2k/csa-wp-user-tracker}"
PANTHEON_SSH_KEY="${PANTHEON_SSH_KEY:-${HOME}/.ssh/pantheon_csa_esnet_ecdsa}"

command -v gh >/dev/null || {
  echo "GitHub CLI is required" >&2
  exit 1
}

if [[ ! -f "${PANTHEON_SSH_KEY}" ]]; then
  echo "Pantheon SSH key not found: ${PANTHEON_SSH_KEY}" >&2
  exit 1
fi

gh secret set PANTHEON_SSH_PRIVATE_KEY --repo "${GITHUB_REPO}" < "${PANTHEON_SSH_KEY}"
echo "Set PANTHEON_SSH_PRIVATE_KEY for ${GITHUB_REPO}"
