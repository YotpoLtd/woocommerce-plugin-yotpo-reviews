<!-- Read by the `yotpo-adlc:code-review` skill on every run. Freeform markdown —
     use whatever sections suit this repo. Delete this comment when done.

     To ADD rules, write your own sections, e.g.:
       ## Conventions      — this repo's own patterns (naming, structure, error handling etc.)
       ## Risk areas       — where a mistake is expensive because it has broken here before
       ## Always check     — conditions to flag on every PR, each with its severity
       ## Do not report    — categories that are always noise

     To OVERRIDE the skill instead of adding to it, reuse its own headings — what you
     put there replaces the default:
       ## Review offloading — how the review splits across sub-agents
       ## The review axes  — replace or extend correctness/readability/performance/architecture
       ## Score            — axis weights, severity points, the formula
       ## Verdict          — the score ranges, or when to REQUEST_CHANGES
       ## Output           — the shape of findings and what a review reports -->

# Code Review instructions

## Also read

Repo docs that are part of the review context, when present:

- `ARCHITECTURE.md` — module boundaries, system map, architectural decisions
- `docs/conventions.md` — patterns and anti-patterns
- `docs/troubleshooting.md` — known failure modes worth flagging in review
