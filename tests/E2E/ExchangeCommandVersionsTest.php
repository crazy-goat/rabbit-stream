<?php

namespace CrazyGoat\RabbitStream\Tests\E2E;

use CrazyGoat\RabbitStream\Enum\KeyEnum;
use CrazyGoat\RabbitStream\Request\ExchangeCommandVersionsRequestV1;
use CrazyGoat\RabbitStream\Request\OpenRequest;
use CrazyGoat\RabbitStream\Request\PeerPropertiesToStreamBufferV1;
use CrazyGoat\RabbitStream\Request\SaslAuthenticateRequestV1;
use CrazyGoat\RabbitStream\Request\SaslHandshakeRequestV1;
use CrazyGoat\RabbitStream\Request\TuneRequestV1;
use CrazyGoat\RabbitStream\Response\ExchangeCommandVersionsResponseV1;
use CrazyGoat\RabbitStream\StreamConnection;
use CrazyGoat\RabbitStream\VO\CommandVersion;
use PHPUnit\Framework\TestCase;

class ExchangeCommandVersionsTest extends TestCase
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
        $connection->sendMessage(new TuneRequestV1($tune->getFrameMax(), $tune->getHeartbeat()));

        $connection->sendMessage(new OpenRequest('/'));
        $connection->readMessage();

        return $connection;
    }

    public function testExchangeCommandVersions(): void
    {
        $connection = $this->connectAndOpen();

        $commands = [
            new CommandVersion(KeyEnum::DECLARE_PUBLISHER->value, 1, 1),
            new CommandVersion(KeyEnum::PUBLISH->value, 1, 1),
            new CommandVersion(KeyEnum::SUBSCRIBE->value, 1, 1),
            new CommandVersion(KeyEnum::CREATE->value, 1, 1),
            new CommandVersion(KeyEnum::DELETE->value, 1, 1),
            new CommandVersion(KeyEnum::METADATA->value, 1, 1),
            new CommandVersion(KeyEnum::OPEN->value, 1, 1),
            new CommandVersion(KeyEnum::CLOSE->value, 1, 1),
        ];

        $connection->sendMessage(new ExchangeCommandVersionsRequestV1($commands));
        $response = $connection->readMessage();

        $this->assertInstanceOf(ExchangeCommandVersionsResponseV1::class, $response);
        $this->assertNotEmpty($response->getCommands());

        foreach ($response->getCommands() as $command) {
            $this->assertGreaterThan(0, $command->getKey());
            $this->assertGreaterThanOrEqual(1, $command->getMinVersion());
            $this->assertGreaterThanOrEqual($command->getMinVersion(), $command->getMaxVersion());
        }

        $connection->close();
    }
}
