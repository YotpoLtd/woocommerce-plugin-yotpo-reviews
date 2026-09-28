---
type: ADR
title: ADR-0001 Release to WordPress.org by a manual SVN copy of trunk/
timestamp: 2026-09-28T11:47:58Z
---

# ADR-0001: Release to WordPress.org by a manual SVN copy of trunk/
- **Status:** Accepted
- **Date:** 2026-09-28 (recorded from `README.md:7-37` and the release commits, e.g. `7190794`, `4fc1d26`, `5eadb85`)

## Context
Merchants install the plugin from the WordPress.org plugin directory, which is an SVN repository
(`plugins.svn.wordpress.org/yotpo-social-reviews-for-woocommerce`). WordPress.org serves the version named by
`Stable tag` in `readme.txt`, and its listing images come from the SVN `assets/` folder.

## Decision
GitHub holds the source; a release is a manual step. A maintainer checks out the SVN repository, copies this
repository's `trunk/` into SVN `trunk/`, bumps the `Version:` header in `trunk/wc_yotpo.php`, `Stable tag` (and
`Tested up to` / `Requires`) plus the `== Changelog ==` section in `trunk/readme.txt`, and the root `changelog`,
then runs `svn ci` with the Yotpo WordPress.org account. The root `assets/` folder mirrors SVN `assets/`.

## Alternatives Considered
- **Deploy from GitHub Actions** — not set up; the repository has no workflows.

## Consequences
- Enables: merging to `master` never ships to merchants by itself.
- Constrains: the version header and `Stable tag` must match, or WordPress.org serves the wrong version (commits
  `5eadb85`, `4fc1d26` fixed exactly that). Only `trunk/` ships; files at the repository root (harness docs,
  `.agents/`, `.claude/`) never reach merchants.
- The SVN password is kept on Confluence, never in this repository.
