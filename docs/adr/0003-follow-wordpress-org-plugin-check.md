---
type: ADR
title: ADR-0003 Follow WordPress.org Plugin Check
timestamp: 2026-09-28T11:47:58Z
---

# ADR-0003: Follow WordPress.org Plugin Check
- **Status:** Accepted
- **Date:** 2026-09-28 (recorded from commits `91dfc0b`, `5e77286`, `a6fab3e`, `7711be5`, versions 1.7.10–1.8.2)

## Context
WordPress.org reviews listed plugins with its Plugin Check rules. Version 1.8.0 fixed the errors it reported.

## Decision
- Escape every value (`esc_attr`, `esc_js`, `esc_url`, `esc_html`) and echo every HTML fragment through
  `wp_kses` with an allowlist from `trunk/lib/utils/allowed-html-functions.php`.
- Sanitize input (`sanitize_email`, `sanitize_user`) and verify nonces with `check_admin_referer`.
- HTTP through `wp_remote_request`, not cURL; files through `WP_Filesystem`, not direct PHP calls.
- No hidden files inside `trunk/` (commit `a6fab3e` removed `.gitignore` and `.travis.yml` from
  `trunk/lib/yotpo-api/`).

## Alternatives Considered
- **`phpcs:ignore` annotations** — not used in this repository.

## Consequences
- Constrains: a new printed attribute must be added to the matching allowlist, or `wp_kses` strips it silently.
- The Plugin Check command itself is not written down in this repository.
