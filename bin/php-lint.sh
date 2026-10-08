#!/usr/bin/env bash
# Syntax-check every PHP file in trunk/ with one or more PHP binaries.
# Deprecations are reported too, so newer PHP versions flag upcoming breakage.
#
# Usage:
#   bin/php-lint.sh                      # uses `php` on PATH
#   PHP_BINARIES="php7.4 php8.4" bin/php-lint.sh
#
# On MAMP, for example:
#   PHP_BINARIES="$(ls -d /Applications/MAMP/bin/php/php{7.4,8.3,8.4,8.5}*/bin/php)" bin/php-lint.sh

set -u
cd "$(dirname "$0")/.."

status=0
for php in ${PHP_BINARIES:-php}; do
	version=$("$php" -r 'echo PHP_VERSION;')
	issues=$(find trunk -name '*.php' -print0 \
		| xargs -0 -n1 -I{} sh -c '"$0" -d error_reporting=E_ALL -d display_errors=stderr -l "$1" || true' "$php" {} 2>&1 >/dev/null \
		| grep -v '^$' | sort -u)
	if [ -n "$issues" ]; then
		echo "PHP $version: issues found"
		echo "$issues" | sed 's/^/  /'
		status=1
	else
		echo "PHP $version: OK"
	fi
done
exit $status
