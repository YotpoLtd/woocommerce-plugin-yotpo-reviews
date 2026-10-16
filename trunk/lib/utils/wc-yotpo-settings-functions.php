<?php

defined( 'ABSPATH' ) || exit;

function get_yotpo_widget_field_name($widget_type_name) {
  switch ($widget_type_name) {
    case 'ReviewsMainWidget':
      return 'reviews_widget';
    case 'QuestionsAndAnswers':
      return 'qna';
    case 'ReviewsStarRatingsWidget':
      return 'star_rating';
    case 'ReviewsCarousel':
      return 'reviews_carousel';
    case 'PromotedProducts':
      return 'promoted_products';
    case 'ReviewsTab':
      return 'reviews_tab';
  }
}

function receive_widget_instances() {
  $yotpo_settings = get_option('yotpo_settings', wc_yotpo_get_default_settings());
  $yotpo_api = new Yotpo($yotpo_settings['app_key'], $yotpo_settings['secret']);
  $response = $yotpo_api->get_widget_instances();
  if (!is_array($response) || !isset($response['widget_instances']) || !is_array($response['widget_instances'])) {
    $reason = !empty($yotpo_api->error) ? $yotpo_api->error : 'unexpected response from the Yotpo API';
    ytdbg($reason, 'Fetching widget instances failed:');
    return new WP_Error('yotpo_widget_instances', $reason);
  }
  return $response;
}

// Returns the widget IDs keyed by field name, or a WP_Error when the Yotpo API could not be reached
// or answered unexpectedly (e.g. a TLS certificate problem). Callers must keep the stored IDs on error.
function get_widget_instances() {
  $response = receive_widget_instances();
  if (is_wp_error($response)) {
    return $response;
  }
  $ids_object = array();
  foreach($response['widget_instances'] as $val) {
    $ids_object[get_yotpo_widget_field_name($val['widget_type_name'])] = $val['widget_instance_id'];
  }
  return $ids_object;
}

function wc_yotpo_widget_instances_error_message($error) {
  return 'Could not fetch the widgets\' IDs from Yotpo, the saved IDs were kept: ' . esc_html($error->get_error_message());
}

function get_widgets_instances() {
  return yotpo_get_arr_value($_POST, 'yotpo_widget_version') === 'v3' ? get_widget_instances() : array(
    'reviews_widget' => yotpo_get_arr_value($_POST, 'yotpo_reviews_widget_id'),
    'qna' => yotpo_get_arr_value($_POST, 'yotpo_qna_widget_id'),
    'star_rating' => yotpo_get_arr_value($_POST, 'yotpo_star_ratings_widget_id'),
    'reviews_carousel' => yotpo_get_arr_value($_POST, 'yotpo_reviews_carousel_widget_id'),
    'promoted_products' => yotpo_get_arr_value($_POST, 'yotpo_promoted_products_widget_id'),
    'reviews_tab' => yotpo_get_arr_value($_POST, 'yotpo_tab_widget_id'),
  );
}

function get_v3_widgets_enables() {
  return array(
    'reviews_widget_product' => isset($_POST['yotpo_reviews_widget_enabled_product']) ? true : false,
    'qna_product' => isset($_POST['yotpo_qna_widget_enabled_product']) ? true : false,
    'star_rating_product' => isset($_POST['yotpo_star_rating_enabled_product']) ? true : false,
    'star_rating_category' => isset($_POST['yotpo_star_rating_enabled_category']) ? true : false,
    'reviews_carousel_product' => isset($_POST['yotpo_reviews_carousel_enabled_product']) ? true : false,
    'reviews_carousel_category' => isset($_POST['yotpo_reviews_carousel_enabled_category']) ? true : false,
    'reviews_carousel_home' => isset($_POST['yotpo_reviews_carousel_enabled_home']) ? true : false,
    'promoted_products_product' => isset($_POST['yotpo_promoted_products_enabled_product']) ? true : false,
    'promoted_products_category' => isset($_POST['yotpo_promoted_products_enabled_category']) ? true : false,
    'promoted_products_home' => isset($_POST['yotpo_promoted_products_enabled_home']) ? true : false,
    'reviews_tab_product' => isset($_POST['yotpo_reviews_tab_enabled_product']) ? true : false,
    'reviews_tab_category' => isset($_POST['yotpo_reviews_tab_enabled_category']) ? true : false,
  );
}

function get_v2_widgets_enables() {
  return array(
    'qna_product' => isset($_POST['yotpo_qna_enabled_product']) ? true : false,
    'bottom_line_product' => isset($_POST['yotpo_bottom_line_enabled_product']) ? true : false,
    'bottom_line_category' => isset($_POST['yotpo_bottom_line_enabled_category']) ? true : false
  );
}

function wc_proccess_yotpo_settings() {
  $current_settings = get_option('yotpo_settings', wc_yotpo_get_default_settings());
  $product_sync_was_on = Yotpo_Product_Sync::setting_on();
  $tags_were_on = Yotpo_Product_Sync::tags_on();
  $widgets_ids = get_widgets_instances();
  if (is_wp_error($widgets_ids)) {
    wc_yotpo_display_message(wc_yotpo_widget_instances_error_message($widgets_ids), true);
    $widgets_ids = yotpo_get_arr_value($current_settings, 'v3_widgets_ids', []);
  }
  $new_settings = array(
    'app_key' => yotpo_get_arr_value($_POST, 'yotpo_app_key'),
    'secret' => yotpo_get_arr_value($_POST, 'yotpo_oauth_token'),
    'v2_widget_location' => yotpo_get_arr_value($_POST, 'yotpo_v2_widget_location'),
    'v3_widget_location' => yotpo_get_arr_value($_POST, 'yotpo_v3_widget_location'),
    'language_code' => yotpo_get_arr_value($_POST, 'yotpo_widget_language_code'),
    'main_widget_tab_name' => yotpo_get_arr_value($_POST, 'yotpo_main_widget_tab_name'),
    'qna_widget_tab_name' => yotpo_get_arr_value($_POST, 'yotpo_qna_widget_tab_name'),
    'widget_version' => yotpo_get_arr_value($_POST, 'yotpo_widget_version'),
    'v3_widgets_ids' => $widgets_ids,
    'v3_widgets_enables' => get_v3_widgets_enables(),
    'v2_widgets_enables' => get_v2_widgets_enables(),
    'yotpo_order_status' => yotpo_get_arr_value($_POST, 'yotpo_order_status'),
    'yotpo_language_as_site' => isset($_POST['yotpo_language_as_site']) ? true : false,
    'disable_native_review_system' => isset($_POST['disable_native_review_system']) ? true : false,
    'product_sync_enabled' => isset($_POST['product_sync_enabled']),
    'product_sync_tags' => isset($_POST['product_sync_tags']),
    'show_submit_past_orders' => yotpo_get_arr_value($current_settings, 'show_submit_past_orders'),
    'debug_mode' => isset($_POST['debug_mode']) ? true : false);
  update_option('yotpo_settings', $new_settings);
  Yotpo_Product_Sync::setting_changed($product_sync_was_on, $new_settings['product_sync_enabled']);
  Yotpo_Product_Sync::tags_setting_changed($tags_were_on, $new_settings['product_sync_tags'], $product_sync_was_on, $new_settings['product_sync_enabled']);
  if ($current_settings['disable_native_review_system'] != $new_settings['disable_native_review_system']) {
    if ($new_settings['disable_native_review_system'] == false) {
      update_option('woocommerce_enable_review_rating', get_option('native_star_ratings_enabled'));
    } else {
      update_option('woocommerce_enable_review_rating', 'no');
    }
  }
}

function wc_display_yotpo_register() {
  $email = isset($_POST['yotpo_user_email']) ? sanitize_email($_POST['yotpo_user_email']) : '';
  $user_name = isset($_POST['yotpo_user_name']) ? sanitize_user($_POST['yotpo_user_name']) : '';
  $fields = "
      <div class='yotpo-field'>
        <label class='yotpo-label' for='yotpo_user_email'>Email address</label>
        <input type='email' class='yotpo-input' id='yotpo_user_email' name='yotpo_user_email' value='" . esc_attr($email) . "' />
      </div>
      <div class='yotpo-field'>
        <label class='yotpo-label' for='yotpo_user_name'>Name</label>
        <input type='text' class='yotpo-input' id='yotpo_user_name' name='yotpo_user_name' value='" . esc_attr($user_name) . "' />
      </div>
      <div class='yotpo-field'>
        <label class='yotpo-label' for='yotpo_user_password'>Password</label>
        <input type='password' class='yotpo-input' id='yotpo_user_password' name='yotpo_user_password' />
      </div>
      <div class='yotpo-field'>
        <label class='yotpo-label' for='yotpo_user_confirm_password'>Confirm password</label>
        <input type='password' class='yotpo-input' id='yotpo_user_confirm_password' name='yotpo_user_confirm_password' />
      </div>
      <div class='yotpo-field'>
        <input type='submit' name='yotpo_register' value='Register' class='yotpo-btn yotpo-btn--primary' />
      </div>
      <p class='yotpo-terms'>By registering I accept the <a href='https://www.yotpo.com/terms-of-service' target='_blank' rel='noopener'>Terms of Use</a> and recognize that a 'Powered by Yotpo' link will appear on the bottom of my Yotpo widget.</p>";
  $register_html = "<div class='wrap yotpo-admin'>"
    . yotpo_page_header('Collect and show product reviews, ratings and Q&amp;A from Yotpo.') . "
    <form method='post'>" . wp_nonce_field('yotpo_registration_form', '_wpnonce', true, false)
      . yotpo_card('Create your Yotpo account', 'Fill out the form below and click Register to get started with Yotpo.', $fields) . "
    </form>
    <form method='post'>"
      . yotpo_card(
        'Already have a Yotpo account?',
        'Enter your app key and secret token to connect this store.',
        "<div class='yotpo-field'><input type='submit' name='log_in_button' value='Enter your keys' class='yotpo-btn yotpo-btn--outline' /></div>
        <p class='yotpo-help'>Learn <a href='https://support.yotpo.com/docs/woocommerce-installing-yotpo#step-4-import-your-existing-reviews' target='_blank' rel='noopener'>how to export your existing reviews</a> into Yotpo.</p>"
      ) . "
    </form>
  </div>";
  echo wp_kses($register_html, yotpo_allowed_register_html());
}
