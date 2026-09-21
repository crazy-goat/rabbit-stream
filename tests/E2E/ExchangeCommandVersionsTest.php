<?php

declare(strict_types=1);

namespace CrazyGoat\RabbitStream\Tests\E2E;

use CrazyGoat\RabbitStream\Enum\KeyEnum;
use CrazyGoat\RabbitStream\Request\ExchangeCommandVersionsRequestV1;
use CrazyGoat\RabbitStream\Response\ExchangeCommandVersionsResponseV1;
use CrazyGoat\RabbitStream\Tests\E2E\E2ETestCase;
use CrazyGoat\RabbitStream\VO\CommandVersion;

class ExchangeCommandVersionsTest extends E2ETestCase
{
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

    /**
     * The high-level Connection::create() handshake must itself send
     * ExchangeCommandVersions and expose the broker's negotiated ranges
     * (GitHub #381).
     */
    public function testCreateNegotiatesCommandVersionsAgainstTheBroker(): void
    {
        $connection = $this->createConnection();

        $versions = $connection->getSupportedCommandVersions();
        $this->assertNotEmpty($versions, 'create() must exchange command versions and store the broker map');

        foreach ($versions as $version) {
            $this->assertGreaterThan(0, $version->getKey());
            $this->assertGreaterThanOrEqual(1, $version->getMinVersion());
            $this->assertGreaterThanOrEqual($version->getMinVersion(), $version->getMaxVersion());
        }

        // Publish is the command the negotiation gates today: a modern broker
        // supports v2 (per-message filter values), which Producer relies on.
        $this->assertArrayHasKey(KeyEnum::PUBLISH->value, $versions);
        $this->assertTrue($connection->supportsCommandVersion(KeyEnum::PUBLISH, 1));
        $this->assertTrue($connection->supportsCommandVersion(KeyEnum::PUBLISH, 2));

        $connection->close();
    }
}
