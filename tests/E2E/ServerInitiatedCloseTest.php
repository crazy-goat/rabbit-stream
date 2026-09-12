<?php

declare(strict_types=1);

namespace CrazyGoat\RabbitStream\Tests\E2E;

use CrazyGoat\RabbitStream\Client\Connection;
use CrazyGoat\RabbitStream\Exception\ConnectionException;

/**
 * @group destructive
 */
class ServerInitiatedCloseTest extends E2ETestCase
{
    private static int $managementPort = 15672;

    private ?Connection $connection = null;
    private string $streamName;

    /**
     * Names of stream connections that already existed before this test opened
     * its own connection. The test's connection is the one that is present
     * afterwards but missing from this snapshot. Keyed by name for O(1) lookup.
     *
     * @var array<string, true>
     */
    private array $preExistingStreamConnectionNames = [];

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        self::$managementPort = (int)(getenv('RABBITMQ_MANAGEMENT_PORT') ?: self::$managementPort);
    }

    protected function setUp(): void
    {
        // Snapshot existing stream connections BEFORE opening ours so the test
        // can tell its own connection apart from unrelated/idle ones.
        $this->preExistingStreamConnectionNames = $this->getStreamConnectionNames();

        $this->connection = $this->createConnection();
        $this->streamName = 'test-srv-close-' . uniqid();
        $this->connection->createStream($this->streamName);
    }

    protected function tearDown(): void
    {
        // Try to clean up the stream via a new connection if our connection was closed
        try {
            $cleanupConn = $this->createConnection();
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

        $this->assertNotNull(
            $lastException,
            'Expected ConnectionException was not thrown after server-initiated close'
        );

        // After the operation fails, connection should be marked as disconnected
        $this->assertFalse($connection->isConnected());
    }

    private function getStreamConnectionName(): ?string
    {
        $maxWait = 10;
        $start = time();

        while (time() - $start < $maxWait) {
            foreach (array_keys($this->getStreamConnectionNames()) as $name) {
                if (!isset($this->preExistingStreamConnectionNames[$name])) {
                    return $name;
                }
            }

            sleep(1);
        }

        return null;
    }

    /**
     * Names of the stream-protocol connections currently registered with the
     * broker, as reported by the management API.
     *
     * The `port` field in `/api/connections` is the broker-side port — every
     * stream connection on this broker reports the same container-side 5552
     * regardless of which client port it came from (the client's ephemeral
     * port is `peer_port`). Matching on `port` therefore selects *any* stream
     * connection, not this test's, so we do not use it. Instead the test takes
     * a snapshot of existing connections in setUp() and later picks the
     * stream connection whose name is new.
     *
     * @return array<string, true> connection names as a set
     */
    private function getStreamConnectionNames(): array
    {
        $data = $this->curlGet(
            sprintf('http://%s:%d/api/connections', self::$host, self::$managementPort)
        );

        if ($data === null) {
            return [];
        }

        $connections = json_decode($data, true);
        if (!is_array($connections)) {
            return [];
        }

        $names = [];
        foreach ($connections as $conn) {
            if (!is_array($conn)) {
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
            if ($conn['name'] === '') {
                continue;
            }

            $names[$conn['name']] = true;
        }

        return $names;
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
