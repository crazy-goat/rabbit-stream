# Findings — Issue #470

## Obstacles / surprises

1. **Stale default-reply test** — `tests/StreamConnectionTest.php:993-998`
   (`testDispatchConsumerUpdateWithoutCallbackSendsDefaultReply`) asserted the
   default `none` reply carried an 8-byte zero offset at bytes 12–20, i.e. it
   codified the buggy wire format. Updated to assert the frame ends at 12
   bytes. Anyone hitting the same failure: the test, not the fix, was wrong.
2. **`vendor/` not installed** in the worktree at start; `composer install`
   needed before any QA command.

## Bugs / weak spots noticed (out of scope)

1. **`ConsumerUpdateReplyV1::toArray()`** (`src/Request/ConsumerUpdateReplyV1.php:42-51`)
   always reports `offset`, even for value-less types — mildly misleading in
   dumps. Suggested fix: report `'offset' => null` when `offsetType < 4`, or
   switch to an `OffsetSpec` VO field so type/value stay consistent.
2. **Raw `int` offsetType in the reply constructor** — the API takes a bare
   int and validates only at `StreamConnection` level
   (`src/StreamConnection.php:1039-1043`). Accepting `OffsetSpec` (or a small
   `OffsetTypeEnum`) in `ConsumerUpdateReplyV1` would make invalid types
   unrepresentable. Suggested as a follow-up refactor (public API change).
3. **`OffsetSpec::toStreamBuffer()`** keys value-emission on `value !== null`
   rather than on the type (`src/VO/OffsetSpec.php:89`). An `OffsetSpec`
   constructed as `new self(TYPE_FIRST, 123)` would emit a value for a
   value-less type. Same pattern as the bug fixed here. Suggested fix: guard
   on type in addition to null, or make valueless factories ignore any value.
4. **`ConsumerUpdateReplyV1` does not validate offsetType at all** — unlike
   `StreamConnection`, calling `new ConsumerUpdateReplyV1(1, 99, 0)` and
   serializing produces a protocol violation silently. Suggested fix: throw
   `InvalidArgumentException` in the constructor or `toStreamBuffer()` for
   types outside 0–5 (and outside 4/5 with a nonzero-requiring value).
