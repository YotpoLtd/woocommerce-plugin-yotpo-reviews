<?php

function generate_v3_reviews_carousel_widget_code(string $carousel_widget_id, int $id = null): string {
	if ($id) {
		return "<div class='qna yotpo-widget-instance'
			data-yotpo-instance-id='".$carousel_widget_id."'
			data-yotpo-product-id='".$id."'
		></div>";
	} else {
		return "<div class='qna yotpo-widget-instance'
			data-yotpo-instance-id='".$carousel_widget_id."'
		></div>";
	}
}
