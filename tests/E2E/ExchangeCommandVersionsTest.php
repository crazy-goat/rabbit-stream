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
}
