#!/usr/bin/env bash
#
# Package the theme for upload.
#
# Produces dist/diromax.zip, containing a single top-level folder named
# "diromax" — the theme slug WordPress will use as the directory name.
#
# Usage:  bash bin/build-zip.sh
#
set -euo pipefail

root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
stage="$(mktemp -d)"
trap 'rm -rf "$stage"' EXIT

# Always package current assets, never a stale build.
php "$root/bin/build-assets.php"

cp -r "$root/diromax-theme" "$stage/diromax"

# Nothing that belongs to the repository rather than to the theme.
find "$stage" \( -name '.DS_Store' -o -name 'Thumbs.db' -o -name '*.swp' \) -delete
find "$stage" -name '.git*' -prune -exec rm -rf {} + 2>/dev/null || true

find "$stage" -type d -exec chmod 755 {} +
find "$stage" -type f -exec chmod 644 {} +

mkdir -p "$root/dist"
rm -f "$root/dist/diromax.zip"
( cd "$stage" && zip -rq "$root/dist/diromax.zip" diromax )

printf '\ndist/diromax.zip — %s files, %s\n' \
  "$(unzip -l "$root/dist/diromax.zip" | tail -1 | awk '{print $2}')" \
  "$(du -h "$root/dist/diromax.zip" | cut -f1)"
printf 'Upload via: עיצוב ← ערכות עיצוב ← הוספה ← העלאת ערכה\n'
