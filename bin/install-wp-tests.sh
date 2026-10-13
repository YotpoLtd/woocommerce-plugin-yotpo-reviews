#!/usr/bin/env bash
# Downloads the WordPress and WooCommerce versions the PHPUnit tests run against into .wp-tests/
# (git-ignored). The WordPress test library itself comes from Composer (wp-phpunit/wp-phpunit).
#
# Usage:
#   bin/install-wp-tests.sh                                   # the versions below
#   WP_VERSION=7.1.3 WC_VERSION=11.2.1 bin/install-wp-tests.sh
#
# Run again after changing a version; a version already in place is not downloaded twice.

set -euo pipefail
cd "$(dirname "$0")/.."

WP_VERSION="${WP_VERSION:-7.1.3}"
WC_VERSION="${WC_VERSION:-11.2.1}"
core="${YOTPO_WP_TESTS_DIR:-$PWD/.wp-tests}/wordpress"
plugins="$core/wp-content/plugins"

if [ "$(cat "$core/.version" 2>/dev/null)" != "$WP_VERSION" ]; then
	echo "Downloading WordPress $WP_VERSION"
	rm -rf "$core"
	mkdir -p "$core"
	curl -fsSL "https://wordpress.org/wordpress-$WP_VERSION.tar.gz" | tar -xz -C "$core" --strip-components=1
	echo "$WP_VERSION" > "$core/.version"
fi

if [ "$(cat "$plugins/woocommerce/.version" 2>/dev/null)" != "$WC_VERSION" ]; then
	echo "Downloading WooCommerce $WC_VERSION"
	rm -rf "$plugins/woocommerce"
	tmp="$(mktemp -d)"
	curl -fsSL -o "$tmp/woocommerce.zip" "https://downloads.wordpress.org/plugin/woocommerce.$WC_VERSION.zip"
	unzip -q "$tmp/woocommerce.zip" -d "$plugins"
	rm -rf "$tmp"
	echo "$WC_VERSION" > "$plugins/woocommerce/.version"
fi

echo "WordPress $WP_VERSION and WooCommerce $WC_VERSION are in $core"
