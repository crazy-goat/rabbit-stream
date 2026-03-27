# Message API Reference

Complete API reference for the `CrazyGoat\RabbitStream\Client\Message` class.

## Class Overview

```php
namespace CrazyGoat\RabbitStream\Client;

class Message
{
    // Constructor (internal, created by AMQP decoder)
    /**
     * @param array<int, mixed>|string|int|float|bool|null $body
     * @param array<string, mixed> $properties
     * @param array<string, mixed> $applicationProperties
     * @param array<string, mixed> $messageAnnotations
     */
    public function __construct(
        int $offset,
        int $timestamp,
        string|int|float|bool|array|null $body,
        array $properties = [],
        array $applicationProperties = [],
        array $messageAnnotations = [],
    );
    
    // Core getters
    public function getOffset(): int;
    public function getTimestamp(): int;
    /** @return array<int, mixed>|string|int|float|bool|null */
    public function getBody(): string|int|float|bool|array|null;
    
    // AMQP properties
    /** @return array<string, mixed> */
    public function getProperties(): array;
    /** @return array<string, mixed> */
    public function getApplicationProperties(): array;
    /** @return array<string, mixed> */
    public function getMessageAnnotations(): array;
    
    // Common property getters
    public function getMessageId(): mixed;
    public function getCorrelationId(): mixed;
    public function getContentType(): ?string;
    public function getSubject(): ?string;
    public function getCreationTime(): ?int;
    public function getGroupId(): ?string;
}
```

## Constructor

The `Message` class is instantiated internally by the AMQP message decoder. You do not create `Message` objects directly - they are created when consuming messages from a stream.

```php
/**
 * @param array<int, mixed>|string|int|float|bool|null $body
 * @param array<string, mixed> $properties
 * @param array<string, mixed> $applicationProperties
 * @param array<string, mixed> $messageAnnotations
 */
public function __construct(
    private readonly int $offset,
    private readonly int $timestamp,
    private readonly string|int|float|bool|array|null $body,
    private readonly array $properties = [],
    private readonly array $applicationProperties = [],
    private readonly array $messageAnnotations = [],
);
```

### Parameters

| Parameter | Type | Description |
|-----------|------|-------------|
| `$offset` | `int` | Stream offset - the position of this message in the stream |
| `$timestamp` | `int` | Unix timestamp when the message was published (seconds since epoch) |
| `$body` | `string\|int\|float\|bool\|array\|null` | The message body content (decoded from AMQP) |
| `$properties` | `array<string, mixed>` | Standard AMQP 1.0 message properties |
| `$applicationProperties` | `array<string, mixed>` | Custom application-specific headers |
| `$messageAnnotations` | `array<string, mixed>` | AMQP message annotations |

### Notes

- Messages are created by `AmqpMessageDecoder` when consuming from a stream
- The body type depends on the AMQP type encoding used by the producer
- Properties are decoded from the AMQP message header
- Application properties are custom headers set by the producer

---

## Core Getters

### getOffset()

Get the stream offset of this message.

```php
public function getOffset(): int
```

#### Return Value

`int` - The position of this message in the stream (0-indexed)

#### Example

```php
$message = $consumer->readOne();
echo "Message offset: {$message->getOffset()}\n";

// Store offset for resuming later
$consumer->storeOffset($message->getOffset());
```

#### Notes

- Offsets are sequential and monotonically increasing
- The first message in a stream has offset 0
- Offsets are durable and survive restarts
- Used for consumer position tracking

---

### getTimestamp()

Get the Unix timestamp when the message was published.

```php
public function getTimestamp(): int
```

#### Return Value

`int` - Unix timestamp (seconds since January 1, 1970)

#### Example

```php
$message = $consumer->readOne();
$timestamp = $message->getTimestamp();
$date = date('Y-m-d H:i:s', $timestamp);
echo "Published at: {$date}\n";

// Check message age
$age = time() - $timestamp;
if ($age > 3600) {
    echo "Message is older than 1 hour\n";
}
```

#### Notes

- Set by the server when the message is received
- Not the same as `creation-time` property (which is set by the producer)
- Useful for message age calculations and time-based processing

---

### getBody()

Get the message body content.

```php
/**
 * @return array<int, mixed>|string|int|float|bool|null
 */
public function getBody(): string|int|float|bool|array|null
```

#### Return Value

`string|int|float|bool|array|null` - The decoded message body. Type depends on:
- The AMQP type encoding used by the producer
- The serializer used (if any)
- The content type of the message

#### Example

```php
$message = $consumer->readOne();
$body = $message->getBody();

// Handle different body types
if (is_string($body)) {
    echo "String message: {$body}\n";
} elseif (is_array($body)) {
    echo "Array message: " . json_encode($body) . "\n";
} elseif (is_null($body)) {
    echo "Empty message\n";
}

// JSON decoding
if ($message->getContentType() === 'application/json') {
    $data = json_decode($body, true);
    processData($data);
}
```

#### Notes

- Body type depends on how the message was encoded by the producer
- Binary data is typically returned as a string
- JSON messages can be decoded using `json_decode()`
- Check `getContentType()` to determine how to handle the body

---

## AMQP Properties

### getProperties()

Get all standard AMQP 1.0 message properties.

```php
/**
 * @return array<string, mixed>
 */
public function getProperties(): array
```

#### Return Value

`array<string, mixed>` - Associative array of AMQP properties. Common properties include:

| Property | Type | Description |
|----------|------|-------------|
| `message-id` | `mixed` | Unique message identifier |
| `correlation-id` | `mixed` | Correlation identifier for request/reply |
| `content-type` | `string` | MIME type of the body (e.g., `application/json`) |
| `content-encoding` | `string` | Content encoding (e.g., `gzip`) |
| `subject` | `string` | Message subject/topic |
| `creation-time` | `int` | Timestamp when message was created (by producer) |
| `group-id` | `string` | Message group identifier |
| `group-sequence` | `int` | Sequence number within group |
| `reply-to` | `string` | Reply address |
| `to` | `string` | Destination address |
| `user-id` | `string` | User ID of the producer |

#### Example

```php
$message = $consumer->readOne();
$properties = $message->getProperties();

// Access all properties
foreach ($properties as $name => $value) {
    echo "{$name}: {$value}\n";
}

// Check for specific property
if (isset($properties['content-type'])) {
    echo "Content-Type: {$properties['content-type']}\n";
}
```

---

### getApplicationProperties()

Get custom application-specific headers.

```php
/**
 * @return array<string, mixed>
 */
public function getApplicationProperties(): array
```

#### Return Value

`array<string, mixed>` - Associative array of custom headers set by the producer

#### Example

```php
$message = $consumer->readOne();
$headers = $message->getApplicationProperties();

// Access custom headers
$eventType = $headers['event-type'] ?? 'unknown';
$priority = $headers['priority'] ?? 'normal';
$source = $headers['source-service'] ?? 'unknown';

echo "Event: {$eventType}, Priority: {$priority}, Source: {$source}\n";

// Route based on header
switch ($headers['event-type'] ?? '') {
    case 'user-created':
        handleUserCreated($message);
        break;
    case 'order-placed':
        handleOrderPlaced($message);
        break;
    default:
        handleGenericEvent($message);
}
```

#### Notes

- Application properties are set by the producer using the AMQP `application-properties` section
- Commonly used for routing, filtering, and metadata
- Values can be any AMQP type (string, int, bool, etc.)

---

### getMessageAnnotations()

Get AMQP message annotations.

```php
/**
 * @return array<string, mixed>
 */
public function getMessageAnnotations(): array
```

#### Return Value

`array<string, mixed>` - Associative array of message annotations

#### Example

```php
$message = $consumer->readOne();
$annotations = $message->getMessageAnnotations();

// Access annotations (typically used by intermediaries)
if (isset($annotations['x-opt-rabbitmq-stream-offset'])) {
    echo "Stream offset annotation: {$annotations['x-opt-rabbitmq-stream-offset']}\n";
}
```

#### Notes

- Annotations are typically added by intermediaries (brokers, proxies)
- Keys are prefixed with `x-opt-` for custom annotations
- Less commonly used than application properties

---

## Common Property Getters

### getMessageId()

Get the message identifier.

```php
public function getMessageId(): mixed
```

#### Return Value

`mixed` - The message ID, or `null` if not set

#### Example

```php
$message = $consumer->readOne();
$messageId = $message->getMessageId();

if ($messageId !== null) {
    echo "Message ID: {$messageId}\n";
    
    // Check for duplicates
    if (isDuplicate($messageId)) {
        echo "Duplicate message, skipping\n";
        continue;
    }
}
```

---

### getCorrelationId()

Get the correlation identifier.

```php
public function getCorrelationId(): mixed
```

#### Return Value

`mixed` - The correlation ID, or `null` if not set

#### Example

```php
$message = $consumer->readOne();
$correlationId = $message->getCorrelationId();

if ($correlationId !== null) {
    echo "Processing request: {$correlationId}\n";
    
    // Reply with same correlation ID
    $reply = new Message(
        body: json_encode(['status' => 'success']),
        properties: [
            'correlation-id' => $correlationId,
        ]
    );
}
```

---

### getContentType()

Get the MIME content type.

```php
public function getContentType(): ?string
```

#### Return Value

`?string` - The content type (e.g., `application/json`, `text/plain`), or `null`

#### Example

```php
$message = $consumer->readOne();
$contentType = $message->getContentType();

switch ($contentType) {
    case 'application/json':
        $data = json_decode($message->getBody(), true);
        break;
    case 'application/xml':
        $data = simplexml_load_string($message->getBody());
        break;
    case 'text/plain':
    default:
        $data = $message->getBody();
        break;
}
```

---

### getSubject()

Get the message subject.

```php
public function getSubject(): ?string
```

#### Return Value

`?string` - The subject/topic, or `null` if not set

#### Example

```php
$message = $consumer->readOne();
$subject = $message->getSubject();

// Route based on subject
if (str_starts_with($subject, 'orders.')) {
    processOrder($message);
} elseif (str_starts_with($subject, 'users.')) {
    processUserEvent($message);
}
```

---

### getCreationTime()

Get the message creation timestamp.

```php
public function getCreationTime(): ?int
```

#### Return Value

`?int` - Unix timestamp when the message was created by the producer, or `null`

#### Example

```php
$message = $consumer->readOne();
$creationTime = $message->getCreationTime();
$serverTime = $message->getTimestamp();

if ($creationTime !== null) {
    $latency = $serverTime - $creationTime;
    echo "Message latency: {$latency} seconds\n";
}
```

#### Notes

- `creation-time` is set by the producer
- `getTimestamp()` is set by the server when received
- The difference shows network/processing latency

---

### getGroupId()

Get the message group identifier.

```php
public function getGroupId(): ?string
```

#### Return Value

`?string` - The group ID, or `null` if not set

#### Example

```php
$message = $consumer->readOne();
$groupId = $message->getGroupId();

if ($groupId !== null) {
    // Process messages in the same group together
    processGroupMessage($groupId, $message);
}
```

---

## AMQP 1.0 Property Mapping

Complete mapping of AMQP 1.0 properties to getter methods:

| AMQP Property | Getter Method | Return Type | Description |
|---------------|---------------|-------------|-------------|
| `message-id` | `getMessageId()` | `mixed` | Unique message identifier |
| `correlation-id` | `getCorrelationId()` | `mixed` | Request/reply correlation ID |
| `content-type` | `getContentType()` | `?string` | MIME type |
| `content-encoding` | `getProperties()['content-encoding']` | `mixed` | Encoding (gzip, etc.) |
| `subject` | `getSubject()` | `?string` | Message subject/topic |
| `creation-time` | `getCreationTime()` | `?int` | Producer timestamp |
| `group-id` | `getGroupId()` | `?string` | Message group ID |
| `group-sequence` | `getProperties()['group-sequence']` | `mixed` | Sequence in group |
| `reply-to` | `getProperties()['reply-to']` | `mixed` | Reply address |
| `to` | `getProperties()['to']` | `mixed` | Destination address |
| `user-id` | `getProperties()['user-id']` | `mixed` | Producer user ID |
| `absolute-expiry-time` | `getProperties()['absolute-expiry-time']` | `mixed` | Expiration timestamp |

---

## Working with Messages

### Pattern 1: Reading Message Body

Handle different body types appropriately:

```php
use CrazyGoat\RabbitStream\Client\Connection;
use CrazyGoat\RabbitStream\VO\OffsetSpec;

$connection = Connection::create('localhost');
$consumer = $connection->createConsumer('events', OffsetSpec::first());

while ($message = $consumer->readOne()) {
    $body = $message->getBody();
    
    // Handle based on type
    if (is_string($body)) {
        // Text or binary data
        processText($body);
    } elseif (is_array($body)) {
        // Already decoded array
        processArray($body);
    } elseif (is_int($body) || is_float($body)) {
        // Numeric value
        processNumber($body);
    } elseif (is_bool($body)) {
        // Boolean flag
        processFlag($body);
    } elseif ($body === null) {
        // Empty message
        echo "Received empty message at offset {$message->getOffset()}\n";
    }
}
```

### Pattern 2: Accessing Custom Headers

Use application properties for routing and metadata:

```php
while ($message = $consumer->readOne()) {
    $headers = $message->getApplicationProperties();
    
    // Extract common headers
    $eventType = $headers['event-type'] ?? 'unknown';
    $version = $headers['version'] ?? '1.0';
    $priority = $headers['priority'] ?? 'normal';
    
    // Validate required headers
    if (!isset($headers['trace-id'])) {
        error_log("Message missing trace-id at offset {$message->getOffset()}");
        continue;
    }
    
    // Process based on event type
    switch ($eventType) {
        case 'user.created':
            handleUserCreated($message, $headers);
            break;
        case 'order.placed':
            handleOrderPlaced($message, $headers);
            break;
        default:
            handleUnknownEvent($message, $headers);
    }
}
```

### Pattern 3: Message Routing by Properties

Route messages based on AMQP properties:

```php
while ($message = $consumer->readOne()) {
    $contentType = $message->getContentType();
    $subject = $message->getSubject();
    
    // Route by content type
    if ($contentType === 'application/json') {
        $data = json_decode($message->getBody(), true);
        
        // Route by subject
        if (str_starts_with($subject, 'payments.')) {
            $paymentProcessor->process($data);
        } elseif (str_starts_with($subject, 'inventory.')) {
            $inventoryProcessor->process($data);
        }
    } elseif ($contentType === 'application/xml') {
        $xmlProcessor->process($message->getBody());
    }
}
```

### Pattern 4: Working with JSON Messages

Common pattern for JSON-encoded messages:

```php
while ($message = $consumer->readOne()) {
    // Verify content type
    if ($message->getContentType() !== 'application/json') {
        error_log("Expected JSON, got: {$message->getContentType()}");
        continue;
    }
    
    // Decode JSON
    $body = $message->getBody();
    $data = json_decode($body, true);
    
    if ($data === null) {
        error_log("Invalid JSON at offset {$message->getOffset()}: " . json_last_error_msg());
        continue;
    }
    
    // Process with metadata
    $metadata = [
        'offset' => $message->getOffset(),
        'timestamp' => $message->getTimestamp(),
        'message-id' => $message->getMessageId(),
        'correlation-id' => $message->getCorrelationId(),
    ];
    
    processEvent($data, $metadata);
    
    // Store offset for tracking
    $consumer->storeOffset($message->getOffset());
}
```

---

## Message Types

### String Messages

Text-based messages with string body:

```php
// Producer
$producer->send('Hello, World!');

// Consumer
$message = $consumer->readOne();
$text = $message->getBody();  // "Hello, World!"
echo $text;
```

### Binary Data

Binary data is returned as a string (PHP string can hold binary):

```php
// Producer sends binary
$binaryData = file_get_contents('image.png');
$producer->send($binaryData);

// Consumer receives binary
$message = $consumer->readOne();
$binary = $message->getBody();  // Binary string
file_put_contents('received.png', $binary);
```

### JSON Messages

JSON-encoded messages with proper content type:

```php
// Producer
$data = ['user_id' => 123, 'action' => 'login'];
$producer->send(json_encode($data));

// Consumer
$message = $consumer->readOne();
if ($message->getContentType() === 'application/json') {
    $data = json_decode($message->getBody(), true);
    echo "User {$data['user_id']} performed {$data['action']}\n";
}
```

### AMQP Type Handling

The body type depends on the AMQP encoding:

| AMQP Type | PHP Type | Example |
|-----------|----------|---------|
| `string` (AMQP) | `string` | `"Hello"` |
| `binary` (AMQP) | `string` | Binary data |
| `int` / `long` | `int` | `42` |
| `float` / `double` | `float` | `3.14` |
| `boolean` | `bool` | `true` |
| `list` / `array` | `array` | `[1, 2, 3]` |
| `map` | `array` | `['key' => 'value']` |
| `null` | `null` | `null` |

---

## See Also

- [Connection API Reference](connection.md)
- [Consumer API Reference](consumer.md)
- [Producer API Reference](producer.md)
- [Consuming Guide](../guide/consuming.md)
- [AMQP 1.0 Specification](https://docs.oasis-open.org/amqp/core/v1.0/os/amqp-core-complete-v1.0-os.pdf)
