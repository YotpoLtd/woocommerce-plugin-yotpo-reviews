<?php
/**
 * PHPUnit bootstrap: the WordPress test suite with WooCommerce and this plugin loaded.
 * Setup and how to run: README.md, "Tests".
 */

$yotpo_root = dirname( __DIR__ );
require $yotpo_root . '/vendor/autoload.php';

putenv( 'WP_PHPUNIT__TESTS_CONFIG=' . __DIR__ . '/wp-tests-config.php' );
$yotpo_wp_tests = getenv( 'WP_PHPUNIT__DIR' );
require_once $yotpo_wp_tests . '/includes/functions.php';

if ( ! file_exists( dirname( __DIR__ ) . '/.wp-tests/wordpress/wp-content/plugins/woocommerce/woocommerce.php' ) && ! getenv( 'YOTPO_WP_TESTS_DIR' ) ) {
	fwrite( STDERR, "WordPress and WooCommerce are not installed for the tests. Run bin/install-wp-tests.sh first.\n" );
	exit( 1 );
}

tests_add_filter(
	'muplugins_loaded',
	function () use ( $yotpo_root ) {
		require ABSPATH . 'wp-content/plugins/woocommerce/woocommerce.php';
		require $yotpo_root . '/trunk/wc_yotpo.php';
	}
);

// WooCommerce's tables, options and roles, as on a real store.
tests_add_filter(
	'setup_theme',
	function () {
		WC_Install::install();
		$GLOBALS['wp_roles'] = null;
		wp_roles();
	}
);

// Jobs never run in the background during tests: each test runs the ones it needs.
tests_add_filter( 'action_scheduler_allow_async_request_runner', '__return_false' );

require $yotpo_wp_tests . '/includes/bootstrap.php';
require __DIR__ . '/includes/class-yotpo-test-case.php';
