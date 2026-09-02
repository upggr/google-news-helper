#!/usr/bin/env bash
#
# Sync the built plugin into a WordPress.org SVN checkout.
#
# Prerequisites:
#   1. SVN installed:            brew install subversion
#   2. Plugin approved, and the SVN repo created by the plugins team
#   3. SVN password set at:      https://profiles.wordpress.org/me/profile/edit/group/3/
#
# First-time checkout:
#   svn co https://plugins.svn.wordpress.org/google-news-helper ~/svn/google-news-helper
#
# Usage:
#   ./bin/svn-deploy.sh ~/svn/google-news-helper
#   ./bin/svn-deploy.sh ~/svn/google-news-helper --commit "Release 1.1.0"
#
# Directory assets (icon/banner/screenshots) live in .wordpress-org/ and are
# copied to the SVN assets/ folder — they must NOT ship inside the plugin ZIP.

set -euo pipefail

cd "$(dirname "$0")/.."
ROOT="$PWD"
SLUG="google-news-helper"
VERSION="$(grep -m1 "^ \* Version:" "$SLUG.php" | awk '{print $3}')"

if [[ $# -lt 1 ]]; then
    echo "Usage: ./bin/svn-deploy.sh <svn-checkout-dir> [--commit \"message\"]" >&2
    echo "" >&2
    echo "First-time checkout:" >&2
    echo "  svn co https://plugins.svn.wordpress.org/$SLUG ~/svn/$SLUG" >&2
    exit 1
fi

SVN_DIR="$1"; shift

command -v svn >/dev/null 2>&1 || { echo "SVN not found. brew install subversion" >&2; exit 1; }
[[ -d "$SVN_DIR/.svn" ]] || { echo "Not an SVN checkout: $SVN_DIR" >&2; exit 1; }

# Always deploy from a fresh build so trunk matches what was tested.
./bin/build.sh >/dev/null
ZIP="$ROOT/dist/$SLUG-$VERSION.zip"
[[ -f "$ZIP" ]] || { echo "Build missing: $ZIP" >&2; exit 1; }

STAGE="$(mktemp -d)"
unzip -q "$ZIP" -d "$STAGE"

echo "Deploying v$VERSION -> $SVN_DIR"

# trunk mirrors the build exactly
rm -rf "${SVN_DIR:?}/trunk"
mkdir -p "$SVN_DIR/trunk"
cp -R "$STAGE/$SLUG/." "$SVN_DIR/trunk/"
echo "  trunk/"

# immutable release tag
if [[ -d "$SVN_DIR/tags/$VERSION" ]]; then
    echo "  tags/$VERSION already exists — leaving it untouched"
else
    mkdir -p "$SVN_DIR/tags/$VERSION"
    cp -R "$STAGE/$SLUG/." "$SVN_DIR/tags/$VERSION/"
    echo "  tags/$VERSION/"
fi

# plugin directory page assets (never part of the plugin itself)
if [[ -d "$ROOT/.wordpress-org" ]] && compgen -G "$ROOT/.wordpress-org/*" >/dev/null; then
    mkdir -p "$SVN_DIR/assets"
    cp "$ROOT"/.wordpress-org/* "$SVN_DIR/assets/"
    echo "  assets/"
fi

rm -rf "$STAGE"

cd "$SVN_DIR"
# Stage adds and deletes so renames and removals propagate.
svn add --force trunk tags assets --auto-props --parents --depth infinity -q 2>/dev/null || true
svn status | grep '^!' | awk '{print $2}' | xargs -r svn rm -q 2>/dev/null || true

echo ""
svn status
echo ""

if [[ "${1:-}" == "--commit" && -n "${2:-}" ]]; then
    svn ci -m "$2"
    echo ""
    echo "Committed. Plugin page: https://wordpress.org/plugins/$SLUG/"
else
    echo "Review the status above, then commit:"
    echo "  cd $SVN_DIR && svn ci -m \"Release $VERSION\""
fi
