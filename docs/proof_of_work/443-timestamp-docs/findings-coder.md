# Findings (coder) — #443 chunk-granular timestamp docs

## Obstacles / surprises

1. **The issue's code line references had drifted.** The issue cited
   `src/Client/OsirisChunkParser.php:66,:84-85,:106-107` for the "timestamp is
   copied onto every entry" claim and `tests/E2E/ConsumerTest.php:393-398` for
   the test comment. In the current tree the same facts live at:
   - `OsirisChunkParser.php:324` — the chunk header timestamp is read once;
   - `:210` (plain entry) and `:264` (sub-batch inner entry) — stamped on every
     entry in the raw path;
   - `:432` / `:483` — the zero-copy view path;
   - `tests/E2E/ConsumerTest.php:431-435` — the comment.
   I verified the semantics against the code, not the cited line numbers.

2. **The task's enumerated "four locations" did not include
   `docs/en/api-reference/message.md`**, which is the canonical reference for
   `Message::getTimestamp()` and the clearest instance of the bug in the issue
   title. I corrected it anyway (semantics + the `seconds` → `milliseconds`
   unit it was inseparably wrong about); it is not #418 scope. I also fixed the
   same false claim in the `consuming.md` §2 offset table and the
   `consumer.md` factory table, which the issue's stale `consumer.md:74`
   pointer referred to. This is consistency within #443, not #418's broader
   factory work.

3. **The internal FAQ-004 already had the correct semantics.** The task was
   essentially to move knowledge that already existed in `docs/helpers/faq.md`
   and one test comment out to user-facing docs. Per the knowledge-base rules,
   implementation subagents do not edit `docs/helpers/`; it was read only.

4. **`offset-tracking.md` and `consuming.md` examples derive the boundary from
   the client clock in seconds** (`time() - 86400`, `time() - 3600`). The
   seconds→milliseconds correction is explicitly #418's, so the examples were
   left as-is and the new prose warns against clock-derived boundaries instead.
   That does leave a visible tension in those two snippets until #418 lands.

## Discovered bugs / places to improve (out of scope for #443)

1. **More docs still state the per-message / wrong-unit claim.**
   - `docs/en/api-reference/value-objects.md:23` — `TYPE_TIMESTAMP` "Start from
     messages at or after a specific timestamp" (not chunk-granular).
   - `docs/en/api-reference/value-objects.md:122-142` — `OffsetSpec::timestamp()`
     factory docs and a client-clock ms example (this is #418 territory).
   - `docs/en/api-reference/value-objects.md:971`, `:991-1020` —
     `ChunkEntry::getTimestamp()` described as "Message timestamp"; `ChunkEntry`
     carries the same chunk-level value as `Message`.
   - `docs/en/guide/flow-control.md:633` — "Start from messages after timestamp".
   - `docs/en/protocol/consuming-commands.md:59` — same claim.
   - `docs/en/api-reference/connection.md:869` — "Start from a specific Unix
     timestamp".
   - `docs/en/guide/consuming.md:212` — `OffsetSpec::timestamp(time() - 3600)`
     seconds example (explicitly #418).
   - Suggested fix: fold into #418 (which already touches the `OffsetSpec`
     factory docs) plus a small follow-up for `value-objects.md`/protocol page.

2. **`message.md` `getCreationTime()` example has a ms/s unit bug.**
   `docs/en/api-reference/message.md:526-531` computes
   `$latency = $serverTime - $creationTime` and prints "seconds". Both values
   are milliseconds (AMQP `creation-time` is an AMQP timestamp, and
   `getTimestamp()` is now documented as ms), so the latency is off by 1000×.
   - Suggested fix: `intdiv($serverTime - $creationTime, 1000)` or relabel the
     output as milliseconds. Not fixed here — it is a units issue, adjacent to
     #418 and outside #443's chunk-granularity scope.

3. **`src/Client/Message.php` constructor parameter `$timestamp` is still
   untyped-documented internally.** The public accessor is now documented; the
   constructor/`fromRawEntry`/`fromChunkView` `$timestamp` params have no
   `@param` describing that it is the chunk timestamp. Not required by #443 and
   a `Message`-wide docblock pass is a separate job.

## Docblock gate — deliberately not added

Existing gates are whole-class reflection checks
(`ConnectionDocblockTest`, `ConsumerDocblockTest`, `ProducerDocblockTest`). A
gate for one method does not fit that pattern, and the thing #443 fixes is
prose accuracy, which reflection cannot assert. A class-level `OffsetSpec` gate
belongs with #418 (all six factories). Rationale also recorded in
`code-decision-1.md`.

## Verification run

```
./vendor/bin/phpunit --testsuite unit   → OK (1242 tests, 8793 assertions)
composer lint                            → OK
  - phpcs PSR-12 (src/tests/examples)    → pass
  - rector dry-run (DEAD_CODE etc.)      → pass (docblocks survived)
  - phpstan level 9 (275 files)          → no errors
  - kb-lint (docs/helpers)               → 12 entries, 0 warnings, 0 stale
  - check-docs-links (docs/en)           → all relative links resolve
  - test:suite-coverage                  → 150 files all covered
```

No E2E run: the change is documentation + PHPDoc only, no wire/protocol code.

## Candidate knowledge-base entries (for the retro; not written here)

- **Proposal:** "A doc-semantics bug is rarely in only the locations the issue
  lists — grep the whole `docs/` tree for the claim before closing." The issue
  named four files; at least six more carried the same per-message/chunk
  contradiction (see finding 1). A one-line `grep` for the phrase would have
  found them.
  - tags: `documentation`, `docblocks`, `offset`
  - trigger: "when fixing a documentation correctness bug, or reviewing a docs
    PR that corrects a claim"
  - gate: none (no automated gate fits prose); could be promoted from
    FAQ-004's existing entry instead of a new one.
- **Proposal (may already be covered):** FAQ-004 is `hits=1`; #443 is the
  second real-world hit (the first was #385). Worth bumping `hits` to 2 in the
  retro, no new entry needed.
