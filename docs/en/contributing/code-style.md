# Code Style

This project follows strict coding standards to maintain consistency and quality.

## Standards Overview

- **PSR-12** — Base PHP coding standard
- **Slevomat Coding Standard** — Additional rules for modern PHP

## QA Commands

Run these commands to check and fix code style:

```bash
# Check code style (PHPCS)
composer lint
# OR
./vendor/bin/phpcs --standard=phpcs.xml.dist

# Auto-fix code style violations
composer lint:fix
# OR
./vendor/bin/phpcbf --standard=phpcs.xml.dist

# Static analysis (PHPStan)
composer phpstan

# Preview refactoring suggestions (dry-run)
composer rector

# Apply Rector refactoring
composer rector:fix
```

## Key Rules with Examples

### Strict Types

Always declare strict types at the top of PHP files:

```php
<?php

declare(strict_types=1);

namespace CrazyGoat\RabbitStream\Request;
```

### Import Organization

Imports must be organized alphabetically:

```php
<?php

declare(strict_types=1);

namespace CrazyGoat\RabbitStream\Request;

use CrazyGoat\RabbitStream\Buffer\ToStreamBufferInterface;
use CrazyGoat\RabbitStream\Buffer\WriteBuffer;
use CrazyGoat\RabbitStream\Contract\CorrelationInterface;
use CrazyGoat\RabbitStream\Contract\KeyVersionInterface;
use CrazyGoat\RabbitStream\Enum\KeyEnum;
use CrazyGoat\RabbitStream\Trait\CommandTrait;
use CrazyGoat\RabbitStream\Trait\CorrelationTrait;
use CrazyGoat\RabbitStream\Trait\V1Trait;
```

### No Unused Imports

Remove any imports that are not used:

```php
<?php

// ❌ Bad - unused import
use CrazyGoat\RabbitStream\Buffer\ReadBuffer;

class Example
{
    // ReadBuffer is never used
}

// ✅ Good - only import what's needed
use CrazyGoat\RabbitStream\Buffer\WriteBuffer;

class Example
{
    public function write(WriteBuffer $buffer): void
    {
        // Uses WriteBuffer
    }
}
```

### Trailing Commas

Use trailing commas in multi-line arrays and function calls:

```php
<?php

// ❌ Bad - missing trailing comma
$array = [
    'first',
    'second'
];

// ✅ Good - trailing comma
$array = [
    'first',
    'second',
];

// ❌ Bad - missing trailing comma
function example(
    string $first,
    string $second
): void {
}

// ✅ Good - trailing comma
function example(
    string $first,
    string $second,
): void {
}
```

### Type Declarations

Always declare parameter and return types:

```php
<?php

// ❌ Bad - missing types
function process($data) {
    return $data;
}

// ✅ Good - explicit types
function process(string $data): string {
    return $data;
}

// Nullable types
function findById(?int $id): ?object {
    return $id === null ? null : $this->repository->find($id);
}
```

### Naming Conventions

#### Classes & Files
- Request classes: `{CommandName}RequestV1.php` (e.g., `SaslHandshakeRequestV1`)
- Response classes: `{CommandName}ResponseV1.php` (e.g., `OpenResponseV1`)
- Enums: `{Name}Enum` (e.g., `KeyEnum`, `ResponseCodeEnum`)
- Traits: `{Name}Trait` (e.g., `CorrelationTrait`, `V1Trait`)
- Interfaces: `{Name}Interface` (e.g., `CorrelationInterface`)

#### Constants & Enum Cases
- Use `SCREAMING_SNAKE_CASE` for enum cases:

```php
<?php

enum KeyEnum: int
{
    case DECLARE_PUBLISHER = 0x0001;
    case PUBLISH = 0x0002;
    case PUBLISH_CONFIRM = 0x0003;
}
```

- Use hex literals for protocol key values:

```php
case EXAMPLE = 0x00xx;
case EXAMPLE_RESPONSE = 0x80xx;
```

## PHP 8.1+ Features

### Backed Enums

Use backed enums for protocol keys:

```php
<?php

enum KeyEnum: int
{
    case DECLARE_PUBLISHER = 0x0001;
    case PUBLISH = 0x0002;
    
    public function isResponse(): bool
    {
        return ($this->value & 0x8000) !== 0;
    }
}
```

### Constructor Property Promotion

Use constructor property promotion to reduce boilerplate:

```php
<?php

// ❌ Old way - verbose
class ExampleRequestV1
{
    private string $stream;
    
    public function __construct(string $stream)
    {
        $this->stream = $stream;
    }
}

// ✅ New way - concise
class ExampleRequestV1
{
    public function __construct(private string $stream) {}
}
```

### Match Expressions

Use match expressions instead of switch statements:

```php
<?php

// ❌ Old way - switch statement
switch ($key) {
    case KeyEnum::DECLARE_PUBLISHER->value:
        return 'declare_publisher';
    case KeyEnum::PUBLISH->value:
        return 'publish';
    default:
        throw new \Exception('Unknown key');
}

// ✅ New way - match expression
return match ($key) {
    KeyEnum::DECLARE_PUBLISHER->value => 'declare_publisher',
    KeyEnum::PUBLISH->value => 'publish',
    default => throw new \Exception('Unknown key'),
};
```

### Named Arguments

Use named arguments for clarity:

```php
<?php

// ❌ Unclear what parameters mean
$buffer->addData($data, true, 1024);

// ✅ Clear with named arguments
$buffer->addData(
    data: $data,
    compress: true,
    maxSize: 1024,
);
```

## Pre-Commit Checklist

Before committing code:

1. ✅ Run `composer lint` — no style violations
2. ✅ Run `composer phpstan` — no static analysis errors
3. ✅ Run `composer test:unit` — all tests pass
4. ✅ Remove unused imports
5. ✅ Add trailing commas to multi-line arrays/calls

## IDE Integration

Most IDEs can be configured to automatically check code style:

### PHPStorm
- Settings → PHP → Quality Tools → PHP_CodeSniffer
- Set path to `phpcs.xml.dist`

### VS Code
- Install "PHP CS Fixer" extension
- Configure to use `phpcs.xml.dist`

## Continuous Integration

All code style checks run in CI. Pull requests will fail if:
- PHPCS finds violations
- PHPStan reports errors
- Tests fail

Run the QA commands locally before pushing to avoid CI failures.
