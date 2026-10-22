---
type: Conventions
title: woocommerce-plugin-yotpo-reviews Conventions
timestamp: 2026-10-22T00:00:00Z
---

# Coding Conventions

## File Organization

- One class per file, named `class-<slug>.php`, in the directory of the concern it serves
  (`trunk/lib/sync/`, `trunk/lib/yotpo-api/`). Procedural helper files are named for their topic
  without the `class-` prefix (`wc-yotpo-functions.php`).
- Every shipped file starts with `defined( 'ABSPATH' ) || exit;` — direct file access is a
  WordPress.org directory requirement. Canonical example: `trunk/lib/utils/wc-yotpo-defaults.php:3`.
- Sync classes are static. They hold no instances; state lives in options and post meta.
- `trunk/wc_yotpo.php` is the only file that registers hooks for the storefront and admin; sync
  classes register their own in an `init()`/`register()` the bootstrap calls.

## Naming

| Thing | Convention | Example |
|-------|-----------|---------|
| Global functions | `wc_yotpo_` or `yotpo_` prefix, snake_case | `wc_yotpo_get_default_settings()` |
| Classes | `Yotpo_Pascal_Snake` | `Yotpo_Order_Backfill` |
| Class files | `class-` + lowercased, hyphenated class name | `class-yotpo-order-backfill.php` |
| Options | `wc_yotpo_` prefix | `wc_yotpo_product_sync` |
| Post meta | `_yotpo_v3_` prefix (leading underscore = hidden) | `_yotpo_v3_product_hash` |
| Job hooks | `wc_yotpo_` + verb + noun | `wc_yotpo_send_past_orders_page` |
| Action Scheduler groups | `yotpo-<domain>-sync` | `yotpo-product-sync` |

The prefixes are enforced, not just conventional: `phpcs.xml.dist` sets
`WordPress.NamingConventions.PrefixAllGlobals` to `wc_yotpo` and `yotpo`.

## Error Handling

- Pattern: API calls return a result array rather than throwing. The caller asks
  `Yotpo_V3_Client::response_error()` what went wrong and `is_retryable()` whether to requeue.
- Canonical implementation: `trunk/lib/yotpo-api/class-yotpo-v3-client.php:213`
- Retries are the job's job, not the client's. The client retries exactly one thing — a refused
  token — and does it once (`class-yotpo-v3-client.php:52`).
- Never: swallow an API error and mark the record synced. The fingerprint must only be written
  after Yotpo accepted the payload, or the next job will skip a record that never arrived.

## Logging

- There is no logging in normal operation. Merchant sites are not ours to fill with log lines.
- The merchant can turn on `debug_mode`, which writes to a private directory under `wp-upload`,
  guarded by `.htaccess` and `index.php`. Canonical example: `trunk/wc_yotpo.php:418` (`ytdbg()`).
- **The debug log contains customer emails.** It is deleted on uninstall
  (`trunk/uninstall.php`), and nothing in it may be echoed to a page.

## Testing

- Tests live in `tests/`, one `*Test.php` per concern, extending `Yotpo_Test_Case`.
- They run inside a real WordPress with WooCommerce against a throwaway MySQL database. **The suite
  drops every table in that database** — its name must contain `test`.
- The Yotpo API is never called. Each test declares the routes it expects with
  `route()` (`tests/includes/class-yotpo-test-case.php:68`); any unexpected request fails the test
  rather than reaching the network (`:107`).
- Fixtures come from the base class: `create_product()`, `create_variable_product()`,
  `create_order()`, `refund()`. Don't hand-build a `WC_Product`.
- `beStrictAboutTestsThatDoNotTestAnything` is on — a test with no assertion is an error.

## Do's and Don'ts

| Do | Don't | Why |
|---|---|---|
| Add a new setting to both `wc_yotpo_get_default_settings()` and `wc_yotpo_legacy_missing_settings()` (`wc-yotpo-defaults.php:60`) | Add it to the defaults only | The second decides what the key means for every store that upgrades. Miss it and you change their behavior on update |
| Update a constant in `uninstall.php` when you change it in a class | Change `Yotpo_Sync_State::STATE_OPTION` alone | `uninstall.php` runs with no classes loaded and repeats the names as literals. Drift means uninstall leaves data behind |
| Wrap a shared-option update in `Yotpo_Option_Lock::run()` (`:25`) | `get_option()` → modify → `update_option()` | Two concurrent jobs lose each other's writes |
| Move `Version:`, `Stable tag` and `changelog` together | Bump one of them | `Stable tag` is what WordPress.org actually serves |
| Reach Yotpo through `Yotpo_V3_Client` | Call `wp_remote_post()` directly | The client owns pacing, the token and the single 401 retry |
| Queue a job from a WooCommerce event | Call the API during the request | A merchant saving a product must not wait on Yotpo |

## Anti-Patterns

| Anti-Pattern | Why It's Dangerous | See Also |
|--------------|-------------------|----------|
| Referencing anything outside `trunk/` from shipped code | Only `trunk/` is mirrored to WordPress.org — it's a fatal error on the merchant's site | `README.md`, "Development" |
| Using a plugin class in `trunk/uninstall.php` | WordPress runs it with the plugin unloaded | `trunk/uninstall.php:10` |
| Writing a fingerprint before Yotpo accepted the payload | The record is skipped forever after | `class-yotpo-catalog-api.php:19` |
| Raising `BATCH_SIZE` above `Yotpo_V3_Client::LOOKUP_MAX` | One lookup must cover the whole page; past the cap, comparisons silently truncate | `class-yotpo-sync-jobs.php:14` |
| Loosening `phpcs.xml.dist` or `phpunit.xml.dist` to get a green build | These are the only automated security and compatibility checks the plugin has | `.claude/protected-files.json` |
| Adding a widget's enable flag without a legacy meaning | Existing stores suddenly render a widget they never switched on | `wc-yotpo-defaults.php:60` |
| Storing a widget type the plugin doesn't render | It lands under an empty key in `v3_widgets_ids` | `trunk/lib/utils/widgets-rendering-logic.php` |
| Echoing anything from the debug log | It contains customer emails | `trunk/wc_yotpo.php:418` |

## Patterns Library

### Queue, don't call
- **When to use:** any time a WooCommerce event should reach Yotpo.
- **Canonical implementation:** `trunk/lib/sync/class-yotpo-sync-jobs.php:37`
- **How it works:** the event handler schedules an Action Scheduler job in the domain's group and
  returns. The job does the API work, retries with a doubling delay, and respects the shared
  rate-limit pause transient.

### Fingerprint before sending
- **When to use:** any upsert of a record that may not have changed.
- **Canonical implementation:** `trunk/lib/sync/class-yotpo-catalog-api.php:148`
- **How it works:** hash the payload, compare with the hash in post meta, send only on a difference,
  and store the new hash only after Yotpo accepted it.

### Locked option update
- **When to use:** any read-modify-write of an option several jobs touch.
- **Canonical implementation:** `trunk/lib/sync/class-yotpo-option-lock.php:25`
- **How it works:** take a lock option with a TTL, re-read inside the lock (another runner may have
  saved since this request loaded its options), apply the change, release.

### Legacy-aware settings read
- **When to use:** any new key in `yotpo_settings`.
- **Canonical implementation:** `trunk/lib/utils/wc-yotpo-defaults.php:82`
- **How it works:** a filter on `option_yotpo_settings` merges the saved settings over the *legacy*
  meaning of every key, recursively. Saved values always win.

### Routed API mock
- **When to use:** every test.
- **Canonical implementation:** `tests/includes/class-yotpo-test-case.php:68`
- **How it works:** `route()` registers a method + path pattern + responder. A `pre_http_request`
  filter answers matching requests and fails everything else, so a test can assert on the exact
  calls made.

# Citations

[1] Development and release procedure — `README.md` in this repository
[2] Sniff configuration and the supported-version floor — `phpcs.xml.dist`
[3] WordPress.org plugin directory requirements — https://developer.wordpress.org/plugins/wordpress-org/
