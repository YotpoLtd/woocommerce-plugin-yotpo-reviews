<?php

defined( 'ABSPATH' ) || exit;

require __DIR__.'/v3_enablers.php';

// The ids and classes below are what assets/js/settings.js shows and hides for the store's widget
// version, and when the widget location changes.

/** The widgets page of the Yotpo dashboard, where the merchant sets up V3 widgets. */
function yotpo_widgets_dashboard_url() {
  return 'https://reviews.yotpo.com/#/display-reviews/on-site-widgets';
}

/** The id of the form the "Switch to V3 widgets" button belongs to (see wc_yotpo_switch_to_v3_widgets()). */
function yotpo_switch_v3_form_id() {
  return 'yotpo_switch_v3_form';
}

/**
 * The widget version notice: the version is no longer a choice. Stores on v3, and every new store, only
 * have v3. A store still on v2 keeps it, so its storefront doesn't change, and gets a notice that v2
 * widgets are being retired with a one-way switch to v3 (its own form: see wc_yotpo_switch_to_v3_widgets()).
 * The hidden field, without a name, only tells settings.js which version's fields to show.
 */
function widget_version_notice() {
  $version = use_v3_widgets() ? 'v3' : 'v2';
  $field = "<input type='hidden' id='yotpo-widget-version' value='" . esc_attr($version) . "' />";
  if ('v3' === $version) {
    return $field;
  }
  return $field . "
    <div class='yotpo-callout yotpo-tone--warning yotpo-callout--action'>
      <p><strong>Your store uses V2 widgets, which are being retired.</strong> Switch to V3 widgets to keep your widgets supported. Their design is set in the <a href='" . esc_url(yotpo_widgets_dashboard_url()) . "' target='_blank' rel='noopener'>Yotpo dashboard</a>. You can't switch back.</p>
      <input type='submit' form='" . yotpo_switch_v3_form_id() . "' name='yotpo_switch_to_v3' id='yotpo-switch-to-v3' value='Switch to V3 widgets' class='yotpo-btn yotpo-btn--primary' />
    </div>";
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

/**
 * The widgets' language, in the widgets card.
 *
 * - v2 widgets take the language from the plugin (data-lang): the widget language code, or the site's
 *   language with "Match the site language" (yotpo_language_as_site).
 * - v3 widgets take it from the loader's languageCode parameter (v3HeaderScript.js): with "Match each
 *   page's language" (widget_language_from_page) on, the language of the page being shown
 *   (wc_yotpo_widget_language_code()), so a site in several languages (WPML, Polylang) shows each page's;
 *   with it off, the language set in Yotpo. It is a setting of its own, off for stores upgrading from 1.x:
 *   yotpo_language_as_site was on for many of them and did nothing for v3 widgets, so reusing it would
 *   have changed their widgets' language on update.
 *
 * The fields that don't apply to the store's widgets stay in the form, hidden, so saving keeps them.
 */
function wc_yotpo_widget_language_settings($yotpo_settings, $language_code) {
  $v2_fields = yotpo_text_field(
    'yotpo_widget_language_code',
    'yotpo_widget_language_code',
    $language_code,
    'Widget language',
    "The language code for the widgets, such as en or fr. <a href='https://support.yotpo.com/docs/translating-yotpo-reviews' target='_blank' rel='noopener'>Supported languages</a>",
    "maxlength='5'",
    '',
    'yotpo-input--short'
  ) .
  yotpo_switch_row(
    'yotpo_language_as_site',
    'yotpo_language_as_site',
    checked(1, $yotpo_settings['yotpo_language_as_site'], false),
    'Match the site language',
    'For multilingual sites: show the widgets in each visitor\'s site language.'
  );
  $v3_field = yotpo_switch_row(
    'widget_language_from_page',
    'widget_language_from_page',
    checked(true, !empty($yotpo_settings['widget_language_from_page']), false),
    "Match each page's language",
    "Shows the widgets in the language of each page, including on multilingual sites (WPML, Polylang). When off, the widgets use the language set in Yotpo, under Account Settings &gt; General Settings &gt; Language. <a href='https://support.yotpo.com/docs/translating-yotpo-reviews' target='_blank' rel='noopener'>Languages and translations in Yotpo</a>"
  );
  if (!use_v3_widgets()) {
    return "<div id='yotpo-v2-language'>" . $v2_fields . "</div>
    <div id='yotpo-v3-language' style='display:none'>" . $v3_field . "</div>";
  }
  return "<div id='yotpo-v3-language'>" . $v3_field . "</div>
    <div id='yotpo-v2-language' style='display:none'>" . $v2_fields . "</div>";
}
