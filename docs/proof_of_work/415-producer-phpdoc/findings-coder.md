# Findings — #415 Producer public API PHPDoc and @throws

## Obstacles

- The issue's line numbers (`send` :80, `waitForConfirms` :112, …) are stale:
  the file has grown to ~590 lines and now has 13 public methods. The
  docblock pass therefore targeted every public method via reflection
  (`\ReflectionClass(Producer::class)`) rather than the five named in the
  issue.
- `ConnectionException` and `DeserializationException` were not imported in
  `Producer.php` because no `throw` statement references them. The `@throws`
  tags resolve against the current namespace, so PHPStan would have reported
  unknown classes; both were added to the `use` block in alphabetical order.
  `phpcs`'s `UnusedUses` is configured with `searchAnnotations=true`, so
  annotation-only imports are accepted.
- The `@return` descriptions had to satisfy two masters: the reflection gate
  requires prose after the type, and Rector (FAQ-008) strips a bare
  `@return <native type>`. Every `@return` was written with a one-line
  description on the same line, and `@return void` is omitted entirely.

## Bugs / weak spots noticed (with suggested fixes)

1. **`docs/en/api-reference/producer.md` — `sendWithFilter()` notes were
   wrong (documentation bug, fixed).** They claimed the message is "sent via
   the same `PublishRequestV2`/`PublishedMessageV2` frame as a normal
   publish". The code sends a v1 frame when `$filterValue === null` (or the
   broker lacks v2); v1 has no filter field. Corrected to describe both
   paths.
2. **`docs/en/api-reference/producer.md` — `close()` exception list was
   wrong (documentation bug, fixed).** It listed only "`ConnectionException`
   - If the connection is already closed". `close()` sends `DeletePublisher`,
   reads its response and drains confirms, so it can raise
   `ConnectionException`, `DeserializationException`,
   `InvalidArgumentException`, `ProtocolException` and `TimeoutException`.
   Corrected.
3. **`docs/en/api-reference/producer.md` — `isClosed()` was missing
   (documentation gap, fixed).** The method is public but had no section and
   was absent from the class-overview method list. Added.
4. **`docs/en/examples/named-producer-deduplication.md:250` — "Returns
   `null` if no messages have been published yet" (documentation bug,
   fixed).** False for a named producer: construction queries the sequence
   and `getLastPublishingId()` returns `0` (or the stored sequence) before
   the first `send()`. Corrected, and the same clarification was added to
   `docs/en/guide/publishing.md`.
5. **`src/Contract/ProducerInterface.php` — five methods had no docblock at
   all** (`close`, `waitForConfirms`, `getLastPublishingId`, `querySequence`,
   `getPendingConfirms`). Brought in line with the class, mirroring #416's
   `ConsumerInterface` update.
6. **Pre-existing, not changed: `close()` leaves stranded ids in
   `pendingConfirms` after a timed-out drain.** Noted in #522's findings.
   `getPendingConfirms()` stays `> 0` after `close()`, which the new
   `getPendingConfirms()` docblock now states explicitly rather than
   implying it resets. Changing it would be a behaviour change beyond
   documentation.

## Test / verification notes

- New gate: `tests/Client/ProducerDocblockTest.php` — 3 tests, 8 assertions,
  passes. It fails if a public `Producer` method loses its docblock, prose
  description, a `@param`, a described `@return`, or documents a `@throws`
  class/interface that does not exist.
- Unit: `./vendor/bin/phpunit --testsuite unit` → OK (1240 tests, 8794
  assertions).
- Lint: `composer lint` (PHPCS PSR-12 + Rector dry-run + PHPStan level 9 +
  kb-lint + docs link check + test-suite coverage) → OK. Rector's dry run
  made no changes, confirming the `@return` descriptions survive the
  DEAD_CODE set.
- No production logic changed; `src/` diff is docblocks and two imports only.
