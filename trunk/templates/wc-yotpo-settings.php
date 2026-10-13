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
        } elseif (isset($_POST['yotpo_register'])) {
            check_admin_referer('yotpo_registration_form');
            $success = wc_proccess_yotpo_register();
            if ($success) {
                wc_display_yotpo_settings($success);
            } else {
                wc_display_yotpo_register();
            }
        } elseif (isset($_POST['yotpo_past_orders'])) {
            check_admin_referer('yotpo_settings_form');
            wc_yotpo_send_past_orders();
            wc_display_yotpo_settings();
        } elseif (isset($_POST['yotdbg-clear'])) {
            check_admin_referer('yotdbg-clear');
            wc_yotpo_clear_debug_log();
            wc_display_yotpo_settings();
        } else {
            $yotpo_settings = get_option('yotpo_settings', wc_yotpo_get_default_settings());
            if (empty($yotpo_settings['app_key']) && empty($yotpo_settings['secret'])) {
                wc_display_yotpo_register();
            } else {
                wc_display_yotpo_settings();
            }
        }
    } else {
        echo '<h1>Yotpo plugin requires PHP ' . esc_html(WC_YOTPO_MIN_PHP_VERSION) . ' or above.</h1><br>';
    }
}
function wc_display_yotpo_settings($success_type = false) {
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
    if (empty($yotpo_settings['app_key'])) {
        if ($success_type == 'b2c') {
            wc_yotpo_display_message('We have sent you a confirmation email. Please check and click on the link to get your app key and secret token to fill out below.', true);
        } else {
            wc_yotpo_display_message('Set your API key in order the Yotpo plugin to work correctly', false);
        }
    }
    $logging_in = isset($_POST['log_in_button']) || $success_type == 'b2c';
    $read_only = $logging_in ? '' : 'readonly';
    $no_credentials = empty($app_key) || empty($secret);
    $credentials_badge = $no_credentials
        ? "<span class='yotpo-badge yotpo-tone--neutral'>Not set up</span>"
        : "<span class='yotpo-badge yotpo-tone--success'>Keys saved</span>";
    // reviews.yotpo.com sends logged-out users to the Yotpo login screen. The old auto-login link put the
    // app secret in the URL (browser history, logs), and www.yotpo.com/?login=true now only opens the marketing site.
    $credentials_help = isset($_POST['log_in_button'])
        ? "<div class='yotpo-callout'>To get your app key and secret token, <a href='https://reviews.yotpo.com/' target='_blank' rel='noopener'>log in to Yotpo</a> and go to your account settings.</div>"
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
        )
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
            "The 2-letter language code for the widgets, such as en or fr. <a href='http://support.yotpo.com/entries/21861473-Languages-Customization-' target='_blank' rel='noopener'>Supported languages</a>",
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
        'Reviews and orders',
        '',
        yotpo_switch_row(
            'disable_native_review_system',
            'disable_native_review_system',
            checked(1, $yotpo_settings['disable_native_review_system'], false),
            'Turn off WooCommerce reviews',
            'Hides the WooCommerce review form and star ratings, so shoppers only see Yotpo reviews.'
        ) .
        yotpo_order_status_option($yotpo_settings)
    );

    $past_orders = $yotpo_settings['show_submit_past_orders']
        ? "<div class='yotpo-row'>
                <div class='yotpo-row__text'>
                    <span class='yotpo-label'>Send past orders</span>
                    <p class='yotpo-help'>Sends orders from the last 90 days with the order status above, so Yotpo can ask those customers for reviews. Available once.</p>
                </div>
                <div class='yotpo-row__control'>
                    <form method='post'>" . wp_nonce_field('yotpo_settings_form', '_wpnonce', true, false) . "
                        <input type='submit' name='yotpo_past_orders' value='Send past orders' class='yotpo-btn yotpo-btn--outline' " . disabled(true, $no_credentials, false) . " />
                    </form>
                </div>
            </div>"
        : '';
    $tools = yotpo_card(
        'Tools',
        '',
        "<div class='yotpo-row'>
            <div class='yotpo-row__text'>
                <span class='yotpo-label'>Export reviews</span>
                <p class='yotpo-help'>Downloads your WooCommerce reviews as a CSV file to import into Yotpo. <a href='https://support.yotpo.com/docs/woocommerce-installing-yotpo#4-importing-your-reviews-to-yotpo' target='_blank' rel='noopener'>How to import them</a></p>
            </div>
            <div class='yotpo-row__control'>
                <button type='button' id='yotpo-export-reviews' class='yotpo-btn yotpo-btn--outline' " . disabled(true, $no_credentials, false) . ">Export reviews</button>
            </div>
        </div>" . $past_orders
    );

    $settings_html = "
    <div class='wrap yotpo-admin'>
        " . yotpo_page_header('Customize how the widgets look, and your review request emails, in the <a href=\'https://reviews.yotpo.com/\' target=\'_blank\' rel=\'noopener\'>Yotpo dashboard</a>.') . "
        <form method='post' id='yotpo_settings_form'>" . wp_nonce_field('yotpo_settings_form', '_wpnonce', true, false) .
            $connection . $catalog . $widgets . $language . $reviews . "
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
    $new_settings = array_replace_recursive(get_option('yotpo_settings', wc_yotpo_get_default_settings()));
    $new_settings['widget_version'] = $_POST['yotpo_widget_version'];
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
function wc_proccess_yotpo_register() {
    $errors = array();
    if ($_POST['yotpo_user_email'] === '') {
        array_push($errors, 'Provide valid email address');
    }
    if (strlen($_POST['yotpo_user_password']) < 6 || strlen($_POST['yotpo_user_password']) > 128) {
        array_push($errors, 'Password must be at least 6 characters');
    }
    if ($_POST['yotpo_user_password'] != $_POST['yotpo_user_confirm_password']) {
        array_push($errors, 'Passwords are not identical');
    }
    if ($_POST['yotpo_user_name'] === '') {
        array_push($errors, 'Name is missing');
    }
    if (count($errors) == 0) {
        $yotpo_api = new Yotpo();
        $shop_url = get_bloginfo('url');
        $user = array(
            'email' => $_POST['yotpo_user_email'],
            'display_name' => $_POST['yotpo_user_name'],
            'first_name' => '',
            'password' => $_POST['yotpo_user_password'],
            'last_name' => '',
            'website_name' => $shop_url,
            'support_url' => $shop_url,
            'callback_url' => $shop_url,
            'url' => $shop_url);
        try {
            $response = $yotpo_api->create_user($user, true);
            if (!empty($response['status']) && !empty($response['status']['code'])) {
                if ($response['status']['code'] == 200) {
                    $app_key = $response['response']['app_key'];
                    $secret = $response['response']['secret'];
                    $yotpo_api->set_app_key($app_key);
                    $yotpo_api->set_secret($secret);
                    $shop_domain = wp_parse_url($shop_url, PHP_URL_HOST);
                    $account_platform_response = $yotpo_api->create_account_platform(array('shop_domain' => wc_yotpo_get_shop_domain(),
                        'utoken' => $response['response']['token'],
                        'platform_type_id' => 12));
                    if (!empty($response['status']) && !empty($response['status']['code']) && $response['status']['code'] == 200) {
                        $current_settings = get_option('yotpo_settings', wc_yotpo_get_default_settings());
                        $current_settings['app_key'] = $app_key;
                        $current_settings['secret'] = $secret;
                        update_option('yotpo_settings', $current_settings);
                        return true;
                    } elseif ($response['status']['code'] >= 400) {
                        if (!empty($response['status']['message'])) {
                            wc_yotpo_display_message($response['status']['message'], true);
                        }
                    }
                } elseif ($response['status']['code'] >= 400) {
                    if (!empty($response['status']['message'])) {
                        if (is_array($response['status']['message']) && !empty($response['status']['message']['email'])) {
                            if (is_array($response['status']['message']['email'])) {
                                wc_yotpo_display_message($response['status']['message']['email'][0], false);
                            } else {
                                wc_yotpo_display_message($response['status']['message']['email'], false);
                            }
                        } else {
                            wc_yotpo_display_message($response['status']['message'], true);
                        }
                    }
                }
            } else {
                if ($response == 'b2c') {
                    return $response;
                }
            }
        } catch (Exception $e) {
            wc_yotpo_display_message($e->getMessage(), true);
        }
    } else {
        wc_yotpo_display_message($errors, false);
    }
    return false;
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
