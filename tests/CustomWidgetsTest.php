<?php
/**
 * wc_yotpo_show_custom_widgets(), which themes call on product pages, renders the store's own widget
 * instances from the wc_yotpo_custom_widget_instances filter.
 */
class CustomWidgetsTest extends Yotpo_Test_Case {

	public function tear_down() {
		remove_all_filters( 'wc_yotpo_custom_widget_instances' );
		unset( $GLOBALS['product'] );
		parent::tear_down();
	}

	public function test_no_widget_without_instance_ids() {
		$this->assertSame( '', $this->render( $this->create_product() ), "No other account's widget" );
	}

	public function test_each_instance_id_from_the_filter_renders_a_widget() {
		$product = $this->create_product();
		add_filter(
			'wc_yotpo_custom_widget_instances',
			function ( $ids, $filtered_product ) use ( $product ) {
				$this->assertSame( $product->get_id(), $filtered_product->get_id(), 'The filter gets the product' );
				return array_merge( $ids, array( '111', ' ', '222' ) );
			},
			10,
			2
		);

		$html = $this->render( $product );

		$this->assertSame( 2, substr_count( $html, 'yotpo-widget-instance' ), 'An empty ID is skipped' );
		foreach ( array( '111', '222' ) as $id ) {
			$this->assertStringContainsString( 'data-yotpo-instance-id="' . $id . '"', $html );
		}
		$this->assertStringContainsString( 'data-yotpo-product-id="' . $product->get_id() . '"', $html );
	}

	public function test_no_widget_when_reviews_are_off_for_the_product() {
		$product = $this->create_product();
		$product->set_reviews_allowed( false );
		$product->save();
		add_filter(
			'wc_yotpo_custom_widget_instances',
			function () {
				return array( '111' );
			}
		);

		$this->assertSame( '', $this->render( wc_get_product( $product->get_id() ) ) );
	}

	private function render( $product ) {
		$GLOBALS['product'] = $product;
		ob_start();
		wc_yotpo_show_custom_widgets();
		return trim( ob_get_clean() );
	}
}
