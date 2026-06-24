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

# Build --dart-define flags from any recognised env vars present.
# Order matters: the matching `case` decides which keys get forwarded.
# GOOGLE_CLIENT_ID is safe to embed in the APK (it's a public OAuth
# client ID). API_BASE is also safe — it's just a URL.
DART_DEFINES=()
for key in GOOGLE_CLIENT_ID API_BASE; do
  val="${!key:-}"
  if [ -n "${val}" ]; then
    DART_DEFINES+=("--dart-define=${key}=${val}")
  fi
done

# Fail fast if API_BASE is missing — the app will assert at startup
# and we want the dev to see the error HERE (build time) instead of
# inside a running emulator where the stack trace is less obvious.
if [ -z "${API_BASE:-}" ]; then
  echo "[run_dev] API_BASE is not set in .env.local." >&2
  echo "[run_dev] Add a line like:  API_BASE=http://10.0.2.2:8080/api/v1" >&2
  echo "[run_dev]   - Android emulator → host PC : http://10.0.2.2:8080/api/v1" >&2
  echo "[run_dev]   - iOS simulator                : http://localhost:8080/api/v1" >&2
  echo "[run_dev]   - Physical device on LAN       : http://<your-LAN-IP>:8080/api/v1" >&2
  echo "[run_dev]   - Production                   : https://api.example.com/api/v1" >&2
  exit 1
fi

cd "${PROJECT_ROOT}"
exec flutter "${DART_DEFINES[@]}" "$@"
