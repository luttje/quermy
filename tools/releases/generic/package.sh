#!/usr/bin/env bash
# Generic release packager.
# Called by tools/build-releases.sh with these env vars set:
#   REPO_ROOT   — absolute path to the repository root
#   SCRIPT_DIR  — absolute path to tools/
#   STAGE_DIR   — scratch directory for staging (will be cleaned up by caller)
#   OUT_DIR     — destination for output zips (dist/)
#   RELEASE     — release name, e.g. "v1.0.0" or "dev-abc1234"
#   stage_backend <dest>  — function already defined by the caller

# Zip name: quermy-<release>.zip
# Structure inside the zip:
#   quermy/
#     public/  src/  vendor/

GENERIC_ROOT="${STAGE_DIR}/generic"
rm -rf "${GENERIC_ROOT}"

stage_backend "${GENERIC_ROOT}/quermy"

(cd "${GENERIC_ROOT}" && zip -r "${OUT_DIR}/quermy-${RELEASE}.zip" quermy/)
echo "    quermy-${RELEASE}.zip"
