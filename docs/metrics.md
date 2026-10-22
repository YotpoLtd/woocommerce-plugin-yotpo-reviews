---
type: Metrics
title: woocommerce-plugin-yotpo-reviews — AI-DLC Metrics
timestamp: 2026-10-22T00:00:00Z
---

# AI-DLC Metrics — woocommerce-plugin-yotpo-reviews

The three AI-DLC metrics for this repo. Definitions follow the standard (CTO-525);
the measurement method is `gh-approx-v1` until the infra metrics pipeline ships.

| Metric | Definition | Method |
|---|---|---|
| PR throughput | Merged PRs per week (90-day window) | GitHub search: `is:pr is:merged merged:>=<window>` |
| Cycle time | Median hours from PR open to merge | 50 most recent merged PRs |
| Auto-merge % | Share of merged PRs landed by auto-merge | `auto_merge` metadata on the sample |

## Baseline

Captured once at onboarding; the scorecard compares live measurements against it.

```
captured: 2026-10-22
pr_throughput_per_week: 1.87
cycle_time_median_hours: 3.0
auto_merge_pct: 0
```

Measured over the 50 most recently merged PRs (24 of them inside the 90-day window).

`auto_merge_pct` is zero by construction, not by accident: `allow_auto_merge` is off on the
repository and `master` requires no status checks, so no PR has ever been able to land by
auto-merge. It is the metric to watch once those two settings change — see the to-do list in
[`.agents/adlc/`](../.agents/adlc/).

## North star (Gold)

Cycle time ≤ 2× org median · auto-merge % meaningfully above zero.
