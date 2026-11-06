<?php

declare(strict_types=1);

namespace CrazyGoat\RabbitStream\Request;

use CrazyGoat\RabbitStream\Buffer\ToArrayInterface;
use CrazyGoat\RabbitStream\Buffer\ToStreamBufferInterface;
use CrazyGoat\RabbitStream\Buffer\WriteBuffer;
use CrazyGoat\RabbitStream\Contract\KeyVersionInterface;
use CrazyGoat\RabbitStream\Enum\KeyEnum;
use CrazyGoat\RabbitStream\Exception\InvalidArgumentException;
use CrazyGoat\RabbitStream\Trait\CommandTrait;
use CrazyGoat\RabbitStream\Trait\V1Trait;

class CreditRequestV1 implements ToStreamBufferInterface, ToArrayInterface, KeyVersionInterface
{
    use V1Trait;
    use CommandTrait;

    /**
     * PROTOCOL.adoc and the broker both define the Credit field as a signed int16.
     * A value above 32767 becomes negative on the broker side and stops delivery with no error.
     */
    public const MAX_CREDIT = 32767;

    /**
     * @throws InvalidArgumentException If $credit is outside 0..MAX_CREDIT
     */
    public function __construct(
        private int $subscriptionId,
        private int $credit
    ) {
        if ($credit < 0 || $credit > self::MAX_CREDIT) {
            throw new InvalidArgumentException(
                sprintf('Credit %d is out of range (0 to %d)', $credit, self::MAX_CREDIT)
            );
        }
    }

    public function toStreamBuffer(): WriteBuffer
    {
        return self::getKeyVersion()
            ->addUInt8($this->subscriptionId)
            ->addInt16($this->credit);
    }

    /** @return array<string, int> */
    public function toArray(): array
    {
        return [
            'subscriptionId' => $this->subscriptionId,
            'credit' => $this->credit,
        ];
    }

    public static function getKey(): int
    {
        return KeyEnum::CREDIT->value;
    }
}
