<?php
/*
	Plugin Name: Yotpo
	Description: The #1 reviews add-on for SMBs. Generate beautiful, trusted reviews for your shop.
	Author: Yotpo
	Version: 1.0
	Author URI: http://www.yotpo.com	
	Text Domain: health-check
	Domain Path: /lang
 */
include( plugin_dir_path( __FILE__ ) . 'templates/settings.php');
include( plugin_dir_path( __FILE__ ) . 'lib/yotpo-api/bootstrap.php');

add_action( 'admin_menu', 'yotpo_admin_settings' );

if(yotpo_is_who_commerce_installed()) {
	$yotpo_settings = get_option('yotpo_settings', yotpo_get_degault_settings());
	if(!empty($yotpo_settings['app_key'])) {
		add_action( 'template_redirect', 'yotpo_front_end_init' );
		if(!is_admin()) {
			add_action( 'wp_enqueue_scripts', 'yotpo_load_js' );	
		}				
		if(!empty($yotpo_settings['secret'])) {
			add_action( 'woocommerce_order_status_completed', 'yotpo_map');	
		}				
	}		
}

register_activation_hook(   __FILE__, 'yotpo_activation' );
register_uninstall_hook( __FILE__, 'yotpo_uninstall' );

function yotpo_admin_settings() {
	wp_register_style( 'yotpoSettingsStylesheet', plugins_url('yotpo.css', __FILE__));
	wp_register_style( 'yotpoSideLogoStylesheet', plugins_url('side-menu-logo.css', __FILE__));
	wp_enqueue_style( 'yotpoSideLogoStylesheet');
	
	$page = add_menu_page( 'Yotpo', 'Yotpo', 'manage_options', 'yotpo-settings-page', 'display_yotpo_admin_page', 'none', 81 );
	//load only whe yotpo settings page is displayed.
	add_action( 'admin_print_styles-' . $page, 'yotpo_add_css' );	
	
	yotpo_show_notification();	
}

function yotpo_show_notification() {
	if (!yotpo_is_who_commerce_installed()) {
		add_action('admin_notices', create_function('', 'echo "<div class=\'updated fade\'<p><strong>Yotpo - </strong> WooCommerce is not installed</p></div>";'));
	}	
	else {
		$yotpo_settings = get_option('yotpo_settings', false);
		if($yotpo_settings == false || (is_array($yotpo_settings) && empty($yotpo_settings['app_key']))) {
			add_action('admin_notices', create_function('', 'echo "<div class=\'updated fade\'<p><strong>Yotpo - </strong>Set your API key in order the Yotpo plugin to work correctly</p></div>";'));	
		}
	}	
}

function yotpo_front_end_init() {
	$settings = get_option('yotpo_settings',yotpo_get_degault_settings());
	add_action('woocommerce_thankyou', 'yotpo_conversion_track');	
			
	if(is_product()) {
		$widget_location = $settings['widget_location'];					
		add_action('woocommerce_product_tabs', 'yotpo_remove_native_review_system');
		if($widget_location == 'footer') {		
			add_action('woocommerce_after_single_product', 'yotpo_show_widget', 10);
		}
		elseif($widget_location == 'tab') {
			add_action('woocommerce_product_tabs', 'yotpo_show_widget_in_tab');		
		}
		if($settings['bottom_line_enabled_product']) {	
			add_action('woocommerce_single_product_summary', 'yotpo_show_botomline',7);	
		}			
	}
	elseif ($settings['bottom_line_enabled_category']) {
		add_action('woocommerce_after_shop_loop_item_title', 'yotpo_show_botomline',7);
	}							
}

function yotpo_activation() {
	if(current_user_can( 'activate_plugins' )) {
	    $plugin = isset( $_REQUEST['plugin'] ) ? $_REQUEST['plugin'] : '';
    	check_admin_referer( "activate-plugin_{$plugin}" );
		$default_settings = get_option('yotpo_settings', false);
		if(!is_array($default_settings)) {
			add_option('yotpo_settings', yotpo_get_degault_settings());
		}	
	}        
}

function yotpo_uninstall() {
	if(current_user_can( 'activate_plugins' ) && __FILE__ == WP_UNINSTALL_PLUGIN ) {
		check_admin_referer( 'bulk-plugins' );
		delete_option('yotpo_settings');	
	}	
}

function yotpo_show_widget() {
	$product_data = yotpo_get_product_data();	
	$yotpo_div = "<div class='yotpo reviews' 
 				data-appkey='".$product_data['app_key']."'
   				data-domain='".$product_data['shop_domain']."'
   				data-product-id='".$product_data['id']."'
   				data-product-models=''
   				data-name='".$product_data['title']."' 
   				data-url='".$product_data['url']."' 
   				data-image-url='' 
  				data-description='' 
  				data-bread-crumbs=''
  				data-lang='".$product_data['lang']."'></div>";
	echo $yotpo_div;						
}

function yotpo_show_widget_in_tab($tabs) {
	$settings = get_option('yotpo_settings', yotpo_get_degault_settings());
 	$tabs['yotpo_widget'] = array(
 	'title' => $settings['widget_tab_name'],
 	'priority' => 50,
 	'callback' => 'yotpo_show_widget'
 	);
 	return $tabs;		
}

function yotpo_load_js(){
	if(yotpo_is_who_commerce_installed() && !is_admin()) {	
    	wp_enqueue_script( 'yquery', 'https://www.yotpo.com/js/yQuery.js',null,null);
	}
}

function yotpo_is_who_commerce_installed() {
	return in_array('woocommerce/woocommerce.php', apply_filters('active_plugins', get_option('active_plugins')));
}

function yotpo_show_botomline($summery) {	
	$product_data = yotpo_get_product_data();	
	$yotpo_div = "</br><div class='yotpo bottomLine' 
   				data-appkey='".$product_data['app_key']."'
   				data-domain='".$product_data['shop_domain']."'
   				data-product-id='".$product_data['id']."'
   				data-product-models=''
   				data-name='".$product_data['title']."' 
   				data-url='".$product_data['url']."' 
   				data-image-url='".$product_data['image-url']."' 
   				data-description='".$product_data['description']."' 
   				data-bread-crumbs=''
   				data-lang='".$product_data['lang']."'></div>";
	echo $yotpo_div;				
}

function yotpo_get_product_data() {
	$product = get_product();
	$product_data = array();
	$settings = get_option('yotpo_settings',yotpo_get_degault_settings());
	$product_data['app_key'] = $settings['app_key'];
	$product_data['shop_domain'] = yotpo_get_shop_domain(); 
	$product_data['url'] = get_page_link();
	$product_data['lang'] = $settings['language_code']; 
	if($settings['yotpo_language_as_site'] == true) {

	}
	$product_data['description'] = strip_tags($product->get_post_data()->post_excerpt);
	$product_data['id'] = $product->id;	
	$product_data['title'] = $product->get_title();
	$product_data['image-url'] = yotpo_get_product_image_url($product->id);
	return $product_data;
}

function yotpo_get_shop_domain() {
	return parse_url(get_bloginfo('url'),PHP_URL_HOST);
}

function yotpo_remove_native_review_system($tabs) {
	 unset($tabs['reviews']);
	 return $tabs;
}

function yotpo_add_css() {
	wp_enqueue_style( 'yotpoSettingsStylesheet' );
}

function yotpo_map($order_id) {
	try {
			$purchase_data = yotpo_get_single_map_data($order_id);
			if(!is_null($purchase_data) && is_array($purchase_data)) {
				$yotpo_settings = get_option('yotpo_settings', yotpo_get_degault_settings());
				$yotpo_api = new \Yotpo\Yotpo($yotpo_settings['app_key'], $yotpo_settings['secret']);
				$get_oauth_token_response = $yotpo_api->get_oauth_token();
				if(!empty($get_oauth_token_response) && !empty($get_oauth_token_response->access_token)) {
					$purchase_data['utoken'] = $get_oauth_token_response->access_token;
					$purchase_data['platform'] = 'prestashop';
					$response = $yotpo_api->create_purchase($purchase_data);			
			}
		}		
	}
	catch (Exception $e) {
		//nothing to do here..
	}

}

function yotpo_get_single_map_data($order_id) {
	$order = new WC_Order($order_id);
	$data = null;
	if(!is_null($order->id)) {
		$data = array();
		$data['order_date'] = $order->order_date;
		$data['email'] = $order->billing_email;
		$data['customer_name'] = $order->billing_first_name.' '.$order->billing_last_name;
		$data['order_id'] = $order_id;
		$data['currency_iso'] = $order->order_custom_fields['_order_currency'];
		if(is_array($data['currency_iso'])) {
			$data['currency_iso'] = $data['currency_iso'][0];
		}
		$products_arr = array();
		foreach ($order->get_items() as $product) 
		{
			$product_instance = get_product($product['product_id']);
			$product_data = array();    
			$product_data['url'] = get_permalink($product['product_id']); 
			$product_data['name'] = $product['name'];
			$product_data['image'] = yotpo_get_product_image_url($product['product_id']);
			$product_data['description'] = strip_tags($product_instance->get_post_data()->post_excerpt);
			$product_data['price'] = $product['line_total'];
			$products_arr[$product['product_id']] = $product_data;	
		}	
		$data['products'] = $products_arr;
	}
	return $data;
}

function yotpo_get_product_image_url($product_id) {
	$url = wp_get_attachment_url(get_post_thumbnail_id($product_id));
	return $url ? $url : null;
}

function yotpo_get_past_orders() {
	$result = null;
	$args = array(
		'post_type'			=> 'shop_order',
        'posts_per_page' 	=> -1,
		'tax_query' => array(
			array(
				'taxonomy' => 'shop_order_status',
				'field' => 'slug',
				'terms' => array('completed'),
				'operator' => 'IN'
			)
		)	
	);	
	add_filter( 'posts_where', 'yotpo_past_order_time_query' );
	$query = new WP_Query( $args );
	remove_filter( 'posts_where', 'yotpo_past_order_time_query' );
	wp_reset_query();
	if ($query->have_posts()) {
		$orders = array();
		while ($query->have_posts()) { 
			$query->the_post();
			$order = $query->post;		
			$single_order_data = yotpo_get_single_map_data($order->ID);
			if(!is_null($single_order_data)) {
				$orders[] = $single_order_data;
			}      	
		}
		if(count($orders) > 0) {
			$post_bulk_orders = array_chunk($orders, 1000);
			$result = array();
			foreach ($post_bulk_orders as $index => $bulk)
			{
				$result[$index] = array();
				$result[$index]['orders'] = $bulk;
				$result[$index]['platform'] = 'prestashop';			
			}
		}		
	}
	return $result;
}

function yotpo_past_order_time_query( $where = '' ) {
	// posts in the last 30 days
	$where .= " AND post_date > '" . date('Y-m-d', strtotime('-90 days')) . "'";
	return $where;
}

function yotpo_send_past_orders() {
	$yotpo_settings = get_option('yotpo_settings', yotpo_get_degault_settings());
	if (!empty($yotpo_settings['app_key']) && !empty($yotpo_settings['secret']))
	{
		$past_orders = yotpo_get_past_orders();
		$is_success = true;
		if(!is_null($past_orders) && is_array($past_orders)) {
			$yotpo_api = new \Yotpo\Yotpo($yotpo_settings['app_key'], $yotpo_settings['secret']);
			$get_oauth_token_response = $yotpo_api->get_oauth_token();
			if(!empty($get_oauth_token_response) && !empty($get_oauth_token_response->access_token)) {
				foreach ($past_orders as $post_bulk) 
					if (!is_null($post_bulk))
					{
						$post_bulk['utoken'] = $get_oauth_token_response->access_token;
						$response = $yotpo_api->create_purchases($post_bulk);						
						if ($response->code != 200 && $is_success)
						{
							$is_success = false;
							yotpo_display_error_message($response->status->message);
						}
					}
				if ($is_success)
				{
					yotpo_show_notification('Past orders sent successfully');
					$yotpo_settings['show_submit_past_orders'] = false;
					update_option('yotpo_settings', $yotpo_settings);
				}	
			}
		}
		else {
			yotpo_display_error_message('Could not retrieve past orders');
		}	
	}
	else {
		yotpo_display_error_message('You need to set your app key and secret token to post past orders');
	}		
}

function yotpo_conversion_track($order_id) {
	$yotpo_settings = get_option('yotpo_settings', yotpo_get_degault_settings());
	$order = new WC_Order($order_id);
	$currency = $order->order_custom_fields['_order_currency'];
	if(is_array($currency)) {
		$currency = $currency[0];
	}
	$conversion_params = "app_key="      .$yotpo_settings['app_key'].
           				 "&order_id="    .$order_id.
           				 "&order_amount=".$order->get_total().
           				 "&order_currency="  .$currency;
	echo "<img 
   	src='https://api.yotpo.com/conversion_tracking.gif?$conversion_params'
	width='1'
	height='1'></img>";
}