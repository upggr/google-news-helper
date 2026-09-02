#!/usr/bin/env bash
#
# Build the WordPress.org distribution ZIP.
#
# The .org build differs from the GitHub one in a single way: includes/class-updater.php
# is left out. Plugins hosted in the directory update through the directory, and the
# review scanner rejects updater code even when it is disabled at runtime — it reads
# the source, not the behaviour. The main plugin file treats that include as optional,
# so nothing else changes and there is one codebase to maintain.
#
# Usage: ./bin/build.sh

set -euo pipefail

cd "$(dirname "$0")/.."
ROOT="$PWD"
SLUG="news-seo-helper"          # WordPress.org slug (directory name in the ZIP)
SRC="google-news-helper"        # repo/source filenames, deliberately unchanged

VERSION="$(grep -m1 "^ \* Version:" "$SRC.php" | awk '{print $3}')"
README_TAG="$(grep -m1 "^Stable tag:" readme.txt | awk '{print $3}')"

if [[ "$VERSION" != "$README_TAG" ]]; then
    echo "Version mismatch: header says $VERSION, readme.txt Stable tag says $README_TAG" >&2
    exit 1
fi

# WordPress.org requires the text domain to equal the slug, or translations
# from translate.wordpress.org never load. Catch a mismatch here rather than
# in review.
HEADER_DOMAIN="$(grep -m1 "^ \* Text Domain:" "$SRC.php" | awk '{print $4}')"
if [[ "$HEADER_DOMAIN" != "$SLUG" ]]; then
    echo "Text Domain ($HEADER_DOMAIN) does not match slug ($SLUG)" >&2
    exit 1
fi

README_NAME="$(grep -m1 '^=== ' readme.txt | sed 's/^=== *//; s/ *===$//')"
HEADER_NAME="$(grep -m1 "^ \* Plugin Name:" "$SRC.php" | sed 's/^ \* Plugin Name: *//')"
if [[ "$HEADER_NAME" != "$README_NAME" ]]; then
    echo "Plugin Name mismatch: header \"$HEADER_NAME\" vs readme \"$README_NAME\"" >&2
    exit 1
fi

BUILD="$(mktemp -d)"
DEST="$BUILD/$SLUG"
mkdir -p "$DEST"

# Ship only what the plugin needs at runtime.
cp "$SRC.php" "$DEST/$SLUG.php"
cp readme.txt "$DEST/"
cp -R includes assets languages "$DEST/"

# Self-updater must not ship to WordPress.org.
rm -f "$DEST/includes/class-updater.php"

# Strip anything that should never ship: dotfiles are rejected outright by the
# review scanner ("Hidden files are not permitted").
find "$DEST" -name '.DS_Store' -delete
find "$DEST" -name '*.map' -delete
find "$DEST" -name '.*' -maxdepth 2 -exec rm -rf {} + 2>/dev/null || true

# Fail loudly rather than shipping something the scanner will bounce.
if [[ -f "$DEST/includes/class-updater.php" ]]; then
    echo "Updater still present in build" >&2
    exit 1
fi
if grep -rlq "site_transient_update_plugins" "$DEST" 2>/dev/null; then
    echo "Update-routine code found in build:" >&2
    grep -rl "site_transient_update_plugins" "$DEST" >&2
    exit 1
fi
if find "$DEST" -name '.*' -not -name '.' -not -name '..' | grep -q .; then
    echo "Hidden files found in build:" >&2
    find "$DEST" -name '.*' -not -name '.' -not -name '..' >&2
    exit 1
fi

OUT="$ROOT/dist"
mkdir -p "$OUT"
rm -f "$OUT/$SLUG-$VERSION.zip"

( cd "$BUILD" && zip -rq "$OUT/$SLUG-$VERSION.zip" "$SLUG" -x '*.git*' )
rm -rf "$BUILD"

echo "Built dist/$SLUG-$VERSION.zip"
unzip -l "$OUT/$SLUG-$VERSION.zip" | tail -n +4 | head -25
echo ""
echo "Submit the ZIP at: https://wordpress.org/plugins/developers/add/"
