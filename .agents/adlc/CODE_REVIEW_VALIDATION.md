# Code Review Validation instructions

## Known false positives

Each of these looks like a finding but is deliberate or accepted in this repo:

- **"`wc_yotpo_get_product_data` values are double-escaped."** The builder pre-escapes with `esc_attr`
  (`trunk/wc_yotpo.php:253-262`) and the widget builders escape again; `esc_attr` does not double-encode existing
  entities. Not a bug.
- **"`wp_kses` output is unescaped."** Echoing through `wp_kses` with an allowlist is the plugin's output pattern
  (ADR-0003). A finding stands only if a value inside the fragment was not escaped first, or the allowlist is too
  wide.
- **"Widget renders nothing."** In v3 mode each widget returns early without its synced instance id, and v2
  widgets are not rendered at all (ADR-0002, `trunk/wc_yotpo.php:125,145`).
- **"Native WooCommerce reviews / star ratings disabled."** Deliberate: activation turns ratings off and the
  `comments_open` filter hides reviews when "Disable native reviews system" is on (`trunk/wc_yotpo.php:83-85,102-103`).
- **"`Yotpo.php` is not loaded on the storefront."** Intentional: it is included in admin and `require_once`d in
  `wc_yotpo_map` (`trunk/wc_yotpo.php:52,306`).
- **"API errors are swallowed."** Storefront and order flow must continue without Yotpo; errors go to
  `error_log` / `ytdbg`. A finding stands only if an error can reach rendering or the order request.
- **"Orders with an email ending in a digit are skipped."** Long-standing filter (`trunk/wc_yotpo.php:329`);
  changing it is a product decision.
- **"`wc_yotpo_show_widget` is dead code."** It is a public alias for merchant themes (commit `2b1b13a`).
- **"No unit tests."** The repository has none; that is the current state, not a regression in the PR.
- **"Global function without namespace."** The plugin is procedural by design; only a new *unprefixed* or
  duplicate name is a finding.
- **The known issues in `docs/troubleshooting.md`** when the PR does not touch those lines.

## Always keep

Never filter these out, even if they look minor or cosmetic:

- a committed Yotpo app key, secret, token or the SVN password;
- unescaped output, or an allowlist widened to scripts, event handlers or `style`;
- a new or changed admin action without a nonce check or a capability check;
- the secret, a token or customer data written to a URL, the page or the debug log;
- SQL without `$wpdb->prepare`;
- a renamed or removed public `wc_yotpo_show_*` function or `yotpo_settings` key;
- `Version:` and `Stable tag` changed separately or outside a release (ADR-0001);
- a new global function name that is unprefixed or already exists;
- a hidden file added under `trunk/`.

## Domain notes

- Nothing here runs without WordPress and WooCommerce; there is no CI. A verifier cannot run a check and must
  judge from the code.
- Hook callbacks are strings: a function with no direct caller can still be live through `add_action` /
  `add_filter`, or through a merchant theme (the public `wc_yotpo_show_*` functions). Search for the quoted name
  before calling code dead.
- `get_option('yotpo_settings', wc_yotpo_get_default_settings())` returns the *stored* array when one exists; the
  defaults only apply to fresh installs, so stored settings can lack newer keys.
- `trunk/wc_yotpo.php` runs on every request; a finding there has site-wide reach.
