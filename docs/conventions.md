---
type: Conventions
title: woocommerce-plugin-yotpo-reviews Conventions
timestamp: 2026-09-28T11:47:58Z
---

# Coding Conventions

## File Organization
Everything that ships is under `trunk/`; `trunk/wc_yotpo.php` is the plugin main file and `require`s the rest.
- Widget markup: one file per widget in `trunk/lib/widgets/`, returning an HTML string (`generate_v2_*` / `generate_v3_*`)
- Shared helpers: `trunk/lib/utils/` (defaults, allowlists, rendering logic, settings save)
- Admin UI: `trunk/templates/` (settings page) and `trunk/templates/reusables/` (fragments returning HTML strings)
- Classes only where there is state: `trunk/lib/yotpo-api/Yotpo.php`, `trunk/classes/class-wc-yotpo-export-reviews.php`
- Static assets: `trunk/assets/{css,js,images}`; WordPress.org listing images: root `assets/`
- Canonical example: `trunk/lib/widgets/qna-widget.php` + `wc_yotpo_show_qna_widget()` (`trunk/wc_yotpo.php:132`)

## Naming
| Thing | Convention | Example |
|-------|-----------|---------|
| Global functions | snake_case, prefixed `wc_yotpo_` or `yotpo_` | `wc_yotpo_show_reviews_widget()`, `yotpo_get_arr_value()` |
| Markup builders | `generate_v2_*_code` / `generate_v3_*_code` | `generate_v3_reviews_widget_code()` (`trunk/lib/widgets/reviews-widget.php:17`) |
| Allowlists | `yotpo_*_allowed_html()` | `yotpo_common_widgets_allowed_html()` |
| Settings keys | snake_case in `yotpo_settings`; form fields prefixed `yotpo_` | `v3_widgets_enables.reviews_carousel_home` ← `yotpo_reviews_carousel_enabled_home` |
| Classes | PascalCase / `Yotpo_Review_Export` (legacy) | `Yotpo` |
| Files | kebab-case, `class-` prefix for classes | `wc-yotpo-settings-functions.php` |

Unprefixed legacy globals exist (`use_v3_widgets`, `get_widget_instances`, `category_page_renders`, `fatal_error_handler`, `echo_message`, ...); don't add new ones.

## Error Handling
- Pattern: never let an error reach the storefront or the order-status request. API calls are wrapped in `try/catch (Exception $e)` → `error_log` (`trunk/wc_yotpo.php:303-318`); `Yotpo::request` returns `false` on a `WP_Error` (`trunk/lib/yotpo-api/Yotpo.php:55-58`)
- Admin feedback: `wc_yotpo_display_message($message, $is_error)` (`trunk/templates/wc-yotpo-settings.php`)
- Missing settings keys: `yotpo_get_arr_value()` instead of `$settings['key']` (commits `5c535ba`, `ff14519`, `0cf4f1d`)
- Never: throw from settings-page code (commit `a7b799b` removed a throw that blocked the whole settings page)

## Logging
- What to log: order submission and past-order steps, with `ytdbg($msg, $label)` (`trunk/wc_yotpo.php:555`)
- Levels: none; `ytdbg` writes only when the hidden "debug mode" setting is on
- Format: free text with a timestamp, appended to `trunk/yotpo_debug.log` through `WP_Filesystem`
- Canonical example: `trunk/wc_yotpo.php:297-313`

## Testing
- Unit tests: none; no PHPUnit, no test directory
- Integration tests: none
- Manual: install `trunk/` into a WordPress + WooCommerce site and exercise the settings page and storefront
- Mocking strategy / fixtures: none

## Do's and Don'ts
| Do (with file:line ref) | Don't | Why |
|--------------------------|-------|-----|
| `esc_attr()` every value, then `echo wp_kses($html, yotpo_*_allowed_html())` (`trunk/wc_yotpo.php:116`, `trunk/lib/widgets/reviews-widget.php:17-28`) | `echo` concatenated HTML, or print a value without escaping | Stored XSS on merchant storefronts; WordPress.org Plugin Check (ADR-0003) |
| Add every new printed tag/attribute to its allowlist (`trunk/lib/utils/allowed-html-functions.php`) | Assume `wp_kses` keeps unknown attributes | `wp_kses` silently strips them, the widget then renders empty |
| `check_admin_referer('<action>')` before acting on a POST (`trunk/templates/wc-yotpo-settings.php:16`) | Act on `$_POST` without a nonce | CSRF on the settings page |
| Sanitize input: `sanitize_email`, `sanitize_user`, `sanitize_text_field` (`trunk/lib/utils/wc-yotpo-settings-functions.php:101-102`) | Store or echo raw `$_POST` | Plugin Check, XSS |
| `yotpo_get_arr_value($settings, 'key', $default)` (`trunk/wc_yotpo.php:213`) | `$settings['new_key']` on settings saved by an older version | PHP warnings on upgraded stores |
| `wp_remote_request` via `Yotpo::request` (`trunk/lib/yotpo-api/Yotpo.php:26`) | `curl_*`, `file_get_contents` on URLs | Plugin Check (ADR-0003) |
| `WP_Filesystem` for files (`trunk/wc_yotpo.php:572-584`) | `fopen` / `file_put_contents` | Plugin Check (ADR-0003) |
| Explicit nullable types: `?int $product_id = null` | `int $product_id = null` | Deprecated in PHP 8.4 (see troubleshooting) |
| Prefix new globals `wc_yotpo_` | Unprefixed function names | Collisions with other plugins are fatal |

## Anti-Patterns

What agents should NEVER do in this repo:
| Anti-Pattern | Why It's Dangerous | See Also |
|--------------|-------------------|----------|
| Renaming or removing a public `wc_yotpo_show_*` function (including the alias `wc_yotpo_show_widget`) | Merchants call them from their theme; a missing function is a fatal error on their product page | `trunk/templates/reusables/widgets-settings.php:36-44`, commit `2b1b13a` |
| Renaming or removing a `yotpo_settings` key | Existing stores keep the old option value; reads break or reset settings | `trunk/lib/utils/wc-yotpo-defaults.php:3` |
| Changing `Version:` in `trunk/wc_yotpo.php` or `Stable tag` in `trunk/readme.txt` outside a release | WordPress.org serves the `Stable tag`; a mismatch ships the wrong version | ADR-0001 |
| Adding hidden files (`.gitignore`, `.travis.yml`, ...) inside `trunk/` | Plugin Check rejects them (commit `a6fab3e`) | ADR-0003 |
| Committing a Yotpo app key, secret or the SVN password | The repository is public | `README.md:33` |
| Printing the secret or customer data in a URL, a page or the debug log | The log and the settings page are readable by more people than intended | Known issues in `docs/troubleshooting.md` |
| Making a Yotpo API call on the storefront render path | Every page load would wait on Yotpo | ARCHITECTURE.md Boundaries |
| Throwing from settings or storefront code | Blocks the settings page or the merchant's page (commit `a7b799b`) | Error Handling above |

## Patterns Library

### v3 widget
- **When to use:** a new Yotpo v3 widget.
- **Canonical implementation:** `trunk/lib/widgets/qna-widget.php`, `wc_yotpo_show_qna_widget()` (`trunk/wc_yotpo.php:132-139`), `v3_product_widgets_render_in_footer()` (`trunk/lib/utils/widgets-rendering-logic.php:7`)
- **How it works:** return early without an instance id or when reviews are not allowed; build a `yotpo-widget-instance` div with `data-yotpo-instance-id` and product data; echo through its allowlist.

### Settings value
- **When to use:** a new admin option.
- **Canonical implementation:** `disable_native_review_system` in `trunk/lib/utils/wc-yotpo-defaults.php:42`, `trunk/lib/utils/wc-yotpo-settings-functions.php:87`, `trunk/templates/wc-yotpo-settings.php:120-122`
- **How it works:** a default, a line in `wc_proccess_yotpo_settings()` reading `$_POST`, and a form field rendered with `checked()` / `selected()`.

### Yotpo API call with a user token
- **When to use:** any server-side Yotpo call.
- **Canonical implementation:** `wc_yotpo_map()` (`trunk/wc_yotpo.php:306-314`)
- **How it works:** `new Yotpo($app_key, $secret)`, `get_oauth_token()`, put `access_token` in `utoken`, call the method, log with `ytdbg`, catch exceptions.

# Citations

[1] Plugin Check fixes, `WP_Filesystem`, `wp_remote_*` (git commit `91dfc0b`, PR #73)
[2] Hidden files removed from `trunk/` (git commit `a6fab3e`, PR #74)
[3] `yotpo_get_arr_value` rollout (git commits `8596faa`, `5c535ba`, `ff14519`)
[4] Settings-page throw removed (git commit `a7b799b`, PR #64)
[5] Backward-compatible `wc_yotpo_show_widget` alias (git commit `2b1b13a`)
