# Findings Review — #470 (appended across rounds)

## Round 1

- `src/Request/ConsumerUpdateReplyV1.php:30` | bare-int `offsetType` accepts types outside 0–5 | low | **FIXED** — constructor now throws `\InvalidArgumentException` for types < 0 or > 5; covered by `testRejectsInvalidOffsetType`.
- `src/Request/ConsumerUpdateReplyV1.php:57` | `toArray()` reports offset for value-less types | nit | **FIXED** — `toArray()` now returns `null` for value-less offset types; covered by `testToArrayOmitsOffsetForValuelessTypes`.
- `src/VO/OffsetSpec.php:87-91` | value emission keyed on `value !== null`, not type (same bug class as #470) | low | **NOT FIXED (out of scope)** — pre-existing, adjacent file; candidate for a follow-up issue.
- `src/Request/ConsumerUpdateReplyV1.php:44` | timestamp serialized as uint64, spec says int64 | nit | **NOT FIXED (by design)** — pre-existing behaviour shared with Subscribe/OffsetSpec; epoch-ms is never negative in practice. Candidate follow-up if strict conformance is wanted.

## Round 2

- `src/Request/ConsumerUpdateReplyV1.php:34` | round-1 fix used global `\InvalidArgumentException` instead of the library's custom exception (convention since #242) | low | **FIXED** — now throws `CrazyGoat\RabbitStream\Exception\InvalidArgumentException`; test updated; all checks green.

## Round 3

- Review verdict: **clean**. Round-2 fix verified; no remaining open findings. The two documented "not fixed" out-of-scope items remain follow-up candidates.


