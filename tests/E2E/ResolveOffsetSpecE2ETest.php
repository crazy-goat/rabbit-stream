<?php

namespace CrazyGoat\RabbitStream\Tests\E2E;

use CrazyGoat\RabbitStream\Request\CreateRequestV1;
use CrazyGoat\RabbitStream\Request\OpenRequest;
use CrazyGoat\RabbitStream\Request\PeerPropertiesToStreamBufferV1;
use CrazyGoat\RabbitStream\Request\ResolveOffsetSpecRequestV1;
use CrazyGoat\RabbitStream\Request\SaslAuthenticateRequestV1;
use CrazyGoat\RabbitStream\Request\SaslHandshakeRequestV1;
use CrazyGoat\RabbitStream\Request\TuneRequestV1;
use CrazyGoat\RabbitStream\Response\CreateResponseV1;
use CrazyGoat\RabbitStream\Response\ResolveOffsetSpecResponseV1;
use CrazyGoat\RabbitStream\Response\TuneResponseV1;
use CrazyGoat\RabbitStream\StreamConnection;
use CrazyGoat\RabbitStream\VO\OffsetSpec;
use PHPUnit\Framework\TestCase;

/**
 * E2E tests for ResolveOffsetSpec command.
 * 
 * NOTE: These tests require RabbitMQ 4.3+ which supports the ResolveOffsetSpec command (0x001f).
 * RabbitMQ 4.2.x and earlier versions do not support this command and will close the connection.
 * The tests are skipped until RabbitMQ 4.3 is released.
 */
class ResolveOffsetSpecE2ETest extends TestCase
{
    private static string $host = '127.0.0.1';
    private static int $port = 5552;

    public static function setUpBeforeClass(): void
    {
        self::$host = getenv('RABBITMQ_HOST') ?: self::$host;
        self::$port = (int)(getenv('RABBITMQ_PORT') ?: self::$port);
    }

    private function connectAndOpen(): StreamConnection
    {
        $connection = new StreamConnection(self::$host, self::$port);
        $connection->connect();

        $connection->sendMessage(new PeerPropertiesToStreamBufferV1());
        $connection->readMessage();

        $connection->sendMessage(new SaslHandshakeRequestV1());
        $connection->readMessage();

        $connection->sendMessage(new SaslAuthenticateRequestV1('PLAIN', 'guest', 'guest'));
        $connection->readMessage();

        $tune = $connection->readMessage();
        $this->assertInstanceOf(TuneRequestV1::class, $tune);
        $connection->sendMessage(new TuneResponseV1($tune->getFrameMax(), $tune->getHeartbeat()));

        $connection->sendMessage(new OpenRequest('/'));
        $connection->readMessage();

        return $connection;
    }

    public function testResolveFirstOffset(): void
    {
        $this->markTestSkipped('ResolveOffsetSpec command requires RabbitMQ 4.3+. Current version does not support this command.');
        
        $connection = $this->connectAndOpen();
        $stream = 'test-resolve-first-stream-' . uniqid();

        // Create stream first
        $connection->sendMessage(new CreateRequestV1($stream));
        $connection->readMessage();

        $request = new ResolveOffsetSpecRequestV1(
            $stream,
            OffsetSpec::first()
        );
        $request->withCorrelationId(1);

        $connection->sendMessage($request);
        $response = $connection->readMessage();

        $this->assertInstanceOf(ResolveOffsetSpecResponseV1::class, $response);
        $this->assertSame(1, $response->getCorrelationId());
        $this->assertIsInt($response->getOffset());
        $this->assertGreaterThanOrEqual(0, $response->getOffset());

        $connection->close();
    }

    public function testResolveLastOffset(): void
    {
        $this->markTestSkipped('ResolveOffsetSpec command requires RabbitMQ 4.3+. Current version does not support this command.');
        
        $connection = $this->connectAndOpen();
        $stream = 'test-resolve-last-stream-' . uniqid();

        // Create stream (empty, so last should be 0 or similar)
        $connection->sendMessage(new CreateRequestV1($stream));
        $connection->readMessage();

        $request = new ResolveOffsetSpecRequestV1(
            $stream,
            OffsetSpec::last()
        );
        $request->withCorrelationId(2);

        $connection->sendMessage($request);
        $response = $connection->readMessage();

        $this->assertInstanceOf(ResolveOffsetSpecResponseV1::class, $response);
        $this->assertIsInt($response->getOffset());

        $connection->close();
    }

    public function testResolveOffsetSpec(): void
    {
        $this->markTestSkipped('ResolveOffsetSpec command requires RabbitMQ 4.3+. Current version does not support this command.');
        
        $connection = $this->connectAndOpen();
        $stream = 'test-resolve-offset-stream-' . uniqid();

        // Create stream
        $connection->sendMessage(new CreateRequestV1($stream));
        $connection->readMessage();

        $request = new ResolveOffsetSpecRequestV1(
            $stream,
            OffsetSpec::offset(100)
        );
        $request->withCorrelationId(3);

        $connection->sendMessage($request);
        $response = $connection->readMessage();

        $this->assertInstanceOf(ResolveOffsetSpecResponseV1::class, $response);
        // Should return the same offset if it exists, or the closest valid offset
        $this->assertIsInt($response->getOffset());

        $connection->close();
    }
}
