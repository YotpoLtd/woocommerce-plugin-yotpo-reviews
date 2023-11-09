<?php

function generate_v3_reviews_tab_widget_code($product, $reviews_tab_widget_id) {
	$product_data = wc_yotpo_get_product_data($product);
	return "<div class='qna yotpo-widget-instance'
		data-yotpo-instance-id='".$reviews_tab_widget_id."'
		data-yotpo-product-id='".$product_data['id']."'
	></div>";
}
