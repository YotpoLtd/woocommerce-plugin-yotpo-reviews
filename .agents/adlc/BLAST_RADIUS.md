# Blast Radius profile

The repo-specific half of the blast-radius scoring method, read by `yotpo-adlc:blast-radius`
on every run. The generic half (factors, weights, caps) lives in the skill.

**This is a WordPress plugin, not a web app.** There are no routes, no module aliases and no
autoloader in the shipped code. The sections below say what stands in for each.

## entryPoints

WordPress invokes callbacks registered with `add_action` / `add_filter` and the
`register_*_hook` family. There is no route table or framework manifest, so the entry points
are the files that register those callbacks at load time or from an `init()` / `register()`.

glob: `trunk/wc_yotpo.php`, `trunk/templates/wc-yotpo-settings.php`,
`trunk/lib/sync/class-yotpo-sync-jobs.php`, `trunk/lib/sync/class-yotpo-order-sync.php`,
`trunk/lib/sync/class-yotpo-order-backfill.php`, `trunk/lib/utils/wc-yotpo-functions.php`,
`trunk/lib/utils/widgets-rendering-logic.php`, `trunk/uninstall.php`,
`trunk/lib/sync/class-yotpo-sync-cli.php`

The first seven are exactly what this reproduces — re-derive rather than trust the list:

```
grep -rlE 'add_action|add_filter|register_(activation|uninstall|deactivation)_hook|WP_CLI::add_command' trunk
```

The last two are entry points the grep does **not** find, because they are registered from
`trunk/wc_yotpo.php` rather than registering themselves:

- `trunk/uninstall.php` — WordPress loads this file directly when the plugin is deleted
  (`register_uninstall_hook`, `trunk/wc_yotpo.php:24`)
- `trunk/lib/sync/class-yotpo-sync-cli.php` — invoked as `wp yotpo product-sync`
  (`WP_CLI::add_command`, `trunk/wc_yotpo.php:70`)

A class whose `init()` only delegates registration elsewhere is **not** an entry point:
`Yotpo_Product_Sync::init()` calls `Yotpo_Sync_Jobs::register()`, so the jobs file is the root,
not the sync file.

Nothing outside `trunk/` is an entry point — only `trunk/` is published to WordPress.org.

## areas

Match most-specific prefix first.

- api: `trunk/lib/yotpo-api/`
- sync: `trunk/lib/sync/`
- widgets: `trunk/lib/widgets/`
- settings: `trunk/templates/`
- export: `trunk/classes/`
- storefront-assets: `trunk/assets/`
- release: `trunk/readme.txt`, `changelog`, `assets/`
- tests: `tests/`, `test-data/`
- tooling: `bin/`, `.github/`, `phpcs.xml.dist`, `phpunit.xml.dist`, `composer.json`, `composer.lock`
- harness: `.agents/`, `.claude/`, `CLAUDE.md`, `AGENTS.md`, `ARCHITECTURE.md`, `docs/`
- core: `trunk/lib/utils/`, `trunk/wc_yotpo.php`, `trunk/uninstall.php`

`core` is the shared layer every other area loads — settings defaults, escaping allowlists,
render wiring and the bootstrap. A change there is cross-cutting by construction.

## imports

aliases: none. The shipped plugin has no namespaces, no Composer autoloader and no alias map;
Composer is dev tooling only.

Modules are loaded with `require plugin_dir_path( __FILE__ ) . '<relative path>'` from
`trunk/wc_yotpo.php`, and classes and functions are then referenced by their **global** names.
So "who imports X" is not answerable from the `require` lines — every module is required from
one place regardless of who uses it. Trace reach by searching for the global symbol instead:

```
grep -rn 'Yotpo_Order_Backfill' trunk tests              # a class
grep -rn 'wc_yotpo_get_default_settings' trunk tests     # a function
```

Also follow these non-import couplings, which no text search over `require` lines will find:

- **Action Scheduler hook names.** A job is wired by string, not by reference: the `HOOK_*`
  constants in `class-yotpo-sync-jobs.php`, `class-yotpo-order-sync.php` and
  `class-yotpo-order-backfill.php`. Renaming one silently orphans every queued job.
- **Option, meta and group names repeated as literals in `trunk/uninstall.php`.** That file
  runs with no classes loaded, so it cannot use the constants; it copies them. A constant
  changed in a class and not there means uninstall leaves merchant data behind.

## hotspots

Reach and criticality are forced to 1.0 for a change in these.

- `trunk/wc_yotpo.php` — requires every module, registers every storefront and admin hook, and
  holds the storefront entry. Reaches everything by construction.
- `trunk/lib/utils/wc-yotpo-defaults.php` — defines the `option_yotpo_settings` filter that
  every read of settings passes through, for every feature and every store. A change to the
  legacy-fill rule changes behaviour on update for stores that never touched the setting.

Deliberately **not** hotspots, so the trace measures them instead:
`class-yotpo-v3-client.php` (the sync half only — the storefront never calls it) and
`widgets-rendering-logic.php` (the storefront half only).

## ignoreAsImporters

- `trunk/wc_yotpo.php`

It `require`s every module in `trunk/lib/`, so counting it as an importer would make every
file in the plugin look like it is reached by the bootstrap, inflating reach for every change.
It is a registry, not a user. (It is still a hotspot when the change is *in* it.)

## generatedFiles

Empty — nothing in `trunk/` is tool-written. `composer.lock` is machine-written but carries a
real dependency decision, so it is scored, not ignored.

## tables

### criticality

How bad is a failure where the change sits, on a merchant's live store?

- 1.0: runs on every storefront page view or every admin request, or is irreversible —
  `trunk/wc_yotpo.php` bootstrap, the settings filter in `trunk/lib/utils/wc-yotpo-defaults.php`,
  `trunk/uninstall.php` (deletes merchant data and cannot be undone)
- 0.7: shopper-visible but the store survives it — widget render functions in
  `trunk/lib/widgets/`, `trunk/lib/utils/widgets-rendering-logic.php`, `trunk/assets/`. A failure
  is a missing or broken widget, not a broken shop
- 0.4: background sync, retried and invisible until the status line reports it —
  `trunk/lib/sync/`, `trunk/lib/yotpo-api/`. Wrong data reaching Yotpo is recoverable by a
  resync; it is still merchant-visible in their Yotpo account
- 0.2: admin-only and re-runnable — `trunk/classes/` (review export),
  `trunk/lib/sync/class-yotpo-sync-cli.php`, the settings screen's own markup
- 0.1: never on a merchant's path — `tests/`, `test-data/`, `bin/`, `docs/`, `.agents/`

### change-type

How risky is this kind of change by nature, in this repo's own files?

- 0.95: irreversible, or trust-breaking for every store at once — `Stable tag` in
  `trunk/readme.txt` or `Version:` in the plugin header (a release: what WordPress.org serves to
  every installed store), `trunk/uninstall.php`, the token and auth path in
  `trunk/lib/yotpo-api/`, `wc_yotpo_legacy_missing_settings()` (changes behaviour for every
  upgrading store), and the wired checks — `.github/workflows/`, `phpcs.xml.dist`,
  `phpunit.xml.dist`, `bin/`
- 0.75: shared infrastructure — hook registration or bootstrap in `trunk/wc_yotpo.php`, a new
  key in `yotpo_settings`, transport changes in `Yotpo_V3_Client`, any rename of an Action
  Scheduler hook or group name, a new external dependency
- 0.50: non-trivial logic on the sync path in `trunk/lib/sync/`; any refactor without test
  coverage
- 0.30: contained work in one widget or one sync class, with tests
- 0.15: admin copy and labels, non-switch defaults, config tweaks
- 0.05: comments, docs, formatting, dead-code removal

<!-- Provenance: entryPoints, areas, imports, hotspots and ignoreAsImporters are read off the
     code and should stay stable as it changes. The criticality and change-type tiers encode
     what this team considers damaging; they were drafted from the tiers in
     .claude/protected-files.json and are the part to revisit first if a score looks wrong. -->
