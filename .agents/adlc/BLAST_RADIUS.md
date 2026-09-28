# Blast Radius profile

## entryPoints

WordPress calls this plugin through hooks, not a router. Everything starts from the plugin main file:

- `trunk/wc_yotpo.php` -- the plugin header and every `register_*_hook`, `add_action`, `add_filter` (lines 14-20, 529, 586); it `require`s `trunk/lib/widgets/*.php` and `trunk/lib/utils/*.php` on every request
- Storefront hooks registered at runtime in `trunk/lib/utils/widgets-rendering-logic.php` and `wc_yotpo_front_end_init` (`woocommerce_after_single_product`, `woocommerce_single_product_summary`, `woocommerce_product_tabs`, `woocommerce_after_shop_loop(_item)`, `wp_footer`, `woocommerce_thankyou`, `comments_open`)
- Order hook: `woocommerce_order_status_changed` → `wc_yotpo_map` (`trunk/wc_yotpo.php:19,292`)
- Admin: the "Yotpo" menu page → `wc_display_yotpo_admin_page` (`trunk/lib/utils/wc-yotpo-functions.php:10`, `trunk/templates/wc-yotpo-settings.php:8`), and the `download_exported_reviews` GET (`trunk/wc_yotpo.php:37`)
- Lifecycle: `trunk/uninstall.php` (run by WordPress on delete), activation/deactivation hooks in `trunk/wc_yotpo.php:14-16`
- Public functions called from merchants' themes: `wc_yotpo_show_reviews_widget`, `wc_yotpo_show_widget`, `wc_yotpo_show_qna_widget`, `wc_yotpo_show_buttomline`, `wc_yotpo_show_promoted_products_widget`, `wc_yotpo_show_reviews_carousel_widget`, `wc_yotpo_show_reviews_tab_widget`, `wc_yotpo_show_qa_bottomline`
- Browser: `trunk/assets/js/v3HeaderScript.js`, `v2HeaderScript.js` (storefront), `settings.js` (admin)

## areas

- widgets: `trunk/lib/widgets/`, `trunk/lib/utils/widgets-rendering-logic.php`, `trunk/assets/js/v2HeaderScript.js`, `trunk/assets/js/v3HeaderScript.js`, `trunk/assets/css/bottom-line.css`
- orders: `wc_yotpo_map`, `wc_yotpo_get_single_map_data`, `wc_yotpo_get_past_orders*`, `wc_yotpo_send_past_orders`, `wc_yotpo_conversion_track` in `trunk/wc_yotpo.php`
- admin: `trunk/templates/`, `trunk/lib/utils/wc-yotpo-settings-functions.php`, `trunk/lib/utils/wc-yotpo-functions.php`, `trunk/assets/js/settings.js`, `trunk/assets/css/yotpo.css`, `trunk/assets/css/side-menu-logo.css`
- export: `trunk/classes/class-wc-yotpo-export-reviews.php`
- api: `trunk/lib/yotpo-api/Yotpo.php`
- release: `trunk/readme.txt`, `changelog`, `assets/`
- docs: `docs/`, `*.md`
- core: `trunk/wc_yotpo.php` (bootstrap), `trunk/lib/utils/wc-yotpo-defaults.php`, `trunk/lib/utils/general-usage-functions.php`, `trunk/lib/utils/allowed-html-functions.php`, `trunk/uninstall.php` -- every area reads `yotpo_settings` through the defaults and echoes through the allowlists, so a change here is cross-cutting

## imports

No aliases, no namespaces, no autoloader. Files are pulled in with `require` / `include` / `require_once` and
`plugin_dir_path( __FILE__ )` or `__DIR__` (`trunk/wc_yotpo.php:21-32,39,51-52,306`,
`trunk/templates/wc-yotpo-settings.php:3-6`). Almost everything is a global function, so reach is found by searching
for the function name, not for an import.

Three things a text search for `require` misses:
- Hook callbacks are named as strings: `add_action('woocommerce_after_single_product', 'wc_yotpo_show_reviews_widget', 10)`. Search for `'<function_name>'` too.
- The `yotpo_settings` option is shared state: a change to a key in `wc_yotpo_get_default_settings()` or `wc_proccess_yotpo_settings()` reaches every reader of that key.
- Merchants' themes call the public `wc_yotpo_show_*` functions; those callers are not in this repository.

## hotspots

- `trunk/wc_yotpo.php` -- loaded on every WordPress request; registers every hook and the global `ob_start('fatal_error_handler')`
- `trunk/lib/utils/wc-yotpo-defaults.php` -- the defaults every `get_option('yotpo_settings', ...)` falls back to, and the fatal-error buffer handler for every page
- `trunk/lib/utils/allowed-html-functions.php` -- every echoed fragment passes through one of these allowlists
- `trunk/lib/utils/widgets-rendering-logic.php` -- decides which widgets appear on every storefront page

## generatedFiles

None tracked. `trunk/yotpo_debug.log` is written at runtime on merchant sites and is not in the repository.

## tables

### criticality

- 1.0: every storefront page, checkout or order-status change -- `trunk/wc_yotpo.php`, `trunk/lib/utils/wc-yotpo-defaults.php`, `trunk/lib/utils/widgets-rendering-logic.php`, `trunk/lib/widgets/**`, `trunk/lib/utils/allowed-html-functions.php`, `trunk/assets/js/v2HeaderScript.js`, `trunk/assets/js/v3HeaderScript.js`, `trunk/lib/yotpo-api/Yotpo.php` (order submission runs inside the merchant's order-status request)
- 0.7: the settings page and credentials a merchant uses to configure Yotpo -- `trunk/templates/**`, `trunk/lib/utils/wc-yotpo-settings-functions.php`, `trunk/lib/utils/wc-yotpo-functions.php`, `trunk/assets/js/settings.js`, `trunk/uninstall.php`
- 0.4: admin actions a merchant can retry -- past orders, v3 id sync, `trunk/classes/class-wc-yotpo-export-reviews.php`
- 0.2: styling -- `trunk/assets/css/**`, `trunk/assets/images/**`
- 0.1: never on a merchant's site -- `docs/**`, `*.md`, root `assets/` (WordPress.org listing), `changelog`, `.gitignore`

### change-type

- 0.95: irreversible or trust-breaking -- a renamed or removed public `wc_yotpo_show_*` function, a renamed or removed `yotpo_settings` key, `trunk/uninstall.php` or activation/deactivation option changes (merchant data), `Version:` in `trunk/wc_yotpo.php` or `Stable tag` in `trunk/readme.txt`, credential handling in `trunk/lib/yotpo-api/Yotpo.php` / the settings page / registration, loosening an allowlist in `trunk/lib/utils/allowed-html-functions.php`, unescaped output, a removed nonce check, a committed Yotpo key or the SVN password, `.github/workflows/**`, `.agents/adlc/**`, `.claude/**`, `CODEOWNERS`
- 0.75: shared/core infrastructure -- the hotspots above, a new hook registration in `trunk/wc_yotpo.php`, a new global function name (collision risk with other plugins), a new file under `trunk/` that must be `require`d, a hidden file under `trunk/` (Plugin Check)
- 0.50: non-trivial logic on a storefront or order path without test coverage -- the v2/v3 and page-type branching in `wc_yotpo_front_end_init` and `widgets-rendering-logic.php`, the order filters in `wc_yotpo_get_single_map_data`
- 0.30: contained feature work -- a new widget or setting following the extension points in `ARCHITECTURE.md`
- 0.15: copy, labels, CSS, `ytdbg` messages, `trunk/readme.txt` description text
- 0.05: comments, docs, formatting, dead-code removal -- `docs/**`, `*.md`
