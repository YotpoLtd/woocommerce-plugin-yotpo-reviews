# Code Review instructions

## Also read

- `ARCHITECTURE.md` -- module map, the storefront / order / settings flows, the v2 vs v3 switch, boundaries.
- `docs/conventions.md` -- patterns, Do's and Don'ts, and the Anti-Patterns table.
- `docs/troubleshooting.md` -- WordPress.org gate failures and the known issues listed at the end.
- `docs/adr/` -- when the change touches versions or release files (0001), widget modes (0002) or output,
  HTTP and file handling (0003).
- `.claude/protected-files.json` -- the criticality tiers; `human-required` is authoritative.

## Risk areas

Where a mistake here is expensive, and why:

- **Public plugin, installed by merchants.** The repository is public and the plugin is installed from
  WordPress.org into stores Yotpo does not operate. A bug ships to every merchant who updates; there is no
  rollback on Yotpo's side.
- **Every storefront page and every order.** `trunk/wc_yotpo.php` loads on every request, installs a global
  output buffer (`ob_start('fatal_error_handler')`) and hooks into WooCommerce product, shop and thank-you
  pages. `wc_yotpo_map` runs inside the merchant's order-status change. A fatal error or a slow call there
  breaks or slows the store.
- **Global functions.** There are no namespaces; a new unprefixed or duplicate function name is a fatal
  "cannot redeclare" error on stores where another plugin uses it.
- **Credentials and customer data.** The app key and secret, OAuth tokens, customer emails and names go
  through `trunk/lib/yotpo-api/Yotpo.php`, the settings page and the debug log.
- **Merchant contracts.** Themes call the public `wc_yotpo_show_*` functions; stored `yotpo_settings` keys
  persist across versions.
- **WordPress.org Plugin Check.** Unescaped output, direct file or cURL calls, and hidden files in `trunk/`
  fail it (ADR-0003).
- **Release metadata.** `Version:` in `trunk/wc_yotpo.php`, `Stable tag` in `trunk/readme.txt` and the
  changelogs move together, only in a release (ADR-0001).

## Always check

| Condition | Severity |
|---|---|
| A Yotpo app key, secret, `utoken`, the WordPress.org SVN password or any other credential committed anywhere. Report the file and line only; never quote the value | critical |
| Output echoed without escaping, or not through `wp_kses` with an allowlist; a value interpolated into HTML without `esc_attr` / `esc_url` / `esc_js` / `esc_html` | critical |
| An allowlist in `trunk/lib/utils/allowed-html-functions.php` that newly allows `script`, event-handler attributes (`on*`), `href`/`src` on new tags, or `style` | critical |
| A new or changed admin POST/GET action without `check_admin_referer` and a `current_user_can('manage_options')` check | critical |
| The secret, a token or customer data written to a URL, the page, or the `ytdbg` log | critical |
| SQL built without `$wpdb->prepare` | critical |
| A renamed or removed public `wc_yotpo_show_*` function or `yotpo_settings` key | major |
| `Version:` in `trunk/wc_yotpo.php` and `Stable tag` in `trunk/readme.txt` not changed together, or changed outside a release PR | major |
| A new global function without the `wc_yotpo_` / `yotpo_` prefix | major |
| A Yotpo API call on the storefront render path, or an API call without `try/catch` | major |
| A direct `$settings['key']` read of a key older stores may not have, instead of `yotpo_get_arr_value` | major |
| An exception or fatal error that can escape into storefront rendering or the order-status request | major |
| `curl_*`, `file_get_contents` / `fopen` on files or URLs instead of `wp_remote_*` / `WP_Filesystem` | major |
| A new file in `trunk/` that is not `require`d, or a hidden file in `trunk/` | major |
| A storefront change that handles only one of v2 / v3 (ADR-0002) | minor |
| A nullable parameter default without a nullable type (`int $x = null`) -- deprecated in PHP 8.4 | minor |

## Do not report

- **The known issues listed in `docs/troubleshooting.md`** (`sslverify => false`, the secret in the dashboard
  link, the debug log location and content, the missing nonce checks on sync and past orders, the unescaped
  settings `value=` attributes, the custom-widgets loop, the tab filters, the uninstall comparison, the
  `curl_init` check, the README drift), unless the PR touches those lines. Flag the same pattern when a PR adds
  a new instance of it.
- **The existing unprefixed global functions** (`use_v3_widgets`, `get_widget_instances`, `fatal_error_handler`,
  `echo_message`, ...), unless the PR touches them. Renaming them would break callers.
- **Missing unit tests.** The repository has no test setup.
- **Formatting and code style** (mixed tabs and spaces, brace style). There is no formatter or committed linter.
- **The `wc_yotpo_show_buttomline` and `wc_proccess_*` spellings.** They are public or long-standing names.
- **`AGENTS.md`**, unless a PR replaces the symlink to `CLAUDE.md` with a real file.
