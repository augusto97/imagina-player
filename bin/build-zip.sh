#!/bin/sh
# Builds the installable plugin ZIP.
#
#   ./bin/build-zip.sh [output-dir]
#
# The archive contains only what WordPress needs at runtime — no sources, tests,
# docs or tooling. The list of what goes in is explicit rather than a list of
# exclusions, so a new directory in the repo cannot leak into a release by
# accident.
set -e

root=$( cd "$( dirname "$0" )/.." && pwd )
outdir=${1:-"$root/dist"}
slug=imagina-player

version=$( sed -n 's/^[[:space:]]*\*[[:space:]]*Version:[[:space:]]*\(.*\)$/\1/p' "$root/$slug.php" | head -1 | tr -d ' \r' )

if [ -z "$version" ]; then
	echo "Could not read the version from $slug.php" >&2
	exit 1
fi

echo "Building $slug $version"

# The build output ships in the archive, so refuse to package a stale one.
if [ ! -f "$root/build/frontend.js" ]; then
	echo "build/ is missing — run 'npm run build' first." >&2
	exit 1
fi

staging=$( mktemp -d )
target="$staging/$slug"

mkdir -p "$target"

for item in \
	"$slug.php" \
	uninstall.php \
	readme.txt \
	LICENSE \
	src \
	build \
	blocks \
	assets/admin
do
	if [ ! -e "$root/$item" ]; then
		echo "Missing required item: $item" >&2
		rm -rf "$staging"
		exit 1
	fi

	mkdir -p "$target/$( dirname "$item" )"
	cp -R "$root/$item" "$target/$( dirname "$item" )/"
done

# Source maps are for debugging the build, not for shipping.
find "$target" -name '*.map' -delete
find "$target" -name '.DS_Store' -delete

mkdir -p "$outdir"
archive="$outdir/$slug-$version.zip"
rm -f "$archive"

( cd "$staging" && zip -qr "$archive" "$slug" -x '*.DS_Store' )

rm -rf "$staging"

echo "Wrote $archive ($( du -h "$archive" | cut -f1 ))"
