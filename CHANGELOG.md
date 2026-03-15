# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/).

## [Unreleased]

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
