# Stream Management Commands

This document provides detailed protocol reference for stream administration in RabbitMQ Streams.

## Overview

Stream management commands handle:

- **Creating streams** - With optional retention policies
- **Deleting streams** - Remove streams and their data
- **Querying metadata** - Get stream topology and broker information
- **Monitoring statistics** - Track stream metrics
- **Managing super streams** - Partitioned stream management

## Protocol Commands

### 1. Create (0x000d)

Creates a new stream with optional configuration arguments.

**Request Frame Structure:**
```
Key:        0x000d (uint16)
Version:    1 (uint16)
CorrelationId: (uint32)
stream:        string (stream name)
arguments:     Map<string, string> (optional configuration)
```

**Request Fields:**
| Field | Type | Description |
|-------|------|-------------|
| `stream` | string | Name of the stream to create |
| `arguments` | Map | Optional configuration parameters |

**Common Arguments:**
| Argument | Type | Description |
|----------|------|-------------|
| `max-length-bytes` | string | Maximum total size of the stream in bytes |
| `max-age` | string | Maximum age of messages (e.g., "1h", "1d") |
| `queue-leader-locator` | string | Policy for leader placement (`client-local`, `balanced`) |
| `max-segment-size-bytes` | string | Maximum size of segment files |
| `initial-cluster-size` | string | Initial number of replicas |

**Response Frame Structure:**
```
Key:        0x800d (uint16)
Version:    1 (uint16)
CorrelationId: (uint32) - matches request
ResponseCode:  uint16 (0x0001 = OK)
```

**Common Response Codes:**
| Code | Description |
|------|-------------|
| 0x0001 | OK - Stream created successfully |
| 0x0002 | Stream already exists |
| 0x0003 | Access refused |
| 0x0004 | Precondition failed (invalid arguments) |

**PHP Implementation:**
```php
use CrazyGoat\RabbitStream\Request\CreateRequestV1;
use CrazyGoat\RabbitStream\Response\CreateResponseV1;

// Create stream with retention policy
$stream->sendMessage(new CreateRequestV1(
    stream: 'my-stream',
    arguments: [
        'max-length-bytes' => '1073741824',  // 1 GB
        'max-age' => '24h',                   // 24 hours
        'queue-leader-locator' => 'balanced'
    ]
));

$response = $stream->readMessage();
assert($response instanceof CreateResponseV1);
assert($response->getResponseCode()->value === 0x0001);  // OK

// Create simple stream
$stream->sendMessage(new CreateRequestV1(stream: 'simple-stream'));
```

### 2. Delete (0x000e)

Deletes a stream and all its data.

**Request Frame Structure:**
```
Key:        0x000e (uint16)
Version:    1 (uint16)
CorrelationId: (uint32)
stream:        string
```

**Request Fields:**
| Field | Type | Description |
|-------|------|-------------|
| `stream` | string | Name of the stream to delete |

**Response Frame Structure:**
```
Key:        0x800e (uint16)
Version:    1 (uint16)
CorrelationId: (uint32) - matches request
ResponseCode:  uint16 (0x0001 = OK)
```

**Common Response Codes:**
| Code | Description |
|------|-------------|
| 0x0001 | OK - Stream deleted successfully |
| 0x0002 | Stream does not exist |
| 0x0003 | Access refused |

**PHP Implementation:**
```php
use CrazyGoat\RabbitStream\Request\DeleteStreamRequestV1;
use CrazyGoat\RabbitStream\Response\DeleteStreamResponseV1;

// Delete stream
$stream->sendMessage(new DeleteStreamRequestV1(stream: 'my-stream'));

$response = $stream->readMessage();
assert($response instanceof DeleteStreamResponseV1);
assert($response->getResponseCode()->value === 0x0001);  // OK
```

### 3. Metadata (0x000f)

Retrieves metadata about one or more streams, including broker topology.

**Request Frame Structure:**
```
Key:        0x000f (uint16)
Version:    1 (uint16)
CorrelationId: (uint32)
streams[]:     Array<string> (stream names to query)
```

**Request Fields:**
| Field | Type | Description |
|-------|------|-------------|
| `streams` | string[] | Names of streams to query metadata for |

**Response Frame Structure:**
```
Key:        0x800f (uint16)
Version:    1 (uint16)
CorrelationId: (uint32) - matches request
ResponseCode:  uint16 (0x0001 = OK)
brokers[]:     Array<Broker>
streamMetadata[]: Array<StreamMetadata>
```

**Broker Structure:**
```
reference: uint16 (broker ID)
host:      string (broker hostname)
port:      uint32 (broker port)
```

**StreamMetadata Structure:**
```
stream:            string (stream name)
responseCode:      uint16 (0x0001 = OK, or error code)
leaderReference:   uint16 (broker ID of leader)
replicaReferences: uint16[] (broker IDs of replicas)
```

**Response Fields:**
| Field | Type | Description |
|-------|------|-------------|
| `brokers` | Broker[] | List of all brokers in the cluster |
| `streamMetadata` | StreamMetadata[] | Metadata for each requested stream |

**PHP Implementation:**
```php
use CrazyGoat\RabbitStream\Request\MetadataRequestV1;
use CrazyGoat\RabbitStream\Response\MetadataResponseV1;

// Query metadata for multiple streams
$stream->sendMessage(new MetadataRequestV1(
    streams: ['stream1', 'stream2', 'stream3']
));

$response = $stream->readMessage();
assert($response instanceof MetadataResponseV1);

// Access broker information
foreach ($response->getBrokers() as $broker) {
    echo "Broker {$broker->getReference()}: {$broker->getHost()}:{$broker->getPort()}\n";
}

// Access stream metadata
foreach ($response->getStreamMetadata() as $metadata) {
    echo "Stream: {$metadata->getStream()}\n";
    echo "Leader: Broker {$metadata->getLeaderReference()}\n";
    echo "Replicas: " . implode(', ', $metadata->getReplicaReferences()) . "\n";
}
```

### 4. MetadataUpdate (0x0010) - Server Push

Server sends this frame when stream topology changes. **No correlation ID** - this is a server-push frame.

**Frame Structure:**
```
Key:        0x0010 (uint16)
Version:    1 (uint16)
metadataInfo:  short string (description of change)
```

**Fields:**
| Field | Type | Description |
|-------|------|-------------|
| `metadataInfo` | short string | Information about the metadata change |

**PHP Implementation:**
```php
use CrazyGoat\RabbitStream\Response\MetadataUpdateResponseV1;

// Handle in readLoop
$response = $connection->readLoop(maxFrames: 1);
if ($response instanceof MetadataUpdateResponseV1) {
    echo "Metadata updated: {$response->getMetadataInfo()}\n";
    // Re-query metadata to get latest topology
    $connection->sendMessage(new MetadataRequestV1(streams: ['my-stream']));
}
```

### 5. CreateSuperStream (0x001d)

Creates a partitioned super stream with multiple partitions.

**Request Frame Structure:**
```
Key:        0x001d (uint16)
Version:    1 (uint16)
CorrelationId: (uint32)
superStream:   string (super stream name)
partitions:    uint32 (number of partitions)
bindingKeys[]: Array<string> (routing keys for partitions)
```

**Request Fields:**
| Field | Type | Description |
|-------|------|-------------|
| `superStream` | string | Name of the super stream |
| `partitions` | uint32 | Number of partitions to create |
| `bindingKeys` | string[] | Routing keys (one per partition) |

**Response Frame Structure:**
```
Key:        0x801d (uint16)
Version:    1 (uint16)
CorrelationId: (uint32) - matches request
ResponseCode:  uint16 (0x0001 = OK)
```

**PHP Implementation:**
```php
use CrazyGoat\RabbitStream\Request\CreateSuperStreamRequestV1;
use CrazyGoat\RabbitStream\Response\CreateSuperStreamResponseV1;

// Create super stream with 3 partitions
$stream->sendMessage(new CreateSuperStreamRequestV1(
    superStream: 'orders-super-stream',
    partitions: 3,
    bindingKeys: ['orders.1', 'orders.2', 'orders.3']
));

$response = $stream->readMessage();
assert($response instanceof CreateSuperStreamResponseV1);
assert($response->getResponseCode()->value === 0x0001);
```

### 6. DeleteSuperStream (0x001e)

Deletes a super stream and all its partitions.

**Request Frame Structure:**
```
Key:        0x001e (uint16)
Version:    1 (uint16)
CorrelationId: (uint32)
superStream:   string
```

**Request Fields:**
| Field | Type | Description |
|-------|------|-------------|
| `superStream` | string | Name of the super stream to delete |

**Response Frame Structure:**
```
Key:        0x801e (uint16)
Version:    1 (uint16)
CorrelationId: (uint32) - matches request
ResponseCode:  uint16 (0x0001 = OK)
```

**PHP Implementation:**
```php
use CrazyGoat\RabbitStream\Request\DeleteSuperStreamRequestV1;
use CrazyGoat\RabbitStream\Response\DeleteSuperStreamResponseV1;

// Delete super stream
$stream->sendMessage(new DeleteSuperStreamRequestV1(
    superStream: 'orders-super-stream'
));

$response = $stream->readMessage();
assert($response instanceof DeleteSuperStreamResponseV1);
assert($response->getResponseCode()->value === 0x0001);
```

### 7. StreamStats (0x001c)

Retrieves statistics about a stream.

**Request Frame Structure:**
```
Key:        0x001c (uint16)
Version:    1 (uint16)
CorrelationId: (uint32)
stream:        string
```

**Request Fields:**
| Field | Type | Description |
|-------|------|-------------|
| `stream` | string | Name of the stream to query |

**Response Frame Structure:**
```
Key:        0x801c (uint16)
Version:    1 (uint16)
CorrelationId: (uint32) - matches request
ResponseCode:  uint16 (0x0001 = OK)
statistics[]:  Array<Statistic>
```

**Statistic Structure:**
```
key:   string (statistic name)
value: uint64 (statistic value)
```

**Common Statistics:**
| Statistic | Description |
|-----------|-------------|
| `first_offset` | Offset of the first message in the stream |
| `last_offset` | Offset of the last message in the stream |
| `committed_chunk_id` | ID of the last committed chunk |
| `chunk_count` | Number of chunks in the stream |

**PHP Implementation:**
```php
use CrazyGoat\RabbitStream\Request\StreamStatsRequestV1;
use CrazyGoat\RabbitStream\Response\StreamStatsResponseV1;

// Query stream statistics
$stream->sendMessage(new StreamStatsRequestV1(stream: 'my-stream'));

$response = $stream->readMessage();
assert($response instanceof StreamStatsResponseV1);

foreach ($response->getStatistics() as $stat) {
    echo "{$stat->getKey()}: {$stat->getValue()}\n";
}

// Access specific stats
$firstOffset = $response->getStatistic('first_offset');
$lastOffset = $response->getStatistic('last_offset');
$messageCount = $lastOffset - $firstOffset + 1;
echo "Stream contains $messageCount messages\n";
```

## Stream Management Flow

```
┌─────────┐     Create                ┌─────────┐
│ Client  │ ────────────────────────► │ Server  │
│         │ ◄──────────────────────── │         │
│         │     CreateResponse (OK)   │         │
│         │                           │         │
│         │     Metadata              │         │
│         │ ────────────────────────► │         │
│         │ ◄──────────────────────── │         │
│         │     MetadataResponse      │         │
│         │     (broker topology)     │         │
│         │                           │         │
│         │     StreamStats           │         │
│         │ ────────────────────────► │         │
│         │ ◄──────────────────────── │         │
│         │     StreamStatsResponse   │         │
│         │                           │         │
│         │     Delete                │         │
│         │ ────────────────────────► │         │
│         │ ◄──────────────────────── │         │
│         │     DeleteResponse (OK)   │         │
└─────────┘                           └─────────┘
```

## Complete Stream Management Example

```php
use CrazyGoat\RabbitStream\StreamConnection;
use CrazyGoat\RabbitStream\Request\CreateRequestV1;
use CrazyGoat\RabbitStream\Request\MetadataRequestV1;
use CrazyGoat\RabbitStream\Request\StreamStatsRequestV1;
use CrazyGoat\RabbitStream\Request\DeleteStreamRequestV1;

$connection = new StreamConnection('localhost', 5552);
$connection->connect();

$streamName = 'my-managed-stream';

// 1. Create stream with retention
$connection->sendMessage(new CreateRequestV1(
    stream: $streamName,
    arguments: [
        'max-length-bytes' => '1073741824',  // 1 GB
        'max-age' => '24h'
    ]
));
$response = $connection->readMessage();
assert($response->getResponseCode()->value === 0x0001);

// 2. Query metadata
$connection->sendMessage(new MetadataRequestV1(streams: [$streamName]));
$metadata = $connection->readMessage();
foreach ($metadata->getStreamMetadata() as $streamMeta) {
    echo "Leader: Broker {$streamMeta->getLeaderReference()}\n";
}

// 3. Get statistics
$connection->sendMessage(new StreamStatsRequestV1(stream: $streamName));
$stats = $connection->readMessage();
$firstOffset = $stats->getStatistic('first_offset');
$lastOffset = $stats->getStatistic('last_offset');
echo "Messages: " . ($lastOffset - $firstOffset + 1) . "\n";

// 4. Delete stream
$connection->sendMessage(new DeleteStreamRequestV1(stream: $streamName));
$response = $connection->readMessage();
assert($response->getResponseCode()->value === 0x0001);
```

## Super Stream Example

```php
use CrazyGoat\RabbitStream\Request\CreateSuperStreamRequestV1;
use CrazyGoat\RabbitStream\Request\DeleteSuperStreamRequestV1;
use CrazyGoat\RabbitStream\Request\PartitionsRequestV1;

// Create super stream with 3 partitions
$connection->sendMessage(new CreateSuperStreamRequestV1(
    superStream: 'events-super-stream',
    partitions: 3,
    bindingKeys: ['events.1', 'events.2', 'events.3']
));
$response = $connection->readMessage();
assert($response->getResponseCode()->value === 0x0001);

// Get partition streams
$connection->sendMessage(new PartitionsRequestV1(
    superStream: 'events-super-stream'
));
$partitions = $connection->readMessage();
foreach ($partitions->getStreams() as $partitionStream) {
    echo "Partition: $partitionStream\n";
}

// Delete super stream when done
$connection->sendMessage(new DeleteSuperStreamRequestV1(
    superStream: 'events-super-stream'
));
$response = $connection->readMessage();
assert($response->getResponseCode()->value === 0x0001);
```

## Next Steps

- Learn about [Routing Commands](./routing-commands.md) - super stream routing
- Explore [Publishing Commands](./publishing-commands.md) - send messages
- Explore [Consuming Commands](./consuming-commands.md) - receive messages
- See [Stream Management Guide](../guide/stream-management.md) - practical guide
