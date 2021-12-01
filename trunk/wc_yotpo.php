<?php
/*
	Plugin Name: Yotpo Social Reviews for Woocommerce
	Description: Yotpo Social Reviews helps Woocommerce store owners generate a ton of reviews for their products. Yotpo is the only solution which makes it easy to share your reviews automatically to your social networks to gain a boost in traffic and an increase in sales.
	Author: Yotpo
	Version: 1.1.8
	Author URI: http://www.yotpo.com?utm_source=yotpo_plugin_woocommerce&utm_medium=plugin_page_link&utm_campaign=woocommerce_plugin_page_link	
	Plugin URI: http://www.yotpo.com?utm_source=yotpo_plugin_woocommerce&utm_medium=plugin_page_link&utm_campaign=woocommerce_plugin_page_link
	WC requires at least: 3.0
	WC tested up to: 5.9.0
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
					ytdbg($file,'Reviews Export Success:');
					$export->downloadReviewToBrowser($file);	
				} else {
					ytdbg($errors,'Reviews Export Fail:');
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
		if($settings['qna_enabled_product']) {	
			add_action('woocommerce_single_product_summary', 'wc_yotpo_show_qa_bottomline',8);
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
	global $product;
	if($product->get_reviews_allowed() == true) {	
		$product_data = wc_yotpo_get_product_data($product);
		$yotpo_div = "<div class='yotpo yotpo-main-widget'
	   				data-product-id='".$product_data['id']."'
	   				data-name='".$product_data['title']."' 
	   				data-url='".$product_data['url']."' 
	   				data-image-url='".$product_data['image-url']."' 
	  				data-description='".$product_data['description']."' 
	  				data-lang='".$product_data['lang']."'
                    data-price='".$product->get_price()."'
                    data-currency='".get_woocommerce_currency()."'></div>";
		echo $yotpo_div;
	}						
}
function wc_yotpo_show_widget_in_tab($tabs) {
	global $product;
	if($product->get_reviews_allowed() == true) {	
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
	if( class_exists('woocommerce') ) {
    	wp_enqueue_script('yquery', plugins_url('assets/js/headerScript.js', __FILE__) ,null,null);
		$settings = get_option('yotpo_settings',wc_yotpo_get_degault_settings());
		wp_localize_script('yquery', 'yotpo_settings', array('app_key' => $settings['app_key']));    	    	
	}
}
function wc_yotpo_show_qa_bottomline() {
	do_action( 'woocommerce_init' );
    $product_data = wc_yotpo_get_product_data(wc_get_product());
    echo "<div class='yotpo QABottomLine'
         data-appkey='".$product_data['app_key']."'
         data-product-id='".$product_data['id']."'></div>";
}
function wc_yotpo_show_buttomline() {
	global $product;
	$show_bottom_line = is_product() ? $product->get_reviews_allowed() == true : true;
	if($show_bottom_line) {
		$product_data = wc_yotpo_get_product_data($product);	
		$yotpo_div = "
		<script>jQuery(document).ready(function() {
					jQuery('div.bottomLine').click(function() {
						if (jQuery('li.yotpo_widget_tab>a').length) { jQuery('li.yotpo_widget_tab>a').click(); }
					})
				})
		</script>
		<div class='yotpo bottomLine' 
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
	$product_data['url'] = get_permalink($product->get_id());
	$product_data['lang'] = $settings['language_code']; 
	if($settings['yotpo_language_as_site'] == true) {
		$lang = explode('-', get_bloginfo('language'));
		if(strlen($lang[0]) == 2) {
			$product_data['lang'] = $lang[0];	
		}		
	}
	$product_data['description'] = wp_strip_all_tags($product->get_description());
	$product_data['id'] = $product->get_id();	
	$product_data['title'] = $product->get_title();
	$product_data['image-url'] = wc_yotpo_get_product_image_url($product->get_id());
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
	do_action( 'woocommerce_init' );
    $order = wc_get_order($order_id);
    $orderStatus = 'wc-' . $order->get_status();
    $yotpo_settings = get_option('yotpo_settings', wc_yotpo_get_degault_settings());
    ytdbg(($orderStatus.' should be '.$yotpo_settings['yotpo_order_status']), "Order #".$order_id." status changed to");
    if ($orderStatus === $yotpo_settings['yotpo_order_status']) {
    	ytdbg('', "Order #".$order_id." submission starting...");
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
                        ytdbg($response['code'].' '.$response['message'], "Order #".$order_id." Submitted with response");
                    }
                }
            } catch (Exception $e) {
                error_log($e->getMessage());
            }
        }
    }
}
function wc_yotpo_get_single_map_data($order_id) {
	do_action( 'woocommerce_init' );
	$order = new WC_Order($order_id);
	$data = null;
	if(!is_null($order->get_id())) {
		$data = array();
		$data['order_date'] = date('Y-m-d H:i:s', strtotime($order->get_date_created()));
		if (!empty($order->get_billing_email()) && !preg_match('/\d$/', $order->get_billing_email())) { $data['email'] = $order->get_billing_email(); } else { return; }
		if (!empty($order->get_billing_first_name())) { $data['customer_name'] = $order->get_billing_first_name().' '.$order->get_billing_last_name(); } else { return; }
		$data['order_id'] = $order_id;
		$data['currency_iso'] = wc_yotpo_get_order_currency($order);
		$products_arr = array();
		ytdbg("Date: ".$data['order_date']." Email: ".$data['email'], "Order #".$data['order_id']);
		if(empty($order->get_items())) { ytdbg('','No Products'); return; }
		foreach ($order->get_items() as $product) {
			if ($product['product_id'] == "0") { ytdbg('','Invalid product - ID 0'); return; }
            $_product = wc_get_product($product['product_id']);
            if(is_object($_product)){
                $product_data = array();   
                $product_data['url'] = get_permalink($product['product_id']); 
                $product_data['name'] = $product['name'];
                $product_data['image'] = wc_yotpo_get_product_image_url($product['product_id']);
                $product_data['description'] = wp_strip_all_tags($_product->get_description());
                $product_data['price'] = $_product->get_price();
                $specs_data = array();
                if($_product->get_sku()){ $specs_data['external_sku'] =$_product->get_sku();} 
                if($_product->get_attribute('upc')){ $specs_data['upc'] =$_product->get_attribute('upc');} 
                if($_product->get_attribute('isbn')){ $specs_data['isbn'] = $_product->get_attribute('isbn');} 
                if($_product->get_attribute('brand')){ $specs_data['brand'] = $_product->get_attribute('brand');} 
                if($_product->get_attribute('mpn')){ $specs_data['mpn'] =$_product->get_attribute('mpn');} 
                if(!empty($specs_data)){ $product_data['specs'] = $specs_data;  }
                ytdbg($product_data['name'].", Descr. length: ".strlen($product_data['description']).", ID: ".$product['product_id'] .", Specs: ".implode(' / ', $specs_data), "\tProduct:", false); 
            } else { ytdbg('','Invalid product - Not an Object'); return; }
			$products_arr[$product['product_id']] = $product_data;	
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
	$yotpo_settings = get_option('yotpo_settings', wc_yotpo_get_degault_settings());
	$result = null;
	$args = array(
		'post_type'		 => 'shop_order',
		'posts_per_page' => -1
	);
	if (defined('WC_VERSION') && (version_compare(WC_VERSION, '2.2.0') >= 0)) {
		$args['post_status'] = $yotpo_settings['yotpo_order_status'];
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
	$where .= " AND post_date > '" . date('Y-m-d', strtotime('-90 days')) . "'";
	return $where;
}
function wc_yotpo_send_past_orders() {
	ytdbg('', 'Submit Past Orders Start -------------------------------------------------------------------');
   	$yotpo_settings = get_option('yotpo_settings', wc_yotpo_get_degault_settings());
	if (!empty($yotpo_settings['app_key']) && !empty($yotpo_settings['secret'])) {
		$past_orders = wc_yotpo_get_past_orders();
		ytdbg("", "\tGot ".count($past_orders)." batches, sending...");
		$is_success = true;
		if(!is_null($past_orders) && is_array($past_orders)) {
			$yotpo_api = new Yotpo($yotpo_settings['app_key'], $yotpo_settings['secret']);
			$get_oauth_token_response = $yotpo_api->get_oauth_token();
			if(!empty($get_oauth_token_response) && !empty($get_oauth_token_response['access_token'])) {
				foreach ($past_orders as $index => $post_bulk) 
					if (!is_null($post_bulk)) {
						$post_bulk['utoken'] = $get_oauth_token_response['access_token'];
						$response = $yotpo_api->create_purchases($post_bulk);						
						if ($response['code'] != 200 && $is_success) {
							ytdbg($response, "\tSending Past Orders failed for batch".$index." :");
							$is_success = false;
							$message = !empty($response['status']) && !empty($response['status']['message']) ? $response['status']['message'] : 'Error occurred';
							wc_yotpo_display_message($message, true);
						} else { ytdbg($response['code']." ".$response['message'], "\tBatch ".$index." sent successfully with response"); }
					}
					if ($is_success) {
						wc_yotpo_display_message('Past orders sent successfully' , false);
						ytdbg('', 'Submit Past Orders End -------------------------------------------------------------------');
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
        'qna_enabled_product' => false,
        'bottom_line_enabled_category' => false,
        'yotpo_language_as_site' => true,
        'show_submit_past_orders' => true,
        'yotpo_order_status' => 'wc-completed',
        'disable_native_review_system' => true,
        'native_star_ratings_enabled' => 'no',
		'debug_mode' => false);
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
	if(method_exists($order,'get_currency')) {
		return $order->get_currency();
	}
	if(isset($order->order_custom_fields) && isset($order->order_custom_fields['_order_currency'])) {		
 		if(is_array($order->order_custom_fields['_order_currency'])) {
 			return $order->order_custom_fields['_order_currency'][0];
 		}	
	}
	return '';
}
function ytdbg( $msg, $name = '', $date = true) {
	$yotpo_settings = get_option('yotpo_settings', wc_yotpo_get_degault_settings());
	if (!$yotpo_settings['debug_mode']) { return; }
    $trace = debug_backtrace();
    $name = ( '' == $name ) ? $trace[1]['function'] : $name;
    $error_dir = plugin_dir_path( __FILE__ )."yotpo_debug.log";
    $msg = print_r( $msg, true );
    if ($date) {
    	$log = "[". date("m/d/Y @ g:i:sA", time()) . "] " . $name .' '. $msg . "\n";
    } else {
    	$log = $name .' '. $msg . "\n";
    }
    $fh = fopen($error_dir, 'a+');
    fwrite($fh, $log);
    fclose($fh);
}
ob_start('fatal_error_handler');
function fatal_error_handler($buffer){
    $error=error_get_last();
    if($error['type'] == 1){
        $newBuffer='<html><header><title>Fatal Error </title></header>
                    <style>                 
                    .error_content{                     
                        background: ghostwhite;
                        vertical-align: middle;
                        margin:0 auto;
                        padding:10px;
                        width:50%;                              
                     } 
                     .error_content label{color: red;font-family: "Ubuntu Mono", Consolas, monospace;font-size: 16pt;font-style: italic;}
                     .error_content ul li{ background: none repeat scroll 0 0 FloralWhite;                   
                                border: 1px solid AliceBlue;
                                display: block;
                                font-family: "Ubuntu Mono", Consolas, monospace;
                                padding: 2%;
                                text-align: left;
                      }
                    </style>
                    <body style="text-align: center;">  
                      <div class="error_content">
                          <label >Fatal Error </label>
                          <ul>
                            <li><b>Line</b> '.$error['line'].'</li>
                            <li><b>Message</b> '.$error['message'].'</li>
                            <li><b>File</b> '.$error['file'].'</li>                             
                          </ul>
                          <a href="javascript:history.back()"> Back </a>                          
                      </div>
                    </body></html>';
        return $newBuffer;
    }
    return $buffer;
}
