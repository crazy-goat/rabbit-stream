<?php

declare(strict_types=1);

use CrazyGoat\RabbitStream\Buffer\ReadBuffer;
use CrazyGoat\RabbitStream\Buffer\WriteBuffer;
use CrazyGoat\RabbitStream\Client\Connection;
use CrazyGoat\RabbitStream\Client\Consumer;
use CrazyGoat\RabbitStream\Client\Message;
use CrazyGoat\RabbitStream\Client\Producer;
use CrazyGoat\RabbitStream\Client\SuperStreamConsumer;
use CrazyGoat\RabbitStream\Client\SuperStreamProducer;
use CrazyGoat\RabbitStream\ResponseBuilder;
use CrazyGoat\RabbitStream\StreamConnection;

$repositoryRoot = dirname(__DIR__);
$docsRoot = $argv[1] ?? $repositoryRoot . '/docs/en/api-reference';
$classes = [
    'connection.md' => Connection::class,
    'producer.md' => Producer::class,
    'consumer.md' => Consumer::class,
    'super-stream-producer.md' => SuperStreamProducer::class,
    'super-stream-consumer.md' => SuperStreamConsumer::class,
    'stream-connection.md' => StreamConnection::class,
    'read-buffer.md' => ReadBuffer::class,
    'write-buffer.md' => WriteBuffer::class,
    'response-builder.md' => ResponseBuilder::class,
    'message.md' => Message::class,
];
$autoload = $repositoryRoot . '/vendor/autoload.php';
if (is_file($autoload)) {
    require_once $autoload;
}

if (!is_dir($docsRoot)) {
    fwrite(STDERR, "Usage: php bin/check-api-reference.php [api-reference-docs-root]\n");
    exit(2);
}

$errors = [];
foreach ($classes as $page => $className) {
    $path = $docsRoot . '/' . $page;
    if (!is_file($path)) {
        $errors[] = sprintf('%s: missing API reference page for %s', $page, $className);
        continue;
    }
    if (!class_exists($className)) {
        $errors[] = sprintf('%s: class %s does not exist', $page, $className);
        continue;
    }

    $content = file_get_contents($path);
    if ($content === false) {
        $errors[] = sprintf('%s: could not read API reference page', $page);
        continue;
    }

    $overview = '';
    if (preg_match('/^##+\s+(?:Class )?Overview\s*\R(.*?)(?=^##+\s|\z)/ms', $content, $matches)) {
        $overview = $matches[1];
    }

    $reflection = new ReflectionClass($className);
    foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
        if (
            $method->isConstructor()
            || $method->isDestructor()
            || str_starts_with($method->getName(), '__')
            || $method->getDeclaringClass()->getName() !== $className
        ) {
            continue;
        }

        $name = preg_quote($method->getName(), '/');
        $heading = preg_match('/^#{1,6}\s+[^\r\n]*\b' . $name . '\s*\(\s*\)[^\r\n]*$/mi', $content) === 1;
        $overviewEntry = preg_match('/\b' . $name . '\s*\(/', $overview) === 1;
        if (!$heading && !$overviewEntry) {
            $errors[] = sprintf('%s: undocumented public method %s::%s()', $page, $className, $method->getName());
        }
    }
}

if ($errors !== []) {
    fwrite(STDERR, "API reference drift detected:\n" . implode("\n", $errors) . "\n");
    exit(1);
}

echo "All public methods on mapped classes are documented in their API reference pages.\n";
exit(0);
