#!/usr/bin/env bash
#
# Build the WordPress.org distribution ZIP.
#
# The .org build differs from the GitHub one in a single way: it carries a
# `.wporg` marker file, which switches the GitHub self-updater off (a plugin
# in the directory must update through the directory). Everything else is
# identical, so there is only one codebase to maintain.
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

# Marker: tells the updater this copy is served by WordPress.org.
touch "$DEST/.wporg"

# Strip anything that should never ship.
find "$DEST" -name '.DS_Store' -delete
find "$DEST" -name '*.map' -delete

OUT="$ROOT/dist"
mkdir -p "$OUT"
rm -f "$OUT/$SLUG-$VERSION.zip"

( cd "$BUILD" && zip -rq "$OUT/$SLUG-$VERSION.zip" "$SLUG" -x '*.git*' )
rm -rf "$BUILD"

echo "Built dist/$SLUG-$VERSION.zip"
unzip -l "$OUT/$SLUG-$VERSION.zip" | tail -n +4 | head -25
echo ""
echo "Submit the ZIP at: https://wordpress.org/plugins/developers/add/"
