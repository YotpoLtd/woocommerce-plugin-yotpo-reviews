<?php

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
  return $yotpo_api->get_widget_instances();
}

function get_widget_instances() {
  $response = receive_widget_instances()['widget_instances'];
  $ids_object = array();
  foreach($response as &$val) {
    $ids_object[get_yotpo_widget_field_name($val['widget_type_name'])] = $val['widget_instance_id'];
  }
  return $ids_object;
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
  $new_settings = array(
    'app_key' => yotpo_get_arr_value($_POST, 'yotpo_app_key'),
    'secret' => yotpo_get_arr_value($_POST, 'yotpo_oauth_token'),
    'v2_widget_location' => yotpo_get_arr_value($_POST, 'yotpo_v2_widget_location'),
    'v3_widget_location' => yotpo_get_arr_value($_POST, 'yotpo_v3_widget_location'),
    'language_code' => yotpo_get_arr_value($_POST, 'yotpo_widget_language_code'),
    'main_widget_tab_name' => yotpo_get_arr_value($_POST, 'yotpo_main_widget_tab_name'),
    'qna_widget_tab_name' => yotpo_get_arr_value($_POST, 'yotpo_qna_widget_tab_name'),
    'widget_version' => yotpo_get_arr_value($_POST, 'yotpo_widget_version'),
    'v3_widgets_ids' => get_widgets_instances(),
    'v3_widgets_enables' => get_v3_widgets_enables(),
    'v2_widgets_enables' => get_v2_widgets_enables(),
    'yotpo_order_status' => yotpo_get_arr_value($_POST, 'yotpo_order_status'),
    'yotpo_language_as_site' => isset($_POST['yotpo_language_as_site']) ? true : false,
    'disable_native_review_system' => isset($_POST['disable_native_review_system']) ? true : false,
    'show_submit_past_orders' => yotpo_get_arr_value($current_settings, 'show_submit_past_orders'),
    'debug_mode' => isset($_POST['debug_mode']) ? true : false);
  update_option('yotpo_settings', $new_settings);
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
  $register_html = "<div class='wrap'><h2>Yotpo Registration</h2>
  <form method='post'>
  <table class='form-table'>"
          . wp_nonce_field('yotpo_registration_form') .
          "<fieldset>
      <h2 class='y-register-title'>Fill out the form below and click register to get started with Yotpo.</h2></br></br>    
      <tr valign='top'>
        <th scope='row'><div>Email address:</div></th>			 			  
        <td><div><input type='text' name='yotpo_user_email' value='$email' /></div></td>
      </tr>
      <tr valign='top'>
        <th scope='row'><div>Name:</div></th>			 			  
        <td><div><input type='text' name='yotpo_user_name' value='$user_name' /></div></td>
      </tr>
      <tr valign='top'>
        <th scope='row'><div>Password:</div></th>			 			  
        <td><div><input type='password' name='yotpo_user_password' /></div></td>
      </tr>
      <tr valign='top'>
        <th scope='row'><div>Confirm password:</div></th>			 			  
        <td><div><input type='password' name='yotpo_user_confirm_password' /></div></td>
      </tr>
      <tr valign='top'>
        <th scope='row'></th>
        <td><div><input type='submit' name='yotpo_register' value='Register' class='button-primary submit-btn' /></div></td>
      </tr>			  
    </fieldset>			
    <table/>
  </form>
  <form method='post'>
    <div>Already registered to Yotpo?<input type='submit' name='log_in_button' value='click here' class='button-secondary not-user-btn' /></div>
  </form></br><p class='description'>*Learn <a href='http://support.yotpo.com/entries/24454261-Exporting-reviews-for-Woocommerce'>how to export your existing reviews</a> into Yotpo.</p></br></br>
  <div class='yotpo-terms'>By registering I accept the <a href='https://www.yotpo.com/terms-of-service' target='_blank'>Terms of Use</a> and recognize that a 'Powered by Yotpo' link will appear on the bottom of my Yotpo widget.</div>
</div>";
  echo $register_html;
}
