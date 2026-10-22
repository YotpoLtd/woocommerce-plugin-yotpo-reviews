<?php
/*
	Plugin Name: Yotpo Product Reviews
	Description: Collect and display product reviews and ratings to showcase social proof and build trust.
	Author: Yotpo
	Version: 1.11.1
	Author URI: http://www.yotpo.com?utm_source=yotpo_plugin_woocommerce&utm_medium=plugin_page_link&utm_campaign=woocommerce_plugin_page_link
	Plugin URI: http://www.yotpo.com?utm_source=yotpo_plugin_woocommerce&utm_medium=plugin_page_link&utm_campaign=woocommerce_plugin_page_link
	Requires at least: 6.0
	Requires PHP: 7.4
	Requires Plugins: woocommerce
	WC requires at least: 3.0
	WC tested up to: 11.2
	License: GPLv2
	License URI: http://www.gnu.org/licenses/gpl-2.0.html
 */
defined( 'ABSPATH' ) || exit;

// Single source of truth for the minimum supported PHP version, used by wc_yotpo_compatible().
// The "Requires PHP" header above and readme.txt only document this same value; keep them in sync.
define( 'WC_YOTPO_MIN_PHP_VERSION', '7.4' );

register_activation_hook(   __FILE__, 'wc_yotpo_activation' );
register_uninstall_hook( __FILE__, 'wc_yotpo_uninstall' );
register_deactivation_hook( __FILE__, 'wc_yotpo_deactivate' );
add_action('plugins_loaded', 'wc_yotpo_init');
add_action('admin_init', 'wc_yotpo_redirect');
// Settings saved by an older version lack the keys added since: fill them in on every read.
add_filter('option_yotpo_settings', 'wc_yotpo_fill_missing_settings');
add_action( 'before_woocommerce_init', 'wc_declare_hops_support' );
require plugin_dir_path( __FILE__ ) . 'lib/widgets/qna-widget.php';
require plugin_dir_path( __FILE__ ) . 'lib/widgets/reviews-widget.php';
require plugin_dir_path( __FILE__ ) . 'lib/widgets/stars-widget.php';
require plugin_dir_path( __FILE__ ) . 'lib/widgets/reviews-carousel.php';
require plugin_dir_path( __FILE__ ) . 'lib/widgets/promoted-products.php';
require plugin_dir_path( __FILE__ ) . 'lib/widgets/reviews-tab.php';
require plugin_dir_path( __FILE__ ) . 'lib/widgets/custom-widgets.php';
require plugin_dir_path( __FILE__ ) . 'lib/utils/general-usage-functions.php';
require plugin_dir_path( __FILE__ ) . 'lib/utils/wc-yotpo-defaults.php';
require plugin_dir_path( __FILE__ ) . 'lib/utils/wc-yotpo-functions.php';
require plugin_dir_path( __FILE__ ) . 'lib/utils/widgets-rendering-logic.php';
require plugin_dir_path( __FILE__ ) . 'lib/utils/allowed-html-functions.php';
require plugin_dir_path( __FILE__ ) . 'lib/yotpo-api/class-yotpo-v3-client.php';
require plugin_dir_path( __FILE__ ) . 'lib/sync/class-yotpo-product-terms.php';
require plugin_dir_path( __FILE__ ) . 'lib/sync/class-yotpo-product-payload.php';
require plugin_dir_path( __FILE__ ) . 'lib/sync/class-yotpo-product-tags.php';
require plugin_dir_path( __FILE__ ) . 'lib/sync/class-yotpo-payload-compare.php';
require plugin_dir_path( __FILE__ ) . 'lib/sync/class-yotpo-catalog-api.php';
require plugin_dir_path( __FILE__ ) . 'lib/sync/class-yotpo-order-payload.php';
require plugin_dir_path( __FILE__ ) . 'lib/sync/class-yotpo-order-api.php';
require plugin_dir_path( __FILE__ ) . 'lib/sync/class-yotpo-order-sync.php';
require plugin_dir_path( __FILE__ ) . 'lib/sync/class-yotpo-order-backfill.php';
require plugin_dir_path( __FILE__ ) . 'lib/sync/class-yotpo-option-lock.php';
require plugin_dir_path( __FILE__ ) . 'lib/sync/class-yotpo-sync-state.php';
require plugin_dir_path( __FILE__ ) . 'lib/sync/class-yotpo-sync-jobs.php';
require plugin_dir_path( __FILE__ ) . 'lib/sync/class-yotpo-product-sync.php';

function wc_yotpo_init() {
	// Everything below relies on WooCommerce; without it the storefront hooks fatal on is_product().
	if (!wc_yotpo_is_woocommerce_active()) {
		add_action('admin_notices', 'wc_yotpo_woocommerce_missing_notice');
		return;
	}
	// Product catalog and order sync to Yotpo (Core API V3), in background jobs.
	Yotpo_Product_Sync::init();
	Yotpo_Order_Sync::init();
	Yotpo_Order_Backfill::init();
	if (defined('WP_CLI') && WP_CLI) {
		require_once plugin_dir_path( __FILE__ ) . 'lib/sync/class-yotpo-sync-cli.php';
		WP_CLI::add_command('yotpo product-sync', 'Yotpo_Sync_CLI');
	}
	$is_admin = is_admin();
	if($is_admin) {
		if (isset($_GET['download_exported_reviews'])) {
			// admin_init rather than now: permalinks are not set up yet during plugins_loaded,
			// so exported product URLs came out as ?p=ID.
			add_action('admin_init', 'wc_yotpo_export_reviews');
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
function wc_yotpo_export_reviews() {
	if(current_user_can('manage_options')) {
		check_admin_referer('yotpo_export_reviews');
		require_once plugin_dir_path( __FILE__ ) . 'classes/class-wc-yotpo-export-reviews.php';
		$export = new Yotpo_Review_Export();
		$export->streamReviewsCsv();
	}
	exit;
}
function wc_yotpo_woocommerce_missing_notice() {
	if (current_user_can('activate_plugins')) {
		echo '<div class="notice notice-error"><p>' . esc_html__('Yotpo Product Reviews requires WooCommerce. Install and activate WooCommerce to use the Yotpo plugin.', 'yotpo-social-reviews-for-woocommerce') . '</p></div>';
	}
}
function wc_yotpo_front_end_init() {
	$settings = get_option('yotpo_settings',wc_yotpo_get_default_settings());
	add_action('woocommerce_thankyou', 'wc_yotpo_conversion_track');
	if(is_product()) {
		if (use_v3_widgets()) {
			$v3_widget_location = $settings['v3_widget_location'];
			if($v3_widget_location == 'automatic') {
				render_bottom_line_widgets();
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
			render_bottom_line_widgets();
		}
		if($settings['disable_native_review_system']) {
			add_filter( 'comments_open', 'wc_yotpo_remove_native_review_system', null, 2);
		}
	// Product listing pages. is_category() only matches *blog* categories, so WooCommerce product
	// categories, tags and attribute archives (is_product_taxonomy()) never got the category widgets.
	// is_category() stays so blog category pages keep what they showed before.
	} else if (is_shop() || is_product_taxonomy() || is_category()) {
		category_page_renders($settings);
		wp_enqueue_style('yotpoSideBootomLineStylesheet', plugins_url('assets/css/bottom-line.css', __FILE__));
	// Home page. is_home() is the blog posts page, which is only the home page when the site shows
	// latest posts; stores with a static front page need is_front_page(). is_home() stays so the
	// blog page keeps what it showed before.
	} else if (is_front_page() || is_home()) {
		rest_of_pages_renders($settings);
	}
}
function wc_yotpo_activation() {
	if(current_user_can( 'activate_plugins' )) {
		// No nonce check here: WordPress verifies it before running activation hooks, and the
		// single-plugin nonce this used to check made bulk and WP-CLI activation die.
		update_option('wc_yotpo_just_installed', true);
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
		wc_yotpo_delete_private_dir();
	}
}
// REVIEWS WIDGET
function wc_yotpo_show_reviews_widget() {
	global $product;
	if($product->get_reviews_allowed() == true) {
		echo wp_kses(generate_reviews_widget_code($product), yotpo_reviews_widget_allowed_html());
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
		echo wp_kses(generate_v3_qna_widget_code($product, $qna_widget_id), yotpo_qna_widget_allowed_html());
	}
}
// PROMOTED PRODUCTS WIDGET
function wc_yotpo_show_promoted_products_widget() {
	global $product;
	$yotpo_settings = get_option('yotpo_settings',wc_yotpo_get_default_settings());
	$promoted_products_widget_id = $yotpo_settings['v3_widgets_ids']['promoted_products'];
	if (!$promoted_products_widget_id) {
		return;
	}
	if(is_product() && $product->get_reviews_allowed() == true) {
		$product_data = wc_yotpo_get_product_data($product);
		echo wp_kses(generate_v3_promoted_products_widget_code($promoted_products_widget_id, $product_data['id']), yotpo_common_widgets_allowed_html());
	} else {
		echo wp_kses(generate_v3_promoted_products_widget_code($promoted_products_widget_id), yotpo_common_widgets_allowed_html());
	}
}
// REVIEWS CAROUSEL WIDGET
function wc_yotpo_show_reviews_carousel_widget(): void {
	global $product;
	$yotpo_settings = get_option('yotpo_settings',wc_yotpo_get_default_settings());
	$reviews_carousel_widget_id = $yotpo_settings['v3_widgets_ids']['reviews_carousel'];
	if (!$reviews_carousel_widget_id) {
		return;
	}
	if(is_product() && $product->get_reviews_allowed() == true) {
		$product_data = wc_yotpo_get_product_data($product);
		echo wp_kses(generate_v3_reviews_carousel_widget_code($reviews_carousel_widget_id, $product_data['id']), yotpo_common_widgets_allowed_html());
	} else {
		echo wp_kses(generate_v3_reviews_carousel_widget_code($reviews_carousel_widget_id), yotpo_common_widgets_allowed_html());
	}
}
// REVIEWS TAB WIDGET
function wc_yotpo_show_reviews_tab_widget() {
	global $product;
	$yotpo_settings = get_option('yotpo_settings',wc_yotpo_get_default_settings());
	$reviews_tab_widget_id = $yotpo_settings['v3_widgets_ids']['reviews_tab'];
	if (!$reviews_tab_widget_id) {
		return;
	}
	if(is_product() && $product->get_reviews_allowed() == true) {
		$product_data = wc_yotpo_get_product_data($product);
		echo wp_kses(generate_v3_reviews_tab_widget_code($reviews_tab_widget_id, $product_data['id']), yotpo_common_widgets_allowed_html());
	} else {
		echo wp_kses(generate_v3_reviews_tab_widget_code($reviews_tab_widget_id), yotpo_common_widgets_allowed_html());
	}
}
// CUSTOM WIDGETS
function wc_yotpo_show_custom_widgets() {
	global $product;
	if($product->get_reviews_allowed() == true) {
		foreach (generate_v3_custom_widgets_code($product) as $widget_code) {
			echo wp_kses($widget_code, yotpo_common_widgets_allowed_html());
		}
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
	}
	// Always return the tabs: returning nothing removed every tab (Description etc.) on
	// products with reviews disabled.
	return $tabs;
}
function wc_yotpo_load_js() {
	if( wc_yotpo_is_woocommerce_active() ) {
		// Versioned by file time, so browsers don't keep an old copy after an update.
		$script = use_v3_widgets() ? 'assets/js/v3HeaderScript.js' : 'assets/js/v2HeaderScript.js';
		wp_enqueue_script('yquery', plugins_url($script, __FILE__), null, filemtime(__DIR__ . '/' . $script));
		$settings = get_option('yotpo_settings',wc_yotpo_get_default_settings());
		$v3_widgets_ids = yotpo_get_arr_value($settings, 'v3_widgets_ids', []);
		wp_localize_script('yquery', 'yotpo_settings', array(
			'app_key' => $settings['app_key'],
			// v3 widgets in the page's language ("Match the site language"); none: the language set in Yotpo.
			'language_code' => use_v3_widgets() && !empty($settings['widget_language_from_page']) ? wc_yotpo_widget_language_code() : '',
			'reviews_widget_id' => yotpo_get_arr_value($v3_widgets_ids, 'reviews_widget'),
			'qna_widget_id' => yotpo_get_arr_value($v3_widgets_ids, 'qna'),
			'star_ratings_widget_id' => yotpo_get_arr_value($v3_widgets_ids, 'star_rating')
		));
	}
}
function wc_yotpo_show_qa_bottomline() {
	$product_data = wc_yotpo_get_product_data(wc_get_product());
	echo "<div class='yotpo QABottomLine'
				 data-appkey='".esc_attr($product_data['app_key'])."'
				 data-product-id='".esc_attr($product_data['id'])."'></div>";
}
// STAR RATINGS WIDGET
function wc_yotpo_show_buttomline() {
	global $product;
	$show_bottom_line = is_product() ? $product->get_reviews_allowed() == true : true;
	if($show_bottom_line) {
		echo wp_kses(generate_star_ratings_widget_code(), yotpo_star_ratings_widgets_allowed_html());
	}
}
function generate_star_ratings_widget_code() {
	global $product;
	$yotpo_settings = get_option('yotpo_settings',wc_yotpo_get_default_settings());
	$use_v3_widgets = use_v3_widgets();
	$v3_widgets_ids = yotpo_get_arr_value($yotpo_settings, 'v3_widgets_ids', []);
	$reviews_widget_id = yotpo_get_arr_value($v3_widgets_ids, 'reviews_widget');
	$star_ratings_widget_id = yotpo_get_arr_value($v3_widgets_ids, 'star_rating');
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
	return wp_parse_url(get_bloginfo('url'),PHP_URL_HOST);
}
function wc_yotpo_remove_native_review_system($open, $post_id) {
	if(get_post_type($post_id) == 'product') {
		return false;
	}
	return $open;
}
function wc_yotpo_get_product_image_url($product_id) {
	$url = wp_get_attachment_url(get_post_thumbnail_id($product_id));
	return $url ? $url : null;
}
function wc_yotpo_conversion_track($order_id) {
	$yotpo_settings = get_option('yotpo_settings', wc_yotpo_get_default_settings());
	$order = new WC_Order($order_id);
	$currency = wc_yotpo_get_order_currency($order);

	// Build the full URL before escaping: esc_url() on a bare query string prefixed it with
	// "http://", so the pixel arrived as ?http://app_key=... without a usable app_key.
	$pixel_url = add_query_arg(array(
		'app_key' => rawurlencode($yotpo_settings['app_key']),
		'order_id' => rawurlencode($order_id),
		'order_amount' => rawurlencode($order->get_total()),
		'order_currency' => rawurlencode($currency),
	), 'https://api.yotpo.com/conversion_tracking.gif');

	$APP_KEY = esc_js($yotpo_settings['app_key']);
	$DATA = "yotpoTrackConversionData = {orderId: " . esc_js($order_id) . ", orderAmount: " . esc_js($order->get_total()) . ", orderCurrency: '" . esc_js($currency) . "'}";
	$DATA_SCRIPT = "<script>" . $DATA . "</script>";
	$IMG = "<img src='" . esc_url($pixel_url) . "' width='1' height='1' />";
	$NO_SCRIPT = "<noscript>" . $IMG . "</noscript>";

	echo wp_kses($DATA_SCRIPT, array('script' => array()));
	echo wp_kses($NO_SCRIPT, yotpo_noscript_pixel_allowed_html());
}
function wc_yotpo_admin_styles($hook) {
	if($hook == 'toplevel_page_woocommerce-yotpo-settings-page') {
		// Versioned by file time: without a version WordPress appends its own, so browsers kept the old files after a plugin update.
		wp_enqueue_script( 'yotpoSettingsJs', plugins_url('assets/js/settings.js', __FILE__), array('jquery-effects-core'), filemtime( __DIR__ . '/assets/js/settings.js' ) );
		// For Send past orders: the order count as the days change (Yotpo_Order_Backfill::ajax_count()).
		wp_localize_script( 'yotpoSettingsJs', 'yotpoPastOrders', array(
			'ajaxUrl' => admin_url( 'admin-ajax.php' ),
			'nonce'   => wp_create_nonce( 'wc_yotpo_past_orders_count' ),
		) );
		wp_enqueue_style( 'yotpoSettingsStylesheet', plugins_url('assets/css/yotpo.css', __FILE__), array(), filemtime( __DIR__ . '/assets/css/yotpo.css' ) );
	}
}
function wc_yotpo_compatible() {
	// HTTP calls go through wp_remote_* (since 1.8.0), so cURL is no longer required.
	return version_compare(phpversion(), WC_YOTPO_MIN_PHP_VERSION, '>=');
}
function wc_yotpo_deactivate() {
	update_option('woocommerce_enable_review_rating', get_option('native_star_ratings_enabled'));
}
// Accept both arguments; with one, $tab was always null. Every other tab must get $allowed back,
// returning nothing disabled Tab Manager for all tabs.
add_filter('woocommerce_tab_manager_integration_tab_allowed', 'wc_yotpo_disable_tab_manager_managment', 10, 2);
function wc_yotpo_disable_tab_manager_managment($allowed, $tab = null) {
	if($tab == 'yotpo_main_widget') {
		return false;
	}
	return $allowed;
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
function ytdbg( $msg, $name = '', $date = true ) {
	$yotpo_settings = get_option( 'yotpo_settings', wc_yotpo_get_default_settings() );
	if ( ! $yotpo_settings['debug_mode'] ) {
		return;
	}

	$trace = debug_backtrace();
	$name = ( '' === $name ) ? $trace[1]['function'] : $name;
	$msg = print_r( $msg, true );

	if ( $date ) {
		$log = "[" . gmdate( "m/d/Y @ g:i:sA", time() ) . "] " . $name . ' ' . $msg . "\n";
	} else {
		$log = $name . ' ' . $msg . "\n";
	}

	$filesystem = wc_yotpo_filesystem();
	$log_file = wc_yotpo_debug_log_path();
	if ( ! $filesystem ) {
		// Debug mode is on but nothing can be logged; leave a trace in the PHP error log instead of failing silently.
		error_log( 'Yotpo debug log: WP_Filesystem could not be initialised, the entry was not written.' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		return;
	}
	if ( ! wc_yotpo_prepare_private_dir( dirname( $log_file ) ) ) {
		error_log( 'Yotpo debug log: could not create ' . dirname( $log_file ) . ', the entry was not written.' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		return;
	}
	$existing_log = $filesystem->exists( $log_file ) ? $filesystem->get_contents( $log_file ) : '';
	$filesystem->put_contents( $log_file, $existing_log . $log, FS_CHMOD_FILE );
}
function wc_yotpo_filesystem() {
	global $wp_filesystem;
	if ( ! function_exists( 'WP_Filesystem' ) ) {
		require_once ABSPATH . 'wp-admin/includes/file.php';
	}
	if ( empty( $wp_filesystem ) && ! WP_Filesystem() ) {
		return null;
	}
	return $wp_filesystem;
}
// The debug log contains customer names and emails, so it lives outside the plugin folder
// under an unguessable, per-site file name (the .htaccess below does not apply on nginx).
function wc_yotpo_debug_log_path() {
	$upload_dir = wp_upload_dir( null, false );
	return trailingslashit( $upload_dir['basedir'] ) . 'yotpo-social-reviews-for-woocommerce/yotpo-debug-' . wp_hash( 'yotpo-debug-log' ) . '.log';
}
function wc_yotpo_prepare_private_dir( $dir ) {
	$filesystem = wc_yotpo_filesystem();
	if ( ! $filesystem || ! wp_mkdir_p( $dir ) ) {
		return false;
	}
	$guards = array(
		'index.php' => "<?php\n// Silence is golden.\n",
		'.htaccess' => "<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n",
	);
	foreach ( $guards as $file => $contents ) {
		$path = trailingslashit( $dir ) . $file;
		if ( ! $filesystem->exists( $path ) ) {
			$filesystem->put_contents( $path, $contents, FS_CHMOD_FILE );
		}
	}
	return true;
}
// Removes uploads/yotpo-social-reviews-for-woocommerce/ (debug log and its guard files); keep in sync with uninstall.php.
function wc_yotpo_delete_private_dir() {
	$filesystem = wc_yotpo_filesystem();
	$dir = dirname( wc_yotpo_debug_log_path() );
	if ( $filesystem && $filesystem->is_dir( $dir ) ) {
		$filesystem->delete( $dir, true );
	}
}
// Returns the log contents (an empty string when the file exists but has no entries), or a WP_Error
// explaining why the log cannot be read, so the viewer can tell "empty" from "logging is broken".
function wc_yotpo_read_debug_log() {
	$filesystem = wc_yotpo_filesystem();
	if ( ! $filesystem ) {
		return new WP_Error( 'yotpo_debug_log_filesystem', 'Could not access the filesystem (WP_Filesystem failed to initialise), so the debug log cannot be written or read. Check the file ownership/permissions of wp-content/uploads.' );
	}
	$log_file = wc_yotpo_debug_log_path();
	if ( ! $filesystem->exists( $log_file ) ) {
		if ( ! $filesystem->is_dir( dirname( $log_file ) ) ) {
			return new WP_Error( 'yotpo_debug_log_directory', 'The log directory wp-content/uploads/yotpo-social-reviews-for-woocommerce/ does not exist and could not be created. Check that wp-content/uploads is writable.' );
		}
		return new WP_Error( 'yotpo_debug_log_missing', 'No log file has been created yet.' );
	}
	$contents = $filesystem->get_contents( $log_file );
	if ( false === $contents ) {
		return new WP_Error( 'yotpo_debug_log_unreadable', 'The debug log file exists but could not be read. Check its file permissions.' );
	}
	return $contents;
}
function wc_yotpo_clear_debug_log() {
	$filesystem = wc_yotpo_filesystem();
	$log_file = wc_yotpo_debug_log_path();
	if ( $filesystem && $filesystem->exists( $log_file ) ) {
		$filesystem->put_contents( $log_file, '', FS_CHMOD_FILE );
	}
}
