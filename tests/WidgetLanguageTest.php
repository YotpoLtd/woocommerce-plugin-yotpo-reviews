<?php
/**
 * The widgets' language. v2 widgets get data-lang; v3 widgets get the loader's languageCode, from the
 * page's WordPress locale when "Match the site language" is on (Yotpo's codes: fr, pt-BR, zh-CN; a code it
 * doesn't know shows English).
 */
class WidgetLanguageTest extends Yotpo_Test_Case {

	public function tear_down() {
		wp_dequeue_script( 'yquery' );
		wp_deregister_script( 'yquery' );
		remove_all_filters( 'locale' );
		parent::tear_down();
	}

	public function test_wordpress_locales_become_yotpos_language_codes() {
		$expected = array(
			'fr_FR'        => 'fr',
			'de_DE_formal' => 'de',
			'en_US'        => 'en',
			'he_IL'        => 'he',
			'ja'           => 'ja',
			'pt_BR'        => 'pt-BR',
			'pt_PT'        => 'pt-PT',
			'pt_AO'        => 'pt-PT',
			'zh_CN'        => 'zh-CN',
			'zh_TW'        => 'zh-TW',
			'zh_HK'        => 'zh-TW',
			''             => '',
		);
		foreach ( $expected as $locale => $code ) {
			$this->assertSame( $code, wc_yotpo_widget_language_code( $locale ), $locale );
		}
	}

	public function test_the_code_can_be_filtered() {
		add_filter(
			'wc_yotpo_widget_language_code',
			function ( $code, $locale ) {
				return 'es_MX' === $locale ? 'es' : $code;
			},
			10,
			2
		);

		$this->assertSame( 'es', wc_yotpo_widget_language_code( 'es_MX' ) );
		remove_all_filters( 'wc_yotpo_widget_language_code' );
	}

	public function test_v3_widgets_load_in_the_page_language() {
		$this->set_locale( 'fr_FR' );

		$this->assertSame( 'fr', $this->loader_settings()['language_code'] );
	}

	public function test_a_multilingual_page_changes_the_language() {
		$this->set_locale( 'pt_BR' ); // As WPML or Polylang set it on a Portuguese page.

		$this->assertSame( 'pt-BR', $this->loader_settings()['language_code'] );
	}

	public function test_with_match_each_pages_language_off_the_language_set_in_yotpo_is_used() {
		$this->set_setting( 'widget_language_from_page', false );
		$this->set_locale( 'fr_FR' );

		$this->assertSame( '', $this->loader_settings()['language_code'], 'No languageCode: the loader uses the account language' );
	}

	public function test_v3_page_language_ignores_the_v2_site_language_switch() {
		$this->set_setting( 'yotpo_language_as_site', true );
		$this->set_setting( 'widget_language_from_page', false );
		$this->set_locale( 'fr_FR' );

		$this->assertSame( '', $this->loader_settings()['language_code'] );
	}

	public function test_stores_upgrading_from_1x_keep_the_language_set_in_yotpo() {
		// Saved by 1.x: "Match the site language" on, as for many stores, and no v3 page-language key.
		update_option(
			'yotpo_settings',
			array( 'app_key' => self::APP_KEY, 'secret' => 'test-secret', 'widget_version' => 'v3', 'yotpo_language_as_site' => true, 'language_code' => 'en' )
		);
		$this->set_locale( 'de_DE' );

		$this->assertFalse( get_option( 'yotpo_settings' )['widget_language_from_page'] );
		$this->assertSame( '', $this->loader_settings()['language_code'], 'Their widgets keep the language set in Yotpo after the update' );
	}

	public function test_new_stores_get_each_pages_language() {
		delete_option( 'yotpo_settings' );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		wc_yotpo_activation();

		$this->assertTrue( get_option( 'yotpo_settings' )['widget_language_from_page'] );
	}

	public function test_v2_widgets_are_left_as_they_were() {
		$this->set_setting( 'widget_version', 'v2' );
		$this->set_setting( 'language_code', 'de' );
		$this->set_setting( 'yotpo_language_as_site', false );

		$this->assertSame( '', $this->loader_settings()['language_code'] );
		$this->assertSame( 'de', wc_yotpo_get_product_data( $this->create_product() )['lang'], 'v2 widgets keep data-lang' );
	}

	public function test_the_loader_script_adds_the_language_and_is_versioned() {
		$this->set_locale( 'de_DE' );
		wc_yotpo_load_js();

		$script = wp_scripts()->registered['yquery'];
		$this->assertSame( filemtime( dirname( __DIR__ ) . '/trunk/assets/js/v3HeaderScript.js' ), $script->ver, 'Browsers get the new file after an update' );
		$source = file_get_contents( dirname( __DIR__ ) . '/trunk/assets/js/v3HeaderScript.js' );
		$this->assertStringContainsString( '"?languageCode=" + encodeURIComponent(yotpo_settings.language_code)', $source );
	}

	/** The settings the storefront script gets (wp_localize_script), as an array. */
	private function loader_settings() {
		wp_dequeue_script( 'yquery' );
		wp_deregister_script( 'yquery' );
		wc_yotpo_load_js();
		$data = wp_scripts()->get_data( 'yquery', 'data' );
		$this->assertIsString( $data );
		$json = substr( $data, strpos( $data, '{' ) );
		return json_decode( rtrim( trim( $json ), ';' ), true );
	}

	private function set_locale( $locale ) {
		add_filter(
			'locale',
			function () use ( $locale ) {
				return $locale;
			}
		);
	}
}
