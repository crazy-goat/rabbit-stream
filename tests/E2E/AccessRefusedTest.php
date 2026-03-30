<?php

declare(strict_types=1);

namespace CrazyGoat\RabbitStream\Tests\E2E;

use CrazyGoat\RabbitStream\Enum\ResponseCodeEnum;
use CrazyGoat\RabbitStream\Exception\ProtocolException;
use CrazyGoat\RabbitStream\Request\CreateRequestV1;
use CrazyGoat\RabbitStream\Request\DeleteStreamRequestV1;
use CrazyGoat\RabbitStream\Request\OpenRequestV1;
use CrazyGoat\RabbitStream\Request\PeerPropertiesRequestV1;
use CrazyGoat\RabbitStream\Request\SaslAuthenticateRequestV1;
use CrazyGoat\RabbitStream\Request\SaslHandshakeRequestV1;
use CrazyGoat\RabbitStream\Request\TuneRequestV1;
use CrazyGoat\RabbitStream\Response\TuneResponseV1;
use CrazyGoat\RabbitStream\StreamConnection;
use PHPUnit\Framework\TestCase;

class AccessRefusedTest extends TestCase
{
    private static string $host = '127.0.0.1';
    private static int $port = 5552;
    private static ?bool $managementApiAvailable = null;

    public static function setUpBeforeClass(): void
    {
        self::$host = getenv('RABBITMQ_HOST') ?: self::$host;
        self::$port = (int)(getenv('RABBITMQ_PORT') ?: self::$port);
    }

    private function isManagementApiAvailable(): bool
    {
        if (self::$managementApiAvailable === null) {
            $ch = curl_init('http://' . self::$host . ':15672/api/overview');
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_USERPWD, 'guest:guest');
            curl_setopt($ch, CURLOPT_TIMEOUT, 5);
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            self::$managementApiAvailable = ($response !== false && $httpCode === 200);
        }
        return self::$managementApiAvailable;
    }

    private function connectAsRestrictedUser(): StreamConnection
    {
        $connection = new StreamConnection(self::$host, self::$port);
        $connection->connect();

        $connection->sendMessage(new PeerPropertiesRequestV1());
        $connection->readMessage();

        $connection->sendMessage(new SaslHandshakeRequestV1());
        $connection->readMessage();

        $connection->sendMessage(new SaslAuthenticateRequestV1('PLAIN', 'restricted', 'restricted'));
        $connection->readMessage();

        $tune = $connection->readMessage();
        $this->assertInstanceOf(TuneRequestV1::class, $tune);
        $connection->sendMessage(new TuneResponseV1($tune->getFrameMax(), $tune->getHeartbeat()));

        $connection->sendMessage(new OpenRequestV1('/'));
        $connection->readMessage();

        return $connection;
    }

    public function testCreateStreamAccessRefused(): void
    {
        if (!$this->isManagementApiAvailable()) {
            $this->markTestSkipped('RabbitMQ management API not available');
        }

        $connection = $this->connectAsRestrictedUser();

        try {
            $this->expectException(ProtocolException::class);
            $this->expectExceptionMessage('ACCESS_REFUSED');

            $streamName = 'test-access-refused-create-' . uniqid();
            $connection->sendMessage(new CreateRequestV1($streamName));
            $connection->readMessage();
        } finally {
            $connection->close();
        }
    }

    public function testCreateStreamAccessRefusedHasResponseCode(): void
    {
        if (!$this->isManagementApiAvailable()) {
            $this->markTestSkipped('RabbitMQ management API not available');
        }

        $connection = $this->connectAsRestrictedUser();

        try {
            $streamName = 'test-access-refused-code-' . uniqid();
            $connection->sendMessage(new CreateRequestV1($streamName));
            $connection->readMessage();
            $this->fail('Expected ProtocolException to be thrown');
        } catch (ProtocolException $e) {
            $this->assertSame(ResponseCodeEnum::ACCESS_REFUSED, $e->getResponseCode());
            $this->assertStringContainsString('Access refused', $e->getMessage());
        } finally {
            $connection->close();
        }
    }

    public function testDeleteStreamAccessRefused(): void
    {
        if (!$this->isManagementApiAvailable()) {
            $this->markTestSkipped('RabbitMQ management API not available');
        }

        $connection = $this->connectAsRestrictedUser();

        try {
            $this->expectException(ProtocolException::class);
            $this->expectExceptionMessage('ACCESS_REFUSED');

            $streamName = 'test-access-refused-delete-' . uniqid();
            $connection->sendMessage(new DeleteStreamRequestV1($streamName));
            $connection->readMessage();
        } finally {
            $connection->close();
        }
    }
}
