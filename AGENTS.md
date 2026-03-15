# AGENTS.md — StreamyCarrot Development Guide

## Project Overview

`crazy-goat/streamy-carrot` is a pure PHP library implementing the RabbitMQ Streams Protocol client (port 5552). It has zero external dependencies — only native PHP socket functions.

- Root namespace: `CrazyGoat\StreamyCarrot`
- PSR-4 autoloading: `src/` → `CrazyGoat\StreamyCarrot\`
- PHP 8.1+ required

---

## Build / Lint / Test Commands

```bash
# Install dependencies
composer install

# Run all tests
./vendor/bin/phpunit

# Run a single test file
./vendor/bin/phpunit tests/Request/SaslHandshakeRequestV1Test.php

# Run a single test method
./vendor/bin/phpunit --filter testSerializesCorrectly

# Run with verbose output
./vendor/bin/phpunit --testdox

# Run only unit tests
./vendor/bin/phpunit --testsuite unit

# Run E2E tests (requires RabbitMQ — use the script below)
./run-e2e.sh

# Run a single example manually (requires running RabbitMQ on 172.17.0.2:5552)
php examples/simple_publisher.php
```

Tests live in `tests/` with PSR-4 autoloading under `CrazyGoat\StreamyCarrot\Tests\`, mirroring the `src/` structure:
- `tests/Buffer/` — ReadBuffer, WriteBuffer tests
- `tests/Request/` — serialization tests for each request class
- `tests/Response/` — deserialization tests for each response class
- `tests/E2E/` — integration tests against real RabbitMQ (via Docker)

The `run-e2e.sh` script starts RabbitMQ via `docker compose`, waits for it to be healthy, runs the e2e suite, and shuts down the container. E2E tests respect `RABBITMQ_HOST` and `RABBITMQ_PORT` env vars (default: `127.0.0.1:5552`).

---

## Directory Structure

```
src/
├── Buffer/       # ReadBuffer, WriteBuffer, interfaces
├── Enum/         # KeyEnum (protocol command keys), ResponseCodeEnum
├── Request/      # Client-sent command classes (*RequestV1.php)
├── Response/     # Server-sent response classes (*ResponseV1.php)
├── Trait/        # Shared traits AND interfaces (CorrelationTrait, V1Trait, CommandTrait)
├── VO/           # Value Objects (KeyValue)
├── ResponseBuilder.php   # Static dispatcher: raw buffer → typed response object
└── StreamConnection.php  # TCP socket connection management
tasks/            # Markdown files describing unimplemented protocol commands
examples/         # Working usage examples
```

---

## Naming Conventions

### Classes & Files
- Request classes: `{CommandName}RequestV1.php` (e.g. `SaslHandshakeRequestV1`)
- Response classes: `{CommandName}ResponseV1.php` (e.g. `OpenResponseV1`)
- Suffix `V1` indicates protocol version 1; future versions use `V2`, etc.
- Enums: `{Name}Enum` (e.g. `KeyEnum`, `ResponseCodeEnum`)
- Traits: `{Name}Trait` (e.g. `CorrelationTrait`, `V1Trait`)
- Interfaces: `{Name}Interface` (e.g. `CorrelationInterface`, `KeyVersionInterface`)

### Methods
- `static public function` is used in existing code (non-standard order; PSR-12 prefers `public static`) — follow existing style for consistency
- Static factory methods on response/request classes: `fromStreamBuffer(ReadBuffer $buffer): ?object`
- Fluent builder methods on `WriteBuffer`: all `add*()` return `self`

### Constants / Enum Cases
- `SCREAMING_SNAKE_CASE` for enum cases (e.g. `KeyEnum::DECLARE_PUBLISHER`)
- Hex literals for protocol key values (e.g. `0x0001`, `0x8011`)

---

## Code Style Guidelines

### PHP Features in Use
- PHP 8.1+ backed enums (`enum KeyEnum: int`)
- Constructor property promotion
- `match` expressions with `default => throw new \Exception(...)` pattern
- Named arguments where appropriate
- Nullsafe operator and null coalescing where applicable

### Imports
- Always use `use` statements; never use fully-qualified class names inline
- Group imports: standard library first, then project namespaces alphabetically
- No unused imports

### Types
- Always declare parameter types and return types on public methods
- `?Type` for nullable; avoid `mixed` unless unavoidable
- `void` return type on methods that return nothing
- `int`, `string`, `bool` — always use lowercase scalar types

### Error Handling
- Throw `\Exception` for protocol errors (no custom exception hierarchy yet)
- Return `null` from `fromStreamBuffer()` when parsing fails gracefully
- `isResponseCodeOk()` in `CommandTrait` throws on non-OK response codes

---

## Implementing a New Protocol Command

Every protocol command needs up to 4 things. See `tasks/` for per-command specs.

### 1. Request class (client → server)

```php
<?php

namespace CrazyGoat\StreamyCarrot\Request;

use CrazyGoat\StreamyCarrot\Buffer\ToStreamBufferInterface;
use CrazyGoat\StreamyCarrot\Buffer\WriteBuffer;
use CrazyGoat\StreamyCarrot\Enum\KeyEnum;
use CrazyGoat\StreamyCarrot\Trait\CommandTrait;
use CrazyGoat\StreamyCarrot\Trait\CorrelationInterface;
use CrazyGoat\StreamyCarrot\Trait\CorrelationTrait;
use CrazyGoat\StreamyCarrot\Trait\KeyVersionInterface;
use CrazyGoat\StreamyCarrot\Trait\V1Trait;

class ExampleRequestV1 implements ToStreamBufferInterface, CorrelationInterface, KeyVersionInterface
{
    use CorrelationTrait;
    use V1Trait;
    use CommandTrait;

    public function __construct(private string $stream) {}

    public function toStreamBuffer(): WriteBuffer
    {
        return self::getKeyVersion($this->getCorrelationId())
            ->addString($this->stream);
    }

    static public function getKey(): int
    {
        return KeyEnum::EXAMPLE->value;
    }
}
```

### 2. Response class (server → client)

```php
<?php

namespace CrazyGoat\StreamyCarrot\Response;

use CrazyGoat\StreamyCarrot\Buffer\FromStreamBufferInterface;
use CrazyGoat\StreamyCarrot\Buffer\ReadBuffer;
use CrazyGoat\StreamyCarrot\Enum\KeyEnum;
use CrazyGoat\StreamyCarrot\Trait\CommandTrait;
use CrazyGoat\StreamyCarrot\Trait\CorrelationInterface;
use CrazyGoat\StreamyCarrot\Trait\CorrelationTrait;
use CrazyGoat\StreamyCarrot\Trait\KeyVersionInterface;
use CrazyGoat\StreamyCarrot\Trait\V1Trait;

class ExampleResponseV1 implements KeyVersionInterface, CorrelationInterface, FromStreamBufferInterface
{
    use CorrelationTrait;
    use CommandTrait;
    use V1Trait;

    public static function fromStreamBuffer(ReadBuffer $buffer): ?object
    {
        self::validateKeyVersion($buffer->getUint16(), $buffer->getUint16());
        $correlationId = $buffer->getUint32();
        self::isResponseCodeOk($buffer->getUint16());
        $object = new self();
        $object->withCorrelationId($correlationId);
        return $object;
    }

    static public function getKey(): int
    {
        return KeyEnum::EXAMPLE_RESPONSE->value;
    }
}
```

### 3. Register in `KeyEnum`

Add both request and response keys:
```php
case EXAMPLE = 0x00xx;
case EXAMPLE_RESPONSE = 0x80xx;
```

### 4. Register in `ResponseBuilder`

Add to the `match` in `getV1()`:
```php
KeyEnum::EXAMPLE_RESPONSE => ExampleResponseV1::fromStreamBuffer($responseBuffer),
```

---

## Known Issues / Technical Debt

These exist in the current codebase — do not replicate them in new code:

- **`gatString()` typo** in `ReadBuffer` — should be `getString()`. New code should call `gatString()` for now to stay compatible, but the method should eventually be renamed.
- **`static public` vs `public static`** — existing code uses `static public function getKey()`. Follow this for consistency in new classes.
- **Debug `echo` in `StreamConnection`** — `sendFrame()` and `readFrame()` echo raw hex to stdout. This is temporary debug output, not a logging system.
- **Polish error messages** in `WriteBuffer` — new code should use English.
- **Interfaces in `Trait\` namespace** — `CorrelationInterface` and `KeyVersionInterface` live in `CrazyGoat\StreamyCarrot\Trait\` despite being interfaces. Follow this convention for now.
- **No custom exceptions** — use `\Exception` until an exception hierarchy is introduced.

---

## Protocol Reference

Full protocol spec: https://github.com/rabbitmq/rabbitmq-server/blob/main/deps/rabbitmq_stream/docs/PROTOCOL.adoc

Frame structure:
```
Frame => Size (uint32) + Payload
Payload => Key (uint16) + Version (uint16) + [CorrelationId (uint32)] + Content
Response key = Request key | 0x8000
```

All integers are big-endian. Strings are int16-length-prefixed UTF-8. Bytes are int32-length-prefixed (-1 = null).
