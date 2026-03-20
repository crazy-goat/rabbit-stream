<?php

declare(strict_types=1);

namespace CrazyGoat\RabbitStream\Response;

use CrazyGoat\RabbitStream\Buffer\FromArrayInterface;
use CrazyGoat\RabbitStream\Buffer\FromStreamBufferInterface;
use CrazyGoat\RabbitStream\Buffer\ReadBuffer;
use CrazyGoat\RabbitStream\Contract\KeyVersionInterface;
use CrazyGoat\RabbitStream\Enum\KeyEnum;
use CrazyGoat\RabbitStream\Trait\CommandTrait;
use CrazyGoat\RabbitStream\Trait\V1Trait;

/** @phpstan-consistent-constructor */
class PublishConfirmResponseV1 implements KeyVersionInterface, FromStreamBufferInterface, FromArrayInterface
{
    use CommandTrait;
    use V1Trait;

    /** @var array<int, int> */
    private array $publishingIds;

    public function __construct(private int $publisherId, int ...$publishingIds)
    {
        $this->publishingIds = $publishingIds;
    }

    public function getPublisherId(): int
    {
        return $this->publisherId;
    }

    /** @return array<int, int> */
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

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): static
    {
        return new static($data['publisherId'], ...$data['publishingIds']);
    }

    public static function getKey(): int
    {
        return KeyEnum::PUBLISH_CONFIRM->value;
    }
}
