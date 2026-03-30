<?php

declare(strict_types=1);

namespace CrazyGoat\RabbitStream\Tests\E2E;

use CrazyGoat\RabbitStream\Client\Connection;
use CrazyGoat\RabbitStream\Exception\ConnectionException;
use PHPUnit\Framework\TestCase;

/**
 * Tests for server-initiated connection close handling.
 *
 * These tests verify that when the RabbitMQ server closes a connection
 * (e.g., via management API or administrative action), the client properly:
 * - Detects the close via isConnected() returning false
 * - Throws ConnectionException on subsequent operations
 * - Handles the close gracefully without resource leaks
 *
 * @group destructive
 * @group slow
 * @group management-api
 */
class ServerInitiatedCloseTest extends TestCase
{
    private static string $host = '127.0.0.1';
    private static int $port = 5552;
    private static string $managementHost = '127.0.0.1';
    private static int $managementPort = 15672;
    private static string $managementUser = 'guest';
    private static string $managementPass = 'guest';

    public static function setUpBeforeClass(): void
    {
        self::$host = getenv('RABBITMQ_HOST') ?: self::$host;
        self::$port = (int)(getenv('RABBITMQ_PORT') ?: self::$port);
        self::$managementHost = getenv('RABBITMQ_MANAGEMENT_HOST') ?: self::$host;
        self::$managementPort = (int)(getenv('RABBITMQ_MANAGEMENT_PORT') ?: self::$managementPort);
        self::$managementUser = getenv('RABBITMQ_MANAGEMENT_USER') ?: self::$managementUser;
        self::$managementPass = getenv('RABBITMQ_MANAGEMENT_PASS') ?: self::$managementPass;
    }

    /**
     * Test that isConnected() returns false after server-initiated close.
     */
    public function testIsConnectedReturnsFalseAfterServerClose(): void
    {
        $connection = Connection::create(
            host: self::$host,
            port: self::$port,
            user: 'guest',
            password: 'guest',
            vhost: '/'
        );

        $this->assertTrue($connection->isConnected(), 'Connection should be connected initially');

        // Force server to close the connection via management API
        $this->forceCloseConnectionViaManagementApi();

        // Wait a moment for the close to propagate
        usleep(100000); // 100ms

        // After server-initiated close, isConnected() should return false
        $this->assertFalse($connection->isConnected(), 'Connection should report as disconnected after server close');
    }

    /**
     * Test that operations throw ConnectionException after server-initiated close.
     */
    public function testOperationsThrowAfterServerClose(): void
    {
        $connection = Connection::create(
            host: self::$host,
            port: self::$port,
            user: 'guest',
            password: 'guest',
            vhost: '/'
        );

        $streamName = 'test-server-close-' . uniqid();
        $connection->createStream($streamName);

        // Force server to close the connection
        $this->forceCloseConnectionViaManagementApi();

        // Wait a moment for the close to propagate
        usleep(100000); // 100ms

        // Subsequent operations should throw ConnectionException
        $this->expectException(ConnectionException::class);
        $connection->createStream('another-stream-' . uniqid());
    }

    /**
     * Test that the client handles server-initiated close gracefully without crashes.
     */
    public function testServerInitiatedCloseIsHandledGracefully(): void
    {
        $connection = Connection::create(
            host: self::$host,
            port: self::$port,
            user: 'guest',
            password: 'guest',
            vhost: '/'
        );

        $streamName = 'test-graceful-close-' . uniqid();
        $connection->createStream($streamName);

        $this->assertTrue($connection->isConnected());

        // Force server close
        $this->forceCloseConnectionViaManagementApi();

        // Wait for close to propagate
        usleep(100000); // 100ms

        // Verify connection is marked as closed
        $this->assertFalse($connection->isConnected());

        // Verify destructor doesn't throw or cause warnings
        unset($connection);

        // If we reach here without errors, the test passes
        $this->addToAssertionCount(1);
    }

    /**
     * Test that multiple operations after server close all throw properly.
     */
    public function testMultipleOperationsThrowAfterServerClose(): void
    {
        $connection = Connection::create(
            host: self::$host,
            port: self::$port,
            user: 'guest',
            password: 'guest',
            vhost: '/'
        );

        $streamName = 'test-multi-ops-' . uniqid();
        $connection->createStream($streamName);

        // Force server close
        $this->forceCloseConnectionViaManagementApi();

        // Wait for close to propagate
        usleep(100000); // 100ms

        // First operation should throw
        try {
            $connection->createStream('stream-1-' . uniqid());
            $this->fail('Expected ConnectionException was not thrown');
        } catch (ConnectionException) {
            // Expected
        }

        // Connection should still report as disconnected
        $this->assertFalse($connection->isConnected());

        // Second operation should also throw
        $this->expectException(ConnectionException::class);
        $connection->deleteStream($streamName);
    }

    /**
     * Force close the connection via RabbitMQ Management API.
     *
     * This method queries the management API for active connections,
     * identifies the connection from this client by peer address, and
     * forces the server to close it.
     */
    private function forceCloseConnectionViaManagementApi(): void
    {
        // Get our local IP address to identify our connection
        $localIp = $this->getLocalIpAddress();

        // Query management API for connections
        $connections = $this->getConnectionsFromManagementApi();

        if ($connections === null) {
            $this->markTestSkipped('Management API not available or returned invalid response');
        }

        // Find our connection by peer_address
        $ourConnection = null;
        foreach ($connections as $conn) {
            if (!is_array($conn)) {
                continue;
            }
            if (!isset($conn['peer_address'])) {
                continue;
            }
            if ($conn['peer_address'] !== $localIp) {
                continue;
            }
            if (!isset($conn['peer_port'])) {
                continue;
            }
            if ($conn['peer_port'] <= 0) {
                continue;
            }
            $ourConnection = $conn;
            break;
        }

        if ($ourConnection === null) {
            // Try alternative: close all stream connections from our host
            foreach ($connections as $conn) {
                if (!is_array($conn)) {
                    continue;
                }
                if (!isset($conn['peer_address'])) {
                    continue;
                }
                if ($conn['peer_address'] !== $localIp) {
                    continue;
                }
                if (!isset($conn['protocol'])) {
                    continue;
                }
                if ($conn['protocol'] !== 'stream') {
                    continue;
                }
                $ourConnection = $conn;
                break;
            }
        }

        if ($ourConnection === null) {
            $this->markTestSkipped(
                'Could not identify our connection in management API. ' .
                'Local IP: ' . $localIp . ', Connections: ' . json_encode($connections)
            );
        }

        // Close the connection via management API
        if (!isset($ourConnection['name']) || !is_string($ourConnection['name'])) {
            $this->markTestSkipped('Connection name not available or invalid');
        }
        $this->closeConnectionViaManagementApi($ourConnection['name']);
    }

    /**
     * Get list of connections from RabbitMQ Management API.
     *
     * @return array<mixed>|null Array of connections or null on error
     */
    private function getConnectionsFromManagementApi(): ?array
    {
        $url = sprintf(
            'http://%s:%d/api/connections',
            self::$managementHost,
            self::$managementPort
        );

        $ch = curl_init($url);
        if ($ch === false) {
            return null;
        }

        curl_setopt($ch, CURLOPT_USERPWD, self::$managementUser . ':' . self::$managementPass);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($response === false || $httpCode !== 200 || !is_string($response)) {
            return null;
        }

        $decoded = json_decode($response, true);
        if (!is_array($decoded)) {
            return null;
        }

        return $decoded;
    }

    /**
     * Close a specific connection via RabbitMQ Management API.
     */
    private function closeConnectionViaManagementApi(string $connectionName): void
    {
        $url = sprintf(
            'http://%s:%d/api/connections/%s',
            self::$managementHost,
            self::$managementPort,
            urlencode($connectionName)
        );

        $ch = curl_init($url);
        if ($ch === false) {
            $this->markTestSkipped('Failed to initialize curl for connection close');
        }

        curl_setopt($ch, CURLOPT_USERPWD, self::$managementUser . ':' . self::$managementPass);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);

        curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        // 204 = success, 404 = connection already closed (also acceptable)
        if ($httpCode !== 204 && $httpCode !== 404) {
            $this->markTestSkipped(
                'Failed to close connection via management API. HTTP code: ' . $httpCode
            );
        }
    }

    /**
     * Get the local IP address used to connect to RabbitMQ.
     */
    private function getLocalIpAddress(): string
    {
        // Try to get the IP address used for outbound connections
        // by creating a test socket connection
        $socket = @fsockopen(self::$host, self::$port, $errno, $errstr, 1);
        if ($socket !== false) {
            $localAddr = stream_socket_get_name($socket, false);
            fclose($socket);
            if ($localAddr !== false) {
                // Parse IP:port format
                if (str_contains($localAddr, ':')) {
                    $parts = explode(':', $localAddr);
                    return $parts[0];
                }
                return $localAddr;
            }
        }

        // Fallback: try to get hostname IP
        $hostname = gethostname();
        if ($hostname !== false) {
            $ip = gethostbyname($hostname);
            if ($ip !== $hostname) {
                return $ip;
            }
        }

        // Final fallback
        return '127.0.0.1';
    }
}
