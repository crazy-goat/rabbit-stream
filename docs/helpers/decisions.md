# Decisions — Notable Project Decisions with Rationale

**How to read this file:** load the tag index below, pick the tags that match
the files in your diff, then read only those `###` entries. Do not read the
whole file.

**Who writes here:** only the retro step. Implementation and review
subagents *propose* candidate entries in their report — they never append
(see [README.md](README.md)).

## Tag index

<!-- kb-index:start -->
- `ci` — DEC-003
- `conventions` — DEC-001, DEC-004
- `exceptions` — DEC-002
- `git-hooks` — DEC-003
- `lint` — DEC-003
- `naming` — DEC-001, DEC-004
- `policy` — DEC-002, DEC-003
- `protocol` — DEC-001
<!-- kb-index:end -->

## Architecture / behavior

### V1 suffix on every protocol command class
<!-- id=DEC-001 date=2025-05-20 tags=naming,protocol,conventions trigger="when adding a Request or Response class" hits=0 status=active -->

Every request/response class is suffixed `V1` (`SaslHandshakeRequestV1`,
`OpenResponseV1`). The protocol is versioned per command, not globally, so a
future `V2` of one command coexists with `V1` of another. Enum cases are
`SCREAMING_SNAKE_CASE`; key values are hex literals (`0x0001`,
`0x8011`). Response key = request key `| 0x8000` (except server-push frames —
see FAQ-002). Template in `AGENTS.md` → "Implementing a New Protocol Command".

### Custom exception hierarchy (fixed in #242)
<!-- id=DEC-002 date=2025-05-20 tags=exceptions,policy trigger="when throwing or catching protocol/connection errors" hits=0 status=active -->

The library no longer throws bare `\Exception`. The custom hierarchy
introduced in #242 (`ProtocolException`, `ConnectionException`,
`TimeoutException`, …) replaced the technical-debt note in `AGENTS.md`. New
code throws the specific exception class; `fromStreamBuffer()` still returns
`null` for *graceful* parse failure and throws only for hard protocol errors
via `assertResponseCodeOk()`. Do not reintroduce bare `\Exception`.

### Pre-push hook runs `bin/lint.sh`; lint is a gate, never lowered
<!-- id=DEC-003 date=2025-05-20 tags=ci,git-hooks,lint,policy trigger="when adding or editing bin/hooks/pre-push, bin/install-hooks.sh, or the lint scripts" hits=1 status=active -->

The pre-push hook (`bin/hooks/pre-push`, symlinked into `.git/hooks/` by
`bin/install-hooks.sh`) runs `bin/lint.sh` before every push. `bin/lint.sh`
lists every lint step. To skip the hook in an emergency, run
`git push --no-verify`. For code changes, CI runs the same script, so the
failure moves to CI. For documentation-only changes, CI does not run
`bin/lint.sh`, so `--no-verify` can let a broken `docs/helpers/` file through.

`ci-ok` is the only required check on `main`. It needs the jobs `changes`,
`docs`, `lint`, `unit-tests` (PHP 8.1 to 8.5) and `e2e-tests` from
`.github/workflows/ci.yml`. For code changes, all of them must succeed. For
documentation-only changes, `lint`, `unit-tests` and `e2e-tests` skip, and
`docs` decides.

Do not lower a gate, for example by disabling a linter rule or by relaxing
the PHPStan level, to make a round or a CI run green. A metric that improves
because its own check became weaker measures nothing.

Changed 2026-11-06 (#911): the text now matches `ci.yml`, the ruleset and `bin/lint.sh`.

### Branch naming: `<type>/issue-<N>-<slug>` on `main` (not `master`)
<!-- id=DEC-004 date=2025-05-20 tags=naming,conventions trigger="when creating a feature branch" hits=0 status=active -->

The default branch is **`main`**. `bin/worktree.sh <N>` creates the branch
`<type>/issue-<N>-<slug>` from `origin/main`. The `<type>` is one of `feat`,
`fix`, `docs`, `refactor`, `test` or `chore`. The script takes the type from
the second argument, or from the `type:*` label of the issue when no argument
is given. Use no other prefix. `bin/worktree-done.sh` finds the worktree by
the `/issue-<N>-` part of the branch name.

Changed 2026-11-06 (#911): the branch form now matches `bin/worktree.sh`. The `feature/` form and the ban on `feat/` are removed.
