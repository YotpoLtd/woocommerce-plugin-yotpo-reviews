---
type: Troubleshooting
title: woocommerce-plugin-yotpo-reviews Troubleshooting
timestamp: 2026-10-22T00:00:00Z
---

# Troubleshooting

## Common Issues

| Symptom | Cause | Fix |
|---------|-------|-----|
| WordPress.org still serves the old version after a release | `Stable tag` in `trunk/readme.txt` points at an SVN tag that doesn't exist, so WordPress.org falls back to serving `trunk` | Create the tag (`svn cp trunk tags/<version>`) and confirm it appears under `/tags/`. See `README.md` step 6 |
| A file deleted on GitHub is still live on WordPress.org | The SVN mirror ran without `--delete` | Re-run the `rsync -av --delete` in `README.md` step 3, then register the removals with the `svn rm` loop in step 5 |
| Store upgraded from 1.x suddenly shows widgets it never enabled | A new key was added to `wc_yotpo_get_default_settings()` but not given a legacy meaning | Add it to `wc_yotpo_legacy_missing_settings()` (`trunk/lib/utils/wc-yotpo-defaults.php:60`). Missing keys must read as they did before the key existed |
| Products stop syncing and nothing is queued | A 429 set the pause transient, or the run was marked stalled after `MAX_STALL_RESTARTS` | Check the sync status line on the settings screen. A stalled run waits for the merchant to retry by design |
| A product never syncs even after editing it | Its fingerprint was written although Yotpo rejected the payload, so every later job skips it | Clear the stored hashes (`Yotpo_Catalog_Api::forget_product_fingerprints()`) and requeue |
| Leftover `wc_yotpo_*` options or `_yotpo_v3_*` meta after uninstall | A constant changed in a class but not in `trunk/uninstall.php`, which runs with no classes loaded | Add the literal name to `trunk/uninstall.php` and cover it in `tests/UninstallTest.php` |
| V3 widgets render in the wrong language | The store is on V2, or `widget_language_from_page` is off, in which case Yotpo's own setting wins | Check `widget_language_from_page`; `tests/WidgetLanguageTest.php` documents the expected mapping |
| Orders reach Yotpo but refunds don't | `woocommerce_order_refunded` queues an update against the stored Yotpo order ID; without one there is nothing to update | Confirm `_yotpo_v3_order_id` exists on the order |
| Order meta survives uninstall on some stores | The store uses WooCommerce HPOS, so order meta is in `wp_wc_orders_meta`, not post meta | Both paths are cleaned in `trunk/uninstall.php`; keep both when changing an order meta key |

## CI Failures

| Error Message | Meaning | Resolution |
|--------------|---------|-----------|
| `PHP 7.4: issues found` from `composer lint` | `bin/php-lint.sh` found a syntax error or deprecation under one of the binaries it ran | Fix the reported file. Reproduce other versions locally with `PHP_BINARIES="php7.4 php8.4" composer lint` |
| `WordPress and WooCommerce are not installed for the tests. Run bin/install-wp-tests.sh first.` | `tests/bootstrap.php` found no WooCommerce in `.wp-tests/` | `composer test:install`. Re-run it after changing `WP_VERSION` or `WC_VERSION` |
| PHPUnit "This test did not perform any assertions" | `beStrictAboutTestsThatDoNotTestAnything` is on in `phpunit.xml.dist` | Assert something, or assert on the recorded calls via `calls()` |
| A test fails with an unexpected HTTP request | The test reached a route it never declared; the mock fails instead of letting it hit the network | Declare it with `route()` (`tests/includes/class-yotpo-test-case.php:68`) — or don't make the call |
| PHPCS flags `PrefixAllGlobals` | A new global function or constant isn't prefixed `wc_yotpo` / `yotpo` | Rename it; the prefixes are set in `phpcs.xml.dist` |
| PHPCS flags a nonce warning on a settings field | Each field of the save handler is flagged individually; the caller checks the nonce once | Confirm the caller's `check_admin_referer`, then leave it. This is a known standing warning |
| PHPCompatibility errors after raising a PHP version | `testVersion` in `phpcs.xml.dist` must match the real floor | Change the floor in all three places together: the plugin header `Requires PHP`, `trunk/readme.txt`, and `phpcs.xml.dist` |
| The two CI jobs (`PHP 7.4`, `PHP 8.3`) disagree | A deprecation or behavior difference between the versions | Both must pass; the matrix is the plugin's minimum and a current version by design |
