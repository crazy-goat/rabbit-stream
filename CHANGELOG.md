# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/).

## [Unreleased]

### Fixed
- **Security + Performance: `StreamConnection` no longer `bin2hex()`-logs every frame unconditionally** — `sendFrame()` and `readFrame()` previously called `bin2hex()` on every frame and logged the result at `debug` level, which (a) leaked the plaintext broker password and all message payloads into application logs whenever debug logging was enabled (the SASL `PLAIN` request body contains `\0username\0password` verbatim), and (b) paid the `bin2hex()` cost on every frame even with a `NullLogger` or any logger filtering out `debug` (~25% of a core and ~1 GB/s allocator churn for a 1 MB/frame consumer at 500 fps). Frame dumping is now gated behind a `$debugLogging` flag resolved once in the constructor (`!$logger instanceof NullLogger`, default off), and `SASL_AUTHENTICATE` request frames are always redacted as `<redacted: SASL_AUTHENTICATE, N bytes>` even when dumping is on. Acceptance criteria from the issue all met; unit tests with a PSR-3 spy logger assert no credential bytes (raw or hex) reach the logger (#401)
- **Docs: removed non-existent `Connection::getStreamConnection()` and other invented API calls from the English documentation** — 37 calls to `Connection::getStreamConnection()` (which never existed on the high-level `Connection`) and ~150 calls to `sendMessage()`/`readMessage()`/`registerPublisher()`/`registerSubscriber()`/`stop()`/`registerDeliverCallback()` on the high-level `Connection` (where they do not exist) were rewritten across 9 doc files to use the real high-level API (`createStream`, `deleteStream`, `createSuperStream`, `deleteSuperStream`, `route`, `getMetadata`, `getStreamStats`, `queryOffset`, `storeOffset`, `createProducer`, `createConsumer`) and labelled low-level `StreamConnection` sections where no high-level wrapper exists. Also corrected invented APIs (`OffsetType` enum, `OffsetSpecification`, `DeliverResponseV1::getMessages()`, `StreamMetadata::getReplicaReferences()`, wrong `OffsetSpec` type values) and missing `use` imports. Every "complete working example" in the stream-management and super-stream docs now fatals no longer on its first line (#423)

### Changed
- **Producer: `send()`/`sendBatch()` now wrap payloads in an AMQP 1.0 Data section** — payloads are no longer put on the wire verbatim, so a publish→consume round trip through `Consumer::read()` works out of the box (it previously threw `DeserializationException`). `Message::getBody()` returns the same plain string that was passed to `send()`. Raw pre-encoded AMQP 1.0 bytes can still be published via the low-level API with the new `AmqpMessageEncoder::encodeDataSection()` helper (#413)
- **WriteBuffer: make UTF-8 validation opt-in** — added `$validateStrings` constructor parameter (default `true`). Set to `false` to skip `mb_check_encoding` in high-throughput scenarios where input strings are guaranteed valid UTF-8 (#350)
- **OsirisChunkParser: use `ReadBuffer::readBytes()` consistently** — replaced 3 instances of raw `substr` + `skip()` with `ReadBuffer::readBytes()` for atomic position tracking (#351)

### Added
- **Process: CI job names match the required status checks** — `.github/workflows/ci.yml` job names are now byte-identical to the branch protection contexts required on `main` (`lint`, `unit-tests (PHP 8.1)` … `unit-tests (PHP 8.4)`, `e2e-tests`). They previously used human-friendly names (`Lint (PHPCS + Rector + PHPStan)`, `Unit Tests (PHP 8.1)`, `E2E Tests`), so the required contexts were never reported and every pull request sat at `BLOCKED` with all checks green — merging was only possible by bypassing protection. Added a comment in `ci.yml` and a note in `docs/workflow.md` so a future rename does not silently reintroduce it
- **Process: subagent workflow** — `docs/workflow.md` describing the issue → feature branch → implementation → review rounds → PR → CI → merge cycle, adapted from the workerman-bundle workflow to this project's conventions (`main`, `feature/issue-NNN-*` branches, `composer lint` / `composer test` / `run-e2e.sh`, PHPStan level 9, milestone-driven backlog)
- **Process: knowledge base** — `docs/helpers/` (`faq.md`, `decisions.md`, `README.md`) with a generated tag index, single-writer rule and decay rules, linted by `bin/kb-lint.php` (runs inside `composer lint`)
- **Process: proof of work** — `docs/proof_of_work/` directory with a README documenting the four per-cycle Markdown files (`findings-coder.md`, `findings-review.md`, `code-decision-<x>.md`, `review-<x>.md`)
- **Process: pre-push hook** — `bin/hooks/pre-push` runs `composer lint` before every push; installed via `bash bin/install-hooks.sh` (symlinks `bin/hooks/*` into `.git/hooks/`)
- **E2E test: MetadataUpdate callback on stream deletion** — E2E test verifying that when a stream is deleted from one connection while another connection is subscribed, the subscribed connection receives a `MetadataUpdate` frame via the `onMetadataUpdate` callback with the correct stream name and response code (#160)
- **E2E test: Multiple concurrent subscriptions on different streams** — two new E2E tests verifying that multiple subscriptions on a single connection, targeting different streams, correctly isolate message routing: each consumer receives only its own stream's messages; closing one consumer does not affect the other (#154)
- **E2E test: SASL mechanism validation** — two new E2E tests verifying that unsupported SASL mechanisms (`SCRAM-SHA-256` and empty string) are rejected with `ProtocolException` containing `SASL_MECHANISM_NOT_SUPPORTED` (#148)
- **E2E test: Server-initiated close handling** — comprehensive E2E test that force-closes a connection via RabbitMQ management API and verifies that subsequent operations throw `ConnectionException` and `isConnected()` correctly returns `false` (#151)
- **E2E test: Multiple publishers on a single connection** — comprehensive E2E test suite verifying that multiple producers on the same connection receive independent confirmations; tests independent publishing IDs across different streams, on the same stream, with batch sends, isolation after closing one producer, and sequential send/wait per producer (#156)
- **E2E tests: Subscribe with OffsetSpec::offset(N)** — three new E2E tests covering subscription offset scenarios:
  - `testSubscribeFromSpecificOffset` — store offset, resume with `first()`, filter in PHP (workaround for RabbitMQ 4.3.0 TYPE_OFFSET bug)
  - `testSubscribeFromOffsetZero` — `OffsetSpec::offset(0)` behaves like `first()`
  - `testSubscribeFromOffsetBeyondEnd` — subscribe with `next()` at stream end, read initial empty, publish then receive new messages (#153)
- **E2E test: Subscribe with OffsetSpec::timestamp()** — two new E2E tests covering time-based offset subscription: `testSubscribeFromTimestamp` verifies messages published after a recorded timestamp are delivered while earlier messages are skipped; `testSubscribeFromFutureTimestampReturnsNoMessages` verifies subscribing at a future timestamp yields no messages (#155)
- **E2E test: Heartbeat handling** — comprehensive E2E test suite covering heartbeat echo/response, multiple heartbeat callbacks via `readLoop()`, correlation ID desync regression test (ensures protocol state not corrupted after heartbeat, regression for #101), clearing heartbeat callback via null, and replacing heartbeat callback (#161)
- **E2E test: readMessage() timeout behavior** — three new E2E tests verifying that `readMessage()` with a 1-second timeout throws `TimeoutException` after approximately 1 second, that the connection remains usable after a timeout, and that zero-timeout returns immediately (#165)
- **E2E test: Empty messages and messages with special characters** — comprehensive E2E test suite covering edge cases: empty message body (0 bytes), null bytes, UTF-8 multibyte characters (emoji/Unicode), binary data, and mixed batch publishing/consuming with content integrity verification (#167)
- **E2E test: Named producer deduplication across reconnect** — comprehensive E2E test verifying that named producers with reference strings correctly deduplicate messages across connection reconnects; tests publish → close → reconnect → resume publishing → verify no duplicates (#173)
- **Producer automatic sequence resume** — when creating a named producer, it automatically queries the last sequence from the server and resumes from `sequence + 1`; enables seamless deduplication without manual intervention (matches Java client behavior)
- **E2E test: Producer-consumer lifecycle with offset tracking and resume** — comprehensive E2E test covering the complete producer-consumer workflow; tests publish → confirm → consume → store offset → query offset → resume with named consumer; verifies message ordering and data integrity across consumer sessions (#172)
- **Connection::create() unit tests** — comprehensive test coverage for handshake validation and error paths; tests wrong response types at each step, missing PLAIN mechanism, tune negotiation, serializer/logger injection, and vhost parameter passing (#193)
- **Connection::create()** — added optional `requestedFrameMax` and `requestedHeartbeat` parameters for client-side Tune negotiation; implements proper negotiation logic per RabbitMQ Stream Protocol (min of client/server values, with 0 = no limit); includes input validation and comprehensive unit tests (#217)
- **Custom Exception Hierarchy** — replaced all generic `\Exception`, `\RuntimeException`, and `\InvalidArgumentException` with domain-specific exceptions (#242):
  - `RabbitStreamException` — base exception (extends `\RuntimeException`)
  - `ConnectionException` — socket/connection errors
  - `TimeoutException` — read/write timeouts (extends ConnectionException)
  - `ProtocolException` — protocol errors with optional `ResponseCodeEnum`
  - `AuthenticationException` — SASL authentication failures
  - `UnexpectedResponseException` — unexpected response types with `create()` factory
  - `DeserializationException` — buffer/parsing errors
  - `InvalidArgumentException` — input validation (extends native `\InvalidArgumentException`)

### Fixed
- **StreamConnection: `readLoop()` select timeout `tv_usec` overflow** — `socket_select()` received `tv_usec >= 1_000_000` for any timeout > 1 s because seconds were clamped to 1 but microseconds were computed from the *unclamped* remainder. On Linux `select(2)` rejects `tv_usec >= 1e6` with `EINVAL`, so the default `Consumer::read()` (timeout 5.0 s) threw `ConnectionException: socket_select failed: Invalid argument` on every call; on macOS the poll blocked for the full timeout in one call, losing `stop()`/deadline responsiveness. Both halves are now derived from `capped = min($remaining, 1)`, so `tv_usec` is always `< 1_000_000`. Added a > 1 s unit regression test and an E2E > 1 s timeout case (#382)
- **CI: 6 test files silently excluded from the `unit` testsuite** — `phpunit.xml` listed only `tests/Buffer`, `tests/Client`, `tests/Request`, `tests/Response`, `tests/Util`, `tests/VO` and `tests/StreamConnectionTest.php`, so `--testsuite unit` (and therefore CI, which runs `./vendor/bin/phpunit --testsuite unit`) never executed `tests/ResponseBuilderTest.php`, `tests/Enum/KeyEnumTest.php`, `tests/Enum/ResponseCodeEnumTest.php`, `tests/Serializer/PhpBinarySerializerTest.php`, `tests/Trait/CommandTraitTest.php` and `tests/Contract/InterfaceImplementationTest.php` — 182 tests, including the ResponseBuilder dispatch tests (the protocol routing core). All pass when included. Added `tests/Contract`, `tests/Enum`, `tests/Serializer`, `tests/Trait` directories and `tests/ResponseBuilderTest.php` to the `unit` suite (#459)
- **OsirisChunkParser: sub-batch entry header parsed as 1+2+4+4 bytes (was 4 bytes)** — the parser read a 4-byte uint32 header for sub-batch entries, but the real Osiris `SubBatchEntry (CHNK_USER)` layout is 1 byte (T=1 | 3-bit Cmp codec | 4-bit Rsvd) + `numRecords` (uint16) + `UncompressedLength` (uint32) + `Length` (uint32) + body. The wrong layout shifted every sub-batch read by a byte, mis-extracted the compression codec (so the "compressed sub-batch not supported" guard never fired for zstd/lz4/snappy/gzip), and could read past the buffer on chunks written with sub-entry batching by a Java/Go client. The discriminator is now read as byte 0; the simple-entry path is unchanged (31-bit length reconstructed from byte 0 + the next 3 bytes, byte-equivalent to the old `uint32 & 0x7FFFFFFF`). The unit-test fixture was rebuilt to the real layout and two regression tests were added (512-record sub-batch; zstd codec guard). Two truncation tests and an empty-sub-batch test were added to lock in the `ReadBuffer` underflow guard on the untrusted-data consume path (#383)
- **Security: AMQP decoder recursion depth limit** — `AmqpDecoder::decodeValue()` recursed into compound/described types with no depth limit, so a ~750 KB nested payload (`memory_limit=128M`) or 6 MB (default frame limit) caused an uncatchable PHP fatal `Allowed memory size exhausted` that killed the worker process. Reachable from an untrusted publisher via `Consumer::read()` → `AmqpMessageDecoder::decode()` → `AmqpDecoder::decodeMessage()`. Added a `$depth` parameter threaded through every recursive reader and a configurable `$maxDepth` (default 32) on `decodeValue()`/`decodeMessage()`; exceeding it throws a catchable `DeserializationException` (`RabbitStreamExceptionInterface`) instead of a fatal. Public API signatures are preserved via optional parameters (#397)

### Changed
- **Performance: Consumer::subscribe** — replaced `array_merge($this->buffer, $messages)` with `array_push($this->buffer, ...$messages)` in the delivery callback hot path to avoid O(n) full-array copy on every delivery; reduces GC pressure when buffer is large (#348)
- **E2E test infrastructure** — 19 E2E test classes now extend `E2ETestCase` instead of duplicating `$host`, `$port`, and `setUpBeforeClass()` boilerplate; added `createConnection()` helper and parametrized `connectAndOpen()` to `E2ETestCase` (79 insertions, 348 deletions) (#346)

### Documentation
- `WriteBuffer::addInt16()`, `addInt32()`, `addInt64()` — added comprehensive PHPDoc comments explaining why unsigned pack formats ('n', 'N', 'J') are used intentionally for signed integers; documents PHP's two's complement behavior and references ReadBuffer reverse conversion methods (#213)

### Changed
- `ConsumerUpdateReplyV1` — refactored to use `CorrelationTrait` and `CommandTrait::getKeyVersion()` patterns, consistent with all other request classes; implements `CorrelationInterface` and sets correlation ID via `withCorrelationId()` instead of constructor parameter (#196)

### Fixed
- **`ReadBuffer::getString()`/`getBytes()` — reject negative lengths (remote OOM, pre-auth)** — both methods read a signed int16/int32 length and only special-cased the `-1` null sentinel; any other negative length (e.g. `0xFFFE`) passed `ensureAvailable()` (negative < available), made `substr()` return a near-full-buffer suffix, and moved `position` **backward** — zero net bytes consumed per call. Combined with unbounded `getUint32()` array counts in `getStringArray()`/`getObjectArray()`, a malicious broker or MITM could send `count=0xFFFFFFFF` + `len=0xFFFE` to loop forever appending copies of the frame body → OOM. Reachable **before authentication** via `SaslHandshakeResponseV1::getStringArray()` and `PeerPropertiesResponseV1::getObjectArray()`. Now: non-sentinel negative lengths throw `DeserializationException`, and array counts are bounded against the remaining buffer before the loop (≥ 2 bytes per string element, ≥ 1 per object). No wire-format change; valid input (including empty strings/bytes and zero-count arrays) still deserializes as before (#384)
- **`Producer::waitForConfirms()` — no longer blocks for the full timeout** — it called `StreamConnection::readLoop()` without `maxFrames`, and `readLoop()` never inspects `pendingConfirms`, so the loop kept running until the deadline expired even when the broker confirmed in milliseconds. Now passes `maxFrames: 1` (the pattern `Consumer::read()` already used), so the call returns as soon as the last confirm is dispatched. Every publish-then-wait workload previously ran at the timeout value instead of the broker's real confirm latency; the E2E suite dropped from ~302s to ~20s as a side effect. Upgrade note: `waitForConfirms()` no longer incidentally services the socket for the whole timeout, so an application that relied on it to dispatch unrelated server-push frames (deliveries, metadata updates) must call `readLoop()` itself (#385)
- **E2E test `ConsumerTest::testSubscribeFromTimestamp` — boundary derived from broker data** — the test sampled the client wall clock for its timestamp boundary, which could land in the same millisecond as the broker's "before" chunk; since `OffsetSpec::timestamp()` resolves to the first chunk with `chunkTs >= t` and delivers it in full, that leaked `before-*` messages into the filtered read. It now reads the stream back and derives the boundary from `Message::getTimestamp()` (the per-chunk timestamp the broker actually wrote), so it never compares a client clock to a broker clock (#385)
- `StreamConnection::readBytes()` — now sets `$this->connected = false` when a socket read error or EOF is detected (matching the pattern already used in `sendFrame()`); enables `isConnected()` to correctly report false after a server-initiated close or network failure (#151)
- E2E test handshake — `StreamStatsTest`, `ExchangeCommandVersionsTest`, and `PartitionsTest` now correctly use `TuneResponseV1` instead of `TuneRequestV1` when responding to the server's tune handshake; per protocol spec, client should respond with key 0x8014 (response bit set) not 0x0014 (#178)
- `StreamStatsResponseV1` — now uses `assertResponseCodeOk()` from `CommandTrait` instead of manual magic number check; provides consistent error messages with enum names and descriptions like all other response classes (#199)
- `Connection::close()` — now properly closes all outstanding producers and consumers before closing the connection; prevents server-side resource leaks and ensures consumer offsets are stored when autoCommit is enabled (#206)
- `PublishingError` — now implements `FromStreamBufferInterface` and changed return type from `self` to `?static` to match interface contract; enables polymorphic use with other VO classes (#201)
- `OpenRequest` → renamed to `OpenRequestV1` to follow naming convention; fixed interface declaration order to match other request classes (#195)
- `StreamConnection::unregisterPublisher()` — added missing method to clean up publisher callbacks when `Producer::close()` is called; fixes resource leak where publisher callbacks accumulated in memory (#202)
- `AmqpDecoder::readUint64()` — replaced bit-shifting implementation with `unpack('J')` to fix integer overflow on 32-bit PHP and negative value issues on 64-bit PHP when reading large uint64 values (#220)

## [1.1.0] - 2026-03-21

### Added
- Unit tests for `ConfirmationStatus` and `ChunkEntry` VO classes — comprehensive test coverage for all getters and edge cases (#192)

### Changed
- `ConsumerTest` — reduced excessive timeouts in E2E deadline loops: read timeout 2s→0.5s, deadline 10s→5s; saves ~2-3 seconds per test run (#138)
- `ProducerTest` — refactored to use `setUpBeforeClass()` pattern with static properties for consistency with other E2E tests; changed `$_ENV` to `getenv()` for environment variable access (#138)
- `PublishTest::testPublishMultipleMessages()` — changed from `$connection->readLoop(maxFrames: 3)` to `$producer->waitForConfirms(timeout: 5.0)` to fix 30-second timeout caused by RabbitMQ batching confirms into single frame; test now completes in ~5s (6x faster) (#137)
- `PublishTest::testPublishSingleMessage()` — changed to use `$producer->waitForConfirms()` for consistency
- `CommandTrait::assertResponseCodeOk()` — renamed from `isResponseCodeOk()` to better communicate its throwing behavior; error messages now include hex response code, enum name, and human-readable description for easier debugging (#109)

### Security
- `SaslAuthenticateRequestV1::toArray()` — password is now masked as `'***'` to prevent credential leakage in logs/debug output; added `__debugInfo()` to protect `var_dump()`/`print_r()` output (#112)
- `StreamConnection` — configurable max frame size limit (default 8MB) to prevent memory exhaustion from malformed frames; `setMaxFrameSize(0)` disables the limit; connection is closed before throwing on violation

### Fixed
- `WriteBuffer::addString()` — replaced unreliable `mb_convert_encoding($value, 'UTF-8', 'auto')` with strict UTF-8 validation using `mb_check_encoding()`; prevents silent data corruption from misidentified encodings (#117)
- `Connection` and `StreamConnection` — added `__destruct()` methods to ensure sockets are properly closed when objects are garbage collected, preventing resource leaks when users forget to call `close()` explicitly (#113)
- `Connection::create()` — added response type validation for PeerProperties, SaslAuthenticate, and Open handshake steps; previously these responses were silently discarded, masking server errors (#106)
- `StreamConnection::connect()` — fixed use of unassigned `$this->socket` in error path that masked real connection failures; socket is now properly closed before throwing (#104)
- `StreamConnection::readLoop()` — non-server-push frames are now logged as warnings via PSR logger instead of being silently discarded (#104)
- `DeliverResponseV1::fromStreamBuffer()` — Deliver v2 frames no longer throw "Unexpected version"; validates key only, allowing both v1 and v2 frames to be processed correctly (#99)
- `StreamConnection::dispatchServerPush()` — heartbeat echo now uses `sendFrame()` directly instead of `sendMessage()`, preventing spurious `correlationId` increments that caused ID mismatches in long-running connections
- `StreamConnection::sendMessage()` — `correlationId` is now only incremented for requests implementing `CorrelationInterface`, fixing drift caused by `PublishRequestV1`, `CreditRequestV1`, `StoreOffsetRequestV1`, and `TuneResponseV1`

## [1.0.0] - 2026-03-20

### Changed
- **PHPStan level 9 (max)** — bumped static analysis from level 0 to level 9 incrementally:
  - Created `src/Util/TypeCast.php` utility class for safe type narrowing from `mixed`
  - Added PHPDoc array type annotations across 76+ files (level 6)
  - Changed `FromStreamBufferInterface::fromStreamBuffer()` return type to `?static` (level 7)
  - Fixed null safety issues across connection and response classes (level 8)
  - Fixed all `mixed` type strictness violations with proper type casting (level 9)

### Added
- **High-Level Client API** — `Connection`, `Producer`, `Consumer`, `Message` classes providing a simple, user-friendly API on top of the low-level protocol implementation
- **PHP_CodeSniffer with PSR-12 and Slevomat Coding Standard** — comprehensive code style enforcement:
  - Added `phpcsstandards/php_codesniffer` ^3.9 and `slevomat/coding-standard` ^8.15 to dev dependencies
  - Created `phpcs.xml.dist` with strict rules (PSR-12 + Slevomat)
  - Added `composer lint` and `composer lint:fix` scripts
  - CI runs linting as a gate before tests
  - All 184 PHP files now comply with strict standards
  - Enforces: `declare(strict_types=1)`, alphabetically sorted uses, no unused imports, short array syntax, trailing commas

### Removed
- **`StreamClient`** — deprecated high-level client (use `Connection::create()` instead)
- **`StreamClientConfig`** — deprecated config class (use `Connection::create()` parameters instead)
- **`ProducerConfig`** — deprecated config class (use `Connection::createProducer()` parameters instead)
- **`examples/legacy/`** — removed legacy example scripts

### Changed (BREAKING)
- **`ReadBuffer::gatString()` → `getString()`** — fixed typo in method name
- **`CorrelationInterface`** — moved from `CrazyGoat\StreamyCarrot\Trait\` to `CrazyGoat\StreamyCarrot\Contract\`
- **`KeyVersionInterface`** — moved from `CrazyGoat\StreamyCarrot\Trait\` to `CrazyGoat\StreamyCarrot\Contract\`
- **`static public` → `public static`** — all classes now follow PSR-12 method ordering
- **Error messages in `WriteBuffer`** — translated from Polish to English

### Added
- **Timeout precision improvement** — all read and write operations now support sub-second (float) timeouts:
  - `StreamConnection::readMessage()`, `readFrame()`, `readLoop()` — accept `float` timeout (e.g., `0.5` for 500ms)
  - `Consumer::read()`, `readOne()` — accept `float` timeout
  - `Producer::waitForConfirms()` — accept `float` timeout
  - `Producer::send()`, `sendBatch()` — optional write timeout to limit blocking on socket write
  - Internal deadline tracking uses `microtime(true)` for millisecond precision
  - All timeout exceptions now consistently use `\RuntimeException`
- `Consumer` class with pull-based `read()`/`readOne()`, auto-commit, offset management
- `OsirisChunkParser` — parses delivery chunks into individual messages
- `AmqpDecoder` / `AmqpMessageDecoder` — decodes AMQP 1.0 messages
- `Message` value object with body, properties, application-properties
- `BinarySerializerInterface` — swappable serialization backend
- `toArray()` on all Request classes, `fromArray()` on all Response classes
- New examples: `examples/producer.php`, `examples/consumer.php`, `examples/consumer_auto_commit.php`, `examples/stream_management.php`
- Quick Start section in README.md
- `Connection` — new high-level entry point class replacing `StreamClient` with full handshake, stream management, and producer/consumer factory methods
- `Connection::create()` — factory method with automatic handshake (PeerProperties → SASL → Tune → Open)
- `Connection::createStream()`, `Connection::deleteStream()`, `Connection::streamExists()` — stream management methods
- `Connection::getStreamStats()`, `Connection::getMetadata()`, `Connection::queryOffset()` — metadata operations
- `Connection::close()` — graceful shutdown with CloseRequestV1
- `Connection::createProducer()`, `Connection::createConsumer()` — factory methods for producers and consumers
- `Producer::sendBatch()` — send multiple messages in a single frame
- `Producer::waitForConfirms()` — block until all pending publish confirms are received (with timeout)
- `Producer::getLastPublishingId()` — returns the last used publishing ID (returns `null` before first `send()`)
- `Producer::querySequence()` — queries the server for the last confirmed publishing ID (named producers only)
- `Connection::readLoop()`, `Connection::storeOffset()` — additional convenience methods
- `Consumer` — stub class for future consumer implementation
- Unit tests for `Connection` class
- E2E tests for `Connection` class
- `SaslHandshakeResponseV1::getMechanisms()` — getter for available SASL mechanisms
- `Message` — value object representing a decoded AMQP 1.0 message with offset, timestamp, body, properties, and application properties
- `AmqpDecoder` — low-level AMQP 1.0 binary decoder supporting all common types (null, bool, integers, strings, binary, lists, maps, described types)
- `AmqpMessageDecoder` — high-level decoder converting `ChunkEntry` objects into `Message` objects
- `AmqpDecoderTest` — 45 unit tests for AMQP type decoding
- `AmqpDecoderMessageTest` — 14 unit tests for message section parsing
- `AmqpMessageDecoderTest` — 12 unit tests for ChunkEntry to Message conversion
- Support for decoding AMQP 1.0 message sections: Header, Properties, ApplicationProperties, MessageAnnotations, Data, AmqpValue, Footer
- Convenience getters on `Message`: `getMessageId()`, `getCorrelationId()`, `getContentType()`, `getSubject()`, `getCreationTime()`, `getGroupId()`
- `PhpBinarySerializer` — PHP implementation wrapping existing WriteBuffer/ReadBuffer/ResponseBuilder
- 29 unit tests for PhpBinarySerializer covering 16 request types and 12 response types
- `StreamConnection` now accepts optional `BinarySerializerInterface` parameter (defaults to `PhpBinarySerializer`) — swapping serialization backend is now a one-line change
- `ToArrayInterface` and `FromArrayInterface` in `src/Buffer/` — foundation for swappable serialization backends
- `toArray()` on all 28 Request classes and 9 VO classes
- `fromArray()` on all 28 Response classes and 6 VO classes
- 57 roundtrip tests for `toArray()`/`fromArray()`
- `ResolveOffsetSpecRequestV1` — client-side request to resolve offset specification to concrete offset (key `0x001f`)
- `ResolveOffsetSpecResponseV1` — server response with resolved offset value and offset type (key `0x801f`)
- `KeyEnum::RESOLVE_OFFSET_SPEC` (`0x001f`) and `KeyEnum::RESOLVE_OFFSET_SPEC_RESPONSE` (`0x801f`)
- Unit tests for ResolveOffsetSpec Request and Response
- E2E tests for ResolveOffsetSpec with automatic skip when server doesn't support the command (RabbitMQ < 4.3)
- `QueryPublisherSequenceRequestV1` — client-side request to query last published sequence for deduplication (key `0x0005`)
- `QueryPublisherSequenceResponseV1` — server response with sequence value (key `0x8005`)
- `KeyEnum::QUERY_PUBLISHER_SEQUENCE_RESPONSE` (`0x8005`)
- `StreamClient::queryPublisherSequence()` — high-level helper method
- E2E tests for QueryPublisherSequence command
- `QueryOffsetRequestV1` — client-side request to query stored consumer offset (key `0x000b`)
- `QueryOffsetResponseV1` — server response with stored offset value (key `0x800b`)
- `KeyEnum::QUERY_OFFSET_RESPONSE` (`0x800b`)
- E2E test for QueryOffset command
- `StreamClient` — high-level client wrapper with automatic handshake and connection management
- `StreamClientConfig` — configuration for `StreamClient`
- `Producer` — high-level producer wrapper for publishing to streams
- `ProducerConfig` — configuration for `Producer`
- `ConfirmationStatus` — represents a message confirmation or error event
- `MetadataRequestV1` — client-side request to query stream broker and replica info (key `0x000f`)
- `MetadataResponseV1` — server response with broker list and stream metadata (key `0x800f`)
- `Broker` VO — represents a broker with reference, host, and port
- `StreamMetadata` VO — represents stream info with leader and replica references
- `KeyEnum::METADATA_RESPONSE` (`0x800f`)
- `CreditRequestV1` — client-side request to grant flow-control credits (key `0x0009`)
- `CreditResponseV1` — server error response for Credit command (key `0x8009`)
- `KeyEnum::CREDIT_RESPONSE` (`0x8009`)
- `SubscribeRequestV1` — client-side request to subscribe to a stream (key `0x0007`)
- `SubscribeResponseV1` — server response for Subscribe command (key `0x8007`)
- `OffsetSpec` VO — offset specification with factory methods: `first()`, `last()`, `next()`, `offset()`, `timestamp()`, `interval()`
- `KeyEnum::SUBSCRIBE_RESPONSE` (`0x8007`)
- E2E test for Subscribe command
- `UnsubscribeRequestV1` — client-side request to unsubscribe from a stream (key `0x000c`)
- `UnsubscribeResponseV1` — server response for Unsubscribe command (key `0x800c`)
- `KeyEnum::UNSUBSCRIBE_RESPONSE` (`0x800c`)
- `PartitionsRequestV1` — client-side request to list partitions of a super stream (key `0x0019`)
- `PartitionsResponseV1` — server response with array of partition stream names (key `0x8019`)
- `KeyEnum::PARTITIONS_RESPONSE` (`0x8019`)
- E2E test for Partitions command
- `CreateSuperStreamRequestV1` — client-side request to create partitioned super stream (key `0x001d`)
- `CreateSuperStreamResponseV1` — server response for CreateSuperStream command (key `0x801d`)
- `WriteBuffer::addStringArray()` — helper method for serializing string arrays
- E2E tests for CreateSuperStream command (including verification via Partitions)
- `RouteRequestV1` — client-side request to resolve routing key to stream partition (key `0x0018`)
- `RouteResponseV1` — server response with array of matching stream names (key `0x8018`)
- `KeyEnum::ROUTE_RESPONSE` (`0x8018`)
- E2E tests for Route command (including CreateSuperStream integration)

## [0.5.0] - 2026-03-17

### Added
- `CreateRequestV1` — client-side request to create a new stream (key `0x000d`)
- `CreateResponseV1` — server response for Create command (key `0x800d`)
- `KeyEnum::CREATE_RESPONSE` (`0x800d`)
- E2E test for Create command
- PSR-3 logger support — `StreamConnection` now accepts optional `LoggerInterface` parameter
- `StreamConnectionTest` — unit tests for logger functionality

### Changed
- `StreamConnection` — replaced debug `echo` statements with `$logger->debug()` calls
- `composer.json` — added `psr/log ^3.0` dependency

### Fixed
- `run-e2e.sh` — added missing test stream creation step

## [0.4.0] - 2026-03-16

### Added
- `CloseRequestV1` — client-side graceful connection shutdown request (key `0x0016`)
- `CloseResponseV1` — server response for Close command (key `0x8016`)
- `KeyEnum::CLOSE_RESPONSE` (`0x8016`)
- E2E test for Close command

## [0.3.0] - 2026-03-15

### Added
- `HeartbeatRequestV1` — bidirectional heartbeat frame (key `0x0017`); auto-echoed by `readMessage()` and `readLoop()`
- `MetadataUpdateResponseV1` — server-push stream topology change notification (key `0x0010`)
- `ConsumerUpdateResponseV1` — server-push single-active-consumer query from server (key `0x001a`)
- `ConsumerUpdateReplyV1` — client reply to ConsumerUpdate with offset specification (key `0x801a`)
- `DeliverResponseV1` — server-push message delivery frame (key `0x0008`); raw OsirisChunk bytes
- `ReadBuffer::getRemainingBytes()` and `ReadBuffer::peekUint16()`
- `KeyEnum::CONSUMER_UPDATE_RESPONSE` (`0x801a`)
- `StreamConnection::registerPublisher(publisherId, onConfirm, onError)` — callback-based publish confirm/error handling
- `StreamConnection::registerSubscriber(subscriptionId, onDeliver)` — callback for Deliver frames
- `StreamConnection::onMetadataUpdate(callback)`, `onHeartbeat(callback)`, `onConsumerUpdate(callback)`
- `StreamConnection::readLoop(?int $maxFrames)` — blocking async dispatch loop using `socket_select()`
- `StreamConnection::stop()` — interrupt `readLoop()`
- `StreamConnection::readMessage()` now transparently handles server-push frames via `socket_select()` internal loop — callers never see Heartbeat, PublishConfirm, Deliver etc.

## [0.2.0] - 2026-03-15

### Added
- `PublishRequestV1` — client-side request to publish messages to a stream (key `0x0002`, protocol v1)
- `PublishRequestV2` — publish request with filter value support (key `0x0002`, protocol v2)
- `PublishConfirmResponseV1` — server confirmation of published messages (key `0x0003`)
- `PublishErrorResponseV1` — server error response for failed publishes (key `0x0004`)
- `PublishedMessage` VO — wraps publishing ID and message bytes for v1 publish
- `PublishedMessageV2` VO — wraps publishing ID, filter value and message bytes for v2 publish
- `PublishingError` VO — wraps publishing ID and error code from server error response
- `ReadBuffer::getUint8()` and `ReadBuffer::getUint64()` — missing buffer read methods

## [0.1.0] - 2026-03-15

### Added
- `DeclarePublisherRequestV1` — client-side request to register a publisher on a stream (key `0x0001`)
- `DeclarePublisherResponseV1` — server response for DeclarePublisher
- `KeyEnum::DECLARE_PUBLISHER_RESPONSE` (`0x8001`) registered in `KeyEnum` and `ResponseBuilder`
- GitHub Actions CI workflow — unit tests on PHP 8.1–8.4 matrix + E2E tests with RabbitMQ 4
- PHPUnit test suite — unit tests for all Request/Response/Buffer classes
- E2E test suite — integration tests against real RabbitMQ via Docker
- `docker-compose.yml` for local RabbitMQ development
- `run-e2e.sh` script to start Docker, wait for health and run E2E suite
- `tasks/` directory with markdown specs for all 26 unimplemented protocol commands
- `AGENTS.md` development guide with conventions, commands and implementation templates

### Fixed
- `ReadBuffer::getInt16()` and `getInt32()` — were returning unsigned values instead of signed (broke null string/bytes parsing)
- `WriteBuffer::addArray()` — was referencing non-existent `StreamBufferInterface` instead of `ToStreamBufferInterface`
- `WriteBuffer::UINT64_MAX` — exceeded PHP `int` range (was `float`)
- `PeerPropertiesRequestV1` — fixed `getKeYVersion()` typo → `getKeyVersion()`
- `DeclarePublisherRequestV1` — null `publisherReference` serializes as empty string `""` (server rejects null string `0xFFFF`)

### Changed
- `composer.json` — added `phpunit/phpunit ^10.5` as dev dependency with `autoload-dev` for `tests/`
- `README.md` — added full project description, usage example and protocol implementation status table
