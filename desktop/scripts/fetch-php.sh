#!/usr/bin/env bash
# Downloads the static PHP 8.5 that the desktop app bundles (built by static-php-cli),
# checks it against the pinned SHA-256, and puts it where Tauri expects a sidecar:
# src-tauri/binaries/php-<target triple>[.exe].
#
# Usage: scripts/fetch-php.sh [target triple]   (default: this machine's)
set -euo pipefail

cd "$(dirname "$0")/.."

TARGET="${1:-$(rustc -vV | sed -n 's/^host: //p')}"
BASE="https://dl.static-php.dev/static-php-cli"

case "$TARGET" in
  aarch64-apple-darwin)      FILE="common/php-8.5.8-cli-macos-aarch64.tar.gz"; SHA="cba9bd8b38b51ef2c7cebf02af689e4481bbd07f65edc0b63d3a43b04e0c9db7" ;;
  x86_64-apple-darwin)       FILE="common/php-8.5.8-cli-macos-x86_64.tar.gz";  SHA="05751000c6643f82e8377ed19d55bc159f84bbed46ace1fcc6f62be9866c787c" ;;
  x86_64-unknown-linux-gnu)  FILE="common/php-8.5.8-cli-linux-x86_64.tar.gz";  SHA="517a18e0f0874de35669fe134eb3c52508e128a6d1da5bf890760cb800ada410" ;;
  aarch64-unknown-linux-gnu) FILE="common/php-8.5.8-cli-linux-aarch64.tar.gz"; SHA="af995ef6b3187b39932a00714dfb97e44bfa40a9243c4c29f61f19d583950753" ;;
  x86_64-pc-windows-msvc)    FILE="windows/spc-max/php-8.5.5-cli-win.zip";     SHA="1eaacf3a0c91069a596cd9ce59d2931216c56cd575034514ac4bfb30cf8e1892" ;;
  *) echo "No bundled PHP for $TARGET." >&2; exit 1 ;;
esac

EXT=""
if [[ "$TARGET" == *windows* ]]; then
  EXT=".exe"
fi

DESTINATION="src-tauri/binaries/php-$TARGET$EXT"
if [[ -f "$DESTINATION" ]]; then
  echo "PHP for $TARGET is already in place."
  exit 0
fi

CACHE=".cache/php"
ARCHIVE="$CACHE/$(basename "$FILE")"
mkdir -p "$CACHE" src-tauri/binaries

if [[ ! -f "$ARCHIVE" ]]; then
  echo "Downloading $FILE"
  curl --fail --location --silent --show-error --retry 3 --output "$ARCHIVE.part" "$BASE/$FILE"
  mv "$ARCHIVE.part" "$ARCHIVE"
fi

ACTUAL="$( (command -v sha256sum >/dev/null && sha256sum "$ARCHIVE" || shasum -a 256 "$ARCHIVE") | cut -d' ' -f1)"
if [[ "$ACTUAL" != "$SHA" ]]; then
  echo "Checksum mismatch for $ARCHIVE: expected $SHA, got $ACTUAL." >&2
  rm -f "$ARCHIVE"
  exit 1
fi

WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"' EXIT

if [[ "$ARCHIVE" == *.zip ]]; then
  unzip -q "$ARCHIVE" -d "$WORK"
else
  tar -xzf "$ARCHIVE" -C "$WORK"
fi

mv "$WORK/php$EXT" "$DESTINATION"
chmod +x "$DESTINATION"
echo "Bundled PHP: $DESTINATION"
