# Development Setup

This guide will help you set up the RabbitStream development environment from scratch.

## Prerequisites

- **PHP 8.1+** — Required for modern PHP features (enums, constructor promotion, match expressions)
- **Composer** — Dependency management
- **Docker** — For running RabbitMQ during E2E testing
- **Git** — Version control

## Quick Start

```bash
# 1. Clone the repository
git clone https://github.com/crazy-goat/rabbit-stream.git
cd rabbit-stream

# 2. Install dependencies
composer install

# 3. Start RabbitMQ (for E2E tests)
docker compose up -d

# 4. Run tests to verify setup
composer test:unit
```

## Detailed Setup

### PHP Installation

#### Ubuntu/Debian
```bash
sudo apt update
sudo apt install php8.1 php8.1-mbstring php8.1-xml php8.1-curl php8.1-sockets
```

#### macOS (with Homebrew)
```bash
brew install php@8.1
```

#### Windows
Download from [php.net](https://www.php.net/downloads) or use WSL2 with Ubuntu.

### Composer Installation

```bash
# Download and install Composer
curl -sS https://getcomposer.org/installer | php
sudo mv composer.phar /usr/local/bin/composer
```

Or follow the [official Composer installation guide](https://getcomposer.org/doc/00-intro.md).

### Docker Setup

The project includes a `docker-compose.yml` file for running RabbitMQ with the Streams plugin enabled:

```bash
# Start RabbitMQ
docker compose up -d

# Check status
docker compose ps

# View logs
docker compose logs -f rabbitmq

# Stop RabbitMQ
docker compose down
```

RabbitMQ will be available at:
- **Stream Protocol**: `127.0.0.1:5552`
- **Management UI**: `http://127.0.0.1:15672` (guest/guest)

### Environment Variables

E2E tests respect these environment variables:

```bash
export RABBITMQ_HOST=127.0.0.1
export RABBITMQ_PORT=5552
```

## IDE Configuration

### PHPStorm

Recommended settings:

1. **Code Style**: Import `phpcs.xml.dist` as the project code style
2. **PHP Language Level**: Set to 8.1
3. **Quality Tools**:
   - Enable PHP_CodeSniffer with `phpcs.xml.dist`
   - Enable PHPStan if installed
4. **Composer**: Set path to `composer.json`

### VS Code

Recommended extensions:

- **PHP Intelephense** — Code intelligence
- **PHP CS Fixer** — Code formatting
- **PHP DocBlocker** — Documentation generation
- **EditorConfig** — Consistent formatting

Settings for `.vscode/settings.json`:

```json
{
    "php.validate.executablePath": "/usr/bin/php",
    "php.codeSniffer.standard": "phpcs.xml.dist",
    "editor.formatOnSave": true
}
```

## PHP Extensions

### Required
- `mbstring` — String manipulation

### Recommended
- `sockets` — Better socket handling (used internally)
- `pcntl` — Process control (for signal handling)

### Verify Installation

```bash
php -m | grep -E "mbstring|sockets|pcntl"
```

## Project Structure

```
rabbit-stream/
├── src/              # Source code (PSR-4: CrazyGoat\RabbitStream)
├── tests/            # Test suite (PSR-4: CrazyGoat\RabbitStream\Tests)
├── docs/             # Documentation
├── examples/         # Usage examples
├── composer.json     # Dependencies
├── phpunit.xml       # Test configuration
└── phpcs.xml.dist    # Code style rules
```

## Next Steps

Once your environment is set up:

1. Read the [Code Style Guide](code-style.md)
2. Learn about [Testing](testing.md)
3. Understand how to [Add Protocol Commands](adding-protocol-commands.md)

## Troubleshooting

### Connection Refused Errors
If E2E tests fail with "Connection refused", ensure RabbitMQ is running:
```bash
docker compose up -d
```

### Permission Issues
If you get permission errors with Composer:
```bash
composer install --no-scripts
```

### PHP Version Mismatch
Check your PHP version:
```bash
php -v
```

If it's below 8.1, update your PHP installation or use a version manager.
