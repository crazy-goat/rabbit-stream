# Findings — coder

Appended across rounds by the implementing agent. Obstacles, surprises, and
bugs noticed in passing — including ones outside this cycle's scope.

## This cycle (workflow / KB / PoW / pre-push bootstrap)

- **Pre-existing PHPStan level-9 `chr()` failures — FIXED in this cycle.**
  29 errors across `tests/Client/AmqpDecoderMessageTest.php`,
  `tests/Client/AmqpMessageDecoderTest.php`,
  `tests/E2E/AmqpMessageDecoderE2ETest.php`. The AMQP test-frame builders
  encoded single-byte fields (sizes, counts, descriptors, smalluint values)
  with `chr()` but passed `int<0, max>` from `strlen`/multiplication/unguarded
  `int` params. Fixed by narrowing the underlying type, not silencing:
  `buildSection(int $descriptor)` got `@param int<0, 255>` (the smallulong
  descriptor is a byte by definition); `chr(strlen(...))`, `chr($size)`,
  `chr($count)`, `chr($count * 2)` masked with `& 0xFF` (genuinely narrows to
  a byte — str8/list8/map8 are single-byte-length by spec). `composer phpstan`,
  `composer lint`, and `composer test:unit` (632 tests) all green after the
  fix. The pre-push hook now passes clean — proof the gate works end to end.
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
- **Pre-push hook verified end to end** — first push (before the `chr()` fix)
  was correctly blocked by the hook with the PHPStan errors printed; after the
  fix, `git push` (no `--no-verify`) passed with `pre-push: lint clean.`.
  The gate is real.

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
