# StreamyCarrot

A PHP library implementing the [RabbitMQ Streams Protocol](https://github.com/rabbitmq/rabbitmq-server/blob/main/deps/rabbitmq_stream/docs/PROTOCOL.adoc) client.

It provides low-level TCP communication with a RabbitMQ broker over the native Stream protocol (port 5552), including binary frame serialization/deserialization.

## Requirements

- PHP 8.1+
- RabbitMQ with the `rabbitmq_stream` plugin enabled

## Installation

```bash
composer require crazy-goat/streamy-carrot
```

## Usage

```php
use CrazyGoat\StreamyCarrot\StreamConnection;
use CrazyGoat\StreamyCarrot\Request\PeerPropertiesToStreamBufferV1;
use CrazyGoat\StreamyCarrot\Request\SaslHandshakeRequestV1;
use CrazyGoat\StreamyCarrot\Request\SaslAuthenticateRequestV1;
use CrazyGoat\StreamyCarrot\Request\TuneRequestV1;
use CrazyGoat\StreamyCarrot\Request\TuneResponseV1;
use CrazyGoat\StreamyCarrot\Request\OpenRequest;
use CrazyGoat\StreamyCarrot\Response\TuneResponseV1;
use CrazyGoat\StreamyCarrot\Response\OpenResponseV1;

$connection = new StreamConnection('127.0.0.1', 5552);
$connection->connect();

$connection->sendMessage(new PeerPropertiesToStreamBufferV1());
$connection->readMessage();

$connection->sendMessage(new SaslHandshakeRequestV1());
$connection->readMessage();

$connection->sendMessage(new SaslAuthenticateRequestV1("PLAIN", "guest", "guest"));
$connection->readMessage();

$tune = $connection->readMessage(); // TuneRequest from server
$connection->sendMessage(new TuneResponseV1($tune->getFrameMax(), $tune->getHeartbeat()));

$connection->sendMessage(new OpenRequest());
$connection->readMessage(); // OpenResponse

$connection->close();
```

See `examples/simple_publisher.php` for a full working example.

## Protocol Implementation Status

Protocol reference: https://github.com/rabbitmq/rabbitmq-server/blob/main/deps/rabbitmq_stream/docs/PROTOCOL.adoc

### Connection & Authentication

| Command              | Key    | Request | Response |
|----------------------|--------|---------|----------|
| PeerProperties       | 0x0011 | ✅      | ✅       |
| SaslHandshake        | 0x0012 | ✅      | ✅       |
| SaslAuthenticate     | 0x0013 | ✅      | ✅       |
| Tune                 | 0x0014 | ✅      | ✅       |
| Open                 | 0x0015 | ✅      | ✅       |

### Publishing

| Command                | Key    | Request | Response |
|------------------------|--------|---------|----------|
| DeclarePublisher       | 0x0001 | ✅      | ✅       |
| Publish                | 0x0002 | ✅      | —        |
| PublishConfirm         | 0x0003 | —       | ✅       |
| PublishError           | 0x0004 | —       | ✅       |
| QueryPublisherSequence | 0x0005 | ❌      | ❌       |
| DeletePublisher        | 0x0006 | ❌      | ❌       |

### Consuming

| Command        | Key    | Request | Response |
|----------------|--------|---------|----------|
| Subscribe      | 0x0007 | ❌      | ❌       |
| Deliver        | 0x0008 | —       | ❌       |
| Credit         | 0x0009 | ❌      | ❌       |
| StoreOffset    | 0x000a | ❌      | —        |
| QueryOffset    | 0x000b | ❌      | ❌       |
| Unsubscribe    | 0x000c | ❌      | ❌       |
| ConsumerUpdate | 0x001a | —       | ❌       |

### Stream Management

| Command         | Key    | Request | Response |
|-----------------|--------|---------|----------|
| Create          | 0x000d | ❌      | ❌       |
| Delete          | 0x000e | ❌      | ❌       |
| Metadata        | 0x000f | ❌      | ❌       |
| MetadataUpdate  | 0x0010 | —       | ❌       |
| CreateSuperStream | 0x001d | ❌    | ❌       |
| DeleteSuperStream | 0x001e | ❌    | ❌       |
| StreamStats     | 0x001c | ❌      | ❌       |

### Routing (Super Streams)

| Command    | Key    | Request | Response |
|------------|--------|---------|----------|
| Route      | 0x0018 | ❌      | ❌       |
| Partitions | 0x0019 | ❌      | ❌       |

### Connection Management

| Command                | Key    | Request | Response |
|------------------------|--------|---------|----------|
| Close                  | 0x0016 | ❌      | ❌       |
| Heartbeat              | 0x0017 | ❌      | —        |
| ExchangeCommandVersions| 0x001b | ❌      | ❌       |
| ResolveOffsetSpec      | 0x001f | ❌      | ❌       |

Legend: ✅ implemented, ❌ not implemented, — not applicable (one-direction command)
