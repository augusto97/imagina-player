#!/bin/sh
# Runs the CLI test suite against PHP stubs — no WordPress install required.
set -e

dir=$( cd "$( dirname "$0" )" && pwd )
status=0

for test in "$dir"/test-*.php; do
	echo "--- $( basename "$test" )"
	php "$test" || status=1
	echo
done

exit $status
