<?php

// FOOTER RENDER
function v2_product_widgets_render_in_footer() {
	add_action('woocommerce_after_single_product', 'wc_yotpo_show_reviews_widget', 10);
}
function v3_product_widgets_render_in_footer($v3_widgets_enables) {
	if($v3_widgets_enables['qna_product']) {	
		add_action('woocommerce_after_single_product', 'wc_yotpo_show_qna_widget',8);
	}
	if($v3_widgets_enables['reviews_widget_product']) {
		add_action('woocommerce_after_single_product', 'wc_yotpo_show_reviews_widget', 10);
	}
}

// TABS RENDER
function v2_product_widgets_render_in_tabs() {
	add_action('woocommerce_product_tabs', 'wc_yotpo_show_main_widget_in_tab');
}

// BOTTOM LINES / STAR RATING RENDER
function render_bottom_line_widgets() {
	$settings = get_option('yotpo_settings',wc_yotpo_get_default_settings());
	
	if (use_v3_widgets()) {
		v3_render_bottom_line_widgets($settings['v3_widgets_enables']);
	} else {
		v2_render_bottom_line_widgets($settings['v2_widgets_enables']);
	}
}
function v2_render_bottom_line_widgets($v2_widgets_enables) {
	if($v2_widgets_enables['bottom_line_product']) {
		add_action('woocommerce_single_product_summary', 'wc_yotpo_show_buttomline',7);
		wp_enqueue_style('yotpoSideBootomLineStylesheet', plugins_url('../../assets/css/bottom-line.css', __FILE__));
	}
	if($v2_widgets_enables['qna_product']) {
		add_action('woocommerce_single_product_summary', 'wc_yotpo_show_qa_bottomline',8);
	}
}
function v3_render_bottom_line_widgets($v3_widgets_enables) {
	if($v3_widgets_enables['star_rating_product']) {	
		add_action('woocommerce_single_product_summary', 'wc_yotpo_show_buttomline',7);	
	}
}
