<?php

defined( 'ABSPATH' ) || exit;

function wc_yotpo_get_default_settings() {
  return array('app_key' => '',
    'secret' => '',
    'v2_widget_location' => 'footer',
    'v3_widget_location' => 'automatic',
    'language_code' => 'en',
    'main_widget_tab_name' => 'Reviews',
    'qna_widget_tab_name' => 'Q&A',
    'widget_version' => 'v3',
    'v3_widgets_ids' => array(
      'reviews_widget' => '',
      'qna' => '',
      'star_rating' => '',
      'reviews_carousel' => '',
      'promoted_products' => '',
      'reviews_tab' => '',
    ),
    'v3_widgets_enables' => array(
      'reviews_widget_product' => true,
      'qna_product' => true,
      'star_rating_product' => true,
      'star_rating_category' => true,
      'reviews_carousel_product' => true,
      'reviews_carousel_category' => true,
      'reviews_carousel_home' => true,
      'promoted_products_product' => true,
      'promoted_products_category' => true,
      'promoted_products_home' => true,
      'reviews_tab_product' => true,
      'reviews_tab_category' => true,
    ),
    'v2_widgets_enables' => array(
      'qna_product' => false,
      'bottom_line_product' => true,
      'bottom_line_category' => false,
    ),
    'yotpo_language_as_site' => true,
    'show_submit_past_orders' => true,
    'yotpo_order_status' => 'wc-completed',
    'disable_native_review_system' => true,
    'product_sync_enabled' => true,
    'product_sync_tags' => false,
    'native_star_ratings_enabled' => 'no',
    'debug_mode' => false
  );
}

// What a missing key meant before it existed: every switch off, widgets included (a widget added
// after the settings were last saved stays hidden), and v2 widgets. Settings saved by an older
// version lack these keys, so they must read as they always did, not as the new-install defaults
// above. The product sync is the exception: it is on for existing stores (Yotpo_Product_Sync::setting_on()).
function wc_yotpo_legacy_missing_settings() {
  $legacy = wc_yotpo_switches_off( wc_yotpo_get_default_settings() );
  $legacy['widget_version'] = 'v2';
  $legacy['product_sync_enabled'] = true;
  return $legacy;
}

// The same settings with every true/false value, nested ones included, set to false.
function wc_yotpo_switches_off( array $settings ) {
  foreach ( $settings as $key => $value ) {
    if ( is_array( $value ) ) {
      $settings[ $key ] = wc_yotpo_switches_off( $value );
    } elseif ( is_bool( $value ) ) {
      $settings[ $key ] = false;
    }
  }
  return $settings;
}

// Every read of yotpo_settings gets the keys missing from the saved settings, nested ones included,
// from wc_yotpo_legacy_missing_settings() (the defaults with every switch off). Saved values win.
function wc_yotpo_fill_missing_settings( $settings ) {
  return is_array( $settings ) ? array_replace_recursive( wc_yotpo_legacy_missing_settings(), $settings ) : $settings;
}
