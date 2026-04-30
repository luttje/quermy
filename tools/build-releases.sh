#!/usr/bin/env bash
# Usage: ./tools/build-releases.sh [release-name]
#
# If no release name is given the script auto-detects:
#   - In GitHub Actions on a tag push: uses the tag name via $GITHUB_REF_NAME
#   - Otherwise: "dev-<short-git-hash>"
#
# Outputs zips to <repo-root>/dist/:
#   quermy-<release>.zip              — generic drop-in
#   quermy-<variant>-<release>.zip    — one per tools/releases/*/package.sh
#
# Note: if you run this on Windows (e.g: with Git Bash), you will need to have zip installed
#       into the mingw64/bin/ directory of Git. See these instructions:
#       https://stackoverflow.com/a/55749636

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "${SCRIPT_DIR}/.." && pwd)"

# ---------------------------------------------------------------------------
# Release name
# ---------------------------------------------------------------------------
if [[ -n "${1:-}" ]]; then
  RELEASE="$1"
elif [[ "${GITHUB_REF:-}" == refs/tags/* ]]; then
  RELEASE="${GITHUB_REF_NAME}"
else
  RELEASE="dev-$(git -C "${REPO_ROOT}" rev-parse --short HEAD)"
fi

echo "==> Release: ${RELEASE}"

OUT_DIR="${REPO_ROOT}/dist"
STAGE_DIR="${OUT_DIR}/.stage"
mkdir -p "${OUT_DIR}"

# ---------------------------------------------------------------------------
# Backend dependencies
# ---------------------------------------------------------------------------
echo "==> composer install (--no-dev)"
(cd "${REPO_ROOT}/backend" && composer install --no-dev --optimize-autoloader --no-interaction --prefer-dist)

# ---------------------------------------------------------------------------
# Frontend build
# ---------------------------------------------------------------------------
echo "==> npm ci"
(cd "${REPO_ROOT}/frontend" && npm ci)

echo "==> npm run build"
(cd "${REPO_ROOT}/frontend" && npm run build)

# ---------------------------------------------------------------------------
# Helper: copy shared backend files into a staging directory
# ---------------------------------------------------------------------------
stage_backend() {
  local dest="$1"
  mkdir -p "${dest}"
  cp -r "${REPO_ROOT}/backend/public"   "${dest}/public"
  cp -r "${REPO_ROOT}/backend/src"      "${dest}/src"
  cp -r "${REPO_ROOT}/backend/vendor"   "${dest}/vendor"
}

# ---------------------------------------------------------------------------
# Variant releases — one tools/releases/*/package.sh per variant
# Each script runs with all variables and the stage_backend function exported.
# ---------------------------------------------------------------------------
export REPO_ROOT SCRIPT_DIR STAGE_DIR OUT_DIR RELEASE
export -f stage_backend

for packager in "${SCRIPT_DIR}/releases/"*/package.sh; do
  [[ -f "${packager}" ]] || continue
  echo "==> Packaging variant: $(basename "$(dirname "${packager}")")..."
  bash "${packager}"
done

# ---------------------------------------------------------------------------
# Restore composer dev dependencies (since we ran composer install with --no-dev)
# This way developers don't have to run this themselves to run tests.
# ---------------------------------------------------------------------------
echo "==> composer install (--dev)"
(cd "${REPO_ROOT}/backend" && composer install --dev --optimize-autoloader --no-interaction --prefer-dist)

# ---------------------------------------------------------------------------
rm -rf "${STAGE_DIR}"
echo ""
echo "Done. Zips in: ${OUT_DIR}/"
