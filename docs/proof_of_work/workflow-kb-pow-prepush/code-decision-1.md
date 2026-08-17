# Code decision 1 — workflow / KB / proof-of-work / pre-push hook

**Cycle:** process/workflow-kb-pow-prepush (no dedicated issue number; this is
the infrastructure bootstrap for `docs/workflow.md`).
**Branch:** `process/issue-398-workflow-knowledge-base-proof-of-work-prepush`

## What was asked

Adapt the workerman-bundle `docs/workflow.md` to rabbit-stream, then actually
add the three mechanisms that workflow references but this project lacked:
the knowledge base, the proof-of-work layout, and the pre-push lint hook.

## Approach taken

1. **Knowledge base** — ported the workerman-bundle `docs/helpers/` shape
   verbatim: `README.md` (rules, front matter grammar, decay), `faq.md` and
   `decisions.md` seeded with rabbit-stream-specific entries, and
   `bin/kb-lint.php` adapted for PHP 8.1 compatibility (the upstream uses
   first-class callable syntax `tokenSet(...)` which is 8.3+; this repo's
   `composer.json` declares `php: >=8.1`, so I used `array_map('tokenSet', …)`
   string-callback form instead).
2. **Proof of work** — created `docs/proof_of_work/README.md` mirroring the
   upstream rationale, including the "why there is no tool" section
   (`pow.php`/`check-pow.php` were ~7,300 lines in workerman-bundle and not
   worth it). No validation script — by design.
3. **Pre-push hook** — git does not version `.git/hooks/`, so I created a
   versioned `bin/hooks/pre-push` plus `bin/install-hooks.sh` that symlinks
   it into `.git/hooks/`. The hook runs `composer lint` (now including
   `kb-lint`) and writes its log to `/tmp/rabbit-stream-prepush-lint.log`.
   Bypass via `--no-verify`; CI runs the same checks.
4. **Wiring** — added `kb-lint` to `composer lint` and `kb-lint --fix` to
   `composer lint:fix`; added `composer kb-lint` / `kb-lint:fix` shortcuts;
   added `.gitattributes` `export-ignore` for `bin/`, `docs/helpers/`,
   `docs/proof_of_work/`; updated `AGENTS.md` (QA Commands + new Git Hooks
   section) and `docs/workflow.md` to point at the now-existing mechanisms
   instead of saying "brak"; added a `bin/README.md`.

## What was rejected and why

- **Husky** — workerman-bundle used `.husky/pre-push`. This project has no
  husky dependency and adding one for a single hook is overkill. Native git
  hooks + a 30-line install script are enough.
- **A `bin/pick-issue.php` / `bin/gh-branch` port** — the user asked for the
  three mechanisms (KB, PoW, pre-push), not the full helper suite. The
  workflow still documents the inline `gh`+`git` equivalent. These can be
  added later as separate issues.
- **Seeding the KB with many entries** — I added only 3 FAQ + 4 DEC entries,
  all grounded in real rabbit-stream facts (port 5552, server-push key rule,
  `gh` 30-limit, V1 suffix, #242 exceptions, branch convention, the new
  pre-push gate). The KB is meant to grow via the retro step, not be filled
  upfront.
- **A `bin/check-pow.php`** — explicitly rejected by the upstream's own
  postmortem and by the PoW README I wrote. No tool.

## What I was unsure about

- Whether to register `kb-lint` as its own CI job in `.github/workflows/ci.yml`
  or keep it inside `composer lint` (which already runs in the `lint` job). I
  chose the latter — it runs automatically and reuses the existing required
  check. If the KB grows and a dedicated job is wanted, that is a separate
  change.
- The pre-push hook writes its log to `/tmp/`. That is fine for a
  single-machine dev flow; if multi-user, a `$TMPDIR`-relative path would be
  cleaner. Left as-is for simplicity.
- `composer lint` is currently **not green** due to pre-existing PHPStan
  level-9 errors in `src/Buffer/ReadBuffer.php` (`chr()` argument range).
  These are unrelated to this change (my diff does not touch `src/`) and
  predate it. The pre-push hook will therefore block pushes until those are
  fixed — which is arguably correct behavior, but worth flagging to the
  maintainer. `kb-lint` alone is clean.
