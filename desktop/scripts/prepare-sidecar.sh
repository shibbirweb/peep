#!/usr/bin/env bash
# Puts what the desktop app bundles in place: the static PHP for the target and the
# viewer PHAR (server/build/peep-server.phar, built with "composer build:phar" in server/).
#
# Usage: scripts/prepare-sidecar.sh [target triple]
set -euo pipefail

cd "$(dirname "$0")/.."

bash scripts/fetch-php.sh "${1:-${TAURI_TARGET:-}}"

PHAR="../server/build/peep-server.phar"
if [[ ! -f "$PHAR" ]]; then
  echo "Missing $PHAR. Build it first: cd server && composer build:ui && composer build:phar" >&2
  exit 1
fi

mkdir -p src-tauri/resources
cp "$PHAR" src-tauri/resources/peep-server.phar
echo "Viewer PHAR: src-tauri/resources/peep-server.phar"
