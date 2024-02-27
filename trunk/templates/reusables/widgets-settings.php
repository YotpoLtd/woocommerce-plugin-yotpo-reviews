<?php
require __DIR__.'/v3_enablers.php';

function version_selector($yotpo_settings) {
  return "
    <tr valign='top'>
      <th scope='row'><div>Widgets version:</div></th>
      <td>
        <select name='yotpo_widget_version' id='yotpo-widget-version'>
          <option value='v2' " . selected('v2', $yotpo_settings['widget_version'], false) . ">v2</option>
          <option value='v3' " . selected('v3', $yotpo_settings['widget_version'], false) . ">v3</option>
        </select>
      </td>
    </tr>
  ";
}

function v2_locations($yotpo_settings, $main_widget_tab_name) {
  return "
    <tbody id='yotpo-v2-locations' style='display:none'>
      <tr valign='top'>
        <th scope='row'><div>Select v2 widget location:</div></th>
        <td>
          <select name='yotpo_v2_widget_location' class='yotpo-v2-widget-location'>
            <option value='footer' " . selected('footer', $yotpo_settings['v2_widget_location'], false) . ">Page footer</option>
            <option value='tab' " . selected('tab', $yotpo_settings['v2_widget_location'], false) . ">Tab</option>
            <option value='other' " . selected('other', $yotpo_settings['v2_widget_location'], false) . ">Other</option>
          </select>
        </td>
      </tr>
      <tr valign='top' class='yotpo-widget-tab-name'>
        <th scope='row'><div>Select tab name of reviews widget:</div></th>
        <td><div><input type='text' name='yotpo_main_widget_tab_name' value='$main_widget_tab_name' /></div></td>
      </tr>
      <tr valign='top' class='yotpo-widget-location-other-explain'>
        <th scope='row'>
          <p class='description'>
            In order to locate the widget in a custome location open 'wp-content/plugins/woocommerce/templates/content-single-product.php'
            and add the following functions
            <code>wc_yotpo_show_reviews_widget();</code>,
            <code>wc_yotpo_show_buttomline();</code>
            in the requested location.
          </p>
        </th>
      </tr>
    </tbody>
  ";
}

function v3_locations($yotpo_settings) {
  return "
    <tbody id='yotpo-v3-locations' style='display:none'>
      <tr valign='top'>
        <th scope='row'><div>Select v3 widget location:</div></th>
        <td>
          <select name='yotpo_v3_widget_location' class='yotpo-v3-widget-location'>
            <option value='automatic' " . selected('automatic', $yotpo_settings['v3_widget_location'], false) . ">Automatic</option>
            <option value='manual' " . selected('manual', $yotpo_settings['v3_widget_location'], false) . ">Manual</option>
          </select>
        </td>
      </tr>
      <tr valign='top' class='yotpo-widget-location-manual-explain'>
        <th scope='row'>
          <p class='description'>
            In order to locate the widget in a custome location open 'wp-content/plugins/woocommerce/templates/content-single-product.php'
            and add the following functions
            <code>wc_yotpo_show_reviews_widget();</code>,
            <code>wc_yotpo_show_qna_widget();</code>,
            <code>wc_yotpo_show_buttomline();</code>
            in the requested location.
          </p>
        </th>
      </tr>
    </tbody>
  ";
}

function v3_enablers($yotpo_settings) {
  return "
    <tbody id='yotpo-v3-enablers' style='display:none'>
      " .
      v3_enabler(
        checked(1, $yotpo_settings['v3_widgets_enables']['reviews_widget_product'], false),
        'yotpo_reviews_widget_enabled_product',
        'Enable Reviews Widget in Product Page'
      )
      .
      v3_enabler(
        checked(1, $yotpo_settings['v3_widgets_enables']['star_rating_product'], false),
        'yotpo_star_rating_enabled_product',
        'Enable Star Rating in Product Page'
      )
      .
      v3_enabler(
        checked(1, $yotpo_settings['v3_widgets_enables']['qna_product'], false),
        'yotpo_qna_widget_enabled_product',
        'Enable Q&A Widget in Product Page',
        'If you set Q&A on second tab, disable this one to avoid widget duplication'
      )
      .
      v3_enabler(
        checked(1, $yotpo_settings['v3_widgets_enables']['star_rating_category'], false),
        'yotpo_star_rating_enabled_category',
        'Enable Star Rating in Category Page'
      )
      .
      v3_multifield_enabler([
        [
          'text' => 'On Product Page',
          'name' => 'yotpo_reviews_carousel_enabled_product',
          'checked' => checked(1, $yotpo_settings['v3_widgets_enables']['reviews_carousel_product'], false)
        ],
        [
          'text' => 'On Category Page',
          'name' => 'yotpo_reviews_carousel_enabled_category',
          'checked' => checked(1, $yotpo_settings['v3_widgets_enables']['reviews_carousel_category'], false)
        ],
        [
          'text' => 'On Home Page',
          'name' => 'yotpo_reviews_carousel_enabled_home',
          'checked' => checked(1, $yotpo_settings['v3_widgets_enables']['reviews_carousel_home'], false)
        ]
      ],
      'Enable Reviews Carousel')
      .
      v3_multifield_enabler([
        [
          'text' => 'On Product Page',
          'name' => 'yotpo_promoted_products_enabled_product',
          'checked' => checked(1, $yotpo_settings['v3_widgets_enables']['promoted_products_product'], false)
        ],
        [
          'text' => 'On Category Page',
          'name' => 'yotpo_promoted_products_enabled_category',
          'checked' => checked(1, $yotpo_settings['v3_widgets_enables']['promoted_products_category'], false)
        ],
        [
          'text' => 'On Home Page',
          'name' => 'yotpo_promoted_products_enabled_home',
          'checked' => checked(1, $yotpo_settings['v3_widgets_enables']['promoted_products_home'], false)
        ]
      ],
      'Enable Promoted Products')
      .
      v3_multifield_enabler([
        [
          'text' => 'On Product Page',
          'name' => 'yotpo_reviews_tab_enabled_product',
          'checked' => checked(1, $yotpo_settings['v3_widgets_enables']['reviews_tab_product'], false)
        ],
        [
          'text' => 'On all other pages',
          'name' => 'yotpo_reviews_tab_enabled_category',
          'checked' => checked(1, $yotpo_settings['v3_widgets_enables']['reviews_tab_category'], false)
        ]
      ],
      'Enable Reviews Tab')
      . "
    </tbody>
  ";
}

function v2_enablers($yotpo_settings) {
  return "
    <tbody id='yotpo-v2-enablers' style='display:none'>
      <tr valign='top'>
        <th scope='row'><div>Enable bottom line in Product Page:</div></th>
        <td>
          <input type='checkbox' name='yotpo_bottom_line_enabled_product' value='1' " . checked(1, $yotpo_settings['v2_widgets_enables']['bottom_line_product'], false) . " />
        </td>
      </tr>					  	 
      <tr valign='top'>
        <th scope='row'><div>Enable Q&A bottom line in Product Page:</div></th>
        <td><input type='checkbox' name='yotpo_qna_enabled_product' value='1' " . checked(1, $yotpo_settings['v2_widgets_enables']['qna_product'], false) . " /></td>
      </tr>
      <tr valign='top'>
        <th scope='row'><div>Enable bottom line in Category Page:</div></th>
        <td>
          <input type='checkbox' name='yotpo_bottom_line_enabled_category' value='1' " . checked(1, $yotpo_settings['v2_widgets_enables']['bottom_line_category'], false) . " />		   		       
        </td>
      </tr>
    </tbody>
  ";
}

function styles() {
  return "
    <style>
      #yotpodbg {
        background: #e09999;
      }
      #info-dialog h3 {
        margin: 0;
      }
      #close-info-modal {
        margin: auto;
        display: block;
      }
    </style>
  ";
}
