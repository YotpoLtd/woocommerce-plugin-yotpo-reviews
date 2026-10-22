<?php
/**
 * The warning about PHP versions without the fix for CVE-2026-109618 and CVE-2026-109619 (critical flaws in
 * PHP itself), on the Plugins screen and the Yotpo settings.
 */
class PhpSecurityNoticeTest extends Yotpo_Test_Case {

	public static function set_up_before_class() {
		parent::set_up_before_class();
		require_once ABSPATH . 'wp-admin/includes/class-wp-screen.php';
		require_once ABSPATH . 'wp-admin/includes/screen.php';
	}

	public function tear_down() {
		remove_all_filters( 'wc_yotpo_show_php_security_notice' );
		set_current_screen( 'front' );
		parent::tear_down();
	}

	public function test_each_branch_is_asked_for_its_first_fixed_release() {
		$expected = array(
			'7.4.33' => '8.2.35', // No fix for branches PHP no longer supports.
			'8.1.30' => '8.2.35',
			'8.2.34' => '8.2.35',
			'8.2.35' => '',
			'8.3.30' => '8.3.36',
			'8.3.36' => '',
			'8.4.26' => '8.4.27',
			'8.4.30' => '',
			'8.5.2'  => '8.5.12',
			'8.5.12' => '',
			'8.6.0'  => '',
			'9.0.0'  => '',
		);
		foreach ( $expected as $version => $update ) {
			$this->assertSame( $update, wc_yotpo_php_security_update( $version ), $version );
		}
	}

	public function test_a_supported_branch_is_asked_to_update_or_confirm_the_fix() {
		$html = wc_yotpo_php_security_message( '8.3.30' );

		$this->assertStringContainsString( '<div class="notice notice-warning">', $html );
		$this->assertStringContainsString( 'your server runs PHP 8.3.30. Releases before 8.3.36 have critical security flaws', $html );
		$this->assertStringContainsString( 'unless your host applied the fix separately. Ask your host to update PHP to 8.3.36 or later, or to confirm the fix is in place.', $html );
		$this->assertStringContainsString( '<a href="https://cert.europa.eu/publications/security-advisories/2026-018" target="_blank" rel="noopener">CVE-2026-109618 and CVE-2026-109619</a>', $html );
	}

	public function test_an_unsupported_branch_is_told_it_gets_no_fix() {
		$html = wc_yotpo_php_security_message( '7.4.33' );

		$this->assertStringContainsString( 'your server runs PHP 7.4.33, which no longer gets security fixes', $html );
		$this->assertStringContainsString( 'This version of the plugin needs PHP 8.2 or later. Ask your host to update PHP to 8.2.35 or later.', $html );
	}

	public function test_no_notice_for_a_fixed_version() {
		$this->assertSame( '', wc_yotpo_php_security_message( '8.4.27' ) );
	}

	public function test_hosts_that_backport_the_fix_can_turn_it_off() {
		add_filter(
			'wc_yotpo_show_php_security_notice',
			function ( $show, $version ) {
				return '8.3.6' === $version ? false : $show;
			},
			10,
			2
		);

		$this->assertSame( '', wc_yotpo_php_security_message( '8.3.6' ) );
		$this->assertNotSame( '', wc_yotpo_php_security_message( '8.3.7' ) );
	}

	public function test_shown_to_admins_on_the_plugins_screen_and_the_yotpo_settings_only() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		foreach ( array( 'plugins' => true, 'toplevel_page_woocommerce-yotpo-settings-page' => true, 'dashboard' => false, 'edit-product' => false ) as $screen => $shown ) {
			set_current_screen( $screen );
			$this->assertSame( $shown, wc_yotpo_php_security_notice_screen(), $screen );
		}

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'shop_manager' ) ) );
		set_current_screen( 'plugins' );
		$this->assertFalse( wc_yotpo_php_security_notice_screen(), 'Not for users who cannot change the server' );
	}

	public function test_the_notice_is_hooked_without_woocommerce_too() {
		$this->assertSame( 10, has_action( 'admin_notices', 'wc_yotpo_php_security_notice' ) );
	}

	public function test_the_minimum_php_version_is_the_same_everywhere() {
		$root   = dirname( __DIR__ );
		$plugin = file_get_contents( $root . '/trunk/wc_yotpo.php' );
		$readme = file_get_contents( $root . '/trunk/readme.txt' );
		$this->assertSame( '8.2', WC_YOTPO_MIN_PHP_VERSION );
		$this->assertStringContainsString( "\tRequires PHP: 8.2\n", $plugin );
		$this->assertStringContainsString( "\nRequires PHP: 8.2\n", $readme );
		$this->assertStringContainsString( "* PHP version 8.2 or greater\n", $readme );
		$this->assertSame( '8.2.0', json_decode( file_get_contents( $root . '/composer.json' ), true )['config']['platform']['php'] );
		$this->assertStringContainsString( "php: ['8.2',", file_get_contents( $root . '/.github/workflows/tests.yml' ) );
		$this->assertStringContainsString( '<config name="testVersion" value="8.2-"/>', file_get_contents( $root . '/phpcs.xml.dist' ) );
	}
}
