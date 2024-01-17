<?php

function generate_v3_reviews_tab_widget_code(string $reviews_tab_widget_id, int $product_id = null): string {
	if ($product_id) {
		return "<div class='qna yotpo-widget-instance'
			data-yotpo-instance-id='".$reviews_tab_widget_id."'
			data-yotpo-product-id='".$product_id."'
		></div>";
	} else {
		return "<div class='qna yotpo-widget-instance'
			data-yotpo-instance-id='".$reviews_tab_widget_id."'
		></div>";
	}
}
