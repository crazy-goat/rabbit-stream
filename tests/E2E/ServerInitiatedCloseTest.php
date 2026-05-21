<?php

declare(strict_types=1);

namespace CrazyGoat\RabbitStream\Tests\E2E;

use CrazyGoat\RabbitStream\Client\Connection;
use CrazyGoat\RabbitStream\Exception\ConnectionException;
use PHPUnit\Framework\TestCase;

/**
 * @group destructive
 */
class ServerInitiatedCloseTest extends TestCase
{
    private static string $host = '127.0.0.1';
    private static int $port = 5552;
    private static int $managementPort = 15672;

    private ?Connection $connection = null;
    private string $streamName;

    public static function setUpBeforeClass(): void
    {
        self::$host = getenv('RABBITMQ_HOST') ?: self::$host;
        self::$port = (int)(getenv('RABBITMQ_PORT') ?: self::$port);
        self::$managementPort = (int)(getenv('RABBITMQ_MANAGEMENT_PORT') ?: self::$managementPort);
    }

    protected function setUp(): void
    {
        $this->connection = Connection::create(self::$host, self::$port);
        $this->streamName = 'test-srv-close-' . uniqid();
        $this->connection->createStream($this->streamName);
    }

    protected function tearDown(): void
    {
        // Try to clean up the stream via a new connection if our connection was closed
        try {
            $cleanupConn = Connection::create(self::$host, self::$port);
            $cleanupConn->deleteStream($this->streamName);
            $cleanupConn->close();
        } catch (\Exception) {
            // Ignore cleanup errors
        }

        if ($this->connection instanceof Connection) {
            try {
                $this->connection->close();
            } catch (\Exception) {
                // Ignore cleanup errors - connection may already be closed
            }
        }
    }

    public function testServerInitiatedCloseIsHandledGracefully(): void
    {
        $connection = $this->connection;
        $this->assertNotNull($connection);

        // Find our connection name in RabbitMQ management API
        // The management API may need a few seconds to register the connection
        $connectionName = $this->getStreamConnectionName();
        $this->assertNotNull($connectionName, 'Could not find stream connection in management API');

        // Force-close the connection via management API
        $this->forceCloseConnection($connectionName);

        // The server needs a moment to close the TCP connection.
        // Retry createStream until it throws ConnectionException.
        $maxAttempts = 10;
        $lastException = null;

        for ($attempt = 0; $attempt < $maxAttempts; $attempt++) {
            try {
                $connection->createStream('another-stream-' . uniqid());
                // Still connected - wait and retry
                usleep(200_000);
            } catch (ConnectionException $e) {
                $lastException = $e;
                break;
            }
        }

        $this->assertNotNull($lastException, 'Expected ConnectionException was not thrown after server-initiated close');

        // After the operation fails, connection should be marked as disconnected
        $this->assertFalse($connection->isConnected());
    }

    private function getStreamConnectionName(): ?string
    {
        $maxWait = 10;
        $start = time();

        while (time() - $start < $maxWait) {
            $data = $this->curlGet(
                sprintf('http://%s:%d/api/connections', self::$host, self::$managementPort)
            );

            if ($data === null) {
                sleep(1);
                continue;
            }

            $connections = json_decode($data, true);
            if (!is_array($connections)) {
                sleep(1);
                continue;
            }

            foreach ($connections as $conn) {
                if (!is_array($conn)) {
                    continue;
                }
                if (!isset($conn['port'])) {
                    continue;
                }
                if ($conn['port'] !== self::$port) {
                    continue;
                }
                if (!isset($conn['protocol'])) {
                    continue;
                }
                if ($conn['protocol'] !== 'stream') {
                    continue;
                }
                if (!isset($conn['name'])) {
                    continue;
                }
                if (!is_string($conn['name'])) {
                    continue;
                }

                return $conn['name'];
            }

            sleep(1);
        }

        return null;
    }

    private function forceCloseConnection(string $name): void
    {
        $url = sprintf(
            'http://%s:%d/api/connections/%s',
            self::$host,
            self::$managementPort,
            rawurlencode($name)
        );

        $this->curlDelete($url);
    }

    private function curlGet(string $url): ?string
    {
        $cmd = sprintf(
            'curl -sf -u guest:guest %s 2>/dev/null',
            escapeshellarg($url)
        );

        $output = [];
        $returnCode = 0;
        exec($cmd, $output, $returnCode);

        if ($returnCode !== 0 || $output === []) {
            return null;
        }

        return implode("\n", $output);
    }

    private function curlDelete(string $url): void
    {
        $cmd = sprintf(
            'curl -sf -u guest:guest -X DELETE %s >/dev/null 2>/dev/null',
            escapeshellarg($url)
        );

        exec($cmd);
    }
}
