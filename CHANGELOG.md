# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/).

## [Unreleased]

### Added
- `BinarySerializerInterface` — abstraction layer for binary serialization backends (C++ FFI, PHP extension)
- `PhpBinarySerializer` — PHP implementation wrapping existing WriteBuffer/ReadBuffer/ResponseBuilder
- 29 unit tests for PhpBinarySerializer covering 16 request types and 12 response types
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
- `ConsumerUpdateQueryV1` — server-push single-active-consumer query from server (key `0x001a`)
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
- `PeerPropertiesToStreamBufferV1` — fixed `getKeYVersion()` typo → `getKeyVersion()`
- `DeclarePublisherRequestV1` — null `publisherReference` serializes as empty string `""` (server rejects null string `0xFFFF`)

### Changed
- `composer.json` — added `phpunit/phpunit ^10.5` as dev dependency with `autoload-dev` for `tests/`
- `README.md` — added full project description, usage example and protocol implementation status table
