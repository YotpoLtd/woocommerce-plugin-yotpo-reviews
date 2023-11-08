<?php

function generate_v3_reviews_carousel_widget_code($product, $carousel_widget_id) {
	$product_data = wc_yotpo_get_product_data($product);
	return "<div class='qna yotpo-widget-instance'
		data-yotpo-instance-id='".$carousel_widget_id."'
		data-yotpo-product-id='".$product_data['id']."'
	></div>";
}
