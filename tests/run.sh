#!/bin/sh
# Runs the CLI test suite against PHP stubs — no WordPress install required.
set -e

dir=$( cd "$( dirname "$0" )" && pwd )
root=$( dirname "$dir" )
status=0

# Several tests compile TypeScript or load the i18n runtime from node_modules.
# Without it they fail in ways that read like plugin regressions — `npx` fetches
# whatever TypeScript is newest, which disagrees with tsconfig — so say what is
# actually wrong and stop.
if [ ! -x "$root/node_modules/.bin/tsc" ] || [ ! -d "$root/node_modules/@wordpress/i18n" ]; then
	echo "node_modules is missing or incomplete: run 'npm ci' first." >&2
	exit 2
fi

PATH="$root/node_modules/.bin:$PATH"
export PATH

for test in "$dir"/test-*.php; do
	echo "--- $( basename "$test" )"
	php "$test" || status=1
	echo
done

exit $status
