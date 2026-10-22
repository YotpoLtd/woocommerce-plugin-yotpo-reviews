<!-- Read by the `yotpo-adlc:blast-radius` skill on every run. This file is the repo's
     blast-radius profile — the repo-specific half of the scoring method (the generic half
     lives in the skill). The skill scores a diff 0-100 by five factors: change type,
     reach, criticality, history, spread.

     Required keys: entryPoints, areas, imports, hotspots, generatedFiles.
     `hotspots` and `generatedFiles` may be empty lists, but must be present.
     If a required key is missing, the skill stops and names it — it never guesses.

     Fill in the {placeholders} below and delete this comment when done. -->

# Blast Radius profile

## entryPoints

<!-- How to list the code an outside actor invokes directly — the roots of the import
     graph the reach trace walks toward. For a web app: the route/page files. For a
     service: HTTP handlers, queue/stream consumers, cron jobs. Prefer a framework
     manifest when one exists; otherwise a glob. -->

glob: {glob, or a framework manifest if one exists}

## areas

<!-- How files group into named parts of the codebase (used by the spread factor and the
     report). List each feature/product area plus a shared/core area — a change in core
     is cross-cutting by construction. -->

- {area}: {path prefix}
- core: {shared/core path prefix}

## imports

<!-- How one module names another, so the reach trace can find importers by text search:
     the alias map (e.g. "@/" -> "src/", "#core/" -> "src/core/"), and anything unusual
     about relative or barrel/re-export imports. -->

aliases: { "{alias}": "{real path}" }

## hotspots

<!-- Files that effectively reach everything, so the trace is skipped and reach and
     criticality are forced to 1.0: the root layout/document, app bootstrap, router
     config, global/auth middleware. A wrapper covering only part of the app is NOT a
     hotspot — let the trace measure it. May be an empty list. -->

- {path}

## generatedFiles

<!-- Files a tool writes, not a person (route manifests, generated clients/types).
     Ignored by the scoring in every role. May be an empty list. -->

- {glob}

## tables

<!-- Optional but recommended: a row here replaces the skill's generic row with the same
     value, in this repo's own vocabulary. Delete this section if the generic tables fit
     as-is. -->

### criticality

<!-- How bad is a failure where the change sits? Fill each tier in this repo's own terms. -->

- 1.0: {blocks a user, no retry budget — e.g. request middleware, user-facing handler/route}
- 0.7: {blocks an internal caller, or user-visible before recovery — e.g. internal RPC, server-rendered component}
- 0.4: {recoverable after failure — e.g. async consumer, client-only interactive component}
- 0.2: {re-runnable or isolated — e.g. batch/cron, lazily loaded feature}
- 0.1: {never on a user's path — e.g. dev tooling, fixtures, mocks}

### change-type

<!-- How risky is this kind of change by nature? Name this repo's own files and patterns per tier. -->

- 0.95: {irreversible or trust-breaking — e.g. this repo's destructive migrations, auth/session logic, published contracts/schemas, feature-flag guards, wired CI checks}
- 0.75: {shared/core infrastructure — e.g. bootstrap, global middleware, router/client config, new external dependency}
- 0.50: {non-trivial logic on a hot path; a refactor without test coverage}
- 0.30: {contained feature work with tests}
- 0.15: {copy/translations, config/env tweaks, log messages}
- 0.05: {comments, docs, formatting, dead-code removal}

<!-- Other optional keys, add as needed:
       ## ignoreAsImporters — authored barrels/registries that import everything; never
                              counted as importers by the reach trace
       ## testFiles         — only if test conventions differ from the skill's default
                              (**/*.test.*, **/*.spec.*, __tests__/, test(s)/)
       ## overrides         — change any of the skill's named constants or weights -->
