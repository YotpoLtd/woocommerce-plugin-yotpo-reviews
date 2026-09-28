---
type: ADR
title: ADR-0002 v3 widgets by default, v2 kept for existing installs
timestamp: 2026-09-28T11:47:58Z
---

# ADR-0002: v3 widgets by default, v2 kept for existing installs
- **Status:** Accepted
- **Date:** 2026-09-28 (recorded from commits `0f10fb5` (v3 widgets, 2023-07), `8ff960e` (v3 loader), `c337379` (v3 default, 1.7.9))

## Context
Yotpo's v3 widgets are configured in Yotpo and addressed by an instance id; v2 widgets are configured by
`data-*` attributes and the v2 `widget.js`. Existing stores were set up with v2.

## Decision
`widget_version` selects v2 or v3 for the whole store. New installs default to `v3`
(`trunk/lib/utils/wc-yotpo-defaults.php:11`). In v3 mode no v2 widget is rendered, and a v3 widget renders only
when its instance id was synced from `GET /api/v2/widgets`. v2 code paths stay for stores that chose v2.
`wc_yotpo_show_widget()` stays as an alias of `wc_yotpo_show_reviews_widget()` for themes that call the old name
(commit `2b1b13a`).

## Alternatives Considered
- **Drop v2** — would break stores that never moved to v3.

## Consequences
- Enables: carousel, promoted products and reviews tab (v3-only).
- Constrains: every storefront change must handle both modes; a v3 store without synced ids shows nothing.
