# Code Review Fix instructions

Check commands come only from `.agents/adlc/REPO_CHECKS.md`. It names no runnable check today, so a fix
cannot be verified locally: say so in the report for every fix, and keep each fix to the lines the finding
covers.

## Convention pointers

The canonical examples to copy (`docs/conventions.md` has the full tables and the Patterns Library):

- **Output:** `esc_attr()` every value while building the HTML string, then
  `echo wp_kses($html, yotpo_*_allowed_html())`. See `trunk/lib/widgets/reviews-widget.php:17-28` and
  `trunk/wc_yotpo.php:116`. Script data: `esc_js()` (`trunk/wc_yotpo.php:507-513`).
- **Allowlists:** a new printed attribute needs an entry in the matching function of
  `trunk/lib/utils/allowed-html-functions.php`. That file is human-required: a fix that needs it is out of scope.
- **Settings reads:** `yotpo_get_arr_value($settings, 'key', $default)`
  (`trunk/lib/utils/general-usage-functions.php:3`).
- **Yotpo API calls:** through `Yotpo::request` with an OAuth `utoken`, inside `try/catch (Exception $e)` that
  calls `error_log` (`trunk/wc_yotpo.php:303-318`).
- **Files:** `WP_Filesystem` (`trunk/wc_yotpo.php:572-584`). **HTTP:** `wp_remote_*`.
- **Types:** nullable defaults use `?Type $x = null`.
- **Names:** new globals start with `wc_yotpo_`.

## Stack specifics

- **WordPress plugin, no local runtime.** Nothing here runs without a WordPress + WooCommerce install, and none is
  available in the fix loop. Do not add a `composer.json`, `vendor/`, `phpcs.xml`, PHPUnit or any tool config as
  part of a fix.
- **Only `trunk/` ships.** Plugin code and assets go under `trunk/`; a new PHP file must be `require`d from
  `trunk/wc_yotpo.php` or the file that uses it. Never add a hidden file (dotfile) under `trunk/` (ADR-0003).
- **No namespaces.** Before adding a function, search the repository for the name; a duplicate is a fatal error.
- **Both widget modes.** A storefront fix must keep the v2 and v3 paths working (ADR-0002).
- **Settings compatibility.** Never rename or drop a `yotpo_settings` key; add new keys with a default in
  `trunk/lib/utils/wc-yotpo-defaults.php` and read them with `yotpo_get_arr_value`.

## Protected paths

`.claude/protected-files.json` is the source of truth. For this repo in particular:

- **Never write** (human-required): `trunk/lib/yotpo-api/Yotpo.php`, `trunk/templates/wc-yotpo-settings.php`,
  `trunk/lib/utils/wc-yotpo-settings-functions.php`, `trunk/lib/utils/allowed-html-functions.php`,
  `trunk/uninstall.php`, `trunk/readme.txt`, `changelog`, `.github/workflows/**`, `.agents/adlc/**`, `.claude/**`,
  `CODEOWNERS`. A finding that needs one of these (credential handling, a nonce on the settings page, an
  allowlist entry, a release bump) is out of scope. Report it; don't work around it.
- **Also never change** the `Version:` line in `trunk/wc_yotpo.php` (release only, ADR-0001).
- **Review-required:** the rest of `trunk/**`, root `assets/`, `CLAUDE.md`, `AGENTS.md`.
- **Public contracts:** never rename a public `wc_yotpo_show_*` function or a `yotpo_settings` key to satisfy a
  finding; merchant themes and stored settings use them.
- **Credentials:** never add a Yotpo app key, secret, token or the SVN password to any file.

## On-demand CI run

There is none. The repository has no CI workflow, so there is no `ci-on-demand.yaml` and no
`ready-for-merge-ci.yaml`. Do not create one as part of a fix.
