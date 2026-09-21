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

        // Publish is the only command the client advertises, so it is the one
        // the broker must report back; v1 is universal. Publish v2 (per-message
        // filter values) exists only on brokers with stream filtering (RabbitMQ
        // 3.13+), so the high-level version selection is asserted against
        // whatever range the broker actually reported rather than hard-coding
        // v2 — this keeps the test valid across the supported broker matrix.
        $this->assertArrayHasKey(KeyEnum::PUBLISH->value, $versions);
        $this->assertTrue($connection->supportsCommandVersion(KeyEnum::PUBLISH, 1));

        $publish = $versions[KeyEnum::PUBLISH->value];
        $this->assertSame(1, $publish->getMinVersion());
        $this->assertSame(
            $publish->getMaxVersion() >= 2,
            $connection->supportsCommandVersion(KeyEnum::PUBLISH, 2),
            'supportsCommandVersion(PUBLISH, 2) must follow the broker-reported range'
        );

        $connection->close();
    }
}
