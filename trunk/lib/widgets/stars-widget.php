<?php

function generate_v2_star_ratings_widget_code($product) {
	$product_data = wc_yotpo_get_product_data($product);	
	return "
		<script>
			jQuery(document).ready(function() {
				jQuery('div.bottomLine').click(function() {
					if (jQuery('li.yotpo_widget_tab>a').length) { jQuery('li.yotpo_widget_tab>a').click(); }
				})
			})
		</script>
		<div class='yotpo bottomLine' 
			data-product-id='".$product_data['id']."'
			data-url='".$product_data['url']."' 
			data-lang='".$product_data['lang']."'
		></div>";
}

function generate_v3_star_ratings_widget_code($product, $star_ratings_widget_id) {
	$product_data = wc_yotpo_get_product_data($product);	
	return "<div class='yotpo-widget-instance'
		data-yotpo-instance-id='".$star_ratings_widget_id."'
		data-yotpo-product-id='".$product_data['id']."'
	></div>";
}
