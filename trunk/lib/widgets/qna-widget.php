<?php

function generate_v3_qna_widget_code($product, $qna_widget_id) {
	$product_data = wc_yotpo_get_product_data($product);
	return "<div class='qna yotpo-widget-instance'
		data-yotpo-instance-id='".$qna_widget_id."'
		data-yotpo-product-id='".$product_data['id']."'
		data-yotpo-name='".$product_data['title']."'
		data-yotpo-url='".$product_data['url']."'
		data-yotpo-image-url='".$product_data['image-url']."'
		data-yotpo-description='".$product_data['description']."'
	></div>";
}
