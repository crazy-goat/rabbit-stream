<?php

declare(strict_types=1);

namespace CrazyGoat\RabbitStream\Tests\Client;

use CrazyGoat\RabbitStream\Exception\ConnectionException;
use CrazyGoat\RabbitStream\StreamConnection;
use CrazyGoat\RabbitStream\VO\TlsConfig;
use PHPUnit\Framework\TestCase;

/**
 * Checks the Server Name Indication (SNI) that the TLS client puts in the ClientHello.
 * RFC 6066 section 3 does not allow an IP literal in the HostName field (GitHub #929).
 */
final class TlsSniTest extends TestCase
{
    public function testIpv4HostSendsNoServerName(): void
    {
        self::assertNull($this->sniForHost('127.0.0.1', null, '127.0.0.1'));
    }

    public function testBracketedIpv6HostSendsNoServerName(): void
    {
        if (@stream_socket_server('tcp://[::1]:0') === false) {
            self::markTestSkipped('IPv6 loopback is not available');
        }

        self::assertNull($this->sniForHost('[::1]', null, '[::1]'));
    }

    public function testPeerNameIsSentAsServerNameForIpHost(): void
    {
        self::assertSame('broker.local', $this->sniForHost('127.0.0.1', 'broker.local', '127.0.0.1'));
    }

    /**
     * Connect to a listener that never answers the handshake, then return the SNI host name
     * from the ClientHello that the listener received. The ClientHello stays in the socket
     * buffer after the client gives up, so the test can read it after connect() fails.
     */
    private function sniForHost(string $host, ?string $peerName, string $bind): ?string
    {
        $server = stream_socket_server('tcp://' . $bind . ':0', $errorCode, $errorMessage);
        self::assertIsResource($server, "Cannot listen: {$errorMessage} ({$errorCode})");
        $port = (int) substr((string) strrchr((string) stream_socket_get_name($server, false), ':'), 1);

        $connection = new StreamConnection(
            $host,
            $port,
            socketTimeout: 0.5,
            tls: new TlsConfig(peerName: $peerName, verifyPeer: false, verifyPeerName: false)
        );

        try {
            $connection->connect();
            self::fail('Expected ConnectionException: the listener never answers the handshake');
        } catch (ConnectionException) {
            // Expected: the listener does not speak TLS.
        }

        $client = stream_socket_accept($server, 2.0);
        fclose($server);
        self::assertIsResource($client, 'No connection reached the listener');

        try {
            return $this->readServerName($client);
        } finally {
            fclose($client);
        }
    }

    /**
     * Parse the ClientHello and return the host_name from the server_name extension, or null.
     *
     * @param resource $client
     */
    private function readServerName($client): ?string
    {
        stream_set_blocking($client, true);
        stream_set_timeout($client, 2);

        // TLS record header: content type (1 byte, 0x16 = handshake), version (2), length (2).
        $record = $this->readBytes($client, 5);
        self::assertSame("\x16", $record[0]);
        $hello = $this->readBytes($client, $this->uint16($record, 3));

        // Handshake header: type (1 byte, 0x01 = ClientHello), length (3), client version (2), random (32).
        self::assertSame("\x01", $hello[0]);
        $pos = 4 + 2 + 32;
        $pos += 1 + ord($hello[$pos]);                          // session id
        $pos += 2 + $this->uint16($hello, $pos);                // cipher suites
        $pos += 1 + ord($hello[$pos]);                          // compression methods
        $extensionsEnd = $pos + 2 + $this->uint16($hello, $pos);
        $pos += 2;

        while ($pos < $extensionsEnd) {
            $type = $this->uint16($hello, $pos);
            $length = $this->uint16($hello, $pos + 2);
            if ($type === 0) {
                // server_name: list length (2), name type (1, 0 = host_name), name length (2), name.
                $nameLength = $this->uint16($hello, $pos + 4 + 3);

                return substr($hello, $pos + 4 + 5, $nameLength);
            }
            $pos += 4 + $length;
        }

        return null;
    }

    /** @param resource $client */
    private function readBytes($client, int $length): string
    {
        $data = '';
        while (strlen($data) < $length) {
            $chunk = fread($client, max(1, $length - strlen($data)));
            if ($chunk === false || $chunk === '') {
                self::fail('Connection closed after ' . strlen($data) . ' of ' . $length . ' bytes');
            }
            $data .= $chunk;
        }

        return $data;
    }

    private function uint16(string $bytes, int $offset): int
    {
        return (ord($bytes[$offset]) << 8) | ord($bytes[$offset + 1]);
    }
}
