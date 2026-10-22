#!/usr/bin/env bash
#
# Canonical full validation for this repository. Scaffold from
# `yotpo-common:generate-harness-docs`; the owning team writes the commands.
#
# Contract -- do not change it, the pipeline depends on it:
#   - run from the repository root, no arguments
#   - echo each command before running it
#   - exit 0  every check passed
#   - exit 1  a check failed
#   - exit 2  not configured / cannot validate -- NOT a pass
#   - never commit, never push, never mutate git state
#
# Exit 2 exists so "cannot validate" is never read as "failed" (hides a broken
# environment) or as "passed" (an unconfigured repo would earn ready-for-merge).
#
# TODO(ai-dlc): KNOWN DIVERGENCE FROM CI -- list here anything CI runs that this
# script cannot; such a PR can pass this script, earn the label, and still fail
# CI. If there is no divergence, say so -- do not delete the section.

set -euo pipefail

# TODO(ai-dlc): delete this block once the commands below are real.
echo "repo-validation: not configured -- this is still the scaffold." >&2
echo "  Nothing was validated and nothing may claim to have passed." >&2
exit 2

# TODO(ai-dlc): guard the preconditions a check needs to mean anything, and
# exit 2 -- not 1 -- when one is missing. The two most repos need:
#
#   [ -f "./SOME_MARKER" ] || { echo "repo-validation: not at repo root" >&2; exit 2; }
#   [ -n "${SOME_TOKEN:-}" ] || { echo "repo-validation: SOME_TOKEN not set -- environment not configured" >&2; exit 2; }

run() {
  echo "+ $*"
  "$@"
}

# TODO(ai-dlc): the commands that together mean "everything passed" -- compile,
# unit tests, linter -- ordered to fail fastest, each with a comment on what it covers.

run TODO_AI_DLC_THE_REAL_COMMAND

echo "repo-validation: all checks passed"
