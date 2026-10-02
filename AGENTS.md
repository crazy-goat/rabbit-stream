# AGENTS.md — RabbitStream Development Guide

The development process (issue, worktree, review, PR, merge) is in
[docs/workflow.md](docs/workflow.md), the release process in
[docs/release-workflow.md](docs/release-workflow.md). Everything is written in English
(code, comments, docs, commits, issues).

## Project Overview

`crazy-goat/rabbit-stream` is a pure PHP library implementing the RabbitMQ Streams Protocol client (port 5552). It has zero external dependencies — only native PHP socket functions.

- Root namespace: `CrazyGoat\RabbitStream`
- PSR-4 autoloading: `src/` → `CrazyGoat\RabbitStream\`
- PHP 8.1+ required

---

## Build / Lint / Test Commands

```bash
# Install dependencies
composer install

# Run all tests
./vendor/bin/phpunit
# OR
composer test

# Run only unit tests
./vendor/bin/phpunit --testsuite unit
# OR
composer test:unit

# Run E2E tests (requires RabbitMQ — use the script below)
./run-e2e.sh

# Run every lint step (PHPCS, Rector dry-run, PHPStan, kb-lint, docs link check,
# test suite coverage, shellcheck). Check-only; runs all steps, exits non-zero on any failure.
bin/lint.sh
# OR
composer lint

# Apply the fixers (Rector, PHPCBF, kb-lint --fix), then check again
bin/lint.sh --fix
# OR
composer lint:fix

# Run a single test file
./vendor/bin/phpunit tests/Request/SaslHandshakeRequestV1Test.php

# Run a single test method
./vendor/bin/phpunit --filter testSerializesCorrectly

# Run with verbose output
./vendor/bin/phpunit --testdox
```

Tests live in `tests/` with PSR-4 autoloading under `CrazyGoat\RabbitStream\Tests\`, mirroring the `src/` structure:
- `tests/Buffer/` — ReadBuffer, WriteBuffer tests
- `tests/Request/` — serialization tests for each request class
- `tests/Response/` — deserialization tests for each response class
- `tests/E2E/` — integration tests against real RabbitMQ (via Docker)

The `run-e2e.sh` script starts RabbitMQ via `docker compose`, waits for it to be healthy, runs the e2e suite, and shuts down the container. E2E tests respect `RABBITMQ_HOST` and `RABBITMQ_PORT` env vars (default: `127.0.0.1:5552`) and `RABBITMQ_MANAGEMENT_PORT` (default: `15672`).

---

## QA Commands

```bash
composer phpstan     # static analysis (PHPStan level 9)
composer cs          # check code style (PHPCS PSR-12)
composer cs-fix      # auto-fix code style (phpcbf)
composer rector      # preview refactoring suggestions (dry-run)
composer rector:fix  # apply Rector refactoring
composer kb-lint     # lint the docs/helpers/ knowledge base (runs inside `lint`)
composer kb-lint:fix # regenerate the docs/helpers/ tag index
composer test:suite-coverage # fail if a tests/**/*Test.php is outside every phpunit.xml suite
```

## Git Hooks

A pre-push hook runs `bin/lint.sh` (PHPCS + Rector dry-run + PHPStan level 9
+ `kb-lint` + docs link check + test suite coverage + shellcheck) before every push. Git does not version `.git/hooks/`, so install
it after a fresh clone:

```bash
bash bin/install-hooks.sh   # symlinks bin/hooks/* into .git/hooks/
```

Bypass in an emergency with `git push --no-verify`; CI runs the same
`bin/lint.sh`, so skipping locally only moves the failure to CI. See `bin/README.md`.

## CI

`.github/workflows/ci.yml` ends in the `ci-ok` job, the only required check. Documentation-only
changes skip the heavy jobs. There is no coverage gate; do not add one. Workflows of fork pull
requests need a maintainer's approval (repository setting "Require approval for fork pull request
workflows"). A maintainer must read the changes to `.github/workflows/` (and anything CI executes,
such as `composer.json` scripts) before approving the run.

---

## Directory Structure

```
src/
├── Buffer/       # ReadBuffer, WriteBuffer, interfaces
├── Enum/         # KeyEnum (protocol command keys), ResponseCodeEnum
├── Request/      # Client-sent command classes (*RequestV1.php)
├── Response/     # Server-sent response classes (*ResponseV1.php)
├── Contract/     # Interfaces (CorrelationInterface, KeyVersionInterface)
├── Trait/        # Shared traits (CorrelationTrait, V1Trait, CommandTrait)
├── VO/           # Value Objects (KeyValue)
├── ResponseBuilder.php   # Static dispatcher: raw buffer → typed response object
└── StreamConnection.php  # TCP socket connection management
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
- `public static function` ordering per PSR-12
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
- Throw a specific class from `src/Exception/` — never bare `\Exception` (see DEC-002 and `docs/en/guide/error-handling.md`)
- `catch (RabbitStreamExceptionInterface)` covers every library throwable; `catch (RabbitStreamException)` does **not** cover `InvalidArgumentException`/`LengthException`, which shadow native types
- Return `null` from `fromStreamBuffer()` when parsing fails gracefully
- `assertResponseCodeOk()` in `CommandTrait` throws `ProtocolException` on non-OK response codes

---

## Implementing a New Protocol Command

Every protocol command needs up to 4 things.

### 1. Request class (client → server)

```php
<?php

namespace CrazyGoat\RabbitStream\Request;

use CrazyGoat\RabbitStream\Buffer\ToStreamBufferInterface;
use CrazyGoat\RabbitStream\Buffer\WriteBuffer;
use CrazyGoat\RabbitStream\Enum\KeyEnum;
use CrazyGoat\RabbitStream\Contract\CorrelationInterface;
use CrazyGoat\RabbitStream\Contract\KeyVersionInterface;
use CrazyGoat\RabbitStream\Trait\CommandTrait;
use CrazyGoat\RabbitStream\Trait\CorrelationTrait;
use CrazyGoat\RabbitStream\Trait\V1Trait;

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

    public static function getKey(): int
    {
        return KeyEnum::EXAMPLE->value;
    }
}
```

### 2. Response class (server → client)

```php
<?php

namespace CrazyGoat\RabbitStream\Response;

use CrazyGoat\RabbitStream\Buffer\FromStreamBufferInterface;
use CrazyGoat\RabbitStream\Buffer\ReadBuffer;
use CrazyGoat\RabbitStream\Enum\KeyEnum;
use CrazyGoat\RabbitStream\Contract\CorrelationInterface;
use CrazyGoat\RabbitStream\Contract\KeyVersionInterface;
use CrazyGoat\RabbitStream\Trait\CommandTrait;
use CrazyGoat\RabbitStream\Trait\CorrelationTrait;
use CrazyGoat\RabbitStream\Trait\V1Trait;

class ExampleResponseV1 implements KeyVersionInterface, CorrelationInterface, FromStreamBufferInterface
{
    use CorrelationTrait;
    use CommandTrait;
    use V1Trait;

    public static function fromStreamBuffer(ReadBuffer $buffer): ?object
    {
        self::validateKeyVersion($buffer->getUint16(), $buffer->getUint16());
        $correlationId = $buffer->getUint32();
        self::assertResponseCodeOk($buffer->getUint16());
        $object = new self();
        $object->withCorrelationId($correlationId);
        return $object;
    }

    public static function getKey(): int
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

## Process and Repo-Specific Rules

The generic flow is in [docs/workflow.md](docs/workflow.md). On top of it:

- Branches are `<type>/issue-<N>-<slug>`, created by `bin/worktree.sh <N>`.
  Never work directly on `main`; it is protected and needs a green `ci-ok`.
- A PR that implements a protocol command also changes `README.md`: `❌` → `✅` in the
  Protocol Implementation Status table.
- Run `bin/lint.sh && composer test:unit` before the review. Run `./run-e2e.sh` only for
  wire-level changes (it needs Docker).
- An extra-careful review (re-check against the protocol spec) is required when the
  diff touches `src/StreamConnection.php` (the `socket_select` loop, server-push dispatch,
  heartbeat echo, `ConsumerUpdate` reply), wire-format code (`*RequestV1`, `*ResponseV1`,
  `Buffer/`), `ResponseBuilder` dispatch or `KeyEnum` values, security-relevant socket and
  parsing code, a public API (`Connection`, `Producer`, `Consumer`), or more than 200 changed lines.
- After about four review rounds stop iterating and decide: narrow the issue, re-plan, or ask.
- Never lower a gate (a linter rule, the PHPStan level) to make a round look clean.
- Follow-up findings that cannot be automated may go to the knowledge base in
  `docs/helpers/` (see below).

### Worktrees and E2E ports

`docker-compose.yml` publishes the broker on `${RABBITMQ_PORT:-5552}` (stream),
`${RABBITMQ_AMQP_PORT:-5672}` and `${RABBITMQ_MANAGEMENT_PORT:-15672}`. `bin/worktree.sh`
writes free ports and a unique `COMPOSE_PROJECT_NAME` to `.env.worktree`; `./run-e2e.sh`
loads that file when it exists. E2E tests read `RABBITMQ_HOST`, `RABBITMQ_PORT` and
`RABBITMQ_MANAGEMENT_PORT`. `bin/worktree-setup.sh` runs `composer install`;
`bin/worktree-teardown.sh` runs `docker compose down -v`.

### Knowledge base (`docs/helpers/`)

A single-writer knowledge base of pitfalls (`faq.md`, `FAQ-NNN`) and decisions
(`decisions.md`, `DEC-NNN`). Read the generated tag index at the top of each file, pick the tags
that match your diff, and read only those entries. Coders and reviewers only propose
entries in their report; the person who runs the follow-up step (step 7 of the workflow)
writes them. Prefer a test or lint rule over an entry. `composer kb-lint` validates the files
and `composer kb-lint:fix` regenerates the index. Format: [docs/helpers/README.md](docs/helpers/README.md).

---

## Server-Push Frames (Async)

Some frames are sent **Server → Client** without a correlation ID — they are not responses to a specific request. These require a `readLoop()` dispatcher in `StreamConnection`, not a simple `readMessage()` call.

| Key    | Command         | Routed by        | Notes |
|--------|-----------------|------------------|-------|
| `0x0003` | PublishConfirm  | `publisherId`    | Async confirm after Publish |
| `0x0004` | PublishError    | `publisherId`    | Async error after Publish |
| `0x0008` | Deliver         | `subscriptionId` | Message delivery to consumer |
| `0x0010` | MetadataUpdate  | stream name      | Stream topology changed |
| `0x0017` | Heartbeat       | —                | Must echo back immediately |
| `0x001a` | ConsumerUpdate  | `subscriptionId` | Server asks client for offset; client must reply |

**Rule:** `PublishConfirm` and `PublishError` use the **request key** (`0x0003`/`0x0004`), NOT the response key (`0x8003`/`0x8004`). Same for `Deliver` (`0x0008`), `MetadataUpdate` (`0x0010`), `Heartbeat` (`0x0017`), `ConsumerUpdate` (`0x001a`).

The `readLoop()` implementation is done — see `src/StreamConnection.php`.

### How readMessage() handles server-push frames

`readMessage()` uses an internal loop with `socket_select()` to handle server-push frames transparently:

```
readMessage():
    while (true):
        wait for data via socket_select()
        frame = readFrame()
        if frame.key is server-push (0x0003/0x0004/0x0008/0x0010/0x0017/0x001a):
            dispatch(frame) → call registered callback, echo heartbeat, etc.
            continue        → keep reading
        else:
            return frame    → give caller the response they were waiting for
```

This mirrors how Go/Java clients work (dedicated goroutine/thread reading all frames), but in single-threaded PHP using `socket_select()` instead. Callers of `readMessage()` never see server-push frames — they are handled transparently inside the loop.

**Consequence:** existing tests do NOT need to change. `readMessage()` still returns the expected response type; it just silently handles any server-push frames that arrive before it.

### readLoop() for pure async use

For publishing/consuming where the caller wants to drive the loop themselves:

```php
$connection->registerPublisher(1, onConfirm: fn($ids) => ..., onError: fn($errs) => ...);
$connection->sendMessage(new PublishRequestV1(...));
$connection->readLoop(maxFrames: 1); // blocks until 1 server-push frame dispatched
```

`readLoop()` also uses `socket_select()` internally and dispatches all frames to callbacks.

---

## Known Issues / Technical Debt

These exist in the current codebase — do not replicate them in new code:

- ~~**No custom exceptions** — use `\Exception` until an exception hierarchy is introduced.~~ ✅ Fixed in #242

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
