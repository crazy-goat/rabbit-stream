<?php

declare(strict_types=1);

namespace CrazyGoat\RabbitStream\Response;

use CrazyGoat\RabbitStream\Buffer\FromArrayInterface;
use CrazyGoat\RabbitStream\Buffer\FromStreamBufferInterface;
use CrazyGoat\RabbitStream\Buffer\ReadBuffer;
use CrazyGoat\RabbitStream\Contract\CorrelationInterface;
use CrazyGoat\RabbitStream\Contract\KeyVersionInterface;
use CrazyGoat\RabbitStream\Enum\KeyEnum;
use CrazyGoat\RabbitStream\Trait\CommandTrait;
use CrazyGoat\RabbitStream\Trait\CorrelationTrait;
use CrazyGoat\RabbitStream\Trait\V1Trait;
use CrazyGoat\RabbitStream\VO\CommandVersion;

/** @phpstan-consistent-constructor */
class ExchangeCommandVersionsResponseV1 implements
    KeyVersionInterface,
    CorrelationInterface,
    FromStreamBufferInterface,
    FromArrayInterface
{
    use CorrelationTrait;
    use CommandTrait;
    use V1Trait;

    /**
     * @param CommandVersion[] $commands
     */
    public function __construct(private array $commands)
    {
    }

    /**
     * @return CommandVersion[]
     */
    public function getCommands(): array
    {
        return $this->commands;
    }

    public static function fromStreamBuffer(ReadBuffer $buffer): ?static
    {
        self::validateKeyVersion($buffer->getUint16(), $buffer->getUint16());
        $correlationId = $buffer->getUint32();
        self::isResponseCodeOk($buffer->getUint16());
        $commands = $buffer->getObjectArray(CommandVersion::class);

        $object = new static($commands);
        $object->withCorrelationId($correlationId);

        return $object;
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): static
    {
        $commands = array_map(
            fn(array $c): \CrazyGoat\RabbitStream\VO\CommandVersion => new CommandVersion(
                $c['key'],
                $c['minVersion'],
                $c['maxVersion']
            ),
            $data['commands']
        );
        $object = new static($commands);
        $object->withCorrelationId($data['correlationId']);
        return $object;
    }

    public static function getKey(): int
    {
        return KeyEnum::EXCHANGE_COMMAND_VERSIONS_RESPONSE->value;
    }
}
