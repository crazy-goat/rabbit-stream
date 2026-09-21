# Code decision — docs: Producer public API PHPDoc and @throws (#415)

## Problem

`src/Client/Producer.php` had partial PHPDoc: five of its public methods
(`send`, `close`, `waitForConfirms`, `getLastPublishingId`, `querySequence`)
had either no docblock or a docblock with no `@throws`, and the file had
grown several more public methods since the issue was written
(`sendWithFilter`, `sendBatch`, `isStale`, `getRedeclareCount`, `isClosed`,
`getPendingConfirms`, `getLostConfirmCount`). The issue also called out two
non-obvious behaviours that were documented nowhere:

- `waitForConfirms()` can throw `TimeoutException`;
- `querySequence()` throws `InvalidArgumentException` for an unnamed producer;
- the payload format `send()` expects;
- a named producer's `getLastPublishingId()` can be non-null **before** the
  first `send()`.

## Approach taken

**Document every public method, not just the five in the issue.** The file
changed after the issue was filed, so the docblock pass covered all 13 public
methods (12 methods plus `__construct`). Each got a prose description, an
`@param` per argument, a described `@return` for every non-void method, and an
`@throws` set derived from the actual transitive throw paths.

**`@throws` sets were derived from the code, not copied.** Per method:

- `__construct` → `InvalidArgumentException` (redeclareTimeout < 0),
  `ConnectionException`/`DeserializationException`/`ProtocolException`/
  `TimeoutException` (the `declare()` DeclarePublisher exchange), and
  `UnexpectedResponseException` (the named-producer `querySequence()`).
- `send` / `sendBatch` / `sendWithFilter` → `ConnectionException`,
  `DeserializationException`, `InvalidArgumentException`, `ProtocolException`,
  `TimeoutException`. `ensureDeclared()` re-declares through
  `StreamConnection::request()` (which can raise Connection/Deserialization/
  Protocol/Timeout) and `applyBackpressure()` drains via `readLoop()` (which
  can raise Connection/Deserialization); a Publish frame above the negotiated
  `frame_max` raises `InvalidArgumentException` from `sendFrame()`.
- `close` → same set through the `DeletePublisher` write/read and the bounded
  confirm drain.
- `waitForConfirms` → `TimeoutException` (explicit) plus `ConnectionException`
  and `DeserializationException` from the drain `readLoop()`.
- `querySequence` → `InvalidArgumentException` (unnamed), plus
  `ConnectionException`, `DeserializationException`, `ProtocolException`,
  `TimeoutException` and `UnexpectedResponseException`.
- `isStale`, `getRedeclareCount`, `isClosed`, `getLastPublishingId`,
  `getPendingConfirms`, `getLostConfirmCount` → no throws; each got a
  descriptive `@return`.

**The named-producer `getLastPublishingId()` behaviour was verified against
the code.** `__construct` calls `initializePublishingId()`, which for a named
producer calls `querySequence()` and sets
`publishingId = sequence + 1`. `getLastPublishingId()` returns
`publishingId - 1`, i.e. the broker's sequence (`0` when nothing was stored),
so it is non-null before any `send()`. Only an anonymous producer starts at
`publishingId = 0`, where the method returns `null`. Both the class docblock
and the API reference now say this explicitly.

**`@return` descriptions were written for Rector, not just the gate.** FAQ-008
notes `RemoveUselessReturnTagRector` deletes a bare `@return <type>` that
merely repeats the native type. Every `@return` here carries prose, so the
reflection gate (`tests/Client/ProducerDocblockTest.php`) stays meaningful
after Rector runs.

**A reflection gate mirrors #414/#416.**
`tests/Client/ProducerDocblockTest.php` is a near-copy of
`ConsumerDocblockTest.php` retargeted at `Producer`: it fails when a public
method loses its docblock, prose description, a `@param`, a described
`@return`, or documents a `@throws` class/interface that does not exist. The
generic-return-type regression test from #416 is carried over.

## Rejected alternatives

- **Document only the five methods named in the issue:** leaves the newer
  public API (`sendWithFilter`, `sendBatch`, `isClosed`, …) undocumented and
  the reflection gate would fail on them immediately.
- **Add `@return void`:** Rector's `RemoveVoidDocblockFromMagicMethodRector`
  / `RemoveUselessReturnTagRector` remove it; the gate deliberately exempts
  `void`.
- **Document `@throws` on private methods only:** `applyBackpressure()` and
  `ensureDeclared()` already carry their own `@throws`; the public surface is
  what callers see, so the tags were lifted to each public method that can
  reach those paths.
- **Skip `ProducerInterface`:** #416 updated `ConsumerInterface` in the same
  commit, so the interface method docblocks were brought in line with the
  class to avoid two divergent descriptions of the same contract.

## Semantics kept unchanged

No production logic changed. The only `src/` edits are docblocks plus two new
`use` imports (`ConnectionException`, `DeserializationException`) that are
referenced only from `@throws` tags (`phpcs`'s `UnusedUses` runs with
`searchAnnotations=true`, so they are not flagged unused). The new test file
is additive.

## Uncertainties / tradeoff

- `@throws DeserializationException` / `InvalidArgumentException` on the
  publish methods are reachable only through rarer paths (a corrupt frame
  read during back-pressure; a payload above the negotiated frame max). They
  are documented because they are genuinely reachable, but a caller is
  unlikely to hit them in normal operation.
- The `ProducerInterface` docblocks now duplicate the class's `@throws`
  lists. If the two ever drift, the class-level reflection gate is the
  authority; the interface is not gated.
