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
| `CREDIT` | 0x0009 | Grant credits for message delivery |
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

- `\ValueError` - If the code is not recognized

**Example:**

```php
use CrazyGoat\RabbitStream\Enum\KeyEnum;

// Convert request code
$key = KeyEnum::fromStreamCode(0x0001); // KeyEnum::DECLARE_PUBLISHER

// Convert response code (automatically handles 0x8000 offset)
$key = KeyEnum::fromStreamCode(0x8001); // KeyEnum::DECLARE_PUBLISHER_RESPONSE
```

**Notes:**

- Handles both request codes (0x0001-0x001f) and response codes (0x8001-0x801f)
- For response codes, automatically subtracts 0x8000 to find the matching request key
- Throws `\ValueError` for unknown codes

---

## ResponseCodeEnum

Response codes returned by the server. Located in `src/Enum/ResponseCodeEnum.php`.

The `ResponseCodeEnum` is a backed enum (`int`) that defines all possible response codes from the server.

### Response Codes Table

| Code | Name | Hex | Description |
|------|------|-----|-------------|
| 1 | `OK` | 0x01 | Operation completed successfully |
| 2 | `STREAM_NOT_EXIST` | 0x02 | Stream does not exist |
| 3 | `SUBSCRIPTION_ID_ALREADY_EXISTS` | 0x03 | Subscription ID already in use |
| 4 | `SUBSCRIPTION_ID_NOT_EXIST` | 0x04 | Subscription ID does not exist |
| 5 | `STREAM_ALREADY_EXISTS` | 0x05 | Stream already exists |
| 6 | `STREAM_NOT_AVAILABLE` | 0x06 | Stream is not available |
| 7 | `SASL_MECHANISM_NOT_SUPPORTED` | 0x07 | SASL mechanism not supported |
| 8 | `AUTHENTICATION_FAILURE` | 0x08 | Authentication failed |
| 9 | `SASL_ERROR` | 0x09 | Generic SASL error |
| 10 | `SASL_CHALLENGE` | 0x0a | SASL challenge (multi-step auth) |
| 11 | `SASL_AUTHENTICATION_FAILURE_LOOPBACK` | 0x0b | SASL loopback authentication failure |
| 12 | `VIRTUAL_HOST_ACCESS_FAILURE` | 0x0c | Virtual host access denied |
| 13 | `UNKNOWN_FRAME` | 0x0d | Unknown frame type received |
| 14 | `FRAME_TOO_LARGE` | 0x0e | Frame exceeds maximum size |
| 15 | `INTERNAL_ERROR` | 0x0f | Internal server error |
| 16 | `ACCESS_REFUSED` | 0x10 | Access refused (permissions) |
| 17 | `PRECONDITION_FAILED` | 0x11 | Precondition failed |
| 18 | `PUBLISHER_NOT_EXIST` | 0x12 | Publisher does not exist |
| 19 | `NO_OFFSET` | 0x13 | No offset available |

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

#### Pattern 1: Check Response Code

```php
use CrazyGoat\RabbitStream\Enum\ResponseCodeEnum;

$responseCode = ResponseCodeEnum::fromInt($buffer->getUint16());

if ($responseCode === null) {
    throw new \Exception("Unknown response code");
}

if ($responseCode->isError()) {
    throw new \Exception($responseCode->getMessage());
}
```

#### Pattern 2: Switch on Specific Codes

```php
use CrazyGoat\RabbitStream\Enum\ResponseCodeEnum;

$responseCode = ResponseCodeEnum::fromInt($rawCode);

switch ($responseCode) {
    case ResponseCodeEnum::OK:
        // Success - continue
        break;
    case ResponseCodeEnum::STREAM_NOT_EXIST:
        // Create the stream first
        $connection->createStream($streamName);
        break;
    case ResponseCodeEnum::STREAM_ALREADY_EXISTS:
        // Stream already exists - that's fine
        break;
    default:
        throw new \Exception($responseCode->getMessage());
}
```

### See Also

- [Producer API Reference](producer.md) - Uses ResponseCodeEnum for confirmation handling
- [Consumer API Reference](consumer.md) - Uses ResponseCodeEnum for subscription handling
- Source: `src/Enum/ResponseCodeEnum.php`
