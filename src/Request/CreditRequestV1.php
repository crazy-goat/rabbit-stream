<?php

declare(strict_types=1);

namespace CrazyGoat\RabbitStream\Request;

use CrazyGoat\RabbitStream\Buffer\ToArrayInterface;
use CrazyGoat\RabbitStream\Buffer\ToStreamBufferInterface;
use CrazyGoat\RabbitStream\Buffer\WriteBuffer;
use CrazyGoat\RabbitStream\Contract\KeyVersionInterface;
use CrazyGoat\RabbitStream\Enum\KeyEnum;
use CrazyGoat\RabbitStream\Trait\CommandTrait;
use CrazyGoat\RabbitStream\Trait\V1Trait;

class CreditRequestV1 implements ToStreamBufferInterface, ToArrayInterface, KeyVersionInterface
{
    use V1Trait;
    use CommandTrait;

    public function __construct(
        private int $subscriptionId,
        private int $credit
    ) {
    }

    public function toStreamBuffer(): WriteBuffer
    {
        return self::getKeyVersion()
            ->addUInt8($this->subscriptionId)
            ->addUInt16($this->credit);
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
