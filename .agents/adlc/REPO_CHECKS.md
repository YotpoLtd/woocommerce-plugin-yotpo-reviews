<!-- Scaffold from `yotpo-common:generate-harness-docs`. Read by the
     `yotpo-adlc:code-review-fixer` skill and this repo's full-validation step:
     it names the commands an agent runs, per kind of change.

     To fill in: replace every TODO(ai-dlc) line. Keep the section headings and
     the rules under them verbatim -- they are the contract the fixer reads.
     These HTML comments are guidance only; keep or delete them.

     Only commands named here run. An unfilled file means the fixer runs nothing
     and says so -- an agent must never infer a command from a package manifest. -->

# Repository check instructions

<!-- ## Environment
     One bullet each:
       - The pinned toolchain, and where each pin lives (version file, build-tool
         wrapper, or the image).
       - Env vars/credentials without which the build cannot even *configure* --
         and the error message that means "credentials missing", not "code broken";
         an agent that cannot tell those apart will try to fix working code.
       - The agent image (e.g. `Dockerfile.agent`), and that it is not the service
         runtime image. -->

## Environment

- TODO(ai-dlc): the toolchain, pinned, and where each piece comes from.
- TODO(ai-dlc): the variables or credentials the build needs to configure at all.
- TODO(ai-dlc): the agent image, and that it is not the runtime image.

<!-- ## Always, on every changed file
     The formatter and the linter. Name each exactly, or state there is none --
     "no formatter; do not add one or reformat as a side effect" is a real
     answer, silence reads as "nobody wrote this yet". Say whether a violation
     fails the build, whether the linter can self-fix, which repo-specific rules
     agents trip most, and where the config and suppression list live.
     The three rules below hold whatever the commands are. Keep them. -->

## Always, on every changed file

TODO(ai-dlc): the formatter, or an explicit statement that there is none.

TODO(ai-dlc): the linter, and whether a violation fails the build.

```
TODO(ai-dlc): the command
```

TODO(ai-dlc): whether the linter can fix violations itself, and which repo-specific
rules an agent is most likely to trip.

TODO(ai-dlc): where the linter config and its suppression list live.

- **A violation your own edit introduced is part of the finding you are fixing** --
  iterate until it is clean. Never report a finding fixed while its checks are red, and
  never weaken or silence a check to get a commit through.
- **Pre-existing violations in code you did not touch are out of scope.** Leave them;
  fixing them widens the diff past the finding.
- **Reverting is the last resort, not the first move.** Only when the retry budget in
  the `code-review-fixer` skill is spent -- the rule is unclear, or satisfying it would
  change behaviour -- back that finding's edit out and report it unfixed, with the
  check's own message as the reason.

<!-- ## By kind of change
     One row per repo area: its paths -> the narrowest covering command. Keep the
     build-scripts row (it can break any area, so it runs the full build) and the
     docs row ("nothing" is a real answer that stops invented work). -->

## By kind of change

Run the narrowest thing that covers what you touched.

| Change touches | Run |
|---|---|
| `TODO(ai-dlc)/**` | `TODO(ai-dlc)` |
| TODO(ai-dlc): the build scripts | TODO(ai-dlc): the full build -- a build-script change can break any area |
| Markdown, `docs/**` only | nothing |

A change spanning several areas runs each area's row, not the full build.

<!-- ## Full validation
     Keep as is unless the canonical entrypoint is genuinely named differently.
     The exit-code contract lives in the script's own header. -->

## Full validation

```
bash .agents/adlc/repo-validation.sh
```

The canonical pre-merge run, and the single definition of "everything passed" -- see the
script's header for the exit-code contract. Not part of the fix loop: run it once at the
end of a pass.

<!-- ## Do not run
     Give the *why* for each exclusion. The last two bullets hold for every
     repository -- keep them. -->

## Do not run

- **TODO(ai-dlc): anything needing infrastructure this environment does not have** --
  integration or component suites that boot databases, brokers or other services. They
  belong to CI, not to a fix loop.
- **TODO(ai-dlc): dependency re-resolution** unless a dependency actually changed -- it
  re-fetches the whole graph over the network every time.
- **Anything that writes to the default branch**, publishes an artifact, pushes an image,
  or triggers a deploy. TODO(ai-dlc): name this repository's versions of those commands.
- **`git push --force`**, and any rebase to sync the branch.
