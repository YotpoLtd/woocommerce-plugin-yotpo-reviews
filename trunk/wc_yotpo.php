<?php
/*
	Plugin Name: Yotpo Social Reviews for Woocommerce
	Description: Yotpo Social Reviews helps Woocommerce store owners generate a ton of reviews for their products. Yotpo is the only solution which makes it easy to share your reviews automatically to your social networks to gain a boost in traffic and an increase in sales.
	Author: Yotpo
	Version: 1.6.5
	Author URI: http://www.yotpo.com?utm_source=yotpo_plugin_woocommerce&utm_medium=plugin_page_link&utm_campaign=woocommerce_plugin_page_link	
	Plugin URI: http://www.yotpo.com?utm_source=yotpo_plugin_woocommerce&utm_medium=plugin_page_link&utm_campaign=woocommerce_plugin_page_link
	WC requires at least: 3.0
	WC tested up to: 7.3.0
 */
register_activation_hook(   __FILE__, 'wc_yotpo_activation' );
register_uninstall_hook( __FILE__, 'wc_yotpo_uninstall' );
register_deactivation_hook( __FILE__, 'wc_yotpo_deactivate' );
add_action('plugins_loaded', 'wc_yotpo_init');
add_action('init', 'wc_yotpo_redirect');
add_action( 'woocommerce_order_status_changed', 'wc_yotpo_map');
add_action( 'before_woocommerce_init', 'wc_declare_hops_support' );
require plugin_dir_path( __FILE__ ) . 'lib/widgets/qna-widget.php';
require plugin_dir_path( __FILE__ ) . 'lib/widgets/reviews-widget.php';
require plugin_dir_path( __FILE__ ) . 'lib/widgets/stars-widget.php';
require plugin_dir_path( __FILE__ ) . 'lib/utils/wc-yotpo-defaults.php';
require plugin_dir_path( __FILE__ ) . 'lib/utils/wc-yotpo-functions.php';
require plugin_dir_path( __FILE__ ) . 'lib/utils/widgets-rendering-logic.php';
		
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
	$yotpo_settings = get_option('yotpo_settings', wc_yotpo_get_default_settings());
	if(!empty($yotpo_settings['app_key']) && wc_yotpo_compatible()) {			
		if(!$is_admin) {
			add_action( 'wp_enqueue_scripts', 'wc_yotpo_load_js' );
			add_action( 'template_redirect', 'wc_yotpo_front_end_init' );	
		}								
	}			
}
function wc_yotpo_front_end_init() {
	$settings = get_option('yotpo_settings',wc_yotpo_get_default_settings());
	add_action('woocommerce_thankyou', 'wc_yotpo_conversion_track');		
	if(is_product()) {
		if (use_v3_widgets()) {
			$v3_widget_location = $settings['v3_widget_location'];
			if($v3_widget_location == 'automatic') {
				v3_product_widgets_render_in_footer($settings['v3_widgets_enables']);
			}
		} else {
			$v2_widget_location = $settings['v2_widget_location'];
			if($v2_widget_location == 'footer') {
				v2_product_widgets_render_in_footer();
			}
			elseif($v2_widget_location == 'tab') {
				v2_product_widgets_render_in_tabs();
			}
		}
		if($settings['disable_native_review_system']) {
			add_filter( 'comments_open', 'wc_yotpo_remove_native_review_system', null, 2);	
		}
		render_bottom_line_widgets();
	}
	elseif (star_rating_category_for_v2_or_v3_enabled($settings)) {
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
			add_option('yotpo_settings', wc_yotpo_get_default_settings());
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
// REVIEWS WIDGET
function wc_yotpo_show_reviews_widget() {		 
	global $product;
	if($product->get_reviews_allowed() == true) {
		echo generate_reviews_widget_code($product);
	}						
}
function generate_reviews_widget_code($product) {
	if (!use_v3_widgets()) {
		return generate_v2_reviews_widget_code($product, get_woocommerce_currency());
	}
	$yotpo_settings = get_option('yotpo_settings',wc_yotpo_get_default_settings());
	$reviews_widget_id = $yotpo_settings['v3_widgets_ids']['reviews_widget'];
	return $reviews_widget_id ? generate_v3_reviews_widget_code($product, $reviews_widget_id, get_woocommerce_currency()) : '';
}
// alias of 'generate_reviews_widget_code' - backward compatibility
function wc_yotpo_show_widget() {
	wc_yotpo_show_reviews_widget();
}
// Q&A WIDGET
function wc_yotpo_show_qna_widget() {		 
	global $product;
	$yotpo_settings = get_option('yotpo_settings',wc_yotpo_get_default_settings());
	$qna_widget_id = $yotpo_settings['v3_widgets_ids']['qna'];
	if($product->get_reviews_allowed() == true && $qna_widget_id) {
		echo generate_v3_qna_widget_code($product, $qna_widget_id);
	}						
}
function wc_yotpo_show_main_widget_in_tab($tabs) {
	global $product;
	if($product->get_reviews_allowed() == true) { 
		$settings = get_option('yotpo_settings', wc_yotpo_get_default_settings());
	 	$tabs['yotpo_main_widget'] = array(
			'title' => $settings['main_widget_tab_name'],
			'priority' => 50,
			'callback' => 'wc_yotpo_show_reviews_widget'
		);
		return $tabs;
	}
}
function wc_yotpo_load_js(){
	if( class_exists('woocommerce') ) {
    if (use_v3_widgets()) {
      wp_enqueue_script('yquery', plugins_url('assets/js/v3HeaderScript.js', __FILE__), null, null);
    } else {
      wp_enqueue_script('yquery', plugins_url('assets/js/v2HeaderScript.js', __FILE__), null, null);
    }
    $settings = get_option('yotpo_settings',wc_yotpo_get_default_settings());
    wp_localize_script('yquery', 'yotpo_settings', array(
      'app_key' => $settings['app_key'],
      'reviews_widget_id' => $settings['v3_widgets_ids']['reviews_widget'],
      'qna_widget_id' => $settings['v3_widgets_ids']['qna'],
      'star_ratings_widget_id' => $settings['v3_widgets_ids']['star_rating']
    ));
  }
}
function wc_yotpo_show_qa_bottomline() {
	do_action( 'woocommerce_init' );
    $product_data = wc_yotpo_get_product_data(wc_get_product());
    echo "<div class='yotpo QABottomLine'
         data-appkey='".$product_data['app_key']."'
         data-product-id='".$product_data['id']."'></div>";
}
// STAR RATINGS WIDGET
function wc_yotpo_show_buttomline() {
	global $product;
	$show_bottom_line = is_product() ? $product->get_reviews_allowed() == true : true;
	if($show_bottom_line) {
		echo generate_star_ratings_widget_code();
	}	
}
function generate_star_ratings_widget_code() {
	global $product;
	$yotpo_settings = get_option('yotpo_settings',wc_yotpo_get_default_settings());
	$use_v3_widgets = use_v3_widgets();
	$reviews_widget_id = $yotpo_settings['v3_widgets_ids']['reviews_widget'];
	$star_ratings_widget_id = $yotpo_settings['v3_widgets_ids']['star_rating'];
	if ($use_v3_widgets && $star_ratings_widget_id && $reviews_widget_id) {
		return generate_v3_star_ratings_widget_code($product, $star_ratings_widget_id);
	}
	else if (!$use_v3_widgets) {
		return generate_v2_star_ratings_widget_code($product);
	}
}
function wc_yotpo_get_product_data($product) {	 
	$settings = get_option('yotpo_settings',wc_yotpo_get_default_settings());
	$product_data = array(
		'app_key' => esc_attr($settings['app_key']),
		'shop_domain' => esc_attr(wc_yotpo_get_shop_domain()),
		'url' => esc_attr(get_permalink($product->get_id())),
		'lang' => esc_attr($settings['language_code']),
		'description' => esc_attr(wp_strip_all_tags($product->get_description())),
		'id' => esc_attr($product->get_id()),
		'title' => esc_attr($product->get_title()),
		'image-url' => esc_attr(wc_yotpo_get_product_image_url($product->get_id()))
	);
	if($settings['yotpo_language_as_site'] == true) {
		$lang = explode('-', get_bloginfo('language'));
		if(strlen($lang[0]) == 2) {
			$product_data['lang'] = $lang[0];	
		}		
	}
	$specs_data = get_specs_data($product);
	if(!empty($specs_data)){ $product_data['specs'] = $specs_data;  }

	return $product_data;
}
function get_specs_data($product) {
	$specs_data = array();
	if($product->get_sku()){ $specs_data['external_sku'] =$product->get_sku();}
	if($product->get_attribute('upc')){ $specs_data['upc'] =$product->get_attribute('upc');}
	if($product->get_attribute('isbn')){ $specs_data['isbn'] = $product->get_attribute('isbn');}
	if($product->get_attribute('brand')){ $specs_data['brand'] = $product->get_attribute('brand');}
	if($product->get_attribute('mpn')){ $specs_data['mpn'] =$product->get_attribute('mpn');}
	return $specs_data;
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
    $yotpo_settings = get_option('yotpo_settings', wc_yotpo_get_default_settings());
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
	if (defined('WC_VERSION') && (version_compare(WC_VERSION, '3.0') >= 0)) {
		$orders = wc_yotpo_get_past_orders_crud();
	} else {
		$orders = wc_yotpo_get_past_orders_legacy();
	}

	if (is_null($orders) || count($orders) == 0) {
		return null;
	}

	$post_bulk_orders = array_chunk($orders, 200);
	$result = array();
	foreach ($post_bulk_orders as $index => $bulk)
	{
		$result[$index] = array();
		$result[$index]['orders'] = $bulk;
		$result[$index]['platform'] = 'woocommerce';
	}
	return $result;
}
function wc_yotpo_get_past_orders_crud() {
	ytdbg("","wc_yotpo_get_past_orders_crud");
	$yotpo_settings = get_option('yotpo_settings', wc_yotpo_get_default_settings());
	$result = null;
	$args = array(
		'type' => 'shop_order',
		'paginate' => false
	);
	$args['status'] = $yotpo_settings['yotpo_order_status'];
	$args['date_created'] = '>' . date('Y-m-d', strtotime('-90 days'));

	$orders_from_db = wc_get_orders( $args );

	if (is_null($orders_from_db)) {
		return null;
	}

	$orders = array();
	foreach ( $orders_from_db as $order ) {
		$single_order_data = wc_yotpo_get_single_map_data($order->id);
		if(!is_null($single_order_data)) {
			$orders[] = $single_order_data;
		}
	}

	return $orders;
}
function wc_yotpo_get_past_orders_legacy() {
	ytdbg("","wc_yotpo_get_past_orders_legacy");
	$yotpo_settings = get_option('yotpo_settings', wc_yotpo_get_default_settings());
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
	if (!$query->have_posts()) {
		return null;
	}

	$orders = array();
	while ($query->have_posts()) {
		$query->the_post();
		$order = $query->post;
		$single_order_data = wc_yotpo_get_single_map_data($order->ID);
		if(!is_null($single_order_data)) {
			$orders[] = $single_order_data;
		}
	}
	return $orders;
}
function wc_yotpo_past_order_time_query( $where = '' ) {
	$where .= " AND post_date > '" . date('Y-m-d', strtotime('-90 days')) . "'";
	return $where;
}
function wc_yotpo_send_past_orders() {
	ytdbg('', 'Submit Past Orders Start -------------------------------------------------------------------');
   	$yotpo_settings = get_option('yotpo_settings', wc_yotpo_get_default_settings());
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
			else {
			    ytdbg("", "failed creating utoken");
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
	$yotpo_settings = get_option('yotpo_settings', wc_yotpo_get_default_settings());
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
	if($tab == 'yotpo_main_widget') {
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
function wc_declare_hops_support() {
	if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
	}
}
function ytdbg( $msg, $name = '', $date = true) {
	$yotpo_settings = get_option('yotpo_settings', wc_yotpo_get_default_settings());
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
