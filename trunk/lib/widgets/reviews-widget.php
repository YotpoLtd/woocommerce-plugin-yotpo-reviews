<?php

function generate_v2_reviews_widget_code($product, $currency) {
	$product_data = wc_yotpo_get_product_data($product);
	return "<div class='yotpo yotpo-main-widget'
		data-product-id='".$product_data['id']."'
		data-name='".$product_data['title']."' 
		data-url='".$product_data['url']."' 
		data-image-url='".$product_data['image-url']."' 
		data-description='".$product_data['description']."' 
		data-lang='".$product_data['lang']."'
		data-price='".$product->get_price()."'
		data-currency='".$currency."'
	></div>";
}
function generate_v3_reviews_widget_code($product, $reviews_widget_id, $currency) {
	$product_data = wc_yotpo_get_product_data($product);
	return "<div class='yotpo-widget-instance'
		data-yotpo-instance-id='".$reviews_widget_id."'
		data-yotpo-product-id='".$product_data['id']."'
		data-yotpo-name='".$product_data['title']."'
		data-yotpo-url='".$product_data['url']."'
		data-yotpo-image-url='".$product_data['image-url']."'
		data-yotpo-price='".$product->get_price()."'
		data-yotpo-currency='".$currency."'
		data-yotpo-description='".$product_data['description']."'
	></div>";
}
