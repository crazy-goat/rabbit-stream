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

### Pre-push hook runs `composer lint`; lint is a gate, never lowered
<!-- id=DEC-003 date=2025-05-20 tags=ci,git-hooks,lint,policy trigger="when adding or editing .git/hooks/pre-push, bin/install-hooks.sh, or the lint scripts" hits=0 status=active -->

`.git/hooks/pre-push` runs `composer lint` (PHPCS PSR-12 + Rector dry-run +
PHPStan level 9 + `bin/kb-lint.php`) before every push. To skip in an
emergency: `git push --no-verify` — CI will still say no, that is the point.
`composer lint` is also a required CI check on `main` (branch protection:
`lint`, `unit-tests (PHP 8.1-8.4)`, `e2e-tests`). Lowering a gate — disabling
a linter rule, relaxing the PHPStan level — to make a round or a CI run green
is forbidden; a metric improved by weakening its own check measures nothing.

### Branch naming: `feature/issue-NNN-<slug>` on `main` (not `master`)
<!-- id=DEC-004 date=2025-05-20 tags=naming,conventions trigger="when creating a feature branch" hits=0 status=active -->

This repository's default branch is **`main`** and its existing feature
branches all follow `feature/issue-NNN-<slug>`
(`feature/issue-9-delete-publisher`, `feature/issue-347-keyenum-fallback`).
Do **not** use `feat/`/`fix/` prefixes — the type lives in the commit message
(`feat`/`fix`/`docs`/…) and in issue labels, not in the branch name. A
`process/` prefix is reserved for changes to the workflow itself
(`docs/workflow.md`, `.github/workflows/*`, `composer.json` scripts,
`run-e2e.sh`, the git hooks).
