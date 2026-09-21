# Findings — Review

One table per review round, appended across rounds. `what happened to it` is
filled in by the coder in the next `code-decision-<n>.md` round; round 1
entries are open at the time of writing.

---

## Round 1 (commit 2992f03)

| # | File:Line | What is wrong | Severity | What happened to it |
|---|-----------|---------------|----------|-------------------|
| 1 | `docs/en/api-reference/value-objects.md:23`, `:971`, `:997`; `docs/en/guide/flow-control.md:633`; `docs/en/protocol/consuming-commands.md:59` | The same "start from messages after/at a timestamp" per-message claim this issue fixes still appears in unowned places. The four locations the issue names are all corrected, but a `grep -rn 'messages after\|messages at or after' docs/en` would have surfaced these. `value-objects.md` is #418 territory (VO factory page), but `flow-control.md:633` and `consuming-commands.md:59` are claimed by neither #418 nor #443 and will remain wrong after v1.5.0. The `ChunkEntry::getTimestamp()` "Message timestamp" wording is the analogue for `ChunkEntry` (not `Message`, so strict-scope-exempt but the same defect). No follow-up issue was filed despite `findings-coder.md` proposing one. | low | **Fixed here.** Chunk-granularity statement corrected at all five locations: `value-objects.md:23` (`TYPE_TIMESTAMP` → "first chunk whose chunk timestamp is `>=` the value, delivered in full"), `:971`/`:997` (`ChunkEntry` timestamp → "chunk timestamp shared by every entry of the chunk"), `flow-control.md:633`, `consuming-commands.md:59`, and `connection.md:869`. No `OffsetSpec` factory/`interval()`/unit documentation was added — that remains #418. |
| 2 | `docs/en/guide/consuming.md:220` | The "Choosing the Right Offset" box still recommends `OffsetSpec::timestamp(time() - 3600)` (client clock, seconds) ~30 lines below the new callout at `:189-195` that says to derive a boundary from broker-written `Message::getTimestamp()` values, "not the client clock". The unit correction itself is #418's, but the *contradiction* is introduced/aggravated by this PR. | low | **Deferred to #418 (v1.5.0).** Not fixed here per scope. #418 remains **OPEN** (verified `gh issue view 418`: state OPEN, milestone v1.5.0, "OffsetSpec factories are undocumented, and timestamp() units are documented wrongly (ms vs s)"). This line is explicitly the #418 unit fix. |
| 3 | `docs/en/api-reference/message.md:530-531` | `getCreationTime()` latency example subtracts `$serverTime = getTimestamp()` from `$creationTime` and prints "seconds"; both are milliseconds (AMQP `creation-time` is ms, and this PR documents `getTimestamp()` as ms). Off by 1000×, and now locally inconsistent with the same file's new `getTimestamp()` docs. Already wrong before the PR; #418-adjacent, not fixed. | low | **Deferred — follow-up candidate, out of #443 scope.** `docs/en/api-reference/message.md:530-531`: `$latency = $serverTime - $creationTime;` prints "seconds" while both operands are milliseconds. Suggested fix: `$latency = intdiv($serverTime - $creationTime, 1000);` (or relabel the output as ms). To be filed as a separate issue; not #418. |
| 4 | `tests/E2E/ConsumerTest.php:432` | Comment cites `OsirisChunkParser.php:66, :84-85` for where the chunk timestamp is stamped on every entry. Those lines have drifted; the stamping is now at `:324` (header read once), `:210` (plain raw), `:264` (sub-batch raw), `:432`/`:483` (zero-copy view). Semantics are correct and consistent with the new docs; only the references are stale. The coder noted the issue's own line refs had drifted but did not refresh this comment. | nit | **Fixed here.** Comment now cites `OsirisChunkParser.php:324` (header read once), `:210` (plain), `:264` (sub-batch), `:432`/`:483` (zero-copy view). |
| 5 | `docs/en/api-reference/connection.md:869` | `OffsetSpec::timestamp(int $timestamp)` described as "Start from a specific Unix timestamp". Not false, but silent on chunk granularity, so it does not reinforce the issue's main point. Optional. | nit | **Fixed here (same change as finding 1).** Now reads "Start at the first chunk with chunk timestamp `>=` the value, delivered in full (chunk-granular)". |

### Accuracy confirmation (no finding)

The load-bearing semantics are correct and were independently verified:

- "First chunk whose chunk timestamp is `>= $t`, delivered in full" matches the
  public RabbitMQ Streams documentation ("consumers attach at a chunk
  boundary and can receive messages published shortly before the specified
  timestamp") and the repo's E2E test.
- "Tie selects the earlier chunk" is the correct logical consequence of
  "*first* chunk with ts `>= $t`".
- "`getTimestamp()` is the chunk write time copied onto every entry including
  sub-batches" matches `OsirisChunkParser` (`:324` read once; `:210`, `:264`,
  `:432`, `:483` stamp every entry) and `Message::getTimestamp()`.
- The ms unit matches the int64 wire type.

### Scope confirmation (no finding)

#418 was not taken: no docblocks for `first/last/next/offset`, `interval()`
still undocumented, `consuming.md:212`/current `:220` seconds example and the
`time() - 86400` samples untouched. The only overlap — stating the millisecond
unit in the new `timestamp()` docblock — is required by #443 itself and leaves
#418's remaining criteria unsatisfied. #418 is still OPEN.

### No behaviour change

`git diff main...HEAD -- src/` is +42/-0, comments/docblocks only.

### Checks

`composer lint` → OK (phpcs, rector, phpstan L9, kb-lint, check-docs-links,
suite-coverage). `./vendor/bin/phpunit --testsuite unit` → OK (1242 tests,
8793 assertions).

---

## Round 2 (commit ca8993f) — convergence

Verification of round 1, plus a fresh grep sweep and `git diff main...HEAD`
new-issue pass. No new HIGH/MEDIUM/LOW finding; the observation below is
explicitly out of #443 scope. Full detail in `review-2.md`.

| # | File:Line | What is wrong | Severity | What happened to it |
|---|-----------|---------------|----------|-------------------|
| 1 | `docs/en/examples/basic-consumer.md:253` | `OffsetSpec::timestamp(time() - 3600)` under "// From a specific timestamp" is the same client-clock/seconds class as the #418-deferred `consuming.md:220`, and was not enumerated among round 1's remaining seconds examples. It does **not** state per-message semantics, so it is not #443's chunk-granularity subject; it belongs with #418's ms/s unit fix. | observation (not a #443 finding) | **Open only as input to #418.** No change required for #443. Sweep for it when landing #418. |

### Round-1 closure (verified)

| Round-1 item | Severity | Verified disposition |
|---|---|---|
| LOW 1 — same-class claims | low | **Fixed.** All six locations (`value-objects.md:23/:971/:997`, `flow-control.md:633`, `consuming-commands.md:59`, `connection.md:869`) now state chunk granularity; none claims per-message. |
| LOW 2 — `consuming.md:220` | low | **Deferred to #418**, still `OPEN` (verified `gh issue view 418`, milestone v1.5.0). Line unchanged as intended. |
| LOW 3 — `message.md:530-531` | low | **Recorded** as a follow-up candidate in `code-decision-2.md` (LOW 3) and round-1 row 3 above; not #443, not #418. |
| NIT 4 — E2E comment refs | nit | **Fixed.** `tests/E2E/ConsumerTest.php:432-434` now cites `OsirisChunkParser.php:324` (header read once), `:210`, `:264`, `:432`, `:483`; all match the code. |
| NIT 5 — `connection.md:869` | nit | **Fixed** (same change as LOW 1). |

### Grep / new-issue sweep (no open finding)

- `grep` over `docs/`, `src/`, `README.md` (excluding `proof_of_work/`) finds no
  remaining per-message / "published after" timestamp claim. Leftovers are
  unrelated ("reprocess up to N messages after crash"), historical
  (`CHANGELOG.md:75`, an old #155 release note), or #418's (`value-objects.md:124`
  factory section, `basic-consumer.md:253`, `message.md:538` imprecise "when
  received").
- `git diff main...HEAD`: docs/PHPDoc/comment only; no executable line, no gate
  weakened, no new bug.

### Verdict

**No open HIGH, MEDIUM or LOW finding for #443. Converged.**

`composer lint` → OK. `./vendor/bin/phpunit --testsuite unit` → OK (1242 tests,
8797 assertions).
