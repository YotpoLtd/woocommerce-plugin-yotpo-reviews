<?php
define("LOG_FILE", plugin_dir_path( __FILE__ ).'../yotpo_debug.log');
require __DIR__.'/../lib/utils/wc-yotpo-settings-functions.php';
require __DIR__.'/reusables/widgets-settings.php';
require __DIR__.'/reusables/error-info.php';

function wc_display_yotpo_admin_page() {
    if (function_exists('current_user_can') && !current_user_can('manage_options')) {
        die(__(''));
    }
    if (wc_yotpo_compatible()) {
        if (isset($_POST['log_in_button'])) {
            wc_display_yotpo_settings();
        } elseif (isset($_POST['yotpo_settings'])) {
            check_admin_referer('yotpo_settings_form');
            wc_proccess_yotpo_settings();
            wc_display_yotpo_settings();
        } elseif (isset($_POST['yotpo_sync_ids'])) {
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
            wc_yotpo_send_past_orders();
            wc_display_yotpo_settings();
        } elseif (isset($_POST['yotdbg-clear'])) {
            check_admin_referer('yotdbg-clear');
            $filename = LOG_FILE;
            file_put_contents($filename, "");
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
        if (version_compare(phpversion(), '5.2.0') < 0) {
            echo '<h1>Yotpo plugin requires PHP 5.2.0 above.</h1><br>';
        }
        if (!function_exists('curl_init')) {
            echo '<h1>Yotpo plugin requires cURL library.</h1><br>';
        }
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
    $google_tracking_params = '&utm_source=yotpo_plugin_woocommerce&utm_medium=header_link&utm_campaign=woocommerce_customize_link';
    if (!empty($yotpo_settings['app_key']) && !empty($yotpo_settings['secret'])) {
        $dashboard_link = '<a href="https://api.yotpo.com/users/b2blogin?app_key=' . $yotpo_settings['app_key'] . '&secret=' . $yotpo_settings['secret'] . $google_tracking_params . '" target="_blank">Yotpo Dashboard.</a></div>';
    } else {
        $dashboard_link = "<a href='https://www.yotpo.com/?login=true$google_tracking_params' target='_blank'>Yotpo Dashboard.</a></div>";
    }
    $read_only = isset($_POST['log_in_button']) || $success_type == 'b2c' ? '' : 'readonly';
    $cradentials_location_explanation = isset($_POST['log_in_button'])
        ? "<tr valign='top'>
                <th scope='row'><p class='description'>To get your api key and secret token <a href='https://www.yotpo.com/?login=true' target='_blank'>log in here</a> and go to your account settings.</p></th>
            </tr>"
        : '';
    $submit_past_orders_button = $yotpo_settings['show_submit_past_orders'] ? "<input type='submit' name='yotpo_past_orders' value='Submit past orders' class='button-secondary past-orders-btn' " . disabled(true, empty($app_key) || empty($secret), false) . ">" : '';
    if (isset($yotpo_settings['debug_mode']) && $yotpo_settings['debug_mode']) {
        $settings_dump = json_encode($yotpo_settings);
        if (file_exists(LOG_FILE)) { $debug_log = file_get_contents(LOG_FILE); } else { $debug_log = false; };
    }
    $settings_html = styles() . "
    <div class='wrap'><h2>Yotpo Settings</h2>
        <h4>To customize the look and feel of the widget, and to edit your Mail After Purchase settings, just head to the " . $dashboard_link . "</h4>
        <form  method='post' id='yotpo_settings_form'>
            <table class='form-table'>" . wp_nonce_field('yotpo_settings_form') . "
                <fieldset>
                    <tr id='yotpodbg' valign='top' style='display: none;'>
                        <th scope='row'>Enable debug mode</th>
                        <td><input type='checkbox' name='debug_mode' value='1' " . checked(1, $yotpo_settings['debug_mode'], false) . " /></td>
                        <td>
                            <p class='description'>Enabling debug mode will output all plugin actions into <i>yotpo_debug.log</i>, output the log here and show all the settings.</p>
                        </td>
                    </tr>
                    <tr valign='top'>
                        <th scope='row'>
                            <div>If you would like to choose a set language, please type the 2-letter language code here. You can find the supported langauge codes <a class='y-href' href='http://support.yotpo.com/entries/21861473-Languages-Customization-' target='_blank'>here.</a></div>
                        </th>
                        <td>
                            <div><input type='text' class='yotpo_language_code_text' name='yotpo_widget_language_code' maxlength='5' value='$language_code'/></div>
                        </td>
                    </tr>
                    <tr valign='top'>
                        <th scope='row'><div>For multiple-language sites, mark this check box. This will choose the language according to the user's site language.</div></th>
                        <td><input type='checkbox' name='yotpo_language_as_site' value='1' " . checked(1, $yotpo_settings['yotpo_language_as_site'], false) . "/></td>
                    </tr>
                    <tr valign='top'>
                        <th scope='row'><div>Disable native reviews system:</div></th>
                        <td><input type='checkbox' name='disable_native_review_system' value='1' " . checked(1, $yotpo_settings['disable_native_review_system'], false) . " /></td>
                    </tr>
                    <tr valign='top' class='yotpo-widget-tab-name yotpo-qna-widget-tab-name'>
                        <th scope='row'><div>Select tab name of Q&A widget:</div></th>
                        <td><div><input type='text' name='yotpo_qna_widget_tab_name' value='$qna_widget_tab_name' /></div></td>
                    </tr>
                    $cradentials_location_explanation
                    <tr valign='top'>
                        <th scope='row'><div>App key:</div></th>
                        <td><div class='y-input'><input id='app_key' type='text' name='yotpo_app_key' value='$app_key' $read_only '/></div></td>
                    </tr>
                    <tr valign='top'>
                        <th scope='row'><div>Secret token:</div></th>
                        <td><div class='y-input'><input id='secret' type='text'  name='yotpo_oauth_token' value='$secret' $read_only '/></div></td>
                    </tr>
                    <input id='reviews_widget' type='hidden' name='yotpo_reviews_widget_id' value='$reviews_widget_id' '/>
                    <input id='qna_widget' type='hidden' name='yotpo_qna_widget_id' value='$qna_widget_id' '/>
                    <input id='star_ratings_widget' type='hidden' name='yotpo_star_ratings_widget_id' value='$star_ratings_widget_id' '/>
                    <input id='reviews_carousel_widget' type='hidden' name='yotpo_reviews_carousel_widget_id' value='$reviews_carousel_widget_id' '/>
                    <input id='promoted_products_widget' type='hidden' name='yotpo_promoted_products_widget_id' value='$promoted_products_widget_id' '/>
                    <input id='reviews_carousel_widget' type='hidden' name='yotpo_tab_widget_id' value='$reviews_tab_widget_id' '/>
                    " .
                    version_selector($yotpo_settings) .
                    v2_locations($yotpo_settings, $main_widget_tab_name) .
                    v3_locations($yotpo_settings)
                    . "
                    <tr valign='top' id='yotpo-sync-widget-ids-row' style='display:none'>
                        <th scope='row'><div>Synchronise the widgets' codes:</div></th>
                        <!-- <td><button type='button' id='yotpo-sync-widget-ids' class='button-secondary'>Sync</button></td> -->
                        <td><input type='submit' name='yotpo_sync_ids' value='Sync' class='button-primary' id='yotpo_sync_ids'/></td>
                    </tr>" .
                    v3_enablers($yotpo_settings) .
                    v2_enablers($yotpo_settings) ."
                    <tr valign='top'>
                        <th scope='row'><div>Order Status:</div></th>
                        <td>
                            <select name='yotpo_order_status' class='yotpo-order-status' >
                                <option value='wc-completed' " . selected('wc-completed', $yotpo_settings['yotpo_order_status'], false) . ">Completed</option>
                                <option value='wc-pending' " . selected('wc-pending', $yotpo_settings['yotpo_order_status'], false) . ">Pending Payment</option>
                                <option value='wc-processing' " . selected('wc-processing', $yotpo_settings['yotpo_order_status'], false) . ">Processing</option>
                                <option value='wc-on-hold' " . selected('wc-on-hold', $yotpo_settings['yotpo_order_status'], false) . ">On Hold</option>
                                <option value='wc-cancelled' " . selected('wc-cancelled', $yotpo_settings['yotpo_order_status'], false) . ">Cancelled</option>
                                <option value='wc-refunded' " . selected('wc-refunded', $yotpo_settings['yotpo_order_status'], false) . ">Refunded</option>
                                <option value='wc-failed' " . selected('wc-failed', $yotpo_settings['yotpo_order_status'], false) . ">Failed</option>
                            </select>
                        </td>
                    </tr>
                </fieldset>
            </table></br>
            <div class='buttons-container'>
                <button type='button' id='yotpo-export-reviews' class='button-secondary' " . disabled(true, empty($app_key) || empty($secret), false) . ">Export Reviews</button>
                <input type='submit' name='yotpo_settings' value='Update' class='button-primary' id='save_yotpo_settings'/>$submit_past_orders_button
                </br></br><p class='description'>*Learn <a href='http://support.yotpo.com/entries/24454261-Exporting-reviews-for-Woocommerce' target='_blank'>how to export your existing reviews</a> into Yotpo.</p>
            </div>
        </form>
        <iframe name='yotpo_export_reviews_frame' style='display: none;'></iframe>
        <form action='' method='get' target='yotpo_export_reviews_frame' style='display: none;'>
            <input type='hidden' name='download_exported_reviews' value='true' />
            <input type='submit' value='Export Reviews' class='button-primary' id='export_reviews_submit'/>
        </form>
    </div>
    <div class='featured-cars'></div>
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
    echo $settings_html;
    if (isset($yotpo_settings['debug_mode']) && $yotpo_settings['debug_mode']) {
        echo '<h3>Settings</h3><pre>'.$settings_dump.'</pre>';
        if ($debug_log === FALSE) {
            echo '<h3>Yotpo Debug</h3>
            <textarea cols=170 rows=15>Problem opening yotpo_debug.log and/or file is empty</textarea>';
        } else {
            echo '<h3>Yotpo Debug</h3><textarea cols=170 rows=15>'.$debug_log.'</textarea>
            <form method="post" id="yotdbg-clear">' .wp_nonce_field('yotdbg-clear') .'<input type="submit" value="Clear" class="button-primary" name="yotdbg-clear" id="yotdbg-clear-submit"/></form>';
        }
    }
}
function wc_proccess_yotpo_widgets_ids_synchronisation() {
    $widgets_instances = get_widget_instances();
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
                    $shop_domain = parse_url($shop_url, PHP_URL_HOST);
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
            echo "<div id='message' class='$class'><p><strong>$message</strong></p></div>";
        }
    } elseif (is_string($messages)) {
        echo "<div id='message' class='$class'><p><strong>$messages</strong></p></div>";
    }
}
