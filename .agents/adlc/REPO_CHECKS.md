# Repository check instructions

## Environment

- **PHP 7.1 or newer.** Nothing in the repository pins a PHP version: `trunk/readme.txt:54` says PHP 5.2.0, but the
  code uses `void` return types (`trunk/wc_yotpo.php:156`, `trunk/lib/utils/widgets-rendering-logic.php:56`) and
  scalar type hints, so it does not run below 7.1. `int $x = null` defaults raise deprecations on PHP 8.4.
- **A WordPress install with WooCommerce 3.0+ active** (`trunk/wc_yotpo.php:9-10`, `trunk/readme.txt:53-55`). This is
  a plugin, not an application: `trunk/` is copied into `wp-content/plugins/` and nothing in this repository runs on
  its own. There is no `composer.json`, no `package.json` and no `vendor/`.
- TODO(ai-dlc): the agent image, and that it is not the runtime image. There is no agent image in this repository,
  and no runtime image either: the plugin runs inside the merchant's WordPress.

## Always, on every changed file

**There is no formatter in this repository** -- no PHP-CS-Fixer, no EditorConfig, no `phpcs.xml`. Do not add one,
and do not reformat lines as a side effect of a fix.

TODO(ai-dlc): the linter, and whether a violation fails the build. There is no CI and no lint command in the
repository. The external gate is WordPress.org Plugin Check: commit `91dfc0b` fixed "various errors detected with
Plugin Check", but how it is run (the Plugin Check plugin in a WordPress install, or `wp plugin check` via WP-CLI)
and against which version is not written down here.

```
TODO(ai-dlc): the command
```

TODO(ai-dlc): whether the linter can fix violations itself, and which repo-specific rules an agent is most likely
to trip. The Plugin Check rules this repository has already hit (commits `91dfc0b`, `a6fab3e`, `7711be5`):

- output escaping -- every echoed value escaped, and HTML echoed through `wp_kses` with an allowlist from
  `trunk/lib/utils/allowed-html-functions.php`;
- no direct file or cURL calls -- `WP_Filesystem` and `wp_remote_*`;
- no hidden files inside `trunk/`.

TODO(ai-dlc): where the linter config and its suppression list live. None is committed.

- **A violation your own edit introduced is part of the finding you are fixing** --
  iterate until it is clean. Never report a finding fixed while its checks are red, and
  never weaken or silence a check to get a commit through.
- **Pre-existing violations in code you did not touch are out of scope.** Leave them;
  fixing them widens the diff past the finding.
- **Reverting is the last resort, not the first move.** Only when the retry budget in
  the `code-review-fixer` skill is spent -- the rule is unclear, or satisfying it would
  change behaviour -- back that finding's edit out and report it unfixed, with the
  check's own message as the reason.

## By kind of change

Run the narrowest thing that covers what you touched.

| Change touches | Run |
|---|---|
| `trunk/**/*.php` | TODO(ai-dlc): no check is defined in CI, scripts or docs. The documented validation is installing `trunk/` into WordPress with WooCommerce and using the plugin (`trunk/readme.txt:57-60`), which needs a WordPress install this environment does not have |
| `trunk/assets/**` | TODO(ai-dlc): no check is defined; JS and CSS are plain files with no build or linter |
| `trunk/wc_yotpo.php` plugin header, `trunk/readme.txt` | TODO(ai-dlc): the full build -- a build-script change can break any area. There is no build; these are release metadata, `trunk/readme.txt` is `human-required`, so a finding that needs them is out of scope |
| Root `assets/` (WordPress.org listing images) | nothing |
| Markdown, `docs/**` only | nothing |

A change spanning several areas runs each area's row, not the full build.

## Full validation

```
bash .agents/adlc/repo-validation.sh
```

The canonical pre-merge run, and the single definition of "everything passed" -- see the
script's header for the exit-code contract. Not part of the fix loop: run it once at the
end of a pass.

## Do not run

- **Anything needing infrastructure this environment does not have** --
  integration or component suites that boot databases, brokers or other services. They
  belong to CI, not to a fix loop. Here: anything that needs a WordPress + WooCommerce install with a database
  (activating the plugin, WP-CLI, Plugin Check), and anything that calls `api.yotpo.com` with real credentials.
- **Dependency re-resolution** unless a dependency actually changed -- it
  re-fetches the whole graph over the network every time. This repository has no dependency manifest, so there is
  nothing to re-resolve.
- **Anything that writes to the default branch**, publishes an artifact, pushes an image,
  or triggers a deploy. Here: any `svn` command against `plugins.svn.wordpress.org` (`README.md:9-33`) -- `svn ci`
  publishes to every merchant -- and bumping `Version:` / `Stable tag`.
- **`git push --force`**, and any rebase to sync the branch.
