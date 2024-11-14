<?php

function generate_v3_qna_widget_code($product, $qna_widget_id) {
	$product_data = wc_yotpo_get_product_data($product);
	return "<div class='qna yotpo-widget-instance'
		data-yotpo-instance-id='" . esc_attr($qna_widget_id) . "'
		data-yotpo-product-id='" . esc_attr($product_data['id']) . "'
		data-yotpo-name='" . esc_attr($product_data['title']) . "'
		data-yotpo-url='" . esc_attr($product_data['url']) . "'
		data-yotpo-image-url='" . esc_attr($product_data['image-url']) . "'
		data-yotpo-description='" . esc_attr($product_data['description']) . "'
	></div>";
}
