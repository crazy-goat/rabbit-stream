<?php

namespace CrazyGoat\RabbitStream\Tests\E2E;

use CrazyGoat\RabbitStream\Client\Connection;
use PHPUnit\Framework\TestCase;

class ConnectionTest extends TestCase
{
    private static string $host = '127.0.0.1';
    private static int $port = 5552;

    public static function setUpBeforeClass(): void
    {
        self::$host = getenv('RABBITMQ_HOST') ?: self::$host;
        self::$port = (int)(getenv('RABBITMQ_PORT') ?: self::$port);
    }

    public function testConnectionCreateAndStreamManagement(): void
    {
        $connection = Connection::create(
            host: self::$host,
            port: self::$port,
            user: 'guest',
            password: 'guest',
            vhost: '/'
        );

        $streamName = 'test-connection-stream-' . uniqid();

        // Create stream
        $connection->createStream($streamName);

        // Verify stream exists
        $this->assertTrue($connection->streamExists($streamName));

        // Delete stream
        $connection->deleteStream($streamName);

        // Verify stream no longer exists
        $this->assertFalse($connection->streamExists($streamName));

        // Close connection gracefully
        $connection->close();
    }

    public function testCreateStreamWithArguments(): void
    {
        $connection = Connection::create(
            host: self::$host,
            port: self::$port,
            user: 'guest',
            password: 'guest',
            vhost: '/'
        );

        $streamName = 'test-connection-stream-args-' . uniqid();

        // Create stream with arguments
        $connection->createStream($streamName, [
            'max-length-bytes' => '1000000',
            'max-age' => '1h'
        ]);

        // Verify stream exists
        $this->assertTrue($connection->streamExists($streamName));

        // Cleanup
        $connection->deleteStream($streamName);
        $connection->close();
    }

    public function testGetMetadata(): void
    {
        $connection = Connection::create(
            host: self::$host,
            port: self::$port,
            user: 'guest',
            password: 'guest',
            vhost: '/'
        );

        $streamName = 'test-metadata-stream-' . uniqid();
        $connection->createStream($streamName);

        $metadata = $connection->getMetadata([$streamName]);
        
        $this->assertNotEmpty($metadata->getStreamMetadata());
        $this->assertSame($streamName, $metadata->getStreamMetadata()[0]->getStreamName());

        // Cleanup
        $connection->deleteStream($streamName);
        $connection->close();
    }

    public function testGetStreamStats(): void
    {
        $connection = Connection::create(
            host: self::$host,
            port: self::$port,
            user: 'guest',
            password: 'guest',
            vhost: '/'
        );

        $streamName = 'test-stats-stream-' . uniqid();
        $connection->createStream($streamName);

        $stats = $connection->getStreamStats($streamName);
        
        // Stats should be an array (may be empty for new stream)
        $this->assertIsArray($stats);

        // Cleanup
        $connection->deleteStream($streamName);
        $connection->close();
    }

    public function testCreateDuplicateStreamThrows(): void
    {
        $connection = Connection::create(
            host: self::$host,
            port: self::$port,
            user: 'guest',
            password: 'guest',
            vhost: '/'
        );

        $streamName = 'test-duplicate-stream-' . uniqid();
        
        // Create stream first time
        $connection->createStream($streamName);

        // Second create should throw
        $this->expectException(\Exception::class);
        $connection->createStream($streamName);

        // Cleanup (won't be reached due to exception)
        $connection->deleteStream($streamName);
        $connection->close();
    }
}
