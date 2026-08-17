# Findings — coder

Appended across rounds by the implementing agent. Obstacles, surprises, and
bugs noticed in passing — including ones outside this cycle's scope.

## This cycle (workflow / KB / PoW / pre-push bootstrap)

- **Pre-existing PHPStan level-9 failures in `src/Buffer/ReadBuffer.php`** —
  29 errors, all `chr()` argument range
  (`Parameter #1 $codepoint of function chr expects int<0, 255>, int<0, max>
  given`). Lines 99, 120, 123, 137 (and more). These are unrelated to this
  change — my diff does not touch `src/` — but they make `composer lint` (and
  therefore the new pre-push hook) non-green. **Suggested fix:** narrow the
  `chr()` argument with a `& 0xFF` mask or an explicit range check, e.g.
  `chr($byte & 0xFF)`. Worth a dedicated `bug`/`code-quality` issue. Not fixed
  here because it is out of scope and would entangle a process-only PR with a
  `src/` change.
- **No `.gitattributes` existed at all** — `composer archive` / GitHub tarballs
  would have shipped `tests/`, `docs/`, etc. This change adds one with
  `export-ignore` for `bin/`, `docs/helpers/`, `docs/proof_of_work/`. A
  follow-up could also exclude `tests/` and `docs/` from the distributed
  package if desired — left as a separate decision.
- **`composer.json` `scripts` had no `kb-lint` entries** — added
  `php bin/kb-lint.php` to the `lint` chain and `php bin/kb-lint.php --fix` to
  `lint:fix`, plus standalone `composer kb-lint` / `kb-lint:fix`. Verified
  `composer kb-lint` runs clean (7 entries, 0 warnings).
- **First manual tag index for `decisions.md` was out of sync** — I wrote the
  `<!-- kb-index:start -->` block by hand and missed a tag mapping. `kb-lint`
  caught it immediately (`tag index is out of sync with the entries (run
  --fix)`), `--fix` regenerated it correctly, second run clean. Good
  confirmation that the linter does its job.

## Out-of-scope observations (not fixed, candidate issues)

- **`README.md` Quick Start references `CrazyGoat\RabbitStream\Client\Connection`**
  but the `src/` tree documented in `AGENTS.md` has no `Client/` subdirectory
  listed. Likely the high-level API lives somewhere not reflected in the
  AGENTS.md directory map. Worth reconciling AGENTS.md ↔ actual `src/` layout
  in a docs issue.
- **`docs/` mixes user docs (`en/`, `index.md`) with internal artifacts**
  (`issue-50-debug-summary.md`, `plans/`). Issue #436 already tracks this
  ("docs/ hygiene — internal debug artifacts and stale plans sit alongside
  user documentation"). Confirmed still present.
