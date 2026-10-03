<?php

declare(strict_types=1);

use CrazyGoat\RabbitStream\Client\ConfirmationStatus;
use CrazyGoat\RabbitStream\Client\Connection;

require_once __DIR__ . '/../vendor/autoload.php';

/**
 * Basic Producer Example
 *
 * Demonstrates:
 * - Connection creation
 * - Producer creation
 * - Single message publishing
 * - Batch publishing
 * - Confirm handling
 * - Cleanup
 */
class BasicProducerExample
{
    private Connection $connection;
    private int $confirmedCount = 0;
    private int $failedCount = 0;

    public function run(): void
    {
        echo "=== Basic Producer Example ===\n\n";

        // Step 1: Create connection
        $this->createConnection();

        // Step 2: Create the stream (if it doesn't exist)
        $this->createStream();

        // Step 3: Create producer with confirm callback
        $producer = $this->createProducer();

        // Step 4: Publish single message
        $this->publishSingleMessage($producer);

        // Step 5: Publish batch of messages
        $this->publishBatch($producer);

        // Step 6: Wait for all confirms
        $this->waitForConfirms($producer);

        // Step 7: Cleanup
        $this->cleanup($producer);

        echo "\n=== Example Complete ===\n";
        echo "Confirmed: {$this->confirmedCount}\n";
        echo "Failed: {$this->failedCount}\n";
    }

    private function createConnection(): void
    {
        echo "Step 1: Creating connection...\n";

        $host = getenv('RABBITMQ_HOST') ?: '127.0.0.1';
        $port = (int)(getenv('RABBITMQ_PORT') ?: 5552);

        $this->connection = Connection::create(
            host: $host,
            port: $port,
            user: 'guest',
            password: 'guest',
        );

        echo "  ✓ Connected to {$host}:{$port}\n\n";
    }

    private function createStream(): void
    {
        echo "Step 2: Creating stream 'example-stream'...\n";

        try {
            $this->connection->createStream('example-stream', [
                'max-length-bytes' => '1000000000',
            ]);
            echo "  ✓ Stream created\n\n";
        } catch (\Exception $e) {
            // Stream may already exist, which is fine
            echo "  ℹ Stream may already exist: {$e->getMessage()}\n\n";
        }
    }

    private function createProducer(): \CrazyGoat\RabbitStream\Client\Producer
    {
        echo "Step 3: Creating producer...\n";

        $producer = $this->connection->createProducer(
            'example-stream',
            onConfirm: function (ConfirmationStatus $status) {
                if ($status->isConfirmed()) {
                    $this->confirmedCount++;
                    echo "  ✓ Confirmed: #{$status->getPublishingId()}\n";
                } else {
                    $this->failedCount++;
                    echo "  ✗ Failed: #{$status->getPublishingId()} ";
                    echo "code={$status->getErrorCode()}\n";
                }
            }
        );

        echo "  ✓ Producer created\n\n";

        return $producer;
    }

    private function publishSingleMessage(\CrazyGoat\RabbitStream\Client\Producer $producer): void
    {
        echo "Step 4: Publishing single message...\n";

        $producer->send('Hello, RabbitMQ Streams!');

        echo "  ✓ Message sent\n\n";
    }

    private function publishBatch(\CrazyGoat\RabbitStream\Client\Producer $producer): void
    {
        echo "Step 5: Publishing batch of 5 messages...\n";

        $messages = [];
        for ($i = 1; $i <= 5; $i++) {
            $messages[] = "Batch message #{$i}";
        }

        $producer->sendBatch($messages);

        echo "  ✓ Batch sent (5 messages)\n\n";
    }

    private function waitForConfirms(\CrazyGoat\RabbitStream\Client\Producer $producer): void
    {
        echo "Step 6: Waiting for confirms...\n";

        try {
            $producer->waitForConfirms(timeout: 5.0);
            echo "  ✓ All messages confirmed\n\n";
        } catch (\CrazyGoat\RabbitStream\Exception\TimeoutException $e) {
            echo "  ⚠ Timeout: {$e->getMessage()}\n\n";
        }
    }

    private function cleanup(\CrazyGoat\RabbitStream\Client\Producer $producer): void
    {
        echo "Step 7: Cleaning up...\n";

        $producer->close();
        $this->connection->close();

        echo "  ✓ Producer and connection closed\n";
    }
}

// Run the example
$example = new BasicProducerExample();
$example->run();
