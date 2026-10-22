#!/usr/bin/env bash
# Sets the release version everywhere it is written, and starts its changelog section:
#     scripts/set-version.sh 1.2.3
# The release workflow runs it with --check, which only verifies (and fails on a mismatch):
#     scripts/set-version.sh --check 1.2.3
# Perl does the editing: it behaves the same on macOS and Linux, unlike sed -i.
set -euo pipefail

cd "$(dirname "$0")/.."

check=false
if [[ "${1:-}" == "--check" ]]; then
  check=true
  shift
fi

version="${1:-}"
if [[ ! "$version" =~ ^[0-9]+\.[0-9]+\.[0-9]+(-[0-9A-Za-z.-]+)?$ ]]; then
  echo "Usage: scripts/set-version.sh [--check] X.Y.Z" >&2
  exit 2
fi

# file|regex with the version as its second group (the first occurrence counts)
places=(
  "src/Version.php|(const VERSION = ')([^']+)'"
  "server/src/Version.php|(const string VERSION = ')([^']+)'"
  "desktop/src-tauri/tauri.conf.json|(\"version\": \")([^\"]+)\""
  "desktop/src-tauri/Cargo.toml|(?m)^(version = \")([^\"]+)\""
)

failed=false
for place in "${places[@]}"; do
  file="${place%%|*}"
  regex="${place#*|}"
  current=$(REGEX="$regex" perl -0ne 'print $2 if /$ENV{REGEX}/' "$file")

  if $check; then
    if [[ "$current" != "$version" ]]; then
      echo "$file has version ${current:-none}, not $version." >&2
      failed=true
    fi
  else
    REGEX="$regex" VERSION="$version" perl -0pi -e 's/$ENV{REGEX}/$1$ENV{VERSION}${\(substr($&, length($1) + length($2)))}/' "$file"
    echo "$file: $current -> $version"
  fi
done

if $check; then
  if ! grep -q "^## \[$version\]" CHANGELOG.md; then
    echo "CHANGELOG.md has no \"## [$version]\" section." >&2
    failed=true
  fi

  if $failed; then
    exit 1
  fi

  echo "Every version is $version."
  exit 0
fi

if ! grep -q "^## \[$version\]" CHANGELOG.md; then
  VERSION="$version" TODAY="$(date -u +%Y-%m-%d)" perl -0pi -e 's/^## \[Unreleased\]\n/## [Unreleased]\n\n## [$ENV{VERSION}] - $ENV{TODAY}\n/m' CHANGELOG.md
  echo "CHANGELOG.md: the Unreleased notes are now $version."
fi
