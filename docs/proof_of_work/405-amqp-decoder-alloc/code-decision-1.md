# #405 — AMQP decoder: remove per-value tuple allocation — decision record

## Approach taken

`src/Client/AmqpDecoder.php`: the internal decoding path was converted to the
in/out position convention. A new private `decodeValueInPlace(string $data,
int &$position, int $depth, int $maxDepth): mixed` carries the former body of
`decodeValue()`; every `read*` helper (25 of them) now advances `$position` in
place (`int &$position`) and returns the plain decoded value instead of
`[value, newPosition]`. `decodeMessage()` and `readDescribedType()` inlined the
`readDescribedTypeWithPosition()` triple-tuple step (that helper is gone).

The **public** `AmqpDecoder::decodeValue(string $data, int $position, ...): array`
signature is kept unchanged as a thin wrapper (`[$value, $position]`) over
`decodeValueInPlace()`, so `tests/Client/AmqpDecoderTest.php` (~40 call sites
destructuring the tuple) and any external callers pass untouched — the issue's
"existing decoder unit tests pass unchanged in behaviour" criterion is met
literally: the test file is byte-identical.

The one tuple the public wrapper still allocates is per *top-level* value
(actually only exercised by tests / external callers — the hot path
`decodeMessage()` never goes through it), versus one per scalar/map-key/
list-element before.

## Rejected

- **Stateful decoder object** (`private int $pos`): heavier change (callers
  `Consumer` / `OsirisChunkParser` / `Message` would construct/hold an
  instance per message or per frame), and static statelessness is part of the
  current API shape. The `&$position` in/out parameter achieves the same
  allocation profile with a purely mechanical diff.
- **Changing the public `decodeValue()` signature to `&$position`**: would
  break the existing decoder tests (literal `0` cannot bind to a by-ref
  parameter) and any external callers, for no extra win — the hot path is
  internal.
- **Removing the public tuple-returning `decodeValue()` entirely**: BC break;
  rejected for the same reason.

## Uncertainties

- Benchmark methodology is a hand-rolled micro-bench (`/tmp/bench405.php`,
  recorded below), not the issue's "chunk of 10k recorded messages" from a
  real stream — the message shape (Properties list + 2-entry Properties list,
  2-pair application-properties map, 12-byte body) approximates the issue's
  "properties list and a 5-entry application-properties map". Numbers:
  **before 23.0 ms (2.30 µs/msg) → after 21.2 ms (2.12 µs/msg)** for 10k
  messages, ~8%. The issue's ~1 M array-alloc/s estimate assumed 20
  `decodeValue()` calls per message at 50k msg/s; the win here is proportionally
  smaller because this synthetic message is small. A real-stream benchmark
  (bench-batch stream used by prior perf PRs) would be more representative and
  is left to the PR reviewer / E2E run.
- PHP 8.1–8.4 may report different ratios; measured on PHP 8.4 (CLI, darwin).
