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
# KNOWN DIVERGENCE FROM CI -- list here anything CI runs that this
# script cannot; such a PR can pass this script, earn the label, and still fail
# CI. If there is no divergence, say so -- do not delete the section.
#
# - There is no CI in this repository (no .github/workflows/), so there is
#   nothing to diverge from yet, and also no check to mirror.
# - The real external gate is WordPress.org Plugin Check (commit 91dfc0b). How
#   it is run is not written down in this repository, so it does not run here.
# - Using the plugin needs a WordPress + WooCommerce install with a database;
#   not available here.
#
# TODO(ai-dlc): the owning team names the checks (e.g. the Plugin Check
# command) and replaces the block below. Until then this script validates
# nothing and exits 2.

set -euo pipefail

if [ ! -f "./trunk/wc_yotpo.php" ] || [ ! -f "./trunk/readme.txt" ]; then
  echo "repo-validation: not at repo root ($(pwd)) -- run from the repository root" >&2
  exit 2
fi

# TODO(ai-dlc): delete this block once the commands below are real.
echo "repo-validation: not configured -- no check command is defined in CI, scripts or docs." >&2
echo "  Nothing was validated and nothing may claim to have passed." >&2
exit 2

run() {
  echo "+ $*"
  "$@"
}

# TODO(ai-dlc): the commands that together mean "everything passed" -- ordered
# to fail fastest, each with a comment on what it covers.

run TODO_AI_DLC_THE_REAL_COMMAND

echo "repo-validation: all checks passed"
