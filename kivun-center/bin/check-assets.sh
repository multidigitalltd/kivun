#!/usr/bin/env bash
#
# Are the minified assets the ones built from the sources in this commit?
#
# This exists because a release went out where they were not. The build had
# failed — node_modules was missing — but the failure was swallowed by a pipe,
# so the plugin shipped with a stylesheet that did not contain the fix it was
# released for. The site loads the minified files, so nobody could see the
# change and the bug was reported again as unfixed.
#
# Rebuilds into a scratch directory and compares. Nothing in the working tree
# is touched, so this is safe to run anywhere.

set -euo pipefail

cd "$(dirname "$0")/.."

if [ ! -x node_modules/.bin/cleancss ] || [ ! -x node_modules/.bin/terser ]; then
	echo "Build tools are missing. Run: npm ci" >&2
	exit 1
fi

scratch="$(mktemp -d)"
trap 'rm -rf "$scratch"' EXIT

stale=0

for name in frontend admin accessibility cookies whatsapp; do
	src="assets/css/${name}.css"
	min="assets/css/${name}.min.css"
	[ -f "$src" ] || continue

	node_modules/.bin/cleancss -o "$scratch/${name}.min.css" "$src"

	if ! cmp -s "$scratch/${name}.min.css" "$min"; then
		echo "STALE: $min does not match $src" >&2
		stale=1
	fi
done

for name in frontend admin-crm accessibility cookies voice; do
	src="assets/js/${name}.js"
	min="assets/js/${name}.min.js"
	[ -f "$src" ] || continue

	node_modules/.bin/terser "$src" -c -m -o "$scratch/${name}.min.js"

	if ! cmp -s "$scratch/${name}.min.js" "$min"; then
		echo "STALE: $min does not match $src" >&2
		stale=1
	fi
done

if [ "$stale" -ne 0 ]; then
	echo "" >&2
	echo "The minified assets are out of date. Run 'npm run build' and commit the result." >&2
	echo "The site loads the minified files, so shipping without this means shipping nothing." >&2
	exit 1
fi

echo "Minified assets are in step with their sources."
