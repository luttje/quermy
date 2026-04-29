#!/usr/bin/env bash
# Laragon release packager.
# Called by tools/build-releases.sh with these env vars set:
#   REPO_ROOT   — absolute path to the repository root
#   SCRIPT_DIR  — absolute path to tools/
#   STAGE_DIR   — scratch directory for staging (will be cleaned up by caller)
#   OUT_DIR     — destination for output zips (dist/)
#   RELEASE     — release name, e.g. "v1.0.0" or "dev-abc1234"
#   stage_backend <dest>  — function already defined by the caller

# Zip name: quermy-laragon-<release>.zip
# Structure inside the zip:
#   laragon/
#     etc/apps/quermy/        <- drop-in app (public/ src/ vendor/)
#     etc/apache2/alias/quermy.conf

LARAGON_ROOT="${STAGE_DIR}/laragon"
rm -rf "${LARAGON_ROOT}"

APP_DIR="${LARAGON_ROOT}/laragon/etc/apps/quermy"

# Stage all backend files (copies the already-built generic public/ as a base
# so index.php, router.php, .htaccess, etc. are present)
stage_backend "${APP_DIR}"

# Remove the generic build artifacts; they'll be replaced by the rebuild below
rm -rf "${APP_DIR}/public/assets" "${APP_DIR}/public/index.html"

# Rebuild the frontend with the /quermy/ base path directly into the staged
# public directory.  We use a relative path from the frontend dir so MSYS/Git
# Bash does not convert it to an absolute Windows path (e.g. /e/… → E:\e\…).
# MSYS_NO_PATHCONV=1 prevents Git Bash/MINGW from expanding /quermy/ into an
# absolute Windows path (e.g. C:/Program Files/Git/quermy/).
OUTDIR_REL=$(realpath --relative-to="${REPO_ROOT}/frontend" "${APP_DIR}/public")
(cd "${REPO_ROOT}/frontend" && MSYS_NO_PATHCONV=1 VITE_BASE=/quermy/ npm run build -- --outDir="${OUTDIR_REL}")

mkdir -p "${LARAGON_ROOT}/laragon/etc/apache2/alias"
cp "${SCRIPT_DIR}/releases/laragon/quermy.conf" \
   "${LARAGON_ROOT}/laragon/etc/apache2/alias/quermy.conf"

(cd "${LARAGON_ROOT}" && zip -r "${OUT_DIR}/quermy-laragon-${RELEASE}.zip" laragon/)
echo "    quermy-laragon-${RELEASE}.zip"
