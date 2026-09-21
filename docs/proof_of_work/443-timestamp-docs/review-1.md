# Review Round 1 — Issue #443 (docs + PHPDoc, branch `feature/issue-443-timestamp-docs`)

**Commit:** 2992f03 — `docs: document OffsetSpec::timestamp() and Message::getTimestamp() as chunk-granular (closes #443)`
**Reviewer:** review agent
**Date:** 2026-09-21
**Diff:** `git diff main...HEAD` — 2 `src/` files (docblocks only, +42 lines, 0 deletions), 4 English doc pages, `CHANGELOG.md`, plus two proof-of-work files.

---

## Verdict

**No HIGH or MEDIUM findings.** Three LOW findings and two NITs, all about
*completeness of the same-class sweep* or units in adjacent examples — none
about the accuracy of the semantics this issue exists to fix. The change ships
in v1.4.0 as-is; the LOWs are recommendations for this PR or a tracked
follow-up.

The central claims are **accurate**, all four locations the issue names are
fixed, #418 scope was respected, and there is no behaviour change.

---

## Scope and methodology

1. Read `AGENTS.md`.
2. Read issue #443 and issue #418 (`gh issue view ... --json title,body,milestone`).
3. Read `src/Client/OsirisChunkParser.php` (the timestamp stamping paths),
   `src/Client/Message.php`, `src/VO/OffsetSpec.php`, `src/Client/ChunkEntry.php`.
4. Read the entire diff against `main`, all four changed docs in full, and the
   unchanged docs that carry the same class of claim.
5. Cross-checked the broker-side rule against the public RabbitMQ Streams docs
   (chunk-boundary wording) in addition to the repo's own FAQ-004 and E2E test.
6. Read `tests/E2E/ConsumerTest.php::testSubscribeFromTimestamp()`.
7. Ran `composer lint` and `./vendor/bin/phpunit --testsuite unit`.

---

## Accuracy verification (the crux)

### `OffsetSpec::timestamp($t)` — "first chunk whose chunk timestamp is `>= $t`, delivered in full"

Accurate. The client does not resolve it (the broker does), so the check is
against the broker's documented behaviour, the repo's E2E test, and FAQ-004:

- RabbitMQ Streams docs: *"Since streams are segmented into chunks that share a
  single timestamp, consumers attach at a chunk boundary and can receive
  messages published shortly before the specified timestamp."* This confirms
  the two load-bearing words — **chunk boundary** and **delivered in full**.
- `tests/E2E/ConsumerTest.php::testSubscribeFromTimestamp()` (comment at
  `:431-435`) derives the boundary from the read-back `getTimestamp()` and
  asserts delivery of the "after" batch — consistent with `>=`.
- The **tie selects the earlier chunk** statement is a correct logical
  consequence of "the *first* chunk whose timestamp is `>= $t`": a chunk whose
  timestamp equals `$t` itself qualifies, so it is selected over any later
  chunk. The wording ("selects the *earlier* chunk") is a little compressed but
  not wrong.

### `Message::getTimestamp()` — chunk write time copied onto every entry

Accurate, verified line-by-line:

- `OsirisChunkParser::parseChunkHeader()` reads the header timestamp **once**
  (`:324`, `getInt64()`, ms since epoch).
- The single value is yielded for every entry: plain entries `:210`,
  sub-batch inner entries `:264`, and on the zero-copy view path `:432`/`:483`.
- `Message` stores it verbatim and `getTimestamp()` returns it (`:283-286`);
  `AmqpMessageDecoder::decode()` passes `$entry->getTimestamp()` through.
- No caller constructs a `Message` with a per-message/seconds value (grepped
  all `fromRawEntry`/`fromChunkView`/`new Message(` sites; the only production
  producers pass the chunk value).

So the docblock's "copied onto every entry of the chunk — including every entry
of a sub-batch" and "**not** a per-message timestamp" are exactly what the code
does.

### Millisecond unit

The `@param` ("Boundary in milliseconds since the Unix epoch") and `@return`
("Chunk timestamp in milliseconds") match the wire type (`int64` ms, PROTOCOL.adoc
and `tests/E2E/ConsumerTest.php:420` `(time() + 86400) * 1000`). Correct.

---

## Scope discipline — #418 was not done

#418 is v1.5.0 and remains **OPEN** (verified with `gh issue list`). The coder
did **not** take its work:

- No docblocks added to `OffsetSpec::first/last/next/offset`; `interval()` is
  still undocumented in `src/VO/OffsetSpec.php` (the `timestamp()` docblock is
  the only new one).
- The seconds example at `docs/en/guide/consuming.md:212`/current `:220`
  (`OffsetSpec::timestamp(time() - 3600)`) is **unchanged**, as are the
  `time() - 86400` examples in `consuming.md:182` and `offset-tracking.md:129`.
- `CHANGELOG.md` explicitly says the remaining factory/`interval()`/unit work
  "remain in #418", and the commit closes only #443.

The one overlap: the new `timestamp()` docblock states the millisecond unit,
which is #418 acceptance-criterion #2 ("`timestamp()` explicitly states
milliseconds"). That is **acceptable and necessary** — #443's suggested fix
says to add the PHPDoc, and chunk-granular semantics cannot be stated without
the unit. #418 stays open for the guide/example unit correction and the other
five factories. No accidental scope grab.

---

## Issue-named locations — all fixed

| Issue location | Status |
|---|---|
| `src/VO/OffsetSpec.php:63` (now `:98-121`) | ✅ PHPDoc added |
| `src/Client/Message.php:30` (now `:261-282`) | ✅ PHPDoc added |
| `docs/en/guide/offset-tracking.md:126` (now `:126-147`) | ✅ claim replaced + callout |
| `docs/en/guide/consuming.md:179-185`, `:229` (now `:181-194`, `:237-240`) | ✅ example comment + callout + `getTimestamp()` comment |
| `docs/en/api-reference/consumer.md:74` (claim had moved to factory table `:122`) | ✅ row corrected + note |

`docs/en/api-reference/message.md` was **also** touched. Justified: it is the
canonical `Message::getTimestamp()` reference, it said "when the message was
published" and "seconds since epoch", and both are the exact bug in the issue
title. Correcting it is consistency within #443, not #418.

---

## Remaining same-class claims (completeness)

The issue named four files; the same false or imprecise claim survives in
several more. The coder recorded these in `findings-coder.md` but did not fix
them and did not file a follow-up issue. Assessment:

| Location | Claim | In #443 scope? |
|---|---|---|
| `docs/en/api-reference/value-objects.md:23` | `TYPE_TIMESTAMP` "Start from messages at or after a specific timestamp" | **Yes by subject** (describes `OffsetSpec`), but #418 owns this VO page. Fix here or ensure #418 covers it. |
| `docs/en/api-reference/value-objects.md:971,997` | `ChunkEntry::getTimestamp()` "Message timestamp" | **No** — `ChunkEntry` is not `Message`, and #443 names only `OffsetSpec`/`Message`. Analogous bug, orphaned. |
| `docs/en/guide/flow-control.md:633` | "Start from messages after timestamp" | **Yes by subject**; not named by either issue. |
| `docs/en/protocol/consuming-commands.md:59` | "Start from messages after timestamp" | **Yes by subject**; protocol-internals page, lower user impact. |
| `docs/en/api-reference/connection.md:869` | "Start from a specific Unix timestamp" | Not false, only silent on granularity. Optional. |

None of these is the canonical guide location (all fixed), so severity is LOW:
the four places a user is most likely to read are now correct, but a grep for
the claim (as the coder themselves proposed as a retrospect candidate) would
have found these, and at least `flow-control.md:633` and
`consuming-commands.md:59` are unowned by #418 and will remain wrong after
v1.5.0 unless someone picks them up.

---

## Adjacent-unit leftovers

- `docs/en/guide/consuming.md:220`: the new callout at `:189-195` tells readers
  to derive a boundary from broker-written values, "not the client clock", yet
  the "Choosing the Right Offset" box 30 lines later still recommends
  `OffsetSpec::timestamp(time() - 3600)`. The unit bug is #418's, but the page
  now contradicts itself. A pointer in the callout ("the seconds examples below
  are corrected in #418") would remove the tension without touching #418's fix.
- `docs/en/api-reference/message.md:530-531`: the `getCreationTime()` latency
  example subtracts two **millisecond** values and prints "seconds" (1000×
  off). This was already wrong, but the same-file change documents
  `getTimestamp()` as ms, making the contradiction locally visible. #418-adjacent.

---

## Tests / comments / behaviour

- **No behaviour change.** `git diff main...HEAD -- src/` is +42/-0 across two
  files, entirely comments/docblocks. No executable line changed.
- `tests/E2E/ConsumerTest.php` needs no change; its comment describes the same
  semantics as the new docs. It does carry **stale line references**
  (`OsirisChunkParser.php:66, :84-85`) — the code is now at `:324`, `:210`,
  `:264`, `:432`, `:483`. Semantics consistent; line numbers drifted. The coder
  noted the issue's own line refs had drifted but left this one.

---

## Checks run

```
composer lint                              → OK
  phpcs PSR-12                             → pass (281 files)
  rector dry-run                           → OK
  phpstan level 9 (275 files)              → no errors
  kb-lint (docs/helpers)                   → 12 entries, 0 warnings
  check-docs-links (docs/en)               → all relative links resolve
  test:suite-coverage                      → 150 files all covered
./vendor/bin/phpunit --testsuite unit      → OK (1242 tests, 8793 assertions)
```

No E2E run — doc-only change; the `>=`/chunk-boundary rule the E2E test asserts
was required reading, not re-run.

---

## Findings summary

| # | File:Line | Severity | Summary |
|---|---|---|---|
| 1 | `docs/en/api-reference/value-objects.md:23,971,997`, `docs/en/guide/flow-control.md:633`, `docs/en/protocol/consuming-commands.md:59` | low | Same false/imprecise per-message claim survives outside the four fixed files; no follow-up issue filed. |
| 2 | `docs/en/guide/consuming.md:220` | low | Client-clock seconds example contradicts the new "never derive from the client clock" callout on the same page (#418 unit fix, but the contradiction is new). |
| 3 | `docs/en/api-reference/message.md:530-531` | low | `getCreationTime()` latency example subtracts ms and labels seconds; now visibly inconsistent with the same-file `getTimestamp()` ms docs. |
| 4 | `tests/E2E/ConsumerTest.php:432` | nit | Stale `OsirisChunkParser.php:66, :84-85` line references in the timestamp comment. |
| 5 | `docs/en/api-reference/connection.md:869` | nit | Silent on chunk granularity; not false. |

**Accuracy:** the semantics the issue is about are correct.
**#418:** untouched, still open — acceptable overlap only on the `timestamp()` unit note.
**Named locations:** all fixed.
**Behaviour:** none changed.
**Lint/unit:** green.
