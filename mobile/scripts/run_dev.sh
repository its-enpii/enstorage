#!/usr/bin/env bash
# -----------------------------------------------------------------------------
# Run Flutter with secrets sourced from .env.local (gitignored).
#
# Usage:
#   ./scripts/run_dev.sh                     # default: flutter run
#   ./scripts/run_dev.sh build apk           # any flutter command + flags
#   ./scripts/run_dev.sh test
#
# .env.local is loaded automatically if present. Copy .env.example to
# .env.local and fill in real values.
#
# IMPORTANT — security:
# - Only CLIENT_ID belongs in a mobile app. The OAuth CLIENT_SECRET must
#   NEVER live here — it belongs on your backend (server-side only).
# - Flutter compiles --dart-define values into the APK; treat them as
#   extractable by anyone who unpacks the binary.
# -----------------------------------------------------------------------------
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(cd "${SCRIPT_DIR}/.." && pwd)"
ENV_FILE="${PROJECT_ROOT}/.env.local"

if [ -f "${ENV_FILE}" ]; then
  # shellcheck disable=SC1090
  set -a; source "${ENV_FILE}"; set +a
else
  echo "[run_dev] ${ENV_FILE} not found — falling back to existing env." >&2
  echo "[run_dev] Copy .env.example to .env.local to set defaults." >&2
fi

# Build --dart-define flags from any GOOGLE_* env vars present.
DART_DEFINES=()
for key in GOOGLE_CLIENT_ID; do
  val="${!key:-}"
  if [ -n "${val}" ]; then
    DART_DEFINES+=("--dart-define=${key}=${val}")
  fi
done

cd "${PROJECT_ROOT}"
exec flutter "${DART_DEFINES[@]}" "$@"
