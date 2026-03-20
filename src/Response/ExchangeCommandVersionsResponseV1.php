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
use CrazyGoat\RabbitStream\Util\TypeCast;
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
        self::assertResponseCodeOk($buffer->getUint16());
        $commands = $buffer->getObjectArray(CommandVersion::class);

        $object = new static($commands);
        $object->withCorrelationId($correlationId);

        return $object;
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): static
    {
        $commandsData = TypeCast::toArray($data['commands'] ?? []);
        $commands = array_map(
            function (mixed $c): CommandVersion {
                $ca = is_array($c) ? $c : [];
                return new CommandVersion(
                    TypeCast::toInt($ca['key'] ?? 0),
                    TypeCast::toInt($ca['minVersion'] ?? 0),
                    TypeCast::toInt($ca['maxVersion'] ?? 0)
                );
            },
            $commandsData
        );
        $object = new static($commands);
        $object->withCorrelationId(TypeCast::toInt($data['correlationId']));
        return $object;
    }

    public static function getKey(): int
    {
        return KeyEnum::EXCHANGE_COMMAND_VERSIONS_RESPONSE->value;
    }
}
