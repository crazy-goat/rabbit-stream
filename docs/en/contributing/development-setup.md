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

# 3. Run tests to verify setup
composer test:unit

# 4. Run the E2E tests (optional, needs Docker; the script starts its own broker)
./run-e2e.sh
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

The project includes a `docker-compose.yml` file. It runs RabbitMQ with the Streams plugin enabled.

To run the E2E tests, run `./run-e2e.sh`. The script starts the broker, waits until it is healthy, runs the E2E suite, runs the example smoke tests with `bin/run-examples.sh` and stops the broker. Do not run `docker compose up -d` before `./run-e2e.sh`. A broker that you start by hand keeps running after the script ends.

To start a broker by hand, use these commands:

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

A plain `docker compose up -d` publishes these ports on the host by default:
- **Stream Protocol**: `127.0.0.1:5552`
- **AMQP**: `127.0.0.1:5672`
- **Management UI**: `http://127.0.0.1:15672` (guest/guest)

The plain command does not start the TLS listener. The TLS E2E test runs only through `./run-e2e.sh`.

### Environment Variables

The E2E tests read these variables:

| Variable | Default | Purpose |
|----------|---------|---------|
| `RABBITMQ_HOST` | `127.0.0.1` | Host of the broker |
| `RABBITMQ_PORT` | `5552` | Stream protocol port |
| `RABBITMQ_MANAGEMENT_PORT` | `15672` | Management port |
| `RABBITMQ_TLS_PORT` | none | TLS stream port. The TLS test is skipped when this variable is not set. |
| `RABBITMQ_TLS_CERT_DIR` | none | Directory with the TLS test certificates |
| `RABBITMQ_TLS_PASSPHRASE` | none | Passphrase of the client key |

`docker-compose.yml` reads the port variables to set the host ports. Its defaults are `RABBITMQ_PORT=5552`, `RABBITMQ_AMQP_PORT=5672` and `RABBITMQ_MANAGEMENT_PORT=15672`. Set these variables to run several checkouts side by side on different ports.

`./run-e2e.sh` reads these variables:

| Variable | Behaviour |
|----------|-----------|
| `RABBITMQ_PORT`, `RABBITMQ_AMQP_PORT`, `RABBITMQ_MANAGEMENT_PORT`, `RABBITMQ_TLS_PORT` | If a variable is not set, the script sets it to a free port on `127.0.0.1`. |
| `RABBITMQ_HOST` | Set to `127.0.0.1` when it is not set. |
| `RABBITMQ_IMAGE` | Broker image. If it is set, the script pulls only this image. |
| `E2E_HEALTH_RETRIES` | Number of health checks. The default is `90`. |
| `E2E_HEALTH_INTERVAL` | Seconds between health checks. The default is `2`. |

The script sets `RABBITMQ_TLS_CERT_DIR` and `RABBITMQ_TLS_PASSPHRASE` on every run. It generates the TLS test certificates in a `.e2e-tls.*` directory and deletes that directory at exit. Do not set these two variables yourself.

`bin/worktree.sh` writes the free ports of a worktree to `.env.worktree`. `./run-e2e.sh` loads that file when it exists. A variable that you export before the run keeps your value.

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
├── tests/            # Test suite (PSR-4: `CrazyGoat\RabbitStream\Tests`)
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
If E2E tests fail with "Connection refused", run the suite with `./run-e2e.sh`. The script starts the broker and waits until it is healthy.

If you run the suite by hand, start the broker and wait for `(healthy)`:
```bash
docker compose up -d
docker compose ps   # wait until the status shows (healthy)
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
