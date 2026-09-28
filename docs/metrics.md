---
type: Metrics
title: woocommerce-plugin-yotpo-reviews — AI-DLC Metrics
timestamp: 2026-09-28T11:47:58Z
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
TODO(ai-dlc): run the scorecard once and copy its measured values here, then delete
this TODO line.

```
captured: {YYYY-MM-DD}
pr_throughput_per_week: {number}
cycle_time_median_hours: {number}
auto_merge_pct: {number}
```

## North star (Gold)

Cycle time ≤ 2× org median · auto-merge % meaningfully above zero.
