# Installation

RabbitStream can be installed via Composer (recommended) or manually from source.

## Installing via Composer

Composer is the recommended way to install RabbitStream. It handles autoloading and dependency management automatically.

### Step 1: Install with Composer

```bash
composer require crazy-goat/rabbit-stream
```

This command:
- Downloads the library and its dependencies
- Updates your `composer.json`
- Generates the autoloader

### Step 2: Verify PHP Version

Ensure your PHP version meets the requirement (8.1+):

```bash
php -v
```

If your PHP version is too old, you'll see an error during installation.

### Step 3: Verify Installation

Create a test file to verify the installation:

```php
<?php

require_once 'vendor/autoload.php';

use CrazyGoat\RabbitStream\Client\Connection;

echo "RabbitStream installed successfully!\n";
echo "Library version: " . \Composer\InstalledVersions::getVersion('crazy-goat/rabbit-stream') . "\n";
```

Save as `verify-installation.php` and run:

```bash
php verify-installation.php
```

Expected output:
```
RabbitStream installed successfully!
Library version: 0.x.x
```

## Manual Installation

If you cannot use Composer, you can install manually:

### Step 1: Clone the Repository

```bash
git clone https://github.com/crazy-goat/rabbit-stream.git
cd rabbit-stream
```

### Step 2: Install Dependencies

```bash
composer install
```

### Step 3: Include the Autoloader

In your PHP scripts, include the autoloader:

```php
<?php

require_once 'path/to/rabbit-stream/vendor/autoload.php';

// Now you can use RabbitStream classes
use CrazyGoat\RabbitStream\Client\Connection;
```

## Autoloading Setup

When using Composer, autoloading is handled automatically. The library uses PSR-4 autoloading:

- **Namespace:** `CrazyGoat\RabbitStream\`
- **Source:** `src/`

No additional configuration is needed if you include `vendor/autoload.php`.

## Troubleshooting Common Issues

### Issue: "PHP version not satisfied"

**Error:**
```
Your requirements could not be resolved to an installable set of packages.
  Problem 1
    - crazy-goat/rabbit-stream requires php >=8.1 -> your php version (7.4.x) does not satisfy that requirement.
```

**Solution:**
Upgrade to PHP 8.1 or higher:

```bash
# Check current version
php -v

# On Ubuntu/Debian
sudo apt-get install php8.1 php8.1-mbstring php8.1-sockets

# On macOS with Homebrew
brew install php@8.1
```

### Issue: "Extension mbstring not loaded"

**Error:**
```
The requested PHP extension ext-mbstring * is missing from your system.
```

**Solution:**
Install the mbstring extension:

```bash
# Ubuntu/Debian
sudo apt-get install php-mbstring

# CentOS/RHEL
sudo yum install php-mbstring

# macOS (usually included with PHP)
brew install php
```

Then restart your web server or PHP-FPM.

### Issue: "Extension sockets not loaded"

**Error:**
```
The requested PHP extension ext-sockets * is missing from your system.
```

**Solution:**
Install the sockets extension:

```bash
# Ubuntu/Debian
sudo apt-get install php-sockets

# CentOS/RHEL
sudo yum install php-sockets

# macOS (usually included with PHP)
brew install php
```

### Issue: Composer not found

**Error:**
```
bash: composer: command not found
```

**Solution:**
Install Composer globally:

```bash
# Download and install
curl -sS https://getcomposer.org/installer | php
sudo mv composer.phar /usr/local/bin/composer

# Or on macOS with Homebrew
brew install composer
```

### Issue: Permission denied during installation

**Error:**
```
Installation failed, deleting ./composer.json.
  [ErrorException] file_put_contents(./composer.json): failed to open stream: Permission denied
```

**Solution:**
Run Composer with appropriate permissions:

```bash
# If using system PHP, you may need sudo
sudo composer require crazy-goat/rabbit-stream

# Or fix directory permissions
sudo chown -R $USER:$USER .
composer require crazy-goat/rabbit-stream
```

## Verifying the Full Setup

After installation, verify everything works with a complete test:

```php
<?php

require_once 'vendor/autoload.php';

use CrazyGoat\RabbitStream\Client\Connection;

// Check if we can load the Connection class
try {
    $reflection = new ReflectionClass(Connection::class);
    echo "Connection class loaded: OK\n";
    echo "Methods available: " . count($reflection->getMethods()) . "\n";
} catch (ReflectionException $e) {
    echo "ERROR: Could not load Connection class\n";
    exit(1);
}

// Check if RabbitMQ is reachable (optional)
$host = getenv('RABBITMQ_HOST') ?: '127.0.0.1';
$port = (int)(getenv('RABBITMQ_PORT') ?: 5552);

$socket = @fsockopen($host, $port, $errno, $errstr, 2);
if ($socket) {
    fclose($socket);
    echo "RabbitMQ connection test: OK ({$host}:{$port})\n";
} else {
    echo "WARNING: RabbitMQ not reachable at {$host}:{$port}\n";
    echo "  Error: {$errstr} ({$errno})\n";
    echo "  Make sure RabbitMQ is running with the stream plugin enabled\n";
}

echo "\nInstallation verified successfully!\n";
```

Save as `verify-full.php` and run:

```bash
php verify-full.php
```

## Next Steps

Now that RabbitStream is installed, proceed to the [Quick Start](./quick-start.md) guide to create your first stream and send messages.
