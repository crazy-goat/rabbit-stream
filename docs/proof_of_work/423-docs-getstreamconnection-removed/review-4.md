# Round 4 Review — Issue #423 (docs-only)

**Branch:** `feature/issue-423-docs-getstreamconnection-removed`
**Latest commit:** `e536d0a` (round-3 fix: added missing `use OffsetSpec` to flow-control.md:569 snippet)
**Reviewer:** review subagent (round 4 — convergence check)

---

## 1. Finding #9 Status — FIXED

**Finding #9** (round 3): Missing `use CrazyGoat\RabbitStream\VO\OffsetSpec;` in the first "Custom ConsumerUpdate Callback" `<?php` snippet at `docs/en/guide/flow-control.md:569`.

**Evidence (current branch, e536d0a):**

`docs/en/guide/flow-control.md` lines 571–574:
```php
<?php

use CrazyGoat\RabbitStream\Response\ConsumerUpdateResponseV1;
use CrazyGoat\RabbitStream\VO\OffsetSpec;
```

The `use OffsetSpec` import is present on line 574. The snippet body at line 590 executes `return [OffsetSpec::TYPE_OFFSET, 100];` — the short-name class `OffsetSpec` is now resolvable. **Verdict: fixed.**

The commit `e536d0a` added exactly this one line, matching the fix described in the round-3 findings-review.md.

---

## 2. Acceptance Grep Results

### Grep A: removed API references
```
grep -rn 'getStreamConnection\|registerDeliverCallback' docs/en
```
**Result: 0 matches (exit code 1).** ✅ Clean.

### Grep B: low-level $connection-> method calls in scope
```
grep -rn '\$connection->sendMessage\|\$connection->readMessage\|\$connection->registerPublisher\|\$connection->registerSubscriber\|\$connection->stop' docs/en/guide docs/en/examples/stream-management.md docs/en/examples/super-stream-routing.md
```
**Result: 0 matches (exit code 1).** ✅ Clean. (error-handling-patterns.md correctly excluded as out of scope.)

### Grep C: raw numeric offsetType literals
```
grep -rn 'return \[0,\|return \[1,\|return \[2,\|return \[3,\|return \[4,\|return \[5,\|return \[6,' docs/en
```
**Result: 0 matches (exit code 1).** ✅ Clean. All offsetType return values use `OffsetSpec::TYPE_*` constants.

---

## 3. Import Cross-Check (snippets touched by 4156c42, 4d78483, e536d0a)

The three fix commits touched snippets in 6 guide files. Each `<?php`-tagged snippet was inspected to confirm every class referenced by short name has a corresponding `use` import.

| File:Line (snippet) | Classes used by short name | Imports present | Status |
|---------------------|---------------------------|-----------------|--------|
| `consuming.md:571–595` (Handle Deliver Frames) | `DeliverResponseV1`, `OsirisChunkParser`, `AmqpMessageDecoder`, `CreditRequestV1` | all 4 imported (CreditRequestV1 added in 4156c42) | ✅ |
| `error-handling.md:388–441` (Consuming Errors) | `ProtocolException`, `ResponseCodeEnum`, `OffsetSpec` | all 3 imported | ✅ |
| `flow-control.md:317–346` (Basic Usage) | `PublishRequestV1`, `PublishedMessage`, `AmqpMessageEncoder` | all 3 imported (added in 4156c42) | ✅ |
| `flow-control.md:371–408` (Stopping the Loop) | `PublishRequestV1`, `PublishedMessage`, `AmqpMessageEncoder` | all 3 imported (added in 4156c42) | ✅ |
| `flow-control.md:571–592` (Custom ConsumerUpdate Callback) | `ConsumerUpdateResponseV1`, `OffsetSpec` | both imported (OffsetSpec added in e536d0a) | ✅ |
| `flow-control.md:608–672` (Complete Example) | `StreamConnection`, `AmqpMessageDecoder`, `Connection`, `OsirisChunkParser`, `SubscribeRequestV1`, `CreditRequestV1`, `DeliverResponseV1`, `OffsetSpec` | all 8 imported | ✅ |
| `publishing.md:364–384` (Publish Messages low-level) | `DeclarePublisherRequestV1`, `PublishRequestV1`, `PublishedMessage`, `AmqpMessageEncoder` | all 4 imported (AmqpMessageEncoder added in 4d78483) | ✅ |
| `stream-management.md:400–440` (StreamMetadataCache) | `Connection`, `StreamMetadata` | both imported (added in 4156c42) | ✅ |
| `super-streams.md:472–492` (ConsumerUpdate callback) | `ConsumerUpdateResponseV1`, `OffsetSpec` | both imported (added in 4d78483) | ✅ |

**All referenced classes verified to exist in `src/`** (OffsetSpec, PublishedMessage, AmqpMessageEncoder, AmqpMessageDecoder, OsirisChunkParser, CreditRequestV1, ConsumerUpdateResponseV1, StreamMetadata, ConfirmationStatus — all confirmed).

**OffsetSpec constant values verified** against `src/VO/OffsetSpec.php`:
- TYPE_FIRST=1, TYPE_LAST=2, TYPE_NEXT=3, TYPE_OFFSET=4, TYPE_TIMESTAMP=5, TYPE_INTERVAL=6 — all match the documented table at flow-control.md:598–603.

**API method signatures verified:**
- `Connection::createConsumer(string, OffsetSpec, ?string, int, int)` — matches all doc usages (3 calls with named `name:` arg and one without).
- `StreamConnection::onConsumerUpdate(callable)` — matches all doc usages.

---

## 4. New Findings

**None.**

No new issues found across the 9 changed files. Every `<?php`-tagged snippet touched by the three fix commits has complete `use` imports. No raw numeric offsetType literals remain. No remaining references to removed APIs (`getStreamConnection`, `registerDeliverCallback`). No snippet that would fatal at runtime.

---

## 5. Conclusion

**Clean round — no open findings. The workflow may proceed to lint/CHANGELOG/PR.**

All 9 findings from rounds 1–3 are confirmed fixed. Acceptance greps A/B/C all return 0 matches. Import cross-check passes for all touched snippets. OffsetSpec constants and API signatures verified against source.
