<?php

function generate_v3_seo_widget_code($product, $seo_widget_id) {
	$product_data = wc_yotpo_get_product_data($product);
	return "<div class='qna yotpo-widget-instance'
		data-yotpo-instance-id='".$seo_widget_id."'
		data-yotpo-product-id='".$product_data['id']."'
	></div>";
}
