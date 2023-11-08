<?php

function generate_v3_promoted_products_widget_code($product, $promoted_products_widget_id) {
	$product_data = wc_yotpo_get_product_data($product);
	return "<div class='yotpo-widget-instance'
		data-yotpo-instance-id='".$promoted_products_widget_id."'
		data-yotpo-product-id='".$product_data['id']."'
	></div>";
}
