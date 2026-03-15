<?php

namespace CrazyGoat\StreamyCarrot\Response;

use CrazyGoat\StreamyCarrot\Buffer\FromStreamBufferInterface;
use CrazyGoat\StreamyCarrot\Buffer\ReadBuffer;
use CrazyGoat\StreamyCarrot\Enum\KeyEnum;
use CrazyGoat\StreamyCarrot\Trait\CommandTrait;
use CrazyGoat\StreamyCarrot\Trait\KeyVersionInterface;
use CrazyGoat\StreamyCarrot\Trait\V1Trait;

class PublishConfirmResponseV1 implements KeyVersionInterface, FromStreamBufferInterface
{
    use CommandTrait;
    use V1Trait;

    private array $publishingIds;

    public function __construct(private int $publisherId, int ...$publishingIds)
    {
        $this->publishingIds = $publishingIds;
    }

    public function getPublisherId(): int
    {
        return $this->publisherId;
    }

    public function getPublishingIds(): array
    {
        return $this->publishingIds;
    }

    public static function fromStreamBuffer(ReadBuffer $buffer): ?object
    {
        self::validateKeyVersion($buffer->getUint16(), $buffer->getUint16());
        $publisherId = $buffer->getUint8();
        $count = $buffer->getUint32();
        $publishingIds = [];
        for ($i = 0; $i < $count; $i++) {
            $publishingIds[] = $buffer->getUint64();
        }
        return new self($publisherId, ...$publishingIds);
    }

    static public function getKey(): int
    {
        return KeyEnum::PUBLISH_CONFIRM->value;
    }
}
