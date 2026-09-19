#!/usr/bin/env bash
# Build a distributable zip (excludes everything in .distignore).
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
SLUG="ibg-client-outreach"
VERSION="$(grep -m1 "Version:" "$ROOT/$SLUG.php" | sed -E 's/.*Version:[[:space:]]*//' | tr -d '\r')"
OUT="$ROOT/dist"
STAGE="$OUT/$SLUG"

rm -rf "$STAGE" && mkdir -p "$STAGE"
rsync -a --exclude-from="$ROOT/.distignore" --exclude "dist" "$ROOT/" "$STAGE/"

# Sanity: every PHP file must lint.
find "$STAGE" -name '*.php' -print0 | xargs -0 -n1 php -l > /dev/null

( cd "$OUT" && rm -f "$SLUG-$VERSION.zip" && zip -qr "$SLUG-$VERSION.zip" "$SLUG" )
rm -rf "$STAGE"
echo "Built $OUT/$SLUG-$VERSION.zip"
