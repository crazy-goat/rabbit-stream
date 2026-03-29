<?php

declare(strict_types=1);

namespace CrazyGoat\RabbitStream\Tests\E2E;

use CrazyGoat\RabbitStream\Request\CreateRequestV1;
use CrazyGoat\RabbitStream\Request\ExchangeCommandVersionsRequestV1;
use CrazyGoat\RabbitStream\Request\OpenRequestV1;
use CrazyGoat\RabbitStream\Request\PeerPropertiesRequestV1;
use CrazyGoat\RabbitStream\Request\ResolveOffsetSpecRequestV1;
use CrazyGoat\RabbitStream\Request\SaslAuthenticateRequestV1;
use CrazyGoat\RabbitStream\Request\SaslHandshakeRequestV1;
use CrazyGoat\RabbitStream\Request\TuneRequestV1;
use CrazyGoat\RabbitStream\Response\ExchangeCommandVersionsResponseV1;
use CrazyGoat\RabbitStream\Response\ResolveOffsetSpecResponseV1;
use CrazyGoat\RabbitStream\Response\TuneResponseV1;
use CrazyGoat\RabbitStream\StreamConnection;
use CrazyGoat\RabbitStream\VO\OffsetSpec;
use PHPUnit\Framework\TestCase;

/**
 * E2E tests for ResolveOffsetSpec command.
 *
 * These tests check if the server supports the ResolveOffsetSpec command (0x001f)
 * via ExchangeCommandVersions. If the command is not supported (RabbitMQ < 4.3),
 * all tests are skipped.
 */
class ResolveOffsetSpecE2ETest extends TestCase
{
    private static string $host = '127.0.0.1';
    private static int $port = 5552;
    private static bool $commandSupported = false;

    public static function setUpBeforeClass(): void
    {
        self::$host = getenv('RABBITMQ_HOST') ?: self::$host;
        self::$port = (int)(getenv('RABBITMQ_PORT') ?: self::$port);

        // Check if server supports ResolveOffsetSpec command
        try {
            $connection = new StreamConnection(self::$host, self::$port);
            $connection->connect();

            $connection->sendMessage(new PeerPropertiesRequestV1());
            $connection->readMessage();

            $connection->sendMessage(new SaslHandshakeRequestV1());
            $connection->readMessage();

            $connection->sendMessage(new SaslAuthenticateRequestV1('PLAIN', 'guest', 'guest'));
            $connection->readMessage();

            $tune = $connection->readMessage();
            if (!$tune instanceof TuneRequestV1) {
                throw new \RuntimeException('Expected TuneRequestV1');
            }
            $connection->sendMessage(new TuneResponseV1($tune->getFrameMax(), $tune->getHeartbeat()));

            $connection->sendMessage(new OpenRequestV1('/'));
            $connection->readMessage();

            // Query supported commands
            $request = new ExchangeCommandVersionsRequestV1([]);
            $request->withCorrelationId(999);
            $connection->sendMessage($request);
            $response = $connection->readMessage();

            if ($response instanceof ExchangeCommandVersionsResponseV1) {
                foreach ($response->getCommands() as $command) {
                    if ($command->getKey() === 0x001f) {
                        self::$commandSupported = true;
                        break;
                    }
                }
            }

            $connection->close();
        } catch (\Throwable) {
            // If we can't connect or check, assume not supported
            self::$commandSupported = false;
        }
    }

    protected function setUp(): void
    {
        if (!self::$commandSupported) {
            $this->markTestSkipped(
                'ResolveOffsetSpec command (0x001f) is not supported by the server. ' .
                'This command requires RabbitMQ 4.3+. Current version does not support this command.'
            );
        }
    }

    private function connectAndOpen(): StreamConnection
    {
        $connection = new StreamConnection(self::$host, self::$port);
        $connection->connect();

        $connection->sendMessage(new PeerPropertiesRequestV1());
        $connection->readMessage();

        $connection->sendMessage(new SaslHandshakeRequestV1());
        $connection->readMessage();

        $connection->sendMessage(new SaslAuthenticateRequestV1('PLAIN', 'guest', 'guest'));
        $connection->readMessage();

        $tune = $connection->readMessage();
        $this->assertInstanceOf(TuneRequestV1::class, $tune);
        $connection->sendMessage(new TuneResponseV1($tune->getFrameMax(), $tune->getHeartbeat()));

        $connection->sendMessage(new OpenRequestV1('/'));
        $connection->readMessage();

        return $connection;
    }

    public function testResolveFirstOffset(): void
    {
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
        $this->assertGreaterThanOrEqual(0, $response->getOffset());

        $connection->close();
    }

    public function testResolveLastOffset(): void
    {
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
        $this->assertGreaterThanOrEqual(0, $response->getOffset());

        $connection->close();
    }

    public function testResolveOffsetSpec(): void
    {
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
        $this->assertGreaterThanOrEqual(0, $response->getOffset());

        $connection->close();
    }
}
