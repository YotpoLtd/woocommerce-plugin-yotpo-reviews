# woocommerce-plugin-yotpo-reviews

## Purpose
The Yotpo reviews plugin for WordPress / WooCommerce, published on WordPress.org as "Yotpo: Product & Photo Reviews for WooCommerce" (slug `yotpo-social-reviews-for-woocommerce`, `README.md:7-10`). It renders the Yotpo reviews, star-rating, Q&A, reviews carousel, promoted products and reviews tab widgets on product, category/shop and home pages (v3 widgets by default, v2 as the legacy option), sends orders to Yotpo when they reach the configured status (mail after purchase), submits the last 90 days of orders on request, exports native WooCommerce reviews to CSV, and adds a conversion pixel on the thank-you page. The repository is public; merchants install the plugin from WordPress.org, which is released by a manual SVN copy of `trunk/` (`README.md:7-37`, ADR-0001). Owned by team Orbits (`CODEOWNERS`, commit `fcf5f38`).

## Stack
- Language: PHP, procedural (global functions) plus two classes. `trunk/readme.txt` says PHP 5.2.0+, but the code uses `void` return types (`trunk/wc_yotpo.php:156`) and scalar type hints, so PHP 7.1+ is the real minimum
- Framework: WordPress plugin API (hooks, options, `wp_kses`, `WP_Filesystem`, `wp_remote_*`) on WooCommerce (`WC requires at least: 3.0`, tested up to 9.4.2, `trunk/wc_yotpo.php:9-10`); WordPress tested up to 6.7 (`trunk/readme.txt:5`)
- Runtime: inside a merchant's WordPress; no service, no image
- Package manager: none. No `composer.json`, no `package.json`, no vendored libraries
- Key dependencies: WooCommerce; the Yotpo API client in `trunk/lib/yotpo-api/Yotpo.php`; the Yotpo CDN widget loader (`trunk/assets/js/v3HeaderScript.js`)

## Directory Structure
Top-level only (run `tree -L 1` to verify if stale):
- `trunk/`: the plugin itself. This folder is what gets copied to WordPress.org SVN `trunk/`
- `assets/`: WordPress.org listing icons and screenshots (SVN `assets/`, not loaded by the plugin)
- `changelog`: legacy developer changelog; the user-facing one is the `== Changelog ==` section of `trunk/readme.txt`
- `docs/`: harness docs
- `.agents/adlc/`, `.claude/`: ADLC pipeline contract files

## Key Files
- `trunk/wc_yotpo.php:1-13`: plugin header (the `Version:`), hook registration, frontend rendering, order submission (`wc_yotpo_map`, line 292), past orders, conversion pixel
- `trunk/lib/utils/widgets-rendering-logic.php`: which widget is hooked where, per page type and v2/v3
- `trunk/lib/widgets/*.php`: the HTML for each widget (`generate_v2_*` / `generate_v3_*`)
- `trunk/lib/utils/allowed-html-functions.php`: the `wp_kses` allowlists every echo goes through
- `trunk/lib/utils/wc-yotpo-defaults.php:3`: default settings for the single `yotpo_settings` option, and the global fatal-error output buffer handler
- `trunk/templates/wc-yotpo-settings.php`: the admin settings page, its POST actions, the v3 widget-id sync and the Yotpo registration flow
- `trunk/lib/utils/wc-yotpo-settings-functions.php:71`: saving settings from `$_POST`
- `trunk/lib/yotpo-api/Yotpo.php`: the Yotpo API client (OAuth token, purchases, widget instances)
- `trunk/classes/class-wc-yotpo-export-reviews.php`: native reviews → CSV export
- `trunk/readme.txt`: WordPress.org listing; `Stable tag` must equal the header `Version:`

## Don't Touch
- `trunk/readme.txt` `Stable tag` and `trunk/wc_yotpo.php` `Version:`: fragile. WordPress.org serves the version named by `Stable tag`; they are bumped together with the changelog, only when releasing (`README.md:20-25`)
- `trunk/uninstall.php`: fragile. Runs on merchant sites when the plugin is deleted and removes `yotpo_settings`
- Public template functions (`wc_yotpo_show_reviews_widget`, `wc_yotpo_show_widget`, `wc_yotpo_show_qna_widget`, `wc_yotpo_show_buttomline`, ...): merchants call them from their theme (`trunk/templates/reusables/widgets-settings.php:36-44,63-72`); never rename
- `assets/`: WordPress.org listing images

## Quick Start
There is no build, test or lint command in this repository and no CI.

- **Run locally:** copy `trunk/` into a WordPress install's `wp-content/plugins/`, with WooCommerce active, and activate it; settings are under the "Yotpo" admin menu (`trunk/readme.txt:49-60`)
- **Release:** manual SVN checkout of the WordPress.org plugin, copy `trunk/`, bump version/Stable tag/changelog, `svn ci` (`README.md:7-37`). Never done by an agent
- **Lint:** none committed. Commit `91dfc0b` fixed errors reported by WordPress.org Plugin Check; its command is not in the repo
- **Checks per kind of change:** `.agents/adlc/REPO_CHECKS.md`

## Development Workflow (Spec-Driven)

> Requires: `superpowers` plugin. Install with `/plugin install superpowers@claude-plugins-official`

All feature work, bug fixes, and refactors MUST start with `/superpowers:brainstorming`.
Do NOT write code without first brainstorming and planning.
The Superpowers pipeline guides you through the rest (planning, execution, finishing).

Specs and plans MUST be committed to the feature branch and included in the PR.

## Common Patterns
- Settings: one option `yotpo_settings`, read with `get_option('yotpo_settings', wc_yotpo_get_default_settings())`; a new key needs a default in `trunk/lib/utils/wc-yotpo-defaults.php` and a line in `wc_proccess_yotpo_settings()`
- Reading a possibly missing key: `yotpo_get_arr_value($arr, 'key', $default)` (`trunk/lib/utils/general-usage-functions.php:3`, commits `8596faa`, `5c535ba`)
- Output: build the HTML with `esc_attr()` on every value, then `echo wp_kses($html, yotpo_*_allowed_html())` (`trunk/wc_yotpo.php:116`)
- A new widget: `generate_v3_*_widget_code()` in `trunk/lib/widgets/`, a `wc_yotpo_show_*_widget()` in `trunk/wc_yotpo.php`, an enable flag in `v3_widgets_enables`, a hook in `widgets-rendering-logic.php`
- Commits follow `type(scope):message` with no space after the colon (local commit-msg hook); PRs land as merge commits, a few as squash (e.g. `7711be5`, #75)

Full details: [docs/conventions.md](docs/conventions.md).

## Anti-Patterns
See [conventions.md](docs/conventions.md#anti-patterns) for the full list.

## Reference Docs
Read BEFORE starting work on the relevant area:
- [Architecture](ARCHITECTURE.md): system map, modules, boundaries, data flow, architectural decisions
- [Docs Index](docs/index.md): entry point for the docs/ folder (conventions, troubleshooting, and any other deep docs)
- Protected Files (`.claude/protected-files.json`): criticality tiers (human-required / review-required / auto-safe); CODEOWNERS mirrors the human-required tier and the agent files
- [Spec template](docs/specs/TEMPLATE.md): copy per feature. Specs are committed to the PR
