# Code Decision 1 — #443 chunk-granular timestamp docs

## Problem

`OffsetSpec::timestamp()` and `Message::getTimestamp()` had no PHPDoc, and the
user-facing docs described both as per-message:

- `OffsetSpec::timestamp($t)` was sold as "start from messages published after
  `$t`". It does not: the broker resolves it to the **first chunk whose chunk
  timestamp is `>= $t`** and delivers that chunk **in full**.
- `Message::getTimestamp()` was described as the message's own publish time.
  It is the **chunk** write time, copied onto every entry of the chunk.

This cost #385 two wrong diagnoses, so the semantics need to be visible to
library users, not only in an internal FAQ (FAQ-004) and an E2E test comment.

## Code cross-check (what is actually true)

Verified against the implementation before writing any prose:

- `src/Client/OsirisChunkParser.php:324` reads the chunk header timestamp once
  (`$buffer->getInt64()`, ms since epoch).
- That single value is stamped onto **every** entry: plain entries at
  `:210`, sub-batch inner entries at `:264` (raw path), and the zero-copy view
  path at `:432`/`:483`. There is no per-message timestamp anywhere.
- `src/Client/Message.php` stores the value verbatim and `getTimestamp()`
  returns it.
- `tests/E2E/ConsumerTest.php:431-435` (the issue cited `:393-398`; the line
  numbers drifted as the file grew) documents the broker behaviour as "first
  chunk with chunkTs >= $t, delivered in full" and derives its boundary from
  the read-back `getTimestamp()` rather than the clock.

Conclusion: the issue's description is accurate. The `>=` is on the chunk
timestamp, and a tie selects the earlier chunk (the first chunk whose
timestamp is not less than `$t`).

## Change

Documentation + PHPDoc only — no behaviour change.

1. **`src/VO/OffsetSpec.php::timestamp()`** — added a docblock stating the
   first-qualifying-chunk resolution, full-chunk delivery, the `>=` tie
   selecting the earlier chunk, and the practical rule to derive the boundary
   from broker-written `Message::getTimestamp()` values. `@param`/`@return`
   carry descriptions (FAQ-008: Rector strips a description-less tag).
2. **`src/Client/Message.php::getTimestamp()`** — added a docblock stating it
   is the chunk write time copied onto every entry (including sub-batches),
   not a per-message time, and repeating the boundary consequence.
3. **`docs/en/guide/offset-tracking.md`** (§5 Timestamp) — replaced the false
   "published after" claim with a chunk-granular callout.
4. **`docs/en/guide/consuming.md`** — corrected the timestamp example comment
   and added the callout; corrected the `getTimestamp()` comment in the
   Message-object example; corrected the same false claim in the §2 offset
   table so the page does not contradict itself.
5. **`docs/en/api-reference/consumer.md`** — corrected the
   `OffsetSpec::timestamp()` table row (the issue cited line 74; the claim had
   moved to the factory table) and added a short note.
6. **`docs/en/api-reference/message.md`** — the canonical
   `Message::getTimestamp()` reference said "when the message was published"
   and "seconds since epoch"; corrected to the chunk timestamp in
   milliseconds, with the boundary rule.
7. **`CHANGELOG.md`** — `[Unreleased]` → `Changed` entry.

## Scope discipline — deliberately NOT done (#418)

The task explicitly ring-fenced #418, which covers the broader `OffsetSpec`
documentation. Left untouched:

- No docblocks for the other four `OffsetSpec` factories (`first`, `last`,
  `next`, `offset`), and `interval()` is not documented at all.
- No ms/s unit correction at `docs/en/guide/consuming.md:212`
  (`OffsetSpec::timestamp(time() - 3600)`), and the seconds examples in
  `offset-tracking.md` / `consuming.md` were left as-is.
- The `OffsetSpec::timestamp()` docblock *mentions* milliseconds because the
  chunk semantics cannot be stated without the unit, but it does not attempt
  #418's unit correction of the guide examples.

Also left for follow-up (noted, not changed): `docs/en/api-reference/value-objects.md`
(`OffsetSpec` factory table and `ChunkEntry::getTimestamp()` as a "message
timestamp"), `docs/en/guide/flow-control.md:633`,
`docs/en/protocol/consuming-commands.md:59`, `docs/en/api-reference/connection.md:869`,
and the `getCreationTime()` latency example in `message.md` (a ms/s unit bug).
These overlap #418 or are separate; they are listed in `findings-coder.md`.

## Docblock gate — deliberately not added

Existing gates (`ConnectionDocblockTest`, `ConsumerDocblockTest`,
`ProducerDocblockTest`) are whole-class reflection gates. A gate for a single
method would not fit that pattern, and the accuracy being fixed here is prose
that reflection cannot assert. The broader class-level gate for `OffsetSpec`
naturally belongs with #418, which documents all six factories. Adding a
partial gate now would create a file #418 then has to rework.
