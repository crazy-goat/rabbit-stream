<?php

declare(strict_types=1);

namespace CrazyGoat\RabbitStream\Response;

use CrazyGoat\RabbitStream\Buffer\FromArrayInterface;
use CrazyGoat\RabbitStream\Buffer\ToStreamBufferInterface;
use CrazyGoat\RabbitStream\Buffer\WriteBuffer;
use CrazyGoat\RabbitStream\Contract\KeyVersionInterface;
use CrazyGoat\RabbitStream\Enum\KeyEnum;
use CrazyGoat\RabbitStream\Trait\CommandTrait;
use CrazyGoat\RabbitStream\Trait\V1Trait;
use CrazyGoat\RabbitStream\Util\TypeCast;

/**
 * TuneResponseV1 - Client's response to server's Tune frame.
 *
 * NOTE: This class is a structural outlier by design. Unlike other Response
 * classes, it:
 * - Implements ToStreamBufferInterface (it's a client-to-server message)
 * - Does NOT implement FromStreamBufferInterface (server sends TuneRequestV1)
 * - Uses response key 0x8014 (per protocol spec, client echoes with response bit set)
 *
 * The Tune exchange flow:
 * 1. Server sends Tune frame (key 0x0014) → parsed as TuneRequestV1
 * 2. Client echoes back (key 0x8014) → serialized as TuneResponseV1
 *
 * @phpstan-consistent-constructor
 */
class TuneResponseV1 implements KeyVersionInterface, ToStreamBufferInterface, FromArrayInterface
{
    use CommandTrait;
    use V1Trait;

    public function __construct(private readonly int $frameMax = 0, private readonly int $heartbeat = 0)
    {
    }

    public static function getKey(): int
    {
        return KeyEnum::TUNE_RESPONSE->value;
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): static
    {
        return new static(TypeCast::toInt($data['frameMax']), TypeCast::toInt($data['heartbeat']));
    }

    public function toStreamBuffer(): WriteBuffer
    {
        return self::getKeyVersion()
            ->addUInt32($this->frameMax)
            ->addUInt32($this->heartbeat);
    }
}
