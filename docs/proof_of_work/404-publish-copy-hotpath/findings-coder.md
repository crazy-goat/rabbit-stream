# 404 — findings-coder

## Obstacles

- `composer install` needed first (vendor/ not shared between worktrees) — expected, done.
- Most of issue #404 was already fixed by #492 on `main`; `git log -S toWire`
  confirms `PublishedMessage::toWire()` and the `pack()`-based `wrapFrame()`
  landed there. Had to diff the issue's acceptance criteria against the
  current code to find the remaining work (V2 publish path).

## Surprises

- The task branch `feature/issue-404-publish-copy-hotpath` was identical to
  `origin/main` with zero commits, yet `docs/proof_of_work/` has no `404-*`
  directory — so the issue was assigned fresh even though its V1 fixes were
  merged under a different issue (#492). Suggested fix for the workflow:
  cross-reference perf issues in the issue body when one closes another
  (e.g. "partially fixed by #493").

## Bugs noticed (out of scope)

| where | what | suggested fix |
|---|---|---|
| `src/VO/PublishedMessage.php:44` (`toWire()` error messages) | Copies of the validation constants (`UINT64_MAX`, `INT32_MAX`) now exist in two VO classes; drift risk. | Extract shared pack/validation into a small trait (e.g. `WireEncodeTrait`) in a follow-up. |
| `src/Request/PublishRequestV2.php:43` | `publisherId` range is validated in `toStreamBuffer()`, i.e. only at serialization time; `Producer::sendWithFilter()` increments `$this->publishingId` and pending counters only after `sendMessage` succeeds, but an oversized publisher id would throw *after* `applyBackpressure()` — harmless today since the id is always ≤255 by construction. | Move validation into the `PublishRequestV2` constructor for fail-fast symmetry with `PublishedMessage` (which validates only in `toWire()` too). |
| `src/Buffer/WriteBuffer.php:169` (`addArray`) | Still copies per-item via `$item->toStreamBuffer()->getContents()` — the generic pattern the issue complains about. No hot-path caller remains, but any future publish-like command reusing `addArray()` reintroduces the double copy. | Either document `addArray()` as non-hot-path or add the `writeTo()` variant later. |
| `src/Client/Producer.php:317` | `sendWithFilter()` rejects `null` filter values by substituting `''`, which *can* match an empty filter value on the broker; the docblock says null "never matches an active filter". Only matters if a consumer filters on `''`. | Verify against broker semantics; possibly encode `null` as a zero-length string deliberately and say so in the docblock. |

## Verification

- Byte-identical V2 wire output checked for empty/UTF-8/empty-body cases.
- `composer lint` (PHPCS + Rector dry-run + PHPStan level 9 + kb-lint): clean.
- `./vendor/bin/phpunit --testsuite unit`: OK, 1070 tests, 8190 assertions.
