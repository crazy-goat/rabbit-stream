<?php

declare(strict_types=1);

namespace CrazyGoat\RabbitStream\Response;

use CrazyGoat\RabbitStream\Buffer\FromArrayInterface;
use CrazyGoat\RabbitStream\Buffer\ToStreamBufferInterface;
use CrazyGoat\RabbitStream\Buffer\WriteBuffer;
use CrazyGoat\RabbitStream\Contract\KeyVersionInterface;
use CrazyGoat\RabbitStream\Enum\KeyEnum;

/** @phpstan-consistent-constructor */
class TuneResponseV1 implements KeyVersionInterface, ToStreamBufferInterface, FromArrayInterface
{
    public function __construct(private readonly int $frameMax = 0, private readonly int $heartbeat = 0)
    {
    }

    public static function getVersion(): int
    {
        return 1;
    }

    public static function getKey(): int
    {
        return KeyEnum::TUNE_RESPONSE->value;
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): static
    {
        return new static($data['frameMax'], $data['heartbeat']);
    }

    public function toStreamBuffer(): WriteBuffer
    {
        return (new WriteBuffer())
            ->addUInt16(self::getKey())
            ->addUInt16(self::getVersion())
            ->addUInt32($this->frameMax)
            ->addUInt32($this->heartbeat);
    }
}
