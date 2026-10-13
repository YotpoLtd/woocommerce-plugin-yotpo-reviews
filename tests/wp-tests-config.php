<?php
/**
 * WordPress test suite settings. Read from the environment, so the same file works in CI and locally.
 *
 * The test suite deletes every table in the database it is given: use a database of its own,
 * never a site's. Its name must contain "test".
 */

$yotpo_tests_db = getenv( 'WP_TESTS_DB_NAME' ) ? getenv( 'WP_TESTS_DB_NAME' ) : 'yotpo_wp_tests';
if ( false === stripos( $yotpo_tests_db, 'test' ) ) {
	fwrite( STDERR, "Refusing to run: the test database name must contain \"test\" (got \"$yotpo_tests_db\").\n" );
	exit( 1 );
}

define( 'ABSPATH', rtrim( getenv( 'YOTPO_WP_TESTS_DIR' ) ? getenv( 'YOTPO_WP_TESTS_DIR' ) : dirname( __DIR__ ) . '/.wp-tests', '/' ) . '/wordpress/' );

define( 'DB_NAME', $yotpo_tests_db );
define( 'DB_USER', getenv( 'WP_TESTS_DB_USER' ) ? getenv( 'WP_TESTS_DB_USER' ) : 'root' );
define( 'DB_PASSWORD', false !== getenv( 'WP_TESTS_DB_PASSWORD' ) ? getenv( 'WP_TESTS_DB_PASSWORD' ) : 'root' );
define( 'DB_HOST', getenv( 'WP_TESTS_DB_HOST' ) ? getenv( 'WP_TESTS_DB_HOST' ) : '127.0.0.1' );
define( 'DB_CHARSET', 'utf8mb4' );
define( 'DB_COLLATE', '' );
$table_prefix = 'wptests_';

define( 'WP_TESTS_DOMAIN', 'example.org' );
define( 'WP_TESTS_EMAIL', 'admin@example.org' );
define( 'WP_TESTS_TITLE', 'Test Blog' );
define( 'WP_PHP_BINARY', 'php' );
define( 'WPLANG', '' );
define( 'WP_DEBUG', true );

// No test may reach the network. Tests answer Yotpo's API themselves (Yotpo_Test_Case::route()).
define( 'WP_HTTP_BLOCK_EXTERNAL', true );
