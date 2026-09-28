---
type: Troubleshooting
title: woocommerce-plugin-yotpo-reviews Troubleshooting
timestamp: 2026-09-28T11:47:58Z
---

# Troubleshooting

## Common Issues

| Symptom | Cause | Fix |
|---------|-------|-----|
| No Yotpo widget or script on any page | No app key saved; the storefront hooks are registered only when `app_key` is set (`trunk/wc_yotpo.php:56`) | Enter the app key and secret on the Yotpo settings page |
| v3 selected, but no widgets | The v3 instance ids were never synced, or Yotpo returned none; each v3 widget returns early without its id (`trunk/wc_yotpo.php:125,145`) | Customize the widgets in Yotpo, then click "Sync" on the settings page |
| Star rating missing on the product page in v3 | It needs both the star-rating **and** the reviews widget ids (`trunk/wc_yotpo.php:244`) | Sync; check both widgets exist in Yotpo |
| Widgets missing with a custom theme | The theme does not fire `woocommerce_after_single_product` / `woocommerce_single_product_summary` | Set the location to manual (v3) or other (v2) and call the `wc_yotpo_show_*` functions from the theme |
| Native WooCommerce reviews still shown | "Disable native reviews system" is off (`comments_open` filter, `trunk/wc_yotpo.php:83-85`) | Turn it on |
| Native star ratings stay off after uninstall | Activation sets `woocommerce_enable_review_rating` to `no`; only deactivation restores it (`trunk/wc_yotpo.php:102-103,526-528`) | Deactivate before deleting, or re-enable ratings in WooCommerce |
| Orders not sent to Yotpo | Order status differs from `yotpo_order_status` (default `wc-completed`), the billing email ends in a digit, the name is empty, or an order line has product id 0 (`trunk/wc_yotpo.php:298,329-337`) | Check the status setting; turn on debug mode and read the log on the settings page |
| "Could not retrieve past orders" | No order of the configured status in the last 90 days (`trunk/wc_yotpo.php:386-412`) | Expected; nothing to send |
| "Submit past orders" button gone | Hidden after one fully successful run (`show_submit_past_orders`, `trunk/wc_yotpo.php:481`) | By design |
| Whole page replaced by a "Fatal Error" box with file and line | `fatal_error_handler` buffers every request and replaces the output on any fatal error, from any plugin (`trunk/wc_yotpo.php:586`, `trunk/lib/utils/wc-yotpo-defaults.php:48`) | Fix the fatal error it names; the handler is not the cause |
| PHP "Deprecated: Implicitly marking parameter ... as nullable" on PHP 8.4 | `int $product_id = null` style defaults in `trunk/lib/widgets/promoted-products.php:3`, `reviews-carousel.php:3`, `reviews-tab.php:3`, `trunk/templates/reusables/v3_enablers.php:3`, `trunk/lib/yotpo-api/Yotpo.php:170` | Use `?int $x = null` |
| Fatal error on PHP 7.0 or older | `void` return types and scalar type hints, although `trunk/readme.txt` says PHP 5.2+ | Run PHP 7.1+ |

## CI Failures

This repository has no CI workflow. The external gate is WordPress.org:

| Error Message | Meaning | Resolution |
|--------------|---------|-----------|
| Plugin Check: output not escaped / `WordPress.Security.EscapeOutput` | A value is echoed without escaping or `wp_kses` | Escape and echo through an allowlist (ADR-0003) |
| Plugin Check: hidden files | A dotfile inside `trunk/` | Remove it (commit `a6fab3e`) |
| Plugin Check: direct file or cURL calls | `fopen`/`curl_*` instead of `WP_Filesystem`/`wp_remote_*` | ADR-0003 |
| WordPress.org still offers the old version after `svn ci` | `Stable tag` in `trunk/readme.txt` does not match the header `Version:` | Bump both (commits `5eadb85`, `4fc1d26`; ADR-0001) |

## Known issues (found during harness onboarding, not changed)

- `trunk/lib/yotpo-api/Yotpo.php:40` sets `'sslverify' => false` on every Yotpo API call, including the ones that send the secret and customer data.
- The settings page puts the app key **and secret** in a dashboard link query string (`trunk/templates/wc-yotpo-settings.php:79`); with debug mode on it also prints all settings, including the secret (`:90-91`, `:187`).
- `ytdbg` writes customer emails and names to `trunk/yotpo_debug.log` inside the plugin folder (`trunk/wc_yotpo.php:563`, `:334`), which is usually reachable over HTTP at `wp-content/plugins/<slug>/yotpo_debug.log`.
- The settings page shows the log with `wp_remote_get(LOG_FILE)` on a filesystem path (`trunk/templates/wc-yotpo-settings.php:92`), which cannot work; `WP_Filesystem` would be needed.
- The "Sync" (`yotpo_sync_ids`) and "Submit past orders" (`yotpo_past_orders`) POST actions do not call `check_admin_referer` (`trunk/templates/wc-yotpo-settings.php:19-21,30-32`); saving settings and registering do. `wc_proccess_yotpo_widgets_ids_synchronisation` also reads `$_POST['yotpo_widget_version']` unsanitized (`:200`).
- Settings values are interpolated into `value='...'` without `esc_attr` (`trunk/templates/wc-yotpo-settings.php:112,125,130,134,136-141`, `trunk/templates/reusables/widgets-settings.php:33`); `wp_kses` does not escape attribute values it allows.
- `generate_v2_star_ratings_widget_code`, `generate_v3_star_ratings_widget_code`, the carousel and the reviews-tab builders concatenate values without `esc_attr` (`trunk/lib/widgets/stars-widget.php:13-25`, `reviews-carousel.php`, `reviews-tab.php`); `wc_yotpo_get_product_data` pre-escapes most fields, the widget ids are not escaped.
- `wc_yotpo_show_custom_widgets` has an empty `foreach (...);` loop and echoes only the last widget (`trunk/wc_yotpo.php:189`); `trunk/lib/widgets/custom-widgets.php:9` hardcodes instance id `540894`. The function is not hooked anywhere.
- `wc_yotpo_show_main_widget_in_tab` returns nothing when reviews are not allowed, which drops every product tab (`trunk/wc_yotpo.php:193-204`); `wc_yotpo_disable_tab_manager_managment` returns `null` for every other tab (`:530-535`).
- `rest_of_pages_renders` checks `reviews_tab_category` for the home page (`trunk/lib/utils/widgets-rendering-logic.php:85`) and reads the enables without `yotpo_get_arr_value`.
- `wc_yotpo_uninstall` compares `__FILE__` with `WP_UNINSTALL_PLUGIN`, which WordPress sets to a relative path, so it never matches; `trunk/uninstall.php` is what actually deletes `yotpo_settings`. Neither deletes `native_star_ratings_enabled`.
- `wc_yotpo_compatible()` still requires `curl_init` although HTTP now goes through `wp_remote_*` (`trunk/wc_yotpo.php:523-525`).
- `get_widget_instances()` does not handle a failed API call: `receive_widget_instances()` can return `false` (`trunk/lib/utils/wc-yotpo-settings-functions.php:27`).
- Doc drift: `README.md:18` says to copy the `woocommerce-yotpo` folder; the folder is `trunk/`. `README.md:14` calls the repository `woocommerce-plugin`. `trunk/readme.txt:53-55` lists PHP 5.2.0.
