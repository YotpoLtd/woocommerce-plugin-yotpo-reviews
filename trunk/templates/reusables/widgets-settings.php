<?php

defined( 'ABSPATH' ) || exit;

require __DIR__.'/v3_enablers.php';

// The ids and classes below are what assets/js/settings.js shows and hides when the widget
// version or location changes.

function version_selector($yotpo_settings) {
  return yotpo_select_row(
    'yotpo-widget-version',
    'yotpo_widget_version',
    '',
    'Widgets version',
    "<option value='v2' " . selected('v2', $yotpo_settings['widget_version'], false) . ">v2</option>
          <option value='v3' " . selected('v3', $yotpo_settings['widget_version'], false) . ">v3</option>"
  );
}

function v2_locations($yotpo_settings, $main_widget_tab_name) {
  return "
    <div id='yotpo-v2-locations' style='display:none'>
      " . yotpo_select_row(
        'yotpo_v2_widget_location',
        'yotpo_v2_widget_location',
        'yotpo-v2-widget-location',
        'Reviews widget location',
        "<option value='footer' " . selected('footer', $yotpo_settings['v2_widget_location'], false) . ">Page footer</option>
          <option value='tab' " . selected('tab', $yotpo_settings['v2_widget_location'], false) . ">Tab</option>
          <option value='other' " . selected('other', $yotpo_settings['v2_widget_location'], false) . ">Other</option>"
      ) .
      yotpo_text_field(
        'yotpo_main_widget_tab_name',
        'yotpo_main_widget_tab_name',
        $main_widget_tab_name,
        'Reviews tab name',
        null,
        '',
        'yotpo-widget-tab-name'
      ) . "
      <div class='yotpo-callout yotpo-widget-location-other-explain'>
        To show the widget somewhere else, open <code>wp-content/plugins/woocommerce/templates/content-single-product.php</code>
        and add <code>wc_yotpo_show_reviews_widget();</code> and <code>wc_yotpo_show_buttomline();</code> where you want them.
      </div>
    </div>
  ";
}

function v3_locations($yotpo_settings) {
  return "
    <div id='yotpo-v3-locations' style='display:none'>
      " . yotpo_select_row(
        'yotpo_v3_widget_location',
        'yotpo_v3_widget_location',
        'yotpo-v3-widget-location',
        'Widget placement',
        "<option value='automatic' " . selected('automatic', $yotpo_settings['v3_widget_location'], false) . ">Automatic</option>
          <option value='manual' " . selected('manual', $yotpo_settings['v3_widget_location'], false) . ">Manual</option>",
        'Automatic places the widgets in your theme\'s standard product page areas.'
      ) . "
      <div class='yotpo-callout yotpo-widget-location-manual-explain'>
        To place the widgets yourself, open <code>wp-content/plugins/woocommerce/templates/content-single-product.php</code>
        and add <code>wc_yotpo_show_reviews_widget();</code>, <code>wc_yotpo_show_qna_widget();</code> and
        <code>wc_yotpo_show_buttomline();</code> where you want them.
      </div>
    </div>
  ";
}

function v3_enablers($yotpo_settings) {
  return "
    <div id='yotpo-v3-enablers' style='display:none'>
      " .
      v3_enabler(
        checked(1, $yotpo_settings['v3_widgets_enables']['reviews_widget_product'], false),
        'yotpo_reviews_widget_enabled_product',
        'Reviews widget',
        'On product pages.'
      )
      .
      v3_enabler(
        checked(1, $yotpo_settings['v3_widgets_enables']['star_rating_product'], false),
        'yotpo_star_rating_enabled_product',
        'Star rating on product pages'
      )
      .
      v3_enabler(
        checked(1, $yotpo_settings['v3_widgets_enables']['star_rating_category'], false),
        'yotpo_star_rating_enabled_category',
        'Star rating on category pages'
      )
      .
      v3_enabler(
        checked(1, $yotpo_settings['v3_widgets_enables']['qna_product'], false),
        'yotpo_qna_widget_enabled_product',
        'Q&A widget',
        'On product pages. If Q&A is shown in its own tab, turn this off so it doesn\'t appear twice.'
      )
      .
      v3_multifield_enabler([
        [
          'text' => 'Product page',
          'name' => 'yotpo_reviews_carousel_enabled_product',
          'checked' => checked(1, $yotpo_settings['v3_widgets_enables']['reviews_carousel_product'], false)
        ],
        [
          'text' => 'Category page',
          'name' => 'yotpo_reviews_carousel_enabled_category',
          'checked' => checked(1, $yotpo_settings['v3_widgets_enables']['reviews_carousel_category'], false)
        ],
        [
          'text' => 'Home page',
          'name' => 'yotpo_reviews_carousel_enabled_home',
          'checked' => checked(1, $yotpo_settings['v3_widgets_enables']['reviews_carousel_home'], false)
        ]
      ],
      'Reviews carousel')
      .
      v3_multifield_enabler([
        [
          'text' => 'Product page',
          'name' => 'yotpo_promoted_products_enabled_product',
          'checked' => checked(1, $yotpo_settings['v3_widgets_enables']['promoted_products_product'], false)
        ],
        [
          'text' => 'Category page',
          'name' => 'yotpo_promoted_products_enabled_category',
          'checked' => checked(1, $yotpo_settings['v3_widgets_enables']['promoted_products_category'], false)
        ],
        [
          'text' => 'Home page',
          'name' => 'yotpo_promoted_products_enabled_home',
          'checked' => checked(1, $yotpo_settings['v3_widgets_enables']['promoted_products_home'], false)
        ]
      ],
      'Promoted products')
      .
      v3_multifield_enabler([
        [
          'text' => 'Product page',
          'name' => 'yotpo_reviews_tab_enabled_product',
          'checked' => checked(1, $yotpo_settings['v3_widgets_enables']['reviews_tab_product'], false)
        ],
        [
          'text' => 'All other pages',
          'name' => 'yotpo_reviews_tab_enabled_category',
          'checked' => checked(1, $yotpo_settings['v3_widgets_enables']['reviews_tab_category'], false)
        ]
      ],
      'Reviews tab')
      . "
    </div>
  ";
}

function v2_enablers($yotpo_settings) {
  return "
    <div id='yotpo-v2-enablers' style='display:none'>
      " .
      v3_enabler(
        checked(1, $yotpo_settings['v2_widgets_enables']['bottom_line_product'], false),
        'yotpo_bottom_line_enabled_product',
        'Star rating on product pages'
      ) .
      v3_enabler(
        checked(1, $yotpo_settings['v2_widgets_enables']['qna_product'], false),
        'yotpo_qna_enabled_product',
        'Q&A summary on product pages'
      ) .
      v3_enabler(
        checked(1, $yotpo_settings['v2_widgets_enables']['bottom_line_category'], false),
        'yotpo_bottom_line_enabled_category',
        'Star rating on category pages'
      ) . "
    </div>
  ";
}
