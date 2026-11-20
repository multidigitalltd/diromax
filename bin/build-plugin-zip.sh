#!/usr/bin/env bash
#
# Package the client-portal plugin for upload.
#
# Produces dist/diromax-portal.zip, containing a single top-level folder
# named "diromax-portal". Tests and development files stay out of it.
#
# Usage:  bash bin/build-plugin-zip.sh
#
set -euo pipefail

root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
stage="$(mktemp -d)"
trap 'rm -rf "$stage"' EXIT

php "$root/bin/build-assets.php"

mkdir -p "$stage/diromax-portal"
cp -r "$root/diromax-portal/assets" "$root/diromax-portal/src" "$root/diromax-portal/templates" "$stage/diromax-portal/"
cp "$root/diromax-portal/diromax-portal.php" "$root/diromax-portal/uninstall.php" "$root/diromax-portal/README.md" "$stage/diromax-portal/"
cp "$root/CHANGELOG.md" "$stage/diromax-portal/CHANGELOG.md"

find "$stage" \( -name '.DS_Store' -o -name 'Thumbs.db' -o -name '*.swp' \) -delete
find "$stage" -type d -exec chmod 755 {} +
find "$stage" -type f -exec chmod 644 {} +

mkdir -p "$root/dist"
rm -f "$root/dist/diromax-portal.zip"
( cd "$stage" && zip -rq "$root/dist/diromax-portal.zip" diromax-portal )

printf '\ndist/diromax-portal.zip — %s files, %s\n' \
  "$(unzip -l "$root/dist/diromax-portal.zip" | tail -1 | awk '{print $2}')" \
  "$(du -h "$root/dist/diromax-portal.zip" | cut -f1)"
printf 'Upload via: תוספים ← הוספת תוסף ← העלאת תוסף\n'
