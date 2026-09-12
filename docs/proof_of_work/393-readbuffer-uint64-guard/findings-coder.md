# Findings — coder — issue #393

## Obstacles / surprises during implementation

1. **`tests/Buffer/ReadBufferTest.php` is flagged as binary by tooling.**
   The file legitimately embeds NUL bytes as test fixtures
   (`tests/Buffer/ReadBufferTest.php:495`:
   `'PREFIX\x00*SUFFIX'`, `:512`: `'XX\x00\x03fooYY'`), so `file` reports
   `data` and some readers (including the agent `read` tool) refuse it. The
   `edit`/`grep`/PHP toolchain all handled it fine — no action needed, but a
   future editor should know the file is intentionally NUL-bearing rather
   than corrupted. A `#`-comment or a small note is optional; not worth a
   change.

2. **The issue's stated blast radius is partially fictional.** The body says
   the wrapped value feeds "`PublishConfirm` publishing ids", but the #411
   performance work made `PublishConfirmResponseV1` read ids with a single
   `unpack('J*')` and bypass `ReadBuffer::getUint64()` entirely. So this
   guard does **not** close that path (see finding 1 below). Worth calling out
   because a reader of the issue would assume it does.

3. **One existing test asserted the bug.** `testGetUint64WithMaxValue`
   (`tests/Buffer/ReadBufferTest.php`, previously line 306) explicitly
   asserted `-1` for `0xFFFFFFFFFFFFFFFF`. Per the task it was rewritten
   rather than left red; the signed-reader counterpart
   (`testGetInt64Negative`) keeps asserting `-1` so the scoping stays pinned.

## Discovered bugs / places to improve (including outside scope)

### 1. `PublishConfirmResponseV1` bypasses the new guard — publishing ids still wrap negative

- **Where:** `src/Response/PublishConfirmResponseV1.php:59`
- **What:** `$unpacked = unpack('J*', $buffer->readBytes($count * 8));` reads
  every publishing id in one call and never invokes `getUint64()`, so ids
  `>= 2^63` are still returned as negative ints (e.g.
  `0xFFFFFFFFFFFFFFFF` → `-1`). The issue names this exact frame as an
  affected consumer. The ids are stored in `$this->publishingIds` and handed
  to user confirm callbacks (`StreamConnection::registerPublisher`), where a
  negative id silently fails to match the pending-confirm bookkeeping.
- **Suggested fix:** after `unpack`, reject wrapped ids before returning:
  ```php
  if (min($unpacked) < 0) {
      throw new DeserializationException('PublishConfirm contains a publishing id above PHP_INT_MAX');
  }
  ```
  (O(n), same order as the existing `array_values`; or fold into the
  `array_values` pass with an explicit loop.) Consider a bounds test mirroring
  `testGetUint64WithValueAbovePhpIntMaxThrows`. This is likely its own issue
  since it touches the #411 hot path.

### 2. `docs/en/api-reference/read-buffer.md` `getUint64()` "Throws" is now incomplete

- **Where:** `docs/en/api-reference/read-buffer.md:103-104`
- **What:** It lists only "buffer underflow" as the throw condition. After
  this change the method also throws when the raw value exceeds
  `PHP_INT_MAX`. The "Returns" line (`:101`, range `0 to PHP_INT_MAX`) is now
  correct and worth keeping.
- **Suggested fix:** add a bullet: ``DeserializationException` - If the
  decoded value exceeds `PHP_INT_MAX` (the 8 raw bytes are included in the
  message)``. Left undone here to keep the diff minimal; safe docs-only
  follow-up.

### 3. `OsirisChunkParser` reads the chunk `epoch` through `getUint64()` and discards it

- **Where:** `src/Client/OsirisChunkParser.php:325`
- **What:** `$buffer->getUint64(); // epoch` now inherits the new guard. The
  epoch is an informational field that the parser throws away; a hostile frame
  with a bogus high-bit epoch will now abort chunk parsing with a
  `DeserializationException` instead of being ignored, even though
  `chunkFirstOffset` on the next line is the field that actually matters.
  This is arguably more defensive, but it is a new failure mode for a field
  the parser does not use.
- **Suggested fix:** none required. If tightening the failure surface is a
  concern, read the epoch with `getInt64()` (it is a running number and is
  discarded anyway) so only `chunkFirstOffset` can reject the chunk. Flagged
  for awareness, not action.

### 4. `ReadBuffer` still has no `getUint64`-style guard for `unpack('J*')` bulk reads

- **Where:** `src/Response/PublishConfirmResponseV1.php:59`, general pattern
- **What:** Finding 1 is one instance of a broader gap: the scalar getters are
  guarded, but any future bulk `unpack('J*')`/`unpack('J')` outside
  `ReadBuffer` re-opens the same signed-wrap hole. There is no shared helper
  for "uint64 array with wrap check".
- **Suggested fix:** if/when finding 1 is fixed, consider a small static
  helper on `ReadBuffer` (e.g. `getUint64Array(int $count)`), or at minimum a
  comment in `PublishConfirmResponseV1` explaining why the fast path must
  re-add the check manually.

### 5. Pre-existing "risky" unit test (no assertions) — carried over from #384

- **Where:** `tests/StreamConnectionTest.php:567`
- **What:** The unit suite still reports `Risky: 1` for a test that performs
  no assertions. Pre-existing on `main`, unrelated to this branch.
- **Suggested fix:** add an assertion or `@doesNotPerformAssertions`. Out of
  scope; noted for the record.

## Candidate KB entry proposal (for the main session to decide)

- **Title:** `unpack('J') is signed — guard every uint64 path, not just ReadBuffer`
- **Tags:** `buffer, protocol, exceptions`
- **Trigger:** when adding or reviewing any code that reads a uint64 via
  `unpack('J')`/`unpack('J*')`, including "performance" fast paths that
  intentionally bypass `ReadBuffer::getUint64()`
- **One paragraph:** PHP has no unsigned 64-bit int, so `unpack('J')` yields
  the two's-complement value: `0xFFFFFFFFFFFFFFFF` → `-1`. `ReadBuffer::getUint64()`
  now throws `DeserializationException` (with the raw bytes and position) for
  any result `< 0`, but that guard only covers callers that go through the
  method. `PublishConfirmResponseV1` reads ids with a single `unpack('J*')`
  for speed and therefore still returns negative ids — exactly the frame the
  #393 issue named. Rule: a bulk `unpack('J*')` fast path must re-apply the
  same `min(...) < 0` check, or it silently reintroduces the #393 bug.
