# OpenResponseV1

## Class Overview

`OpenResponseV1` is the response to the Open request (key `0x8015`). The class is in `src/Response/OpenResponseV1.php`.

The broker sends connection properties in this response. Each property has a key and a string value. Two properties name the address that the broker advertises for later connections: `advertised_host` and `advertised_port`. Read them with `getAdvertisedHost()` and `getAdvertisedPort()`.

The library does not connect to the advertised address. The getters only return the values. An address resolver is not part of this class.

`Connection::create()` reads this response during the handshake, and it does not return the response to the caller. Use `StreamConnection` and call `readMessage()` to get the `OpenResponseV1` object. See [StreamConnection](stream-connection.md).

## Methods

### getConnectionProperties()

```php
public function getConnectionProperties(): array
```

Returns every connection property, in the order that the broker sent them. Each entry is a `KeyValue` object with `getKey()` and `getValue()`.

**Returns:** `KeyValue[]`

### getAdvertisedHost()

```php
public function getAdvertisedHost(): ?string
```

Returns the value of the `advertised_host` property.

Returns `null` when the property is absent or empty. The method does not check the format of the host name.

**Returns:** `?string`

**Example:**

```php
$host = $openResponse->getAdvertisedHost();

if ($host !== null) {
    echo "The broker advertises host: {$host}\n";
}
```

### getAdvertisedPort()

```php
public function getAdvertisedPort(): ?int
```

Returns the value of the `advertised_port` property as an `int`. The broker sends the port as a decimal string.

Returns `null` when one of these cases is true:

- The property is absent.
- The value is not one to five decimal digits. For example, `+5552`, `5552abc` and `1e3` are not valid.
- The port is outside 1..65535. For example, `0` and `65536` are not valid.

**Returns:** `?int`

**Example:**

```php
$port = $openResponse->getAdvertisedPort();

if ($port !== null) {
    echo "The broker advertises port: {$port}\n";
}
```

### fromStreamBuffer()

```php
public static function fromStreamBuffer(ReadBuffer $buffer): ?static
```

Reads the response from a `ReadBuffer` that holds the frame payload.

**Throws:**
- `ProtocolException` - When the key or the version is not the Open response, or when the response code is not OK.
- `DeserializationException` - When the buffer is too short for the response.

### fromArray()

```php
public static function fromArray(array $data): static
```

Creates the response from an array. The array has a `correlationId` key and a `connectionProperties` key. Each entry of `connectionProperties` has a `key` and a `value`.

### getKey()

```php
public static function getKey(): int
```

Returns the protocol key of the response, `0x8015` (`KeyEnum::OPEN_RESPONSE`).

### getVersion()

```php
public static function getVersion(): int
```

Returns the protocol version of the response, `1`.

### getCorrelationId()

```php
public function getCorrelationId(): int
```

Returns the correlation ID that the response carries. The ID links the response to its Open request.

### withCorrelationId()

```php
public function withCorrelationId(int $correlationId): void
```

Sets the correlation ID of the response. The library calls this method when it reads the response.

## Related

- [ResponseBuilder](response-builder.md) - Creates an `OpenResponseV1` from a frame.
- [Value Objects](value-objects.md) - The `KeyValue` class.
