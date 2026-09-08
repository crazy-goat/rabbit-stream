# Findings — review 1 (issue #404)

1. `src/VO/PublishedMessageV2.php:59` (toWire) — **Low** — open
   No direct unit tests for `PublishedMessageV2::toWire()`: only indirect coverage
   through `PublishRequestV2Test`. The validation branches (negative publishingId,
   filterValue length > 32767, invalid UTF-8 filterValue, body length > INT32_MAX)
   are untested. (V1 `PublishedMessage::toWire()` has the same gap; mirror-fix both
   in a follow-up or in this PR.)

2. `src/VO/PublishedMessageV2.php:11-13` — **Low** — open
   Validation constants `UINT64_MAX`, `INT16_MAX`, `INT32_MAX` are now duplicated
   across `PublishedMessage`, `PublishedMessageV2`, and `WriteBuffer`. If
   `WriteBuffer` limits change, the copies drift silently. Follow-up: extract a
   shared wire-encode/validation trait.

3. `src/VO/PublishedMessageV2.php:61-71` (toWire) — **Low** — open
   Validation order differs from `WriteBuffer::addString()`: `toWire()` checks
   filterValue length before UTF-8 validity, `addString()` checks UTF-8 first. For
   input violating both, a different exception message is thrown than on the old
   path. Single-fault inputs are exception-identical; cosmetic only.

4. `src/VO/PublishedMessageV2.php:79-80` (toWire) — **Nit** — open
   `pack('J', $this->publishingId) . pack('n', $filterLength)` could be a single
   `pack('Jn', $this->publishingId, $filterLength)`, matching
   `PublishedMessage::toWire()`'s combined `pack('JN', ...)` style.

5. `src/VO/PublishedMessageV2.php:60` (toWire) — **Nit** — open
   The `$this->publishingId > self::UINT64_MAX` upper-bound check is dead code on
   64-bit PHP (`UINT64_MAX === PHP_INT_MAX`), and `< 0` is unreachable in practice
   since `Producer` assigns ids. Kept for parity with V1 and 32-bit safety — no
   action required.
