<?php

namespace CrazyGoat\RabbitStream\Response;

use CrazyGoat\RabbitStream\Buffer\FromStreamBufferInterface;
use CrazyGoat\RabbitStream\Buffer\ReadBuffer;
use CrazyGoat\RabbitStream\Enum\KeyEnum;
use CrazyGoat\RabbitStream\Trait\CommandTrait;
use CrazyGoat\RabbitStream\Trait\CorrelationInterface;
use CrazyGoat\RabbitStream\Trait\CorrelationTrait;
use CrazyGoat\RabbitStream\Trait\KeyVersionInterface;
use CrazyGoat\RabbitStream\Trait\V1Trait;
use CrazyGoat\RabbitStream\VO\CommandVersion;

class ExchangeCommandVersionsResponseV1 implements KeyVersionInterface, CorrelationInterface, FromStreamBufferInterface
{
    use CorrelationTrait;
    use CommandTrait;
    use V1Trait;

    /**
     * @param CommandVersion[] $commands
     */
    public function __construct(private array $commands) {}

    /**
     * @return CommandVersion[]
     */
    public function getCommands(): array
    {
        return $this->commands;
    }

    public static function fromStreamBuffer(ReadBuffer $buffer): ?object
    {
        self::validateKeyVersion($buffer->getUint16(), $buffer->getUint16());
        $correlationId = $buffer->getUint32();
        self::isResponseCodeOk($buffer->getUint16());
        $commands = $buffer->getObjectArray(CommandVersion::class);

        $object = new self($commands);
        $object->withCorrelationId($correlationId);

        return $object;
    }

    static public function getKey(): int
    {
        return KeyEnum::EXCHANGE_COMMAND_VERSIONS_RESPONSE->value;
    }
}
