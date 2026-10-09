# Enums

This section provides API reference documentation for the protocol enums used in RabbitMQ Streams Protocol.

## KeyEnum

Protocol command keys for the RabbitMQ Streams Protocol. Located in `src/Enum/KeyEnum.php`.

The `KeyEnum` is a backed enum (`int`) that defines all protocol command keys. Response keys are calculated as `request_key | 0x8000`.

### Publishing Keys (0x0001-0x0006)

| Case | Hex | Description |
|------|-----|-------------|
| `DECLARE_PUBLISHER` | 0x0001 | Declare a new publisher |
| `PUBLISH` | 0x0002 | Publish messages to a stream |
| `PUBLISH_CONFIRM` | 0x0003 | Server-push confirmation for published messages |
| `PUBLISH_ERROR` | 0x0004 | Server-push error for failed publishes |
| `QUERY_PUBLISHER_SEQUENCE` | 0x0005 | Query the last confirmed sequence for a publisher |
| `DELETE_PUBLISHER` | 0x0006 | Delete a publisher and free resources |

### Consuming Keys (0x0007-0x000c, 0x001a)

| Case | Hex | Description |
|------|-----|-------------|
| `SUBSCRIBE` | 0x0007 | Subscribe to a stream |
| `DELIVER` | 0x0008 | Server-push message delivery |
| `CREDIT` | 0x0009 | Grant chunk credit for delivery (1 credit = 1 chunk) |
| `STORE_OFFSET` | 0x000a | Store consumer offset |
| `QUERY_OFFSET` | 0x000b | Query stored offset |
| `UNSUBSCRIBE` | 0x000c | Unsubscribe from a stream |
| `CONSUMER_UPDATE` | 0x001a | Server request for consumer offset update |

### Stream Management Keys (0x000d-0x0010, 0x001c-0x001e)

| Case | Hex | Description |
|------|-----|-------------|
| `CREATE` | 0x000d | Create a new stream |
| `DELETE` | 0x000e | Delete a stream |
| `METADATA` | 0x000f | Query stream metadata |
| `METADATA_UPDATE` | 0x0010 | Server-push metadata update notification |
| `STREAM_STATS` | 0x001c | Query stream statistics |
| `CREATE_SUPER_STREAM` | 0x001d | Create a super stream (partitioned) |
| `DELETE_SUPER_STREAM` | 0x001e | Delete a super stream |

### Connection Keys (0x0011-0x0018, 0x001b, 0x001f)

| Case | Hex | Description |
|------|-----|-------------|
| `PEER_PROPERTIES` | 0x0011 | Exchange peer properties |
| `SASL_HANDSHAKE` | 0x0012 | Initiate SASL handshake |
| `SASL_AUTHENTICATE` | 0x0013 | Send SASL authentication data |
| `TUNE` | 0x0014 | Connection tuning parameters |
| `OPEN` | 0x0015 | Open a connection to a virtual host |
| `CLOSE` | 0x0016 | Close the connection |
| `HEARTBEAT` | 0x0017 | Connection heartbeat (server-push and client response) |
| `ROUTE` | 0x0018 | Query routes for a super stream |
| `PARTITIONS` | 0x0019 | Query partitions of a super stream |
| `EXCHANGE_COMMAND_VERSIONS` | 0x001b | Exchange supported command versions |
| `RESOLVE_OFFSET_SPEC` | 0x001f | Resolve offset specification to concrete offset |

### Response Keys (0x8xxx)

Response keys follow the pattern `0x8000 | request_key`:

| Case | Hex | Request Key |
|------|-----|-------------|
| `DECLARE_PUBLISHER_RESPONSE` | 0x8001 | DECLARE_PUBLISHER |
| `QUERY_PUBLISHER_SEQUENCE_RESPONSE` | 0x8005 | QUERY_PUBLISHER_SEQUENCE |
| `DELETE_PUBLISHER_RESPONSE` | 0x8006 | DELETE_PUBLISHER |
| `SUBSCRIBE_RESPONSE` | 0x8007 | SUBSCRIBE |
| `CREDIT_RESPONSE` | 0x8009 | CREDIT |
| `QUERY_OFFSET_RESPONSE` | 0x800b | QUERY_OFFSET |
| `UNSUBSCRIBE_RESPONSE` | 0x800c | UNSUBSCRIBE |
| `CREATE_RESPONSE` | 0x800d | CREATE |
| `DELETE_RESPONSE` | 0x800e | DELETE |
| `METADATA_RESPONSE` | 0x800f | METADATA |
| `PEER_PROPERTIES_RESPONSE` | 0x8011 | PEER_PROPERTIES |
| `SASL_HANDSHAKE_RESPONSE` | 0x8012 | SASL_HANDSHAKE |
| `SASL_AUTHENTICATE_RESPONSE` | 0x8013 | SASL_AUTHENTICATE |
| `TUNE_RESPONSE` | 0x8014 | TUNE |
| `OPEN_RESPONSE` | 0x8015 | OPEN |
| `CLOSE_RESPONSE` | 0x8016 | CLOSE |
| `ROUTE_RESPONSE` | 0x8018 | ROUTE |
| `PARTITIONS_RESPONSE` | 0x8019 | PARTITIONS |
| `CONSUMER_UPDATE_RESPONSE` | 0x801a | CONSUMER_UPDATE |
| `EXCHANGE_COMMAND_VERSIONS_RESPONSE` | 0x801b | EXCHANGE_COMMAND_VERSIONS |
| `STREAM_STATS_RESPONSE` | 0x801c | STREAM_STATS |
| `CREATE_SUPER_STREAM_RESPONSE` | 0x801d | CREATE_SUPER_STREAM |
| `DELETE_SUPER_STREAM_RESPONSE` | 0x801e | DELETE_SUPER_STREAM |
| `RESOLVE_OFFSET_SPEC_RESPONSE` | 0x801f | RESOLVE_OFFSET_SPEC |

### Utility Methods

#### fromStreamCode()

Converts a raw protocol code to a `KeyEnum` instance.

```php
public static function fromStreamCode(int $code): KeyEnum
```

**Parameters:**

| Parameter | Type | Description |
|-----------|------|-------------|
| `$code` | `int` | Raw protocol command code |

**Return Value:**

`KeyEnum` - The corresponding enum case

**Throws:**

- `ProtocolException` - If the code matches no defined command. This is reached
  from `ResponseBuilder::fromResponseBuffer()` for every inbound frame, so it
  stays inside the library's exception hierarchy: a `catch
  (RabbitStreamExceptionInterface)` around a read loop sees it. `getResponseCode()`
  returns `null`.

**Example:**

```php
use CrazyGoat\RabbitStream\Enum\KeyEnum;

// Convert request code
$key = KeyEnum::fromStreamCode(0x0001); // KeyEnum::DECLARE_PUBLISHER

// Convert response code (each response code is its own enum case)
$key = KeyEnum::fromStreamCode(0x8001); // KeyEnum::DECLARE_PUBLISHER_RESPONSE
```

**Notes:**

- Handles both request codes (0x0001-0x001f) and response codes (0x8001-0x801f)
- Every valid code is an explicit enum case; there is no 0x8000 fallback. An
  unknown response code such as `0x8002` therefore throws, rather than
  stripping the high bit and silently returning the `0x0002` request key
- Throws `ProtocolException` for unknown codes

---

## ResponseCodeEnum

Response codes returned by the server. Located in `src/Enum/ResponseCodeEnum.php`.

The `ResponseCodeEnum` is a backed enum (`int`) that defines all possible response codes from the server.

### Response Codes Table

| Code | Name | Hex | Description |
|------|------|-----|-------------|
| 1 | `OK` | 0x01 | OK |
| 2 | `STREAM_NOT_EXIST` | 0x02 | Stream does not exist |
| 3 | `SUBSCRIPTION_ID_ALREADY_EXISTS` | 0x03 | Subscription ID already exists |
| 4 | `SUBSCRIPTION_ID_NOT_EXIST` | 0x04 | Subscription ID does not exist |
| 5 | `STREAM_ALREADY_EXISTS` | 0x05 | Stream already exists |
| 6 | `STREAM_NOT_AVAILABLE` | 0x06 | Stream not available |
| 7 | `SASL_MECHANISM_NOT_SUPPORTED` | 0x07 | SASL mechanism not supported |
| 8 | `AUTHENTICATION_FAILURE` | 0x08 | Authentication failure |
| 9 | `SASL_ERROR` | 0x09 | SASL error |
| 10 | `SASL_CHALLENGE` | 0x0a | SASL challenge |
| 11 | `SASL_AUTHENTICATION_FAILURE_LOOPBACK` | 0x0b | SASL authentication failure loopback |
| 12 | `VIRTUAL_HOST_ACCESS_FAILURE` | 0x0c | Virtual host access failure |
| 13 | `UNKNOWN_FRAME` | 0x0d | Unknown frame |
| 14 | `FRAME_TOO_LARGE` | 0x0e | Frame too large |
| 15 | `INTERNAL_ERROR` | 0x0f | Internal error |
| 16 | `ACCESS_REFUSED` | 0x10 | Access refused |
| 17 | `PRECONDITION_FAILED` | 0x11 | Precondition failed |
| 18 | `PUBLISHER_NOT_EXIST` | 0x12 | Publisher does not exist |
| 19 | `NO_OFFSET` | 0x13 | No offset |
| 20 | `SASL_CANNOT_CHANGE_MECHANISM` | 0x14 | SASL cannot change mechanism |
| 21 | `SASL_CANNOT_CHANGE_USERNAME` | 0x15 | SASL cannot change username |

### Methods

#### getMessage()

Returns a human-readable message for the response code.

```php
public function getMessage(): string
```

**Return Value:**

`string` - Human-readable description of the response code

**Example:**

```php
use CrazyGoat\RabbitStream\Enum\ResponseCodeEnum;

$code = ResponseCodeEnum::STREAM_NOT_EXIST;
echo $code->getMessage(); // "Stream does not exist"
```

#### fromInt()

Creates a `ResponseCodeEnum` from an integer code.

```php
public static function fromInt(int $code): ?self
```

**Parameters:**

| Parameter | Type | Description |
|-----------|------|-------------|
| `$code` | `int` | Integer response code |

**Return Value:**

`?ResponseCodeEnum` - The enum case, or `null` if code is not recognized

**Example:**

```php
use CrazyGoat\RabbitStream\Enum\ResponseCodeEnum;

$code = ResponseCodeEnum::fromInt(0x02);
if ($code !== null) {
    echo $code->name; // "STREAM_NOT_EXIST"
}
```

#### isSuccess()

Checks if the response code indicates success.

```php
public function isSuccess(): bool
```

**Return Value:**

`bool` - `true` if the code is `OK` (0x01), `false` otherwise

**Example:**

```php
use CrazyGoat\RabbitStream\Enum\ResponseCodeEnum;

$responseCode = ResponseCodeEnum::fromInt($rawCode);
if ($responseCode?->isSuccess()) {
    echo "Operation successful!";
} else {
    echo "Operation failed: " . $responseCode?->getMessage();
}
```

#### isError()

Checks if the response code indicates an error.

```php
public function isError(): bool
```

**Return Value:**

`bool` - `true` if the code is not `OK`, `false` if it is `OK`

**Example:**

```php
use CrazyGoat\RabbitStream\Enum\ResponseCodeEnum;

$responseCode = ResponseCodeEnum::fromInt($rawCode);
if ($responseCode?->isError()) {
    // Handle error
    error_log("Error: " . $responseCode->getMessage());
}
```

### Common Error Handling Patterns

Correlated commands throw `ProtocolException` during response deserialization when the broker returns a non-OK code. Inspect `getResponseCode()` in the catch block rather than parsing the response code from the buffer. `ResponseCodeEnum::fromInt()` remains useful for codes in server-push frames such as `PublishError`, whose errors are delivered to callbacks.

#### Pattern 1: Check a Response Code

```php
use CrazyGoat\RabbitStream\Enum\ResponseCodeEnum;
use CrazyGoat\RabbitStream\Exception\ProtocolException;

try {
    $connection->createStream($streamName);
} catch (ProtocolException $e) {
    if ($e->getResponseCode() === ResponseCodeEnum::STREAM_ALREADY_EXISTS) {
        // Stream already exists - that's fine.
    } else {
        throw $e;
    }
}
```

#### Pattern 2: Switch on Specific Codes

```php
use CrazyGoat\RabbitStream\Enum\ResponseCodeEnum;
use CrazyGoat\RabbitStream\Exception\ProtocolException;

try {
    $connection->deleteStream($streamName);
} catch (ProtocolException $e) {
    switch ($e->getResponseCode()) {
        case ResponseCodeEnum::STREAM_NOT_EXIST:
            // The stream is already absent - that's fine.
            break;
        default:
            throw $e;
    }
}
```

### See Also

- [Producer API Reference](producer.md) - Uses ResponseCodeEnum for confirmation handling
- [Consumer API Reference](consumer.md) - Uses ResponseCodeEnum for subscription handling
- Source: `src/Enum/ResponseCodeEnum.php`
