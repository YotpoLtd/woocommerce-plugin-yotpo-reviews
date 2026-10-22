<?php

defined( 'ABSPATH' ) || exit;

/**
 * The widgets wc_yotpo_show_custom_widgets() renders where a theme calls it, one per instance ID from the
 * wc_yotpo_custom_widget_instances filter (none by default), for example in the theme's functions.php:
 *
 *     add_filter( 'wc_yotpo_custom_widget_instances', function ( $ids ) {
 *         $ids[] = '123456'; // The widget's instance ID, from the Yotpo dashboard.
 *         return $ids;
 *     } );
 *
 * Until 2.0.0 this file had one instance ID written in it, from a single Yotpo account.
 */
function generate_v3_custom_widgets_code($product): array {
	$product_data = wc_yotpo_get_product_data($product);
	$codes = array();
	foreach ((array) apply_filters('wc_yotpo_custom_widget_instances', array(), $product) as $instance_id) {
		$instance_id = trim((string) $instance_id);
		if ('' === $instance_id) {
			continue;
		}
		$codes[] = "<div class='yotpo-widget-instance'
		data-yotpo-instance-id='".esc_attr($instance_id)."'
		data-yotpo-product-id='".esc_attr($product_data['id'])."'
	></div>";
	}
	return $codes;
}
