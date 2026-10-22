# woocommerce-plugin-yotpo-reviews

## Purpose
"Yotpo Product Reviews" is the WordPress plugin that connects a WooCommerce store to Yotpo. It does
two things: it renders Yotpo's review widgets on the storefront (product, category and home pages),
and it syncs the store's catalog and orders to Yotpo over the Core API V3 so reviews can be requested
and attributed. It ships to WordPress.org as `yotpo-social-reviews-for-woocommerce` (the slug is
permanent). Owned by team `orbits` (`CODEOWNERS`).

## Stack
- Language: PHP 8.2+ (`WC_YOTPO_MIN_PHP_VERSION`, `trunk/wc_yotpo.php:21`)
- Framework: none — plain WordPress plugin. WordPress ≥ 6.0, WooCommerce ≥ 3.0
- Runtime: the merchant's WordPress install; background work runs on WooCommerce's Action Scheduler
- Package manager: Composer, for **dev tooling only** — nothing is vendored into the shipped plugin
- Key dependencies: PHPUnit 9.6 + `wp-phpunit`, PHPCS with `wp-coding-standards/wpcs` and
  `phpcompatibility/phpcompatibility-wp`

## Directory Structure
Top-level only (run `ls` to verify if stale):
- `trunk/` — **the plugin that ships.** Everything else is tooling
- `trunk/lib/sync/` — catalog and order sync: jobs, state, payloads, API wrappers
- `trunk/lib/yotpo-api/` — the HTTP clients (`Yotpo_V3_Client` for Core API V3, `Yotpo` for widget IDs)
- `trunk/lib/widgets/` — one file per storefront widget's markup
- `trunk/lib/utils/` — settings defaults, render wiring, escaping allowlists
- `trunk/templates/` — the admin settings screen
- `trunk/classes/` — the review CSV export
- `tests/` — PHPUnit suite, runs inside a real WordPress + WooCommerce
- `test-data/` — a WooCommerce product import CSV with widget edge cases
- `assets/` — WordPress.org **listing** images (icon, banners, screenshots), not plugin assets
- `bin/` — `install-wp-tests.sh`, `php-lint.sh`

## Key Files
- `trunk/wc_yotpo.php` — plugin header, every hook registration, storefront entry (`wc_yotpo_init`, `:58`)
- `trunk/uninstall.php` — deletes every option, meta key and transient the plugin creates
- `trunk/lib/utils/wc-yotpo-defaults.php` — settings defaults and the legacy-fill rule (`:60`, `:82`)
- `trunk/lib/yotpo-api/class-yotpo-v3-client.php` — token, pacing and retry for every Core API call
- `trunk/lib/sync/class-yotpo-sync-jobs.php` — which WooCommerce events queue which sync job (`:37`)
- `trunk/lib/sync/class-yotpo-catalog-api.php` — product/variant upsert, fingerprints, blocklisting
- `trunk/lib/sync/class-yotpo-order-sync.php` — order send on status change, refund updates, stats
- `trunk/lib/utils/widgets-rendering-logic.php` — which widget renders where, V2 vs V3 (`:33`)
- `tests/includes/class-yotpo-test-case.php` — the base test case and the Yotpo API mock
- `README.md` — the WordPress.org release procedure. Read it before any release PR

## Don't Touch
- `trunk/readme.txt` `Stable tag` — decides what WordPress.org serves to **every** store; release-only
- `trunk/wc_yotpo.php` plugin header `Version:` — must move together with the readme and `changelog`
- `assets/` — these are the public listing images; they update the plugin page for every version at once
- `.claude/protected-files.json` `human-required` paths — see Reference Docs

## Quick Start
# Install dev tooling (once)
composer install

# Test (needs a MySQL database whose name contains "test" — it drops every table)
composer test:install
WP_TESTS_DB_HOST=localhost WP_TESTS_DB_PASSWORD=... composer test

# Lint
composer lint          # PHP syntax + deprecations; PHP_BINARIES="php8.2 php8.5" for several versions
composer phpcs         # security, DB, PHP-compatibility sniffs over trunk/

# Run locally
Install the repo's `trunk/` as a plugin in a WordPress site with WooCommerce active.

## Development Workflow (Spec-Driven)

> Requires: `superpowers` plugin. Install with `/plugin install superpowers@claude-plugins-official`

All feature work, bug fixes, and refactors MUST start with `/superpowers:brainstorming`.
Do NOT write code without first brainstorming and planning.
The Superpowers pipeline guides you through the rest (planning, execution, finishing).

Specs and plans MUST be committed to the feature branch and included in the PR.

## Common Patterns
- Settings are one option, `yotpo_settings`. Every read is filled with the keys a saved older version
  lacks, so a missing key reads as it always did — never as the new-install default
  (`trunk/wc_yotpo.php:29`, `trunk/lib/utils/wc-yotpo-defaults.php:82`)
- Sync work never happens in the request. A WooCommerce event queues an Action Scheduler job
  (`class-yotpo-sync-jobs.php:37`); the job calls Yotpo
- Every Core API call goes through `Yotpo_V3_Client::request()` (`:52`), which paces to 5 req/s
  (`:16`) and replaces a refused token once
- A product or order is only sent when its fingerprint changed (`META_PRODUCT_HASH`,
  `class-yotpo-catalog-api.php:19`)
- Concurrent writes to a shared option go through `Yotpo_Option_Lock::run()` (`:25`), never a
  read-modify-write
- Tests never reach the network: each declares the routes it expects (`class-yotpo-test-case.php:68`)

## Anti-Patterns
See [conventions.md](docs/conventions.md#anti-patterns) for the full list.

## Reference Docs
Read BEFORE starting work on the relevant area:
- [Architecture](ARCHITECTURE.md) — system map, modules, boundaries, data flow, architectural decisions
- [Docs Index](docs/index.md) — entry point for the docs/ folder (conventions, troubleshooting, and any other deep docs)
- [Protected Files](.claude/protected-files.json) — criticality tiers (agents should respect these)
- [Spec template](docs/specs/TEMPLATE.md) — copy per feature; specs are committed to the PR
- [ADLC contract](.agents/adlc/) — review, fix, validation, blast-radius and check rules for pipeline agents
