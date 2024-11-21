<?php

function generate_v3_promoted_products_widget_code(string $promoted_products_widget_id, int $product_id = null) {
	if ($product_id) {
		return "<div class='yotpo-widget-instance'
			data-yotpo-instance-id='".esc_attr($promoted_products_widget_id)."'
			data-yotpo-product-id='".esc_attr($product_id)."'
		></div>";
	} else {
		return "<div class='yotpo-widget-instance'
			data-yotpo-instance-id='".esc_attr($promoted_products_widget_id)."'
		></div>";
	}
}
