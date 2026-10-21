<?php

defined( 'ABSPATH' ) || exit;

require __DIR__.'/../lib/utils/wc-yotpo-settings-functions.php';
require __DIR__.'/reusables/widgets-settings.php';
require __DIR__.'/reusables/error-info.php';
require __DIR__.'/menu/menu-positions.php';

function wc_display_yotpo_admin_page() {
    if (function_exists('current_user_can') && !current_user_can('manage_options')) {
        die('');
    }
    if (wc_yotpo_compatible()) {
        if (isset($_POST['log_in_button'])) {
            wc_display_yotpo_settings();
        } elseif (isset($_POST['yotpo_settings'])) {
            check_admin_referer('yotpo_settings_form');
            wc_proccess_yotpo_settings();
            wc_display_yotpo_settings();
        } elseif (isset($_POST['yotpo_sync_ids'])) {
            check_admin_referer('yotpo_settings_form');
            wc_proccess_yotpo_widgets_ids_synchronisation();
            wc_display_yotpo_settings();
        } elseif (isset($_POST['yotpo_past_orders'])) {
            // Its own form (yotpo_past_orders_form), not the settings form: starts a run and saves nothing else.
            check_admin_referer('yotpo_settings_form');
            $days = isset($_POST['yotpo_past_orders_days']) ? (int) $_POST['yotpo_past_orders_days'] : Yotpo_Order_Backfill::DEFAULT_DAYS;
            $started = Yotpo_Order_Backfill::start($days);
            if (is_wp_error($started)) {
                wc_yotpo_display_message($started->get_error_message(), true);
            } else {
                wc_yotpo_display_message('Sending orders from the last ' . $days . ' days in the background. Progress shows under Send past orders.', false);
            }
            wc_display_yotpo_settings();
        } elseif (isset($_POST['yotdbg-clear'])) {
            check_admin_referer('yotdbg-clear');
            wc_yotpo_clear_debug_log();
            wc_display_yotpo_settings();
        } elseif (isset($_POST['yotpo_switch_to_v3'])) {
            check_admin_referer('yotpo_settings_form');
            wc_yotpo_switch_to_v3_widgets();
            wc_display_yotpo_settings();
        } else {
            wc_display_yotpo_settings();
        }
    } else {
        echo '<h1>Yotpo plugin requires PHP ' . esc_html(WC_YOTPO_MIN_PHP_VERSION) . ' or above.</h1><br>';
    }
}
function wc_display_yotpo_settings() {
    $yotpo_settings = get_option('yotpo_settings', wc_yotpo_get_default_settings());
    $app_key = $yotpo_settings['app_key'];
    $secret = $yotpo_settings['secret'];
    $v3_widgets_ids = yotpo_get_arr_value($yotpo_settings, 'v3_widgets_ids', []);
    $reviews_widget_id = yotpo_get_arr_value($v3_widgets_ids, 'reviews_widget');
    $qna_widget_id = yotpo_get_arr_value($v3_widgets_ids, 'qna');
    $star_ratings_widget_id = yotpo_get_arr_value($v3_widgets_ids, 'star_rating');
    $reviews_carousel_widget_id = yotpo_get_arr_value($v3_widgets_ids, 'reviews_carousel');
    $promoted_products_widget_id = yotpo_get_arr_value($v3_widgets_ids, 'promoted_products');
    $reviews_tab_widget_id = yotpo_get_arr_value($v3_widgets_ids, 'reviews_tab');
    $language_code = $yotpo_settings['language_code'];
    $main_widget_tab_name = $yotpo_settings['main_widget_tab_name'];
    $qna_widget_tab_name = $yotpo_settings['qna_widget_tab_name'];
    $no_credentials = empty($app_key) || empty($secret);
    // The key fields are editable until keys are saved, and after "Enter your keys" (log_in_button).
    $logging_in = isset($_POST['log_in_button']) || $no_credentials;
    $read_only = $logging_in ? '' : 'readonly';
    $credentials_badge = $no_credentials
        ? "<span class='yotpo-badge yotpo-tone--neutral'>Not set up</span>"
        : "<span class='yotpo-badge yotpo-tone--success'>Keys saved</span>";
    // reviews.yotpo.com sends logged-out users to the Yotpo login screen. The old auto-login link put the
    // app secret in the URL (browser history, logs), and www.yotpo.com/?login=true now only opens the marketing site.
    // Accounts are created on Yotpo's site, not in the plugin.
    $credentials_help = $logging_in
        ? "<div class='yotpo-callout'>To get your app key and secret token, <a href='https://reviews.yotpo.com/' target='_blank' rel='noopener'>log in to Yotpo</a> and go to your account settings. New to Yotpo? <a href='https://accounts.yotpo.com/#/signup' target='_blank' rel='noopener'>Sign up on yotpo.com</a>.</div>"
        : '';
    if (isset($yotpo_settings['debug_mode']) && $yotpo_settings['debug_mode']) {
        $settings_dump = wp_json_encode($yotpo_settings);
        $debug_log = wc_yotpo_read_debug_log();
    }

    $connection = yotpo_card(
        'Connection',
        'The app key and secret token connect this store to your Yotpo account.',
        $credentials_help .
        yotpo_text_field('app_key', 'yotpo_app_key', $app_key, 'App key', null, $read_only, '', 'yotpo-input--mono') .
        // Masked once saved; shown while the merchant types it in. autocomplete=off keeps password managers out of it.
        yotpo_text_field('secret', 'yotpo_oauth_token', $secret, 'Secret token', null, $read_only . " autocomplete='off'", '', 'yotpo-input--mono', $logging_in ? 'text' : 'password') . "
        <input id='reviews_widget' type='hidden' name='yotpo_reviews_widget_id' value='" . esc_attr($reviews_widget_id) . "' />
        <input id='qna_widget' type='hidden' name='yotpo_qna_widget_id' value='" . esc_attr($qna_widget_id) . "' />
        <input id='star_ratings_widget' type='hidden' name='yotpo_star_ratings_widget_id' value='" . esc_attr($star_ratings_widget_id) . "' />
        <input id='reviews_carousel_widget' type='hidden' name='yotpo_reviews_carousel_widget_id' value='" . esc_attr($reviews_carousel_widget_id) . "' />
        <input id='promoted_products_widget' type='hidden' name='yotpo_promoted_products_widget_id' value='" . esc_attr($promoted_products_widget_id) . "' />
        <input id='reviews_tab_widget' type='hidden' name='yotpo_tab_widget_id' value='" . esc_attr($reviews_tab_widget_id) . "' />",
        '',
        $credentials_badge
    );

    $catalog = yotpo_card(
        'Product catalog',
        '',
        yotpo_switch_row(
            'product_sync_enabled',
            'product_sync_enabled',
            checked(true, Yotpo_Product_Sync::setting_on(), false),
            'Sync products to Yotpo',
            'Keeps your product catalog, including variations, up to date in Yotpo. Runs in the background.',
            '',
            "<div class='yotpo-status yotpo-tone--" . wc_yotpo_sync_status_tone() . "'>" . esc_html(Yotpo_Product_Sync::status_summary()) . "</div>"
        ) .
        yotpo_switch_row(
            'product_sync_tags',
            'product_sync_tags',
            checked(true, Yotpo_Product_Sync::tags_on(), false),
            'Use product tags for the blocklist, groups and custom questions',
            "Tag products with <code>YBlocklist</code>, <code>YGroup_</code><i>group</i> or <code>YCRF_</code><i>form tag</i> to set their Yotpo blocklist, review group and custom question form. When on, these tags replace the blocklist, groups and form tags set in Yotpo, for example by catalog CSV."
        )
    );

    $orders = yotpo_card(
        'Orders',
        '',
        yotpo_switch_row(
            'order_sync_enabled',
            'order_sync_enabled',
            checked(true, Yotpo_Order_Sync::setting_on(), false),
            'Send orders to Yotpo',
            'Sends each order once it reaches the status below, so Yotpo can ask for a review. Runs in the background. Turn off only if your orders reach Yotpo another way.',
            '',
            "<div class='yotpo-status yotpo-tone--" . esc_attr(Yotpo_Order_Sync::status_tone()) . "'>" . esc_html(Yotpo_Order_Sync::status_summary()) . "</div>"
        ) .
        yotpo_order_status_option($yotpo_settings) .
        wc_yotpo_past_orders_row()
    );

    $widgets = yotpo_card(
        'On-site widgets',
        'Choose where Yotpo widgets appear in your store. Their design is set in the Yotpo dashboard.',
        version_selector($yotpo_settings) .
        v2_locations($yotpo_settings, $main_widget_tab_name) .
        v3_locations($yotpo_settings) .
        yotpo_text_field(
            'yotpo_qna_widget_tab_name',
            'yotpo_qna_widget_tab_name',
            $qna_widget_tab_name,
            'Q&A tab name',
            null,
            '',
            'yotpo-widget-tab-name yotpo-qna-widget-tab-name'
        ) .
        v3_enablers($yotpo_settings) .
        v2_enablers($yotpo_settings)
    );

    $language = yotpo_card(
        'Language',
        '',
        yotpo_text_field(
            'yotpo_widget_language_code',
            'yotpo_widget_language_code',
            $language_code,
            'Widget language',
            "The 2-letter language code for the widgets, such as en or fr. <a href='https://support.yotpo.com/docs/languages-yotpo-supports-for-widgets' target='_blank' rel='noopener'>Supported languages</a>",
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
        )
    );

    $reviews = yotpo_card(
        'Reviews',
        '',
        yotpo_switch_row(
            'disable_native_review_system',
            'disable_native_review_system',
            checked(1, $yotpo_settings['disable_native_review_system'], false),
            'Turn off WooCommerce reviews',
            'Hides the WooCommerce review form and star ratings, so shoppers only see Yotpo reviews.'
        )
    );

    $tools = yotpo_card(
        'Tools',
        '',
        "<div class='yotpo-row'>
            <div class='yotpo-row__text'>
                <span class='yotpo-label'>Export reviews</span>
                <p class='yotpo-help'>Downloads your WooCommerce reviews as a CSV file to import into Yotpo. <a href='https://support.yotpo.com/docs/woocommerce-installing-yotpo#step-4-import-your-existing-reviews' target='_blank' rel='noopener'>How to import them</a></p>
            </div>
            <div class='yotpo-row__control'>
                <button type='button' id='yotpo-export-reviews' class='yotpo-btn yotpo-btn--outline' " . disabled(true, $no_credentials, false) . ">Export reviews</button>
            </div>
        </div>"
    );

    $settings_html = "
    <div class='wrap yotpo-admin'>
        " . yotpo_page_header('Customize how the widgets look, and your review request emails, in the <a href=\'https://reviews.yotpo.com/\' target=\'_blank\' rel=\'noopener\'>Yotpo dashboard</a>.') . "
        <form method='post' id='yotpo_settings_form'>" . wp_nonce_field('yotpo_settings_form', '_wpnonce', true, false) .
            $connection . $catalog . $orders . $widgets . $language . $reviews . "
            <div id='yotpodbg' style='display: none;'>" .
                yotpo_switch_row('debug_mode', 'debug_mode', checked(1, $yotpo_settings['debug_mode'], false), 'Debug mode', 'Writes all plugin actions to a private log file under <i>wp-content/uploads/yotpo-social-reviews-for-woocommerce/</i> and shows the log and all settings on this page.') . "
            </div>
            <div class='yotpo-actions'>
                <p class='yotpo-actions__note'>Changes take effect once you save.</p>
                <input type='submit' name='yotpo_settings' value='Save changes' class='yotpo-btn yotpo-btn--primary' id='save_yotpo_settings' />
            </div>
            <div id='yotpo-sync-widget-ids-row' style='display:none'>
                <input type='submit' name='yotpo_sync_ids' value='Sync' class='yotpo-btn yotpo-btn--outline' id='yotpo_sync_ids' />
            </div>
        </form>
        <form method='post' id='yotpo_past_orders_form'>" . wp_nonce_field('yotpo_settings_form', '_wpnonce', true, false) . "</form>
        <form method='post' id='yotpo_switch_v3_form'>" . wp_nonce_field('yotpo_settings_form', '_wpnonce', true, false) . "</form>
        " . $tools . "
        <iframe name='yotpo_export_reviews_frame' style='display: none;'></iframe>
        <form action='' method='get' target='yotpo_export_reviews_frame' style='display: none;'>
            <input type='hidden' name='download_exported_reviews' value='true' />
            " . wp_nonce_field('yotpo_export_reviews', '_wpnonce', true, false) . "
            <input type='submit' value='Export Reviews' id='export_reviews_submit' />
        </form>
        " . info_dialog(
            use_v3_widgets(),
            dialog_error_info(
                is_array($yotpo_settings['v3_widgets_enables']) ? $yotpo_settings['v3_widgets_enables'] : [],
                $reviews_widget_id,
                $qna_widget_id,
                $star_ratings_widget_id,
                $reviews_carousel_widget_id,
                $promoted_products_widget_id,
                $reviews_tab_widget_id
            )
        );
    add_filter( 'safe_style_css', function( $styles ) {
        $styles[] = 'display';
        return $styles;
    } );
    echo wp_kses($settings_html, yotpo_settings_allowed_html());
    if (isset($yotpo_settings['debug_mode']) && $yotpo_settings['debug_mode']) {
        echo '<section class="yotpo-card yotpo-debug"><div class="yotpo-card__header"><div><h2 class="yotpo-card__title">Debug</h2></div></div><div class="yotpo-card__content">';
        echo '<span class="yotpo-label">Settings</span><pre>' . esc_html($settings_dump) . '</pre><span class="yotpo-label">Log</span>';
        if (is_wp_error($debug_log)) {
            echo '<textarea class="yotpo-textarea" readonly>' . esc_html($debug_log->get_error_message()) . '</textarea>';
        } elseif ($debug_log === '') {
            echo '<textarea class="yotpo-textarea" readonly>The debug log is empty.</textarea>';
        } else {
            echo '<textarea class="yotpo-textarea" readonly>' . esc_html($debug_log) . '</textarea>
            <form method="post" id="yotdbg-clear">' . wp_kses(wp_nonce_field('yotdbg-clear', '_wpnonce', true, false), yotpo_nonce_field_allowed_html()) . '<input type="submit" value="Clear log" class="yotpo-btn yotpo-btn--outline" name="yotdbg-clear" id="yotdbg-clear-submit"/></form>';
        }
        echo '</div></section>';
    }
    echo '</div>';
}

/**
 * The Send past orders row of the Orders card: how many days back and the button, with the orders that
 * covers under them, and the last run's progress. The row sits in the Orders card, inside the settings
 * form, but its days field and button belong to their own form (yotpo_past_orders_form, rendered after
 * the settings form) through the form attribute, with their own handler (wc_display_yotpo_admin_page()).
 * The button is therefore not the settings form's default button: Enter in a settings field still saves.
 * settings.js updates the count as the days change and asks for confirmation before a run starts.
 */
function wc_yotpo_past_orders_row() {
    $days = Yotpo_Order_Backfill::DEFAULT_DAYS;
    $ready = Yotpo_Order_Sync::enabled();
    Yotpo_Order_Backfill::end_if_stalled();
    $running = Yotpo_Order_Backfill::running();
    $count = $ready ? Yotpo_Order_Backfill::count($days) : 0;
    $status = Yotpo_Order_Backfill::status_summary();
    $note = $ready ? '' : " <p class='yotpo-help'>Turn on Send orders to Yotpo and save to use it.</p>";
    return "
    <div class='yotpo-row'>
      <div class='yotpo-row__text'>
        <label class='yotpo-label' for='yotpo_past_orders_days'>Send past orders</label>
        <p class='yotpo-help'>Sends orders in the status above from the last 1 to " . Yotpo_Order_Backfill::MAX_DAYS . " days, except those Yotpo already has. Your customers may get a review request.</p>" .
        $note .
        ('' !== $status ? "<div class='yotpo-status yotpo-tone--" . esc_attr(Yotpo_Order_Backfill::status_tone()) . "' id='yotpo-past-orders-status'>" . esc_html($status) . "</div>" : '') . "
      </div>
      <div class='yotpo-row__control yotpo-row__control--stack'>
        <div class='yotpo-row__control yotpo-row__control--nowrap'>
          <input type='number' class='yotpo-input yotpo-input--days' id='yotpo_past_orders_days' name='yotpo_past_orders_days' value='" . esc_attr($days) . "' min='1' max='" . esc_attr(Yotpo_Order_Backfill::MAX_DAYS) . "' step='1' form='yotpo_past_orders_form' " . disabled(true, !$ready || $running, false) . " />
          <span class='yotpo-help'>days</span>
          <input type='submit' form='yotpo_past_orders_form' name='yotpo_past_orders' id='yotpo-past-orders' value='Send past orders' class='yotpo-btn yotpo-btn--primary' " . disabled(true, !$ready || $running, false) . " />
        </div>
        <p class='yotpo-help' id='yotpo-past-orders-count'>" . ($ready ? esc_html(sprintf(_n('%1$d order in the last %2$d days.', '%1$d orders in the last %2$d days.', $count, 'yotpo-social-reviews-for-woocommerce'), $count, $days)) : '') . "</p>
      </div>
    </div>";
}

/**
 * Color of the product sync status line: what the merchant should make of it at a glance.
 */
function wc_yotpo_sync_status_tone() {
    if (!Yotpo_Product_Sync::enabled()) {
        return 'neutral';
    }
    $status = Yotpo_Product_Sync::state();
    switch (isset($status['status']) ? $status['status'] : '') {
        case Yotpo_Sync_State::STATUS_COMPLETE:
            return empty($status['failed']) ? 'success' : 'warning';
        case Yotpo_Sync_State::STATUS_STOPPED:
            return 'warning';
        case Yotpo_Sync_State::STATUS_STALLED:
            return 'danger';
        default:
            return 'info';
    }
}
function wc_proccess_yotpo_widgets_ids_synchronisation() {
    $widgets_instances = get_widget_instances();
    if (is_wp_error($widgets_instances)) {
        wc_yotpo_display_message(wc_yotpo_widget_instances_error_message($widgets_instances), true);
        return;
    }
    // The widget version is not changed here: only wc_yotpo_switch_to_v3_widgets() changes it, one way.
    $new_settings = array_replace_recursive(get_option('yotpo_settings', wc_yotpo_get_default_settings()));
    $new_settings['v3_widgets_ids']['reviews_widget'] = $widgets_instances['reviews_widget'];
    $new_settings['v3_widgets_ids']['qna'] = $widgets_instances['qna'];
    $new_settings['v3_widgets_ids']['star_rating'] = $widgets_instances['star_rating'];
    $new_settings['v3_widgets_ids']['reviews_carousel'] = $widgets_instances['reviews_carousel'];
    $new_settings['v3_widgets_ids']['promoted_products'] = $widgets_instances['promoted_products'];
    $new_settings['v3_widgets_ids']['reviews_tab'] = $widgets_instances['reviews_tab'];
    update_option('yotpo_settings', $new_settings)
        ? wc_yotpo_display_message('Widgets\' IDs have been synchronised')
        : wc_yotpo_display_message('Widgets\' IDs have not been synchronised', false);
}
/**
 * Moves a store on v2 widgets to v3, for good: the "Switch to V3 widgets" button. The store's v3 widget
 * IDs are read from Yotpo first. Without a Reviews Widget there, the switch would leave product pages
 * without reviews, so it is refused and the merchant is sent to the Yotpo dashboard. A store that never
 * set v3 widgets on or off gets the ones it showed on v2: the reviews widget, and the star ratings and
 * Q&A where v2 showed them.
 */
function wc_yotpo_switch_to_v3_widgets() {
    $settings = get_option('yotpo_settings', wc_yotpo_get_default_settings());
    if ('v3' === $settings['widget_version']) {
        return;
    }
    $ids = get_widget_instances();
    if (is_wp_error($ids)) {
        wc_yotpo_display_message(wc_yotpo_widget_instances_error_message($ids), true);
        return;
    }
    if (empty($ids['reviews_widget'])) {
        wc_yotpo_display_message("Your Yotpo account has no V3 Reviews Widget yet, so your store is still on V2 widgets. Set up your widgets in the <a href='https://reviews.yotpo.com/#/display-reviews/on-site-widgets' target='_blank' rel='noopener'>Yotpo dashboard</a>, then switch again.", true);
        return;
    }
    $settings['widget_version'] = 'v3';
    $settings['v3_widgets_ids'] = array_merge(yotpo_get_arr_value($settings, 'v3_widgets_ids', array()), $ids);
    if (!in_array(true, (array) $settings['v3_widgets_enables'], true)) {
        $v2 = $settings['v2_widgets_enables'];
        $settings['v3_widgets_enables']['reviews_widget_product'] = true;
        $settings['v3_widgets_enables']['star_rating_product'] = !empty($v2['bottom_line_product']);
        $settings['v3_widgets_enables']['star_rating_category'] = !empty($v2['bottom_line_category']);
        $settings['v3_widgets_enables']['qna_product'] = !empty($v2['qna_product']);
    }
    // v2 put the reviews widget in the footer or a tab; v3 places it automatically. "Other" meant the
    // merchant placed it in the theme, which v3 calls manual.
    $settings['v3_widget_location'] = 'other' === $settings['v2_widget_location'] ? 'manual' : 'automatic';
    update_option('yotpo_settings', $settings);
    wc_yotpo_display_message('Your store now uses V3 widgets. Check your product pages, and adjust the widgets below and in the Yotpo dashboard.', false);
}
function wc_yotpo_display_message($messages = array(), $is_error = false) {
    $class = $is_error ? 'error' : 'updated fade';
    if (is_array($messages)) {
        foreach ($messages as $message) {
            echo_message($class, $message);
        }
    } elseif (is_string($messages)) {
        echo_message($class, $messages);
    }
}

function echo_message($class, $message) {
    echo wp_kses("<div id='message' class='$class'><p><strong>$message</strong></p></div>", yotpo_message_allowed_html());
};
