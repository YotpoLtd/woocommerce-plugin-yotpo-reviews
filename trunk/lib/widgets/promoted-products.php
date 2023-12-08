<?php

function generate_v3_promoted_products_widget_code(string $promoted_products_widget_id, int $id = null) {
	if ($id) {
		return "<div class='yotpo-widget-instance'
			data-yotpo-instance-id='".$promoted_products_widget_id."'
			data-yotpo-product-id='".$id."'
		></div>";
	} else {
		return "<div class='yotpo-widget-instance'
			data-yotpo-instance-id='".$promoted_products_widget_id."'
		></div>";
	}
}
