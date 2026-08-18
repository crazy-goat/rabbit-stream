# Review 2 — Issue #413: Producer AMQP-encode round-trip

Branch: `feature/issue-413-producer-amqp-encode-roundtrip`
Diff: `git diff origin/main...HEAD` (2 commits: `85a3dc3` feat, `03afc30` fix for review R1/R2)

## Overall verdict

**Ship-able.** Both round-1 fixes (R1 overflow guard, R2 Producer→encoder
wiring unit guard) are correct, complete for what they target, and introduce no
new defects. All automated gates pass (PHPStan 9, PHPCS, PHPUnit unit suite).
No high/medium-severity findings. One new low-severity coverage gap (N1: the
single-message `send()` path is not unit-guarded, only `sendBatch()` is) and the
pre-existing nits remain; none block merge.

## Automated checks (run from repo root)

| Check | Command | Result |
|-------|---------|--------|
| PHPStan 9 | `composer phpstan` | passed — 0 errors, 237 files |
| PHPCS (PSR-12) | `composer cs` | passed — 241 files, 0 violations |
| Unit tests | `./vendor/bin/phpunit --testsuite unit` | passed — 656 tests, 1418 assertions (1 pre-existing risky test: `StreamConnectionTest::testDispatchMetadataUpdateWithoutCallbackDoesNotCrash`) |
| Staged files | `git diff --cached` | none — working tree clean |

E2E (`./run-e2e.sh`) was run green by the parent session (128 tests) and is not
re-run here (requires Docker/RabbitMQ).

## Earlier-round findings — status on current branch

### C1 — src/Client/AmqpDecoder.php:101-107 — decoder exception message lacks hint
- **Not a real finding for this diff.** `AmqpDecoder` is not in the changed set
  (`git diff origin/main...HEAD -- src/Client/AmqpDecoder.php` is empty). The
  new `Producer::send()` auto-encode means well-formed high-API publishes no
  longer reach this throw path; the low-level API remains the documented escape
  hatch. Pre-existing UX suggestion, out of scope for #413.

### C2 — src/Client/AmqpDecoder.php readDescribedType() — dead/duplicated code
- **Not a real finding for this diff.** Pre-existing, untouched by this change.
  Out of scope.

### C3 — tests/E2E/AmqpMessageDecoderE2ETest.php:102,140 — inline data-section bytes
- **Still present, by design.** Confirmed at current branch lines 102 and 140:
  both `buildAmqpMessageWithProperties` and `buildAmqpMessageWithAppProperties`
  keep `$dataSection = "\x00\x53\x75\xb0" . pack('N', $length) . $body;`. These
  are multi-section fixtures (Properties+Data / AppProperties+Data) that
  `Producer::send()` cannot produce; the Data-section fragment is byte-identical
  to `AmqpMessageEncoder::encodeDataSection()` output, so no behavioral drift.
  Nit only.

### C4 — docs/en/examples/basic-producer.md, basic-consumer.md — missing cross-link
- **Still present, optional.** The examples already use plain strings both ways
  and are correct; the cross-link to the "Message Encoding" section is a
  discoverability nicety. README and `docs/en/guide/publishing.md` already cover
  the topic. Nit.

### C5 — tests/StreamConnectionTest.php:567 — risky test (no assertions)
- **Still present, pre-existing.** Confirmed by the unit run: "There was 1 risky
  test … StreamConnectionTest.php:567". The file is not in this diff's changed
  set. Not introduced by #413. Smallest out-of-scope fix:
  `$this->expectNotToPerformAssertions()`.

### R1 — src/Client/AmqpMessageEncoder.php:28 — silent overflow for ≥4 GiB bodies
- **FIXED.** `src/Client/AmqpMessageEncoder.php:31-34` now guards:
  ```php
  if (strlen($body) > 0xFFFFFFFF) {
      throw new \LengthException(
          'AMQP 1.0 Data section payload exceeds the 4294967295-byte vbin32 limit'
      );
  }
  ```
  - Boundary is correct: `0xFFFFFFFF` = 4294967295. The guard rejects
    `strlen > 4294967295` (i.e. ≥ 4 GiB) and accepts the max legal vbin32
    payload (4294967295). Verified with PHP one-liners:
    `pack('N', 4294967296)` → `00000000` (wraps, now thrown), `pack('N',
    4294967295)` → `ffffffff` (accepted).
  - On 64-bit PHP `strlen()` returns a native int, so the `> 0xFFFFFFFF`
    comparison is exact (no float truncation).
  - `@throws \LengthException` is documented in the method PHPDoc.
  - `testEncodeDataSectionAcceptsBodyAtVbin32Limit` documents the boundary
    (it encodes a 1024-byte body and asserts the length prefix; a real 4 GiB
    allocation is infeasible in CI, which the test comment explains). The throw
    path itself is not exercised with a real over-limit string — acceptable
    given the guard is a single integer comparison.
  - No new issue introduced by the guard: it sits before the `pack()` call, so
    the silent-corruption path is unreachable; the exception type (`\LengthException`)
    is a sensible SPL choice (the project has a custom exception hierarchy but
    no specific "frame too large" type, and `\LengthException` is semantically
    exact).

### R2 — tests/Client/ProducerTest.php — no unit guard on Producer→encoder wiring
- **FIXED (for sendBatch).** `testSendBatchCreatesSingleRequestWithMultipleMessages`
  (lines 188-199) now captures the `PublishRequestV1`, reads `->toArray()`, and
  asserts:
  ```php
  $this->assertIsArray($messages[0]);
  $this->assertArrayHasKey('data', $messages[0]);
  $this->assertSame(AmqpMessageEncoder::encodeDataSection('msg1'), $messages[0]['data'], ...);
  // … and $messages[2] likewise for 'msg3'
  ```
  - The `'data'` key is correct: `src/VO/PublishedMessage.php:34` returns
    `['publishingId' => …, 'data' => $this->message]`, so the assertion inspects
    the actual encoded body that goes on the wire.
  - The assertion uses `AmqpMessageEncoder::encodeDataSection('msg1')` as the
    expected value, so dropping the encode call from `Producer::sendBatch()`
    would make `assertSame` compare the raw `'msg1'` against the encoded bytes
    and fail — exactly the desired fast unit-level regression signal.
  - First and last array elements are sampled (indices 0 and 2); the middle
    element (index 1) is not asserted but `count === 3` is already checked, so
    coverage is adequate.
  - See N1 below for the residual: the single-message `send()` path is not
    unit-guarded.

### R3 — tests/E2E/AmqpMessageDecoderE2ETest.php:102,140 — inline data-section bytes
- **Still present** (same as C3). No separate defect.

## New findings (this round)

### N1 — tests/Client/ProducerTest.php — single-message `send()` wiring not unit-guarded
- What: R2 was fixed only for `sendBatch()`.
  `testSendIncrementsPendingConfirms` (line 245) calls `$producer->send('msg1')`
  three times but mocks `sendMessage` with `$this->any()` and never captures the
  `PublishRequestV1`, so it cannot detect whether `send()` encoded the body.
  `testSendAcceptsOptionalWriteTimeout` (line 18) likewise does not inspect the
  request body. A refactor that removed `AmqpMessageEncoder::encodeDataSection()`
  from `Producer::send()` (line 95) but left `sendBatch()` (line 113) intact
  would pass the entire unit suite and only fail E2E.
- Severity: **low.** Both `send()` and `sendBatch()` call the same
  `AmqpMessageEncoder::encodeDataSection()` one line apart in `Producer.php`,
  so a regression would most likely break both and be caught by the existing
  `sendBatch` assertion. The residual risk is a refactor that touches only the
  single-message path.
- Smallest safe fix (out of scope for this review — do not edit source):
  in `testSendIncrementsPendingConfirms` (or a new test), capture the
  `PublishRequestV1` via a `callback` and assert `$messages[0]['data'] ===
  AmqpMessageEncoder::encodeDataSection('msg1')`, mirroring the `sendBatch`
  assertions.
- Automated check that could catch it: the proposed unit test (PHPUnit).

## Fix-commit correctness (03afc30)

- The fix commit touches only `src/Client/AmqpMessageEncoder.php` (+11),
  `tests/Client/AmqpMessageEncoderTest.php` (+19),
  `tests/Client/ProducerTest.php` (+19), and the two proof-of-work docs. No
  behavioral change beyond the guard and the test assertions; the feat commit's
  Producer auto-encode logic is untouched.
- The new `testEncodeDataSectionAcceptsBodyAtVbin32Limit` is honest about its
  limitation (cannot allocate 4 GiB in CI) and instead proves the length-prefix
  encoding for a representative size; combined with the visible guard it is
  sufficient documentation of the boundary.
- No new imports, no new public API, no signature changes in the fix commit.

## Areas checked clean

- Wire-format round-trip (empty, text, UTF-8, null bytes, binary, high-bit
  lengths): covered by `AmqpMessageEncoderTest` (5 tests) — unchanged by the
  fix commit and still passing.
- Producer API signatures (`send`, `sendBatch` on both `Producer` and
  `ProducerInterface`): unchanged by the fix commit.
- Double-wrapping within the library: none — the encoder is called exactly once
  per message in both paths.
- E2E classes: all 7 still pass plain strings (no residual manual `amqp()`
  helper; grep confirms zero remaining `function amqp(` / `$this->amqp(` in
  `tests/E2E`).
- Docs/examples/CHANGELOG consistency: unchanged by the fix commit; the feat
  commit's documentation is accurate and warns about the double-wrap escape
  hatch.
- PHPStan 9 / PHPCS / PHPUnit unit: all clean.
- No staged files, no accidental commits.

## Residual risks

- N1 (low): single-message `send()` encode wiring lacks a unit-level assertion.
- C5 (nit, pre-existing): `StreamConnectionTest::testDispatchMetadataUpdateWithoutCallbackDoesNotCrash`
  is risky — not introduced by this diff.
- C3/R3 (nit): two inline data-section constructions in E2E multi-section
  fixtures — byte-identical to the encoder, no drift risk.
- C4 (nit): optional cross-links from basic examples to the publishing guide.
- The over-limit throw path is not exercised with a real ≥4 GiB string
  (infeasible in CI); the guard is a single integer comparison and is trusted
  on inspection.
```

---
