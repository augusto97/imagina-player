#!/bin/sh
# Renders the player variants to a PNG using headless Chromium.
#
#   ./tests/preview/shot.sh [output.png]
#
# Requires python3, php and a Chromium binary (CHROMIUM_BIN to override).
set -e

dir=$( cd "$( dirname "$0" )" && pwd )
out=${1:-"$dir/../../docs/preview.png"}
chromium=${CHROMIUM_BIN:-$( command -v chromium || command -v chromium-browser || command -v google-chrome )}

if [ -z "$chromium" ]; then
	echo "No Chromium binary found. Set CHROMIUM_BIN." >&2
	exit 1
fi

python3 "$dir/generate-fixtures.py"
php "$dir/make.php"

"$chromium" \
	--headless --no-sandbox --disable-gpu --hide-scrollbars \
	--force-device-scale-factor=2 --window-size=1320,760 \
	--virtual-time-budget=5000 \
	--screenshot="$out" \
	"file://$dir/index.html"

echo "Wrote $out"
