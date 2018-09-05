<?php
/*
	Plugin Name: Yotpo Social Reviews for Woocommerce
	Description: Yotpo Social Reviews helps Woocommerce store owners generate a ton of reviews for their products. Yotpo is the only solution which makes it easy to share your reviews automatically to your social networks to gain a boost in traffic and an increase in sales.
	Author: Yotpo
	Version: Project Woo, Beta
	Author URI: http://www.yotpo.com?utm_source=yotpo_plugin_woocommerce&utm_medium=plugin_page_link&utm_campaign=woocommerce_plugin_page_link	
	Plugin URI: http://www.yotpo.com?utm_source=yotpo_plugin_woocommerce&utm_medium=plugin_page_link&utm_campaign=woocommerce_plugin_page_link
 */
register_activation_hook(   __FILE__, 'wc_yotpo_activation' );
register_uninstall_hook( __FILE__, 'wc_yotpo_uninstall' );
register_deactivation_hook( __FILE__, 'wc_yotpo_deactivate' );
add_action('plugins_loaded', 'wc_yotpo_init');
add_action('init', 'wc_yotpo_redirect');
add_action( 'woocommerce_order_status_changed', 'wc_yotpo_map');

function wc_yotpo_init() {
	$is_admin = is_admin();	
	if($is_admin) {
		if (isset($_GET['download_exported_reviews'])) {
			if(current_user_can('manage_options')) {
				require('classes/class-wc-yotpo-export-reviews.php');	
				$export = new Yotpo_Review_Export();
				list($file, $errors) = $export->exportReviews();	
				if(is_null($errors)) {
					$export->downloadReviewToBrowser($file);	
				}
			}
			exit;
		}		
		include( plugin_dir_path( __FILE__ ) . 'templates/wc-yotpo-settings.php');
		include(plugin_dir_path( __FILE__ ) . 'lib/yotpo-api/Yotpo.php');
		add_action( 'admin_menu', 'wc_yotpo_admin_settings' );
	}
	$yotpo_settings = get_option('yotpo_settings', wc_yotpo_get_degault_settings());
	if(!empty($yotpo_settings['app_key']) && wc_yotpo_compatible()) {			
		if(!$is_admin) {
			add_action( 'wp_enqueue_scripts', 'wc_yotpo_load_js' );
			add_action( 'template_redirect', 'wc_yotpo_front_end_init' );	
		}								
	}			
}

function wc_yotpo_redirect() {
	if ( get_option('wc_yotpo_just_installed', false)) {
		delete_option('wc_yotpo_just_installed');
		wp_redirect( ( ( is_ssl() || force_ssl_admin() || force_ssl_login() ) ? str_replace( 'http:', 'https:', admin_url( 'admin.php?page=woocommerce-yotpo-settings-page' ) ) : str_replace( 'https:', 'http:', admin_url( 'admin.php?page=woocommerce-yotpo-settings-page' ) ) ) );
		exit;
	}	
}

function wc_yotpo_admin_settings() {
	add_action( 'admin_enqueue_scripts', 'wc_yotpo_admin_styles' );	
	$page = add_menu_page( 'Yotpo', 'Yotpo', 'manage_options', 'woocommerce-yotpo-settings-page', 'wc_display_yotpo_admin_page', 'none', null );			
}

function wc_yotpo_front_end_init() {
	$settings = get_option('yotpo_settings',wc_yotpo_get_degault_settings());
	add_action('woocommerce_thankyou', 'wc_yotpo_conversion_track');		
	if(is_product()) {
		
		$widget_location = $settings['widget_location'];	
		if($settings['disable_native_review_system']) {
			add_filter( 'comments_open', 'wc_yotpo_remove_native_review_system', null, 2);	
		}						
		if($widget_location == 'footer') {		
			add_action('woocommerce_after_single_product', 'wc_yotpo_show_widget', 10);
		}
		elseif($widget_location == 'tab') {
			add_action('woocommerce_product_tabs', 'wc_yotpo_show_widget_in_tab');		
		}
		if($settings['bottom_line_enabled_product']) {	
			add_action('woocommerce_single_product_summary', 'wc_yotpo_show_buttomline',7);	
			wp_enqueue_style('yotpoSideBootomLineStylesheet', plugins_url('assets/css/bottom-line.css', __FILE__));
		}			
	}
	 elseif ($settings['bottom_line_enabled_category']) {
        add_action('woocommerce_after_shop_loop_item', 'wc_yotpo_show_buttomline', 7);
        wp_enqueue_style('yotpoSideBootomLineStylesheet', plugins_url('assets/css/bottom-line.css', __FILE__));
    }
}

function wc_yotpo_activation() {
	if(current_user_can( 'activate_plugins' )) {
		update_option('wc_yotpo_just_installed', true);
	    $plugin = isset( $_REQUEST['plugin'] ) ? $_REQUEST['plugin'] : '';
    	check_admin_referer( "activate-plugin_{$plugin}" );
		$default_settings = get_option('yotpo_settings', false);
		if(!is_array($default_settings)) {
			add_option('yotpo_settings', wc_yotpo_get_degault_settings());
		}
		update_option('native_star_ratings_enabled', get_option('woocommerce_enable_review_rating'));
		update_option('woocommerce_enable_review_rating', 'no');			
	}        
}

function wc_yotpo_uninstall() {
	if(current_user_can( 'activate_plugins' ) && __FILE__ == WP_UNINSTALL_PLUGIN ) {
		check_admin_referer( 'bulk-plugins' );
		delete_option('yotpo_settings');	
	}	
}

function wc_yotpo_show_widget() {		 
	$product = get_product();
	if($product->post->comment_status == 'open') {		
		$product_data = wc_yotpo_get_product_data($product);
		$yotpo_div = "<div class='yotpo yotpo-main-widget'
	   				data-product-id='".$product_data['id']."'
	   				data-name='".$product_data['title']."' 
	   				data-url='".$product_data['url']."' 
	   				data-image-url=''
	  				data-description='".$product_data['description']."' 
	  				data-lang='".$product_data['lang']."'></div>";
		echo $yotpo_div;
	}						
}

function wc_yotpo_show_widget_in_tab($tabs) {
	$product = get_product();
	if($product->post->comment_status == 'open') {
		$settings = get_option('yotpo_settings', wc_yotpo_get_degault_settings());
	 	$tabs['yotpo_widget'] = array(
	 	'title' => $settings['widget_tab_name'],
	 	'priority' => 50,
	 	'callback' => 'wc_yotpo_show_widget'
	 	);
	}
	return $tabs;		
}

function wc_yotpo_load_js(){
	if(wc_yotpo_is_who_commerce_installed()) {		
    	wp_enqueue_script('yquery', plugins_url('assets/js/headerScript.js', __FILE__) ,null,null);
		$settings = get_option('yotpo_settings',wc_yotpo_get_degault_settings());
		wp_localize_script('yquery', 'yotpo_settings', array('app_key' => $settings['app_key']));    	    	
	}
}

function wc_yotpo_is_who_commerce_installed() {
    $wooVer =  WooCommerce::plugin_path();
    $findme   = "plugins";
    $pos = strpos($wooVer, $findme)+8;
    $pluginCheck =  substr($wooVer, $pos).'/woocommerce.php';
    $string = WooCommerce::plugin_path();
    return in_array($pluginCheck, apply_filters('active_plugins', get_option('active_plugins')));
}

function wc_yotpo_show_qa_bottomline() {
    $product_data = wc_yotpo_get_product_data(get_product());
    echo "<div class='yotpo QABottomLine'
         data-appkey='".$product_data['app_key']."'
         data-product-id='".$product_data['id']."'></div>";
}

function wc_yotpo_show_buttomline() {
	$product = get_product();
	$show_bottom_line = is_product() ? $product->post->comment_status == 'open' : true;
	if($show_bottom_line) {
		$product_data = wc_yotpo_get_product_data($product);	
		$yotpo_div = "<div class='yotpo bottomLine' 
	   				data-product-id='".$product_data['id']."'
	   				data-url='".$product_data['url']."' 
	   				data-lang='".$product_data['lang']."'></div>";
		echo $yotpo_div;	
	}	
				
}

function wc_yotpo_get_product_data($product) {	
	$product_data = array();
	$settings = get_option('yotpo_settings',wc_yotpo_get_degault_settings());
	$product_data['app_key'] = $settings['app_key'];
	$product_data['shop_domain'] = wc_yotpo_get_shop_domain(); 
	$product_data['url'] = get_permalink($product->id);
	$product_data['lang'] = $settings['language_code']; 
	if($settings['yotpo_language_as_site'] == true) {
		$lang = explode('-', get_bloginfo('language'));
		// In some languages there is a 3 letters language code
		//TODO map these iso-639-2 to iso-639-1 (from 3 letters language code to 2 letters language code) 
		if(strlen($lang[0]) == 2) {
			$product_data['lang'] = $lang[0];	
		}		
	}
	$product_data['description'] = strip_tags($product->get_post_data()->post_excerpt);
	$product_data['id'] = $product->id;	
	$product_data['title'] = $product->get_title();
	$product_data['image-url'] = wc_yotpo_get_product_image_url($product->id);
        $specs_data = array();
            if($product->get_sku()){ $specs_data['external_sku'] =$product->get_sku();} 
            if($product->get_attribute('upc')){ $specs_data['upc'] =$product->get_attribute('upc');} 
            if($product->get_attribute('isbn')){ $specs_data['isbn'] = $product->get_attribute('isbn');} 
            if($product->get_attribute('brand')){ $specs_data['brand'] = $product->get_attribute('brand');} 
            if($product->get_attribute('mpn')){ $specs_data['mpn'] =$product->get_attribute('mpn');} 
            if(!empty($specs_data)){ $product_data['specs'] = $specs_data;  }
	return $product_data;
}

function wc_yotpo_get_shop_domain() {
	return parse_url(get_bloginfo('url'),PHP_URL_HOST);
}

function wc_yotpo_remove_native_review_system($open, $post_id) {
	if(get_post_type($post_id) == 'product') {
		return false;
	}
	return $open;
}

function wc_yotpo_map($order_id) {
    $order = wc_get_order($order_id);
    $orderStatus = 'wc-' . $order->get_status();
    $yotpo_settings = get_option('yotpo_settings', wc_yotpo_get_degault_settings());
    if ($orderStatus === $yotpo_settings['yotpo_order_status']) {
        $secret = $yotpo_settings['secret'];
        $app_key = $yotpo_settings['app_key'];
        if (!empty($app_key) && !empty($secret) && wc_yotpo_compatible()) {
            try {
                $purchase_data = wc_yotpo_get_single_map_data($order_id);
                if (!is_null($purchase_data) && is_array($purchase_data)) {
                    require_once(plugin_dir_path(__FILE__) . 'lib/yotpo-api/Yotpo.php');
                    $yotpo_api = new Yotpo($app_key, $secret);
                    $get_oauth_token_response = $yotpo_api->get_oauth_token();
                    if (!empty($get_oauth_token_response) && !empty($get_oauth_token_response['access_token'])) {
                        $purchase_data['utoken'] = $get_oauth_token_response['access_token'];
                        $purchase_data['platform'] = 'woocommerce';
                        $response = $yotpo_api->create_purchase($purchase_data);
                    }
                }
            } catch (Exception $e) {
                error_log($e->getMessage());
            }
        }
    }
}

function wc_yotpo_get_single_map_data($order_id) {
	$yotpo_settings = get_option('yotpo_settings', wc_yotpo_get_degault_settings());
	$variants_status = $yotpo_settings['product_variants']; //Get if the varinats is enabled or disabled
	$order = new WC_Order($order_id);
	$data = null;
	if(!is_null($order->id)) {
		$data = array();
		$data['order_date'] = $order->order_date;
		$data['email'] = $order->billing_email;
		$data['customer_name'] = $order->billing_first_name.' '.$order->billing_last_name;
		$data['order_id'] = $order_id;
		$data['currency_iso'] = wc_yotpo_get_order_currency($order);
		$products_arr = array();
		foreach ($order->get_items() as $product) 
		{
			$use_variant = ($variants_status && $product['variation_id']); //Check if the ordered product is a variant
            $_product = wc_get_product($product['product_id']);

            if(is_object($_product)){
				if($use_variant){ $_variant = wc_get_product($product['variation_id']); }
                $product_data = array();   
                $product_data['url'] = get_permalink($product['product_id']);

                $use_variant ? $product_data['name'] = $product['name'] : $product_data['name'] = $_product->get_title();

				if ($use_variant) { 
					$product_data['image'] = wc_yotpo_get_product_image_url($product['variation_id']); //get variant image
					if($product_data['image'] == null){ //if there's no variant image, get the parent product's image
						$product_data['image'] = wc_yotpo_get_product_image_url($product['product_id']);
					}
				} else {
					$product_data['image'] = wc_yotpo_get_product_image_url($product['product_id']); //no variant = take the pic of the parent product
				}

                $use_variant ? $product_data['description'] = $product['product_id'] : $product_data['description'] = strip_tags($_product->get_description());

                $product_data['price'] = $product['line_total'];
                $specs_data = array(); //there are no special specs for variants, all variants use the specs of the parent product.

                if($use_variant && $_variant->get_sku()){
                	$specs_data['external_sku'] = $_variant->get_sku();
                }
                else if($_product->get_sku()){
                	$specs_data['external_sku'] = $_product->get_sku();
                }

                if($_product->get_attribute('upc')){ $specs_data['upc'] =$_product->get_attribute('upc');} 
                if($_product->get_attribute('isbn')){ $specs_data['isbn'] = $_product->get_attribute('isbn');} 
                if($_product->get_attribute('brand')){ $specs_data['brand'] = $_product->get_attribute('brand');} 
                if($_product->get_attribute('mpn')){ $specs_data['mpn'] =$_product->get_attribute('mpn');} 
                if(!empty($specs_data)){ $product_data['specs'] = $specs_data;  }
            }

			$use_variant ? $products_arr[$product['variation_id']] = $product_data : $products_arr[$product['product_id']] = $product_data;
				
		}
		$data['products'] = $products_arr;
	}
	return $data;
}

function wc_yotpo_get_product_image_url($product_id) {
	$url = wp_get_attachment_url(get_post_thumbnail_id($product_id));
	return $url ? $url : null;
}

function wc_yotpo_get_past_orders() {
	$result = null;
	$args = array(
		'post_type'		 => 'shop_order',
		'posts_per_page' => -1
	);

	if (defined('WC_VERSION') && (version_compare(WC_VERSION, '2.2.0') >= 0)) {
		$args['post_status'] = 'wc-completed';
	} else {
		$args['tax_query'] = array(
			array(
				'taxonomy' => 'shop_order_status',
				'field'    => 'slug',
				'terms'    => array('completed'),
				'operator' => 'IN'
			)
		);
	}
	
	add_filter( 'posts_where', 'wc_yotpo_past_order_time_query' );
	$query = new WP_Query( $args );
	remove_filter( 'posts_where', 'wc_yotpo_past_order_time_query' );
	wp_reset_query();
	if ($query->have_posts()) {
		$orders = array();
		while ($query->have_posts()) { 
			$query->the_post();
			$order = $query->post;		
			$single_order_data = wc_yotpo_get_single_map_data($order->ID);
			if(!is_null($single_order_data)) {
				$orders[] = $single_order_data;
			}      	
		}
		if(count($orders) > 0) {
			$post_bulk_orders = array_chunk($orders, 200);
			$result = array();
			foreach ($post_bulk_orders as $index => $bulk)
			{
				$result[$index] = array();
				$result[$index]['orders'] = $bulk;
				$result[$index]['platform'] = 'woocommerce';			
			}
		}		
	}
	return $result;
}

function wc_yotpo_past_order_time_query( $where = '' ) {
	// posts in the last 30 days
	$where .= " AND post_date > '" . date('Y-m-d', strtotime('-90 days')) . "'";
	return $where;
}

function wc_yotpo_send_past_orders() {
   	$yotpo_settings = get_option('yotpo_settings', wc_yotpo_get_degault_settings());
	if (!empty($yotpo_settings['app_key']) && !empty($yotpo_settings['secret']))
	{
		$past_orders = wc_yotpo_get_past_orders();		
		$is_success = true;
		if(!is_null($past_orders) && is_array($past_orders)) {
			$yotpo_api = new Yotpo($yotpo_settings['app_key'], $yotpo_settings['secret']);
			$get_oauth_token_response = $yotpo_api->get_oauth_token();
			if(!empty($get_oauth_token_response) && !empty($get_oauth_token_response['access_token'])) {
				foreach ($past_orders as $post_bulk) 
					if (!is_null($post_bulk))
					{
						$post_bulk['utoken'] = $get_oauth_token_response['access_token'];
						$response = $yotpo_api->create_purchases($post_bulk);						
						if ($response['code'] != 200 && $is_success)
						{
							$is_success = false;
							$message = !empty($response['status']) && !empty($response['status']['message']) ? $response['status']['message'] : 'Error occurred';
							wc_yotpo_display_message($message, true);
						}
					}
				if ($is_success)
				{
					wc_yotpo_display_message('Past orders sent successfully' , false);
					$yotpo_settings['show_submit_past_orders'] = false;
					update_option('yotpo_settings', $yotpo_settings);
				}	
			}
		}
		else {
			wc_yotpo_display_message('Could not retrieve past orders', true);
		}	
	}
	else {
		wc_yotpo_display_message('You need to set your app key and secret token to post past orders', false);
	}		
}

function wc_yotpo_conversion_track($order_id) {
	$yotpo_settings = get_option('yotpo_settings', wc_yotpo_get_degault_settings());
	$order = new WC_Order($order_id);
	$currency = wc_yotpo_get_order_currency($order);
	
	$conversion_params = "app_key="      .$yotpo_settings['app_key'].
           				 "&order_id="    .$order_id.
           				 "&order_amount=".$order->get_total().
           				 "&order_currency="  .$currency;
	$APP_KEY = $yotpo_settings['app_key'];
	$DATA = "yotpoTrackConversionData = {orderId: ".$order_id.", orderAmount: ".$order->get_total().", orderCurrency: '".$currency."'}";
	$DATA_SCRIPT = "<script>".$DATA."</script>";
	$IMG = "<img 
   	src='https://api.yotpo.com/conversion_tracking.gif?$conversion_params'
	width='1'
	height='1'></img>";
	$NO_SCRIPT = "<noscript>".$IMG."</noscript>";
	echo $DATA_SCRIPT;
	echo $NO_SCRIPT;
}

function wc_yotpo_get_degault_settings() {
    return array('app_key' => '',
        'secret' => '',
        'widget_location' => 'footer',
        'language_code' => 'en',
        'widget_tab_name' => 'Reviews',
        'bottom_line_enabled_product' => true,
        'bottom_line_enabled_category' => false,
        'yotpo_language_as_site' => true,
        'show_submit_past_orders' => true,
        'product_variants' => false,
        'yotpo_order_status' => 'wc-completed',
        'disable_native_review_system' => true,
        'native_star_ratings_enabled' => 'no',
    	'product_catalog' => array());
}

function wc_yotpo_admin_styles($hook) {
	if($hook == 'toplevel_page_woocommerce-yotpo-settings-page') {		
		wp_enqueue_script( 'yotpoSettingsJs', plugins_url('assets/js/settings.js', __FILE__), array('jquery-effects-core'));				
		wp_enqueue_style( 'yotpoSettingsStylesheet', plugins_url('assets/css/yotpo.css', __FILE__));
	}
	wp_enqueue_style('yotpoSideLogoStylesheet', plugins_url('assets/css/side-menu-logo.css', __FILE__));
}

function wc_yotpo_compatible() {
	return version_compare(phpversion(), '5.2.0') >= 0 && function_exists('curl_init');
}

function wc_yotpo_deactivate() {
	update_option('woocommerce_enable_review_rating', get_option('native_star_ratings_enabled'));	
}

add_filter('woocommerce_tab_manager_integration_tab_allowed', 'wc_yotpo_disable_tab_manager_managment');

function wc_yotpo_disable_tab_manager_managment($allowed, $tab = null) {
	if($tab == 'yotpo_widget') {
		$allowed = false;
		return false;
	}
}

function wc_yotpo_get_order_currency($order) {
	if(is_null($order) || !is_object($order)) {
		return '';
	}
	if(method_exists($order,'get_order_currency')) { 
		return $order->get_order_currency();
	}
	if(isset($order->order_custom_fields) && isset($order->order_custom_fields['_order_currency'])) {		
 		if(is_array($order->order_custom_fields['_order_currency'])) {
 			return $order->order_custom_fields['_order_currency'][0];
 		}	
	}
	return '';
}

function wc_yotpo_log($log_msg)
{
	$log_directory = plugin_dir_path(__FILE__).'/log';
	
    if (!file_exists($log_directory)) 
    {
        // create directory/folder uploads.
        mkdir($log_directory, 0777, true);
    }
    $log_file = $log_directory.'/log_wc_yotpo '.gmdate("M d Y", time()).'.log';
    file_put_contents($log_file, $log_msg . "\n", FILE_APPEND);
}

function wc_yotpo_product_catalog_export($mode) {
	$is_successful = true;
    $yotpo_settings = get_option('yotpo_settings', wc_yotpo_get_degault_settings());
    $secret = $yotpo_settings['secret'];
    $app_key = $yotpo_settings['app_key'];
    if (!empty($app_key) && !empty($secret) && wc_yotpo_compatible()) {
        $products_data = wc_yotpo_get_catalog_api();
        if (!is_null($products_data) && is_array($products_data)) {
			$products_data_create = $products_data['create'];
			$products_data_update = $products_data['update'];
            require_once(plugin_dir_path(__FILE__) . 'lib/yotpo-api/Yotpo.php');
            $yotpo_api = new Yotpo($app_key, $secret);
            $get_oauth_token_response = $yotpo_api->get_oauth_token();
            if (!empty($get_oauth_token_response) && !empty($get_oauth_token_response['access_token'])) {
                if(!empty($products_data_create['products']) && ($mode == 'all' || $mode == 'create')){
					if (count($products_data_create['products']) > 500){ //if contains more than 500 prods
						$chunk_seperated = array_chunk($products_data_create['products'], 500, true);
                		$products_data_create_chunk = array();
                		foreach ($chunk_seperated as $products_chunk){
                			$products_data_create_chunk['products'] = $products_chunk;
                			$products_data_create_chunk['utoken'] = $get_oauth_token_response['access_token'];
							$products_data_create_chunk['platform'] = 'woocommerce';
							$response_create = wc_yotpo_catalog_mode($products_data_create_chunk, 'create', $yotpo_api, $app_key, $secret);
							if ($response_create['code'] != 200){ $is_successful = false; }
                		}
					}

					else{
						$products_data_create['utoken'] = $get_oauth_token_response['access_token'];
						$products_data_create['platform'] = 'woocommerce';
	                	$response_create = wc_yotpo_catalog_mode($products_data_create, 'create', $yotpo_api, $app_key, $secret);
	                	if ($response_create['code'] != 200){ $is_successful = false; }
					}
            	}
            	if(!empty($products_data_update['products']) && ($mode == 'all' || $mode == 'update')){
            		if (count($products_data_update['products']) > 500){ //if contains more than 500 prods
                		$chunk_seperated = array_chunk($products_data_update['products'], 500, true);
                		$products_data_update_chunk = array();
                		foreach ($chunk_seperated as $products_chunk){
                			$products_data_update_chunk['products'] = $products_chunk;
                			$products_data_update_chunk['utoken'] = $get_oauth_token_response['access_token'];
							$products_data_update_chunk['platform'] = 'woocommerce';
							$response_update = wc_yotpo_catalog_mode($products_data_update_chunk, 'update', $yotpo_api, $app_key, $secret);
							if ($response_update['code'] != 200){ $is_successful = false; }
                		}
					}

					else{
	            		$products_data_update['utoken'] = $get_oauth_token_response['access_token'];
						$products_data_update['platform'] = 'woocommerce';
	                	$response_update = wc_yotpo_catalog_mode($products_data_update, 'update', $yotpo_api, $app_key, $secret);
	                	if ($response_update['code'] != 200){ $is_successful = false; }
                	}
            	}
            }
        }
    }
    return $is_successful;
}

function wc_yotpo_get_catalog_api(){ //NOTE TO SELF: NEED TO ADD "if product is published to site"
	$yotpo_settings = get_option('yotpo_settings', wc_yotpo_get_degault_settings()); //Get yotpo_settings
	$current_catalog = $yotpo_settings['product_catalog']; //Get the catalog that already exists on our end
	$variants_status = $yotpo_settings['product_variants']; //Get if the varinats is enabled or disabled
	$products_arr_create=array();
	$products_arr_update=array();
	$args = array(
		'post_type' => 'product',
		'posts_per_page' => -1
		);
	$loop = new WP_Query( $args ); //get the store's catalog
	while ( $loop->have_posts() ){
		$loop->the_post();
		$product_post = &new WC_Product( $loop->post->ID );
		$_product = wc_get_product($product_post->get_id());
		if (get_post_status($product_post->get_id()) == 'publish'){ //only if the product is published, send to API
			$product_data=array();
			$product_id = $_product->get_id(); 
			$product_data['url'] = get_permalink($product_id);
			$product_data['name'] = $_product->get_title();
			$product_data['description'] = $product_id; //Using the description as parent product ID in order to group the products easily.
			$product_data['image'] = wc_yotpo_get_product_image_url($product_id);
			$product_data['price'] = $_product->get_price();
			$product_data['currency'] = get_woocommerce_currency();
			if($_product->get_sku()){ $specs_data['external_sku'] = $_product->get_sku();}
			if(!empty($specs_data)){ $product_data['specs'] = $specs_data;  }

			(!empty($current_catalog) && is_array($current_catalog) && in_array($product_id, $current_catalog)) ? $products_arr_update[$product_id] = $product_data : $products_arr_create[$product_id] = $product_data;

			//WORKING ON VARIABLES
			if ($_product->is_type('variable') && $variants_status) { //if product has variations and checkbox is enabled
				$available_variations = $_product->get_available_variations();
				if($available_variations){
					foreach($available_variations as $variation){
						$variation_id = $variation['variation_id'];
						$_variation = wc_get_product($variation_id);
						$variation_data=array();
						$variation_data['url'] = get_permalink($product_id);
						$variation_data['name'] = $_variation->get_name();
						$variation_data['description'] = $product_id; //in order for grouping to be comfortable
						$variation_data['price'] = $variation['display_price'];
						$variation_data['currency'] = get_woocommerce_currency();
						$variation_data['image'] = wc_yotpo_get_product_image_url($variation_id); //get variant image
						if($variation_data['image'] == null){ //if there's no variant image, get the parent product's image
							$variation_data['image'] = wc_yotpo_get_product_image_url($product_id);
						}

						if($_variation->get_sku()){ $specs_data['external_sku'] = $_variation->get_sku();}
						if(!empty($specs_data)){ $variation_data['specs'] = $specs_data;  }

						(!empty($current_catalog) && is_array($current_catalog) && in_array($variation_id, $current_catalog)) ? $products_arr_update[$variation_id] = $variation_data : $products_arr_create[$variation_id] = $variation_data;
					}
				}
			}
		}
	}
	$data['create']['products'] = $products_arr_create;
	$data['update']['products'] = $products_arr_update;
	return $data;
}

function wc_yotpo_catalog_mode($arr, $mode, $yotpo_api = null, $app_key = null, $secret = null){ //Preparing the function for further updates in the future
	$yotpo_settings = get_option('yotpo_settings', wc_yotpo_get_degault_settings());
    if (!empty($app_key) && !empty($secret) && wc_yotpo_compatible()) {
        try {
            if (!is_null($arr) && is_array($arr) && !empty($arr['products'])) {
                $get_oauth_token_response = $yotpo_api->get_oauth_token();
                if (!empty($get_oauth_token_response) && !empty($get_oauth_token_response['access_token'])) {
					$arr['utoken'] = $get_oauth_token_response['access_token'];
					$arr['platform'] = 'woocommerce';
					if ($mode == 'create'){
                		$response = $yotpo_api->create_mass_products($arr);
						if ($response['code'] == 200){ //Only if the call was succesfull, insert the new products into yotpo_settings.
							if(is_null($yotpo_settings['product_catalog'])){
								$yotpo_settings['product_catalog'] = array();
							}
							$yotpo_settings['product_catalog'] = array_merge($yotpo_settings['product_catalog'], array_keys($arr['products']));
							update_option('yotpo_settings', $yotpo_settings);

						}
						wc_yotpo_log("\r\n\r\n".gmdate('r', time())."\r\nAPI Call: ".$mode."_mass_products\r\nAPI Response: ".$response['code']."\r\nProducts Sent: ".implode(", ",array_keys($arr['products'])));
						return $response;
					}
					else if($mode == "update"){
						$response ;//= $yotpo_api->update_mass_products($arr);
						wc_yotpo_log("\r\n\r\n".gmdate('r', time())."\r\nAPI Call: ".$mode."_mass_products\r\nAPI Response: ".$response['code']."\r\nProducts Sent: ".implode(", ",array_keys($arr['products'])));
						return $response;
					}
                }
            }
        } catch (Exception $e) {
            error_log($e->getMessage());
        }
    }
}

function wc_yotpo_product_catalog_csv(){
	$yotpo_settings = get_option('yotpo_settings', wc_yotpo_get_degault_settings()); //Get yotpo_settings
	$variants_status = $yotpo_settings['product_variants']; //Get if the varinats is enabled or disabled
	$args = array(
		'post_type' => 'product',
		'posts_per_page' => -1
		);
	$loop = new WP_Query( $args ); //get the store's catalog
	$iteration = 0;
	$saveData = array();
	ob_end_clean();
	header('Content-type: application/utf-8');
	header('Content-disposition: attachment; filename="Yotpo Catalog Export.csv"');
	$fp = fopen('php://output', 'w'); 
	while ( $loop->have_posts() ){
		$loop->the_post();
		$product_post = &new WC_Product( $loop->post->ID );
		$_product = wc_get_product($product_post->get_id());
		if (get_post_status($product_post->get_id()) == 'publish'){
			$product_id = $_product->get_id(); 
			$saveData['Product ID'] = $product_id;
			$saveData['Product Name'] = $_product->get_title();
			$saveData['Product Description'] = '';
			$saveData['Product URL'] = get_permalink($product_id);
			$saveData['Product Image URL'] = wc_yotpo_get_product_image_url($product_id);
			$saveData['Product Price'] = $_product->get_price();
			$saveData['Currency'] = get_woocommerce_currency();
			$saveData['Spec UPC'] = '';
			$saveData['Spec SKU'] = $_product->get_sku();
			$saveData['Spec Brand'] = '';
			$saveData['Spec MPN'] = '';
			$saveData['Spec ISBN'] = '';
			$saveData['Blacklisted'] = 'false';
			$saveData['Product Group'] = '';

			if($iteration==0) fputcsv($fp, array_keys($saveData));
			fputcsv($fp, $saveData);
			$iteration++;

			if ($_product->is_type('variable') && $variants_status) { //if product has variations and checkbox is enabled
				$available_variations = $_product->get_available_variations();
				if($available_variations){
					foreach($available_variations as $variation){
						$variation_id = $variation['variation_id'];
						$_variation = wc_get_product($variation_id);
						$saveData['Product ID'] = $variation_id;
						$saveData['Product Name'] = $_variation->get_name();
						$saveData['Product Description'] = '';
						$saveData['Product URL'] = get_permalink($product_id);
						$saveData['Product Image URL'] = wc_yotpo_get_product_image_url($variation_id);
						if($saveData['Product Image URL'] == null){ //if there's no variant image, get the parent product's image
							$saveData['Product Image URL'] = wc_yotpo_get_product_image_url($product_id);
						}
						$saveData['Product Price'] = $variation['display_price'];
						$saveData['Currency'] = get_woocommerce_currency();
						$saveData['Spec UPC'] = '';
						$saveData['Spec SKU'] = $_variation->get_sku();
						$saveData['Spec Brand'] = '';
						$saveData['Spec MPN'] = '';
						$saveData['Spec ISBN'] = '';
						$saveData['Blacklisted'] = 'false';
						$saveData['Product Group'] = '';
						
						fputcsv($fp, $saveData);
						$iteration++;
					}
				}
			}
		}
	}
	fclose($fp);
	exit();
}
