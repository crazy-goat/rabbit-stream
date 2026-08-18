# Findings — Review Round 1

| # | File:Line | What is wrong | Severity | What happened to it |
|---|-----------|---------------|----------|-------------------|
| 1 | docs/en/guide/error-handling.md:406 | `$consumer->close()` called on undefined variable. `createConsumer()` threw before the assignment completed, so `$consumer` is never assigned in the `SUBSCRIPTION_ID_ALREADY_EXISTS` catch branch. Would fatal at runtime with "Call to a member function close() on null". Introduced by this change — old code used `$connection->unsubscribe(1)` which operated on the already-defined `$connection`. | high | Fixed: removed the `$consumer->close()` call (there is no Consumer object to close — the constructor threw). Rewrote the branch to explain that the thrown createConsumer() never returned an object, and to retry with a unique consumer name. |
| 2 | docs/en/guide/error-handling.md:424 | `$consumer->queryOffset()` called after the catch block unconditionally. If the catch block took the `STREAM_NOT_EXIST` or `ACCESS_REFUSED` branches (which just `error_log()` and fall through), `$consumer` was never assigned. Would fatal at runtime. Introduced by this change. | medium | Fixed: initialize `$consumer = null` before the try, and add a `if ($consumer === null) { return; }` guard before the NO_OFFSET block. |
| 3 | docs/en/guide/consuming.md:589 | Missing `use` import for `CreditRequestV1` in the "Handle Deliver Frames" snippet. The snippet has `use` statements for `DeliverResponseV1`, `OsirisChunkParser`, `AmqpMessageDecoder` but omits `CreditRequestV1` which is used in the callback body. Would fatal with "Class not found" if run as-is. Check: `php -l` on extracted block. | low | Fixed: added `use CrazyGoat\RabbitStream\Request\CreditRequestV1;` to the import block. |
| 4 | docs/en/guide/flow-control.md:317,371 | Two `<?php`-tagged snippets ("Basic Usage" and "Stopping the Loop") use `PublishRequestV1`, `PublishedMessage`, `AmqpMessageEncoder` without `use` imports. A nearby snippet ("readMessage() Transparent Dispatch", line 276) DOES include `use` statements for the same classes, making the inconsistency visible. Would fatal with "Class not found" if run as-is. Check: `php -l` on extracted block. | low | Fixed: added the three `use` statements (PublishRequestV1, PublishedMessage, AmqpMessageEncoder) after `<?php` in both snippets. |
| 5 | docs/en/guide/stream-management.md:410 | `StreamMetadata` and `Connection` used as type hints in the `StreamMetadataCache` class snippet without `use` imports. Inline class definition (no `<?php` tag), illustrative only. | nit | Fixed: added `use CrazyGoat\RabbitStream\Client\Connection;` and `use CrazyGoat\RabbitStream\VO\StreamMetadata;` before the class. |
| 6 | docs/en/guide/super-streams.md:476 | `ConsumerUpdateResponseV1` used as type hint in inline snippet without `use` import. Illustrative snippet, not a `<?php` block. | nit | Fixed: added `use CrazyGoat\RabbitStream\Response\ConsumerUpdateResponseV1;` before the snippet. |

---

# Findings — Review Round 2

| # | File:Line | What is wrong | Severity | What happened to it |
|---|-----------|---------------|----------|-------------------|
| 7 | docs/en/guide/publishing.md:374 | Missing `use CrazyGoat\RabbitStream\Client\AmqpMessageEncoder;` in the "Publish Messages" low-level snippet. The rewrite (a47bb50) changed `message: 'Hello'` to `message: AmqpMessageEncoder::encodeDataSection('Hello')` but did not add the import. Would fatal with "Class not found" if run as-is. Same class of issue as round-1 findings #3/#4. | low | |
| 8 | docs/en/guide/super-streams.md:486-487 | ConsumerUpdate callback returns incorrect offsetType values. `[0, 0]` uses offsetType=0 which is not a valid OffsetSpec type (valid types start at 1=FIRST); comment says "from the beginning" which should be TYPE_FIRST=1. `[1, 0]` has comment "OFFSET" but offsetType=1 is TYPE_FIRST, not TYPE_OFFSET (=4). Introduced by a47bb50. | low | |

# Resolutions — Round 2 findings

- #7 (low, publishing.md:374): Fixed — added `use CrazyGoat\RabbitStream\Client\AmqpMessageEncoder;` to the "Publish Messages" low-level snippet import block.
- #8 (low, super-streams.md:486-487): Fixed — replaced `[0, 0]` with `[OffsetSpec::TYPE_FIRST, 0]` and `[1, 0]` with `[OffsetSpec::TYPE_OFFSET, 0]`, added `use OffsetSpec`, and corrected the comments. Same class of defect also found and fixed in two more places during resolution: `flow-control.md:575` (inline offset-type comment block + `return [1, 100]` → `[OffsetSpec::TYPE_OFFSET, 100]`, plus the "Offset Types" table rewritten to the real `OffsetSpec` constants 1–6) and `flow-control.md:629` (`return [1, 0]` with wrong "offset type 1 = OFFSET" comment → `[OffsetSpec::TYPE_OFFSET, 0]`). All three were introduced by the a47bb50 rewrite.
