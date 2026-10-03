<?php

declare(strict_types=1);

use CrazyGoat\RabbitStream\Client\ConfirmationStatus;
use CrazyGoat\RabbitStream\Client\Connection;

require_once __DIR__ . '/../vendor/autoload.php';

/**
 * Named Producer Deduplication Example
 *
 * Demonstrates:
 * - Creating a named producer
 * - Publishing with sequence numbers
 * - Simulating a disconnect/reconnect
 * - Querying the last confirmed sequence
 * - Deduplication in action
 */
class NamedProducerDeduplicationExample
{
    private string $producerName = 'order-producer';
    private string $streamName = 'orders-stream';
    private array $confirmedMessages = [];
    private array $failedMessages = [];

    public function run(): void
    {
        echo "=== Named Producer Deduplication Example ===\n\n";

        // Phase 1: Initial connection and publishing
        echo "PHASE 1: Initial Connection\n";
        echo str_repeat('-', 40) . "\n";
        $connection1 = $this->createConnection();
        $this->createStream($connection1);
        $producer1 = $this->createNamedProducer($connection1);

        // Publish messages 1-5
        echo "Publishing messages 1-5...\n";
        for ($i = 1; $i <= 5; $i++) {
            $producer1->send("Order #{$i}");
            echo "  → Sent Order #{$i} (ID: {$producer1->getLastPublishingId()})\n";
        }

        $this->waitForConfirms($producer1);
        $this->showStatus("After Phase 1");

        // Phase 2: Simulate disconnect
        echo "\nPHASE 2: Simulating Disconnect\n";
        echo str_repeat('-', 40) . "\n";
        echo "Closing connection (simulating network failure)...\n";
        $producer1->close();
        $connection1->close();
        echo "  ✓ Connection closed\n";

        // Phase 3: Reconnect with same producer name
        echo "\nPHASE 3: Reconnecting\n";
        echo str_repeat('-', 40) . "\n";
        $connection2 = $this->createConnection();
        $producer2 = $this->createNamedProducer($connection2);

        // Query the sequence - should be 5
        $lastSequence = $producer2->querySequence();
        echo "Last confirmed sequence from server: {$lastSequence}\n";
        echo "Next publishing ID will be: " . ($lastSequence + 1) . "\n";

        // Phase 4: Demonstrate deduplication
        echo "\nPHASE 4: Deduplication in Action\n";
        echo str_repeat('-', 40) . "\n";

        // Try to "retry" messages 3, 4, 5 (these should be deduplicated)
        echo "Attempting to retry messages 3, 4, 5 (should be deduplicated)...\n";
        $producer2->send("Order #3 (retry)");
        $producer2->send("Order #4 (retry)");
        $producer2->send("Order #5 (retry)");

        // Send a new message (should succeed)
        echo "Sending new message 6...\n";
        $producer2->send("Order #6 (new)");

        $this->waitForConfirms($producer2);
        $this->showStatus("After Phase 4");

        // Phase 5: Cleanup
        echo "\nPHASE 5: Cleanup\n";
        echo str_repeat('-', 40) . "\n";
        $producer2->close();
        $connection2->close();
        echo "  ✓ Cleanup complete\n";

        // Summary
        echo "\n=== Summary ===\n";
        echo "Total confirmed (unique): " . count($this->confirmedMessages) . "\n";
        echo "Total failed/duplicates: " . count($this->failedMessages) . "\n";
        echo "\nConfirmed messages:\n";
        foreach ($this->confirmedMessages as $id => $msg) {
            echo "  #{$id}: {$msg}\n";
        }
    }

    private function createConnection(): Connection
    {
        $host = getenv('RABBITMQ_HOST') ?: '127.0.0.1';
        $port = (int)(getenv('RABBITMQ_PORT') ?: 5552);

        return Connection::create(
            host: $host,
            port: $port,
            user: 'guest',
            password: 'guest',
        );
    }

    private function createStream(Connection $connection): void
    {
        try {
            $connection->createStream($this->streamName, [
                'max-length-bytes' => '1000000000',
            ]);
            echo "  ✓ Stream '{$this->streamName}' created\n";
        } catch (\Exception $e) {
            echo "  ℹ Stream may already exist\n";
        }
    }

    private function createNamedProducer(Connection $connection): \CrazyGoat\RabbitStream\Client\Producer
    {
        echo "Creating named producer '{$this->producerName}'...\n";

        $producer = $connection->createProducer(
            $this->streamName,
            name: $this->producerName,
            onConfirm: function (ConfirmationStatus $status) {
                if ($status->isConfirmed()) {
                    $id = $status->getPublishingId();
                    $this->confirmedMessages[$id] = true;
                    echo "    ✓ Confirmed: #{$id}\n";
                } else {
                    $id = $status->getPublishingId();
                    $this->failedMessages[$id] = $status->getErrorCode();
                    echo "    ✗ Failed/duplicate: #{$id} (code: {$status->getErrorCode()})\n";
                }
            }
        );

        echo "  ✓ Producer created\n";

        return $producer;
    }

    private function waitForConfirms(\CrazyGoat\RabbitStream\Client\Producer $producer): void
    {
        try {
            $producer->waitForConfirms(timeout: 5.0);
            echo "  ✓ All confirms received\n";
        } catch (\CrazyGoat\RabbitStream\Exception\TimeoutException $e) {
            echo "  ⚠ Timeout waiting for confirms\n";
        }
    }

    private function showStatus(string $phase): void
    {
        echo "\n  Status [{$phase}]:\n";
        echo "    - Confirmed: " . count($this->confirmedMessages) . "\n";
        echo "    - Failed/Duplicates: " . count($this->failedMessages) . "\n";
    }
}

// Run the example
$example = new NamedProducerDeduplicationExample();
$example->run();
