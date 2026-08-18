# Review Round 3 — issue #423 (docs: getStreamConnection/registerDeliverCallback removed)

Branch: `feature/issue-423-docs-getstreamconnection-removed`
Reviewed commit: `4d78483` ("fix(docs): address review round 2 …")
Scope: docs-only. Read-only review (no edits made).

## Status of prior findings

### Finding #7 (publishing.md:374 — missing `use AmqpMessageEncoder`) — **FIXED**

Evidence (`docs/en/guide/publishing.md:367-380`, current branch):

```php
<?php

use CrazyGoat\RabbitStream\Request\PublishRequestV1;
use CrazyGoat\RabbitStream\VO\PublishedMessage;
use CrazyGoat\RabbitStream\Client\AmqpMessageEncoder;

$message = new PublishedMessage(
    publishingId: 1,
    message: AmqpMessageEncoder::encodeDataSection('Hello')
);
```

`use CrazyGoat\RabbitStream\Client\AmqpMessageEncoder;` is present at line 371 and
`AmqpMessageEncoder::encodeDataSection('Hello')` resolves. The class exists at
`src/Client/AmqpMessageEncoder.php`. Confirmed fixed.

### Finding #8 (super-streams.md:486-487 — wrong offsetType literals `[0,0]`/`[1,0]`) — **FIXED**

Evidence (`docs/en/guide/super-streams.md:474-487`, current branch):

```php
use CrazyGoat\RabbitStream\Response\ConsumerUpdateResponseV1;
use CrazyGoat\RabbitStream\VO\OffsetSpec;

$stream->onConsumerUpdate(function (ConsumerUpdateResponseV1 $query): array {
    if ($query->getSubscriptionId() === 1) {
        echo "Consumer became active for subscription 1\n";
        // Start processing from the beginning of the stream (TYPE_FIRST).
        return [OffsetSpec::TYPE_FIRST, 0];
    }
    // Other subscriptions: resume at a stored offset (TYPE_OFFSET, offset 0).
    return [OffsetSpec::TYPE_OFFSET, 0];
});
```

- `[0, 0]` → `[OffsetSpec::TYPE_FIRST, 0]` ✓ (TYPE_FIRST = 0x0001 = 1)
- `[1, 0]` → `[OffsetSpec::TYPE_OFFSET, 0]` ✓ (TYPE_OFFSET = 0x0004 = 4)
- `use CrazyGoat\RabbitStream\VO\OffsetSpec;` present at line 476 ✓
- Comments corrected ("from the beginning" / "resume at a stored offset") ✓

Confirmed fixed.

## Additional flow-control.md offsetType fixes (made by main session in 4d78483)

### "Offset Types" table (flow-control.md:595-602) — **CORRECT**

| Table entry | Value | `src/VO/OffsetSpec.php` constant | Match |
|-------------|-------|----------------------------------|-------|
| `OffsetSpec::TYPE_FIRST`     | 1 | `TYPE_FIRST = 0x0001`     | ✓ |
| `OffsetSpec::TYPE_LAST`      | 2 | `TYPE_LAST = 0x0002`      | ✓ |
| `OffsetSpec::TYPE_NEXT`      | 3 | `TYPE_NEXT = 0x0003`      | ✓ |
| `OffsetSpec::TYPE_OFFSET`    | 4 | `TYPE_OFFSET = 0x0004`    | ✓ |
| `OffsetSpec::TYPE_TIMESTAMP` | 5 | `TYPE_TIMESTAMP = 0x0005` | ✓ |
| `OffsetSpec::TYPE_INTERVAL`  | 6 | `TYPE_INTERVAL = 0x0006`  | ✓ |

All six table values match the source constants exactly.

### Inline comment block (flow-control.md:580-586) — **CORRECT**

The comment block lists `FIRST=1, LAST=2, NEXT=3, OFFSET=4, TIMESTAMP=5, INTERVAL=6`,
matching `OffsetSpec.php`. The `return [OffsetSpec::TYPE_OFFSET, 100];` literal is
correct (TYPE_OFFSET=4, offset=100).

### "Complete Example" onConsumerUpdate snippet (flow-control.md:611-631) — **CORRECT**

```php
use CrazyGoat\RabbitStream\VO\OffsetSpec;   // line 618 — imported ✓
…
$stream->onConsumerUpdate(function ($query) {
    echo "Promoted to active consumer!\n";
    // Start from where we left off (TYPE_OFFSET, offset 0).
    return [OffsetSpec::TYPE_OFFSET, 0];    // line 631 ✓
});
```

`use OffsetSpec` is present (line 618), the return value is correct
(TYPE_OFFSET=4, offset=0), and the comment is accurate.

### First onConsumerUpdate snippet (flow-control.md:569-590) — **DEFECT: missing `use OffsetSpec`**

See NEW finding #9 below.

## NEW findings (round 3)

### Finding #9 — flow-control.md:569-590 — missing `use OffsetSpec` (low)

The "Custom ConsumerUpdate Callback" `<?php` snippet imports only
`ConsumerUpdateResponseV1` but the body uses the short class name
`OffsetSpec::TYPE_OFFSET` in the return statement:

```php
<?php

use CrazyGoat\RabbitStream\Response\ConsumerUpdateResponseV1;   // <-- only import

$stream->onConsumerUpdate(function (ConsumerUpdateResponseV1 $query): array {
    …
    // Offset types (see CrazyGoat\RabbitStream\VO\OffsetSpec):   // FQCN in comment only
    //   OffsetSpec::TYPE_OFFSET    = 4 (…)
    …
    return [OffsetSpec::TYPE_OFFSET, 100];   // line 589 — short name, no import
});
```

The comment helpfully writes the fully-qualified name
`CrazyGoat\RabbitStream\VO\OffsetSpec`, but the executable `return` line uses the
short name `OffsetSpec::TYPE_OFFSET`, which requires a `use` import. Without
`use CrazyGoat\RabbitStream\VO\OffsetSpec;` this snippet would fatal with
"Class \"OffsetSpec\" not found" if run as-is.

This is the same class of defect as round-1 findings #3/#4 and round-2 finding #7
(missing `use` for a class referenced by short name in a `<?php` block). It was
introduced/retained by the 4d78483 offsetType rewrite: the sibling "Complete
Example" snippet at line 611 *did* get `use OffsetSpec` (line 618), but this
earlier snippet was missed.

Severity: **low** (docs snippet; same severity assigned to the analogous #3/#4/#7).
Fix: add `use CrazyGoat\RabbitStream\VO\OffsetSpec;` after line 573.

No other new findings.

## Acceptance greps

### Grep 1 — removed API names must be absent from docs/en

```
$ grep -rn 'getStreamConnection\|registerDeliverCallback' docs/en
# exit 1, no matches
```

**Result: 0 matches — PASS.**

### Grep 2 — low-level `$connection->…` calls must be absent from in-scope guide/examples

```
$ grep -rn '\$connection->sendMessage\|\$connection->readMessage\|\$connection->registerPublisher\|\$connection->registerSubscriber\|\$connection->stop' \
    docs/en/guide docs/en/examples/stream-management.md docs/en/examples/super-stream-routing.md
# exit 1, no matches
```

**Result: 0 matches — PASS.** (`docs/en/examples/error-handling-patterns.md` is
out of scope per the task instructions and was intentionally not grepped.)

## Other checks performed (clean)

- **FQCN verification**: every `use CrazyGoat\RabbitStream\…` statement across the
  9 changed docs files was resolved against `src/`. All 45 distinct referenced
  classes exist (Client\{Connection,AmqpMessageEncoder,AmqpMessageDecoder,
  OsirisChunkParser,ConfirmationStatus,Producer,Consumer};
  VO\{OffsetSpec,PublishedMessage,PublishedMessageV2,StreamMetadata};
  Request\{PublishRequestV1,PublishRequestV2,SubscribeRequestV1,CreditRequestV1,
  DeclarePublisherRequestV1,DeletePublisherRequestV1,CreateRequestV1,
  DeleteStreamRequestV1,MetadataRequestV1,StreamStatsRequestV1,
  PartitionsRequestV1,ResolveOffsetSpecRequestV1,StoreOffsetRequestV1,
  QueryOffsetRequestV1,UnsubscribeRequestV1};
  Response\{ConsumerUpdateResponseV1,DeliverResponseV1,CreateResponseV1,
  DeleteStreamResponseV1,MetadataResponseV1,StreamStatsResponseV1,
  PartitionsResponseV1,QueryOffsetResponseV1,ResolveOffsetSpecResponseV1};
  Exception\{ProtocolException,AuthenticationException,UnexpectedResponseException,
  ConnectionException,TimeoutException,DeserializationException,
  InvalidArgumentException,RabbitStreamException}; Enum\ResponseCodeEnum;
  StreamConnection). No dangling FQCNs.
- **Remaining invalid offsetType literals**: `grep -rn 'return \[[0-9],' docs/en`
  returns no matches — no raw numeric offsetType literals remain anywhere.
- **OffsetSpec constants cross-check**: confirmed against
  `src/VO/OffsetSpec.php` (TYPE_FIRST=0x0001 … TYPE_INTERVAL=0x0006). The
  flow-control.md table and both super-streams.md return values are consistent.

## Summary

- Findings #7 and #8: both **confirmed fixed**.
- Additional flow-control.md offsetType fixes: the table and the line-629 snippet
  are **correct**. The line-569 snippet has one residual defect → **new finding #9**
  (missing `use OffsetSpec`, same class as #7).
- Both acceptance greps pass (0 matches).
- No other new issues in the 9 changed files.

This is **not** a clean round: finding #9 must be fixed before lint/PR. The fix is
a one-line `use` import addition, identical in nature to the already-fixed #7.
