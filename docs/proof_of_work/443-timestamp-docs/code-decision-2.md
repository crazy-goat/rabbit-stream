# Code Decision 2 — #443 review round 1

Round 1 (commit `2992f03`) found no HIGH/MEDIUM issues: the chunk-granular
semantics shipped in round 1 are accurate, #418 was respected, and there is no
behaviour change. Three LOWs and two NITs were raised, all about the
*completeness of the same-class sweep* rather than the correctness of the fix.
This round addresses them.

## Per-finding disposition

### LOW 1 — same per-message claim survives in unowned docs — FIXED

The chunk-vs-per-message defect was corrected in the four files the issue names,
but the same class of claim survived elsewhere. Fixed here (granularity wording
only):

| File:Line | Before | After |
|---|---|---|
| `docs/en/api-reference/value-objects.md:23` | `TYPE_TIMESTAMP` "Start from messages at or after a specific timestamp" | "Start at the first chunk whose chunk timestamp is `>=` the value (chunk-granular, delivered in full)" |
| `docs/en/api-reference/value-objects.md:971` | `$timestamp` "Message timestamp (Unix timestamp in milliseconds)" | "Chunk timestamp shared by every entry of the chunk (milliseconds since the Unix epoch)" |
| `docs/en/api-reference/value-objects.md:997` | `getTimestamp()` "Returns the message timestamp (Unix timestamp in milliseconds)." | "Returns the chunk timestamp (milliseconds since the Unix epoch) shared by every entry of the chunk." |
| `docs/en/guide/flow-control.md:633` | "Start from messages after timestamp" | "Start at the first chunk with chunk timestamp >= the value (chunk-granular)" |
| `docs/en/protocol/consuming-commands.md:59` | "Start from messages after timestamp (followed by uint64 ms)" | "Start at the first chunk with chunk timestamp >= the value (chunk-granular), followed by uint64 ms" |
| `docs/en/api-reference/connection.md:869` | "Start from a specific Unix timestamp" | "Start at the first chunk with chunk timestamp `>=` the value, delivered in full (chunk-granular)" |

Scope discipline: only the **granularity statement** was corrected. No
`OffsetSpec` factory documentation (`first`/`last`/`next`/`offset`/`interval()`)
and no ms/s unit correction was added — those remain #418.

### LOW 2 — `docs/en/guide/consuming.md:220` seconds example — DEFERRED (#418)

`OffsetSpec::timestamp(time() - 3600)` is the #418 unit bug (v1.5.0). Not fixed
here. #418 was re-checked and is still **OPEN**:

```
gh issue view 418 --json number,title,state,milestone
→ state: OPEN, milestone: v1.5.0,
  title: "Docs: OffsetSpec factories are undocumented, and timestamp() units
          are documented wrongly (ms vs s)"
```

### LOW 3 — `docs/en/api-reference/message.md:530-531` ms labelled seconds — DEFERRED (separate issue)

Out of #443 scope. Not fixed here; recorded as a follow-up candidate:

- Location: `docs/en/api-reference/message.md:530-531`
- Bug: `$latency = $serverTime - $creationTime;` subtracts two millisecond
  values (AMQP `creation-time` is ms; `Message::getTimestamp()` is documented as
  ms) and prints `"Message latency: {$latency} seconds"` — off by 1000×.
- Suggested fix: `$latency = intdiv($serverTime - $creationTime, 1000);`
  (or relabel the output as milliseconds).

To be filed as its own issue; it is not part of #418.

### NIT 4 — stale `OsirisChunkParser.php` line refs — FIXED

`tests/E2E/ConsumerTest.php:432` cited `OsirisChunkParser.php:66, :84-85`. The
comment now cites the real stamping sites: `:324` (header read once), `:210`
(plain raw), `:264` (sub-batch raw), `:432`/`:483` (zero-copy view).

### NIT 5 — `connection.md:869` — FIXED

Covered by LOW 1 above.

## Change character

Documentation and comments only. `git diff` touches 4 English doc pages, one
test comment, plus the proof-of-work files. No executable line changed, no gate
weakened.

## Checks

- `./vendor/bin/phpunit --testsuite unit` → OK
- `composer lint` → OK (phpcs PSR-12, rector dry-run, PHPStan level 9, kb-lint,
  check-docs-links, suite-coverage)
