<?php

declare(strict_types=1);

namespace CrazyGoat\RabbitStream\Response;

use CrazyGoat\RabbitStream\Buffer\FromArrayInterface;
use CrazyGoat\RabbitStream\Buffer\FromStreamBufferInterface;
use CrazyGoat\RabbitStream\Buffer\ReadBuffer;
use CrazyGoat\RabbitStream\Contract\KeyVersionInterface;
use CrazyGoat\RabbitStream\Enum\KeyEnum;
use CrazyGoat\RabbitStream\Exception\DeserializationException;
use CrazyGoat\RabbitStream\Trait\CommandTrait;
use CrazyGoat\RabbitStream\Trait\V1Trait;
use CrazyGoat\RabbitStream\Util\TypeCast;

/** @phpstan-consistent-constructor */
class PublishConfirmResponseV1 implements KeyVersionInterface, FromStreamBufferInterface, FromArrayInterface
{
    use CommandTrait;
    use V1Trait;

    /** @var array<int, int> */
    private array $publishingIds;

    public function __construct(private int $publisherId, int ...$publishingIds)
    {
        $this->publishingIds = array_values($publishingIds);
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

    /**
     * Read all publishing ids with a single unpack('J*', ...) call instead of
     * one getUint64() call (bounds check + unpack + position bump) per id,
     * and hand them to the constructor via a private array-taking path
     * instead of a variadic spread + array_values() — this matters when a
     * broker confirms thousands of publishing ids in one PublishConfirm frame.
     */
    public static function fromStreamBuffer(ReadBuffer $buffer): ?static
    {
        self::validateKeyVersion($buffer->getUint16(), $buffer->getUint16());
        $publisherId = $buffer->getUint8();
        $count = $buffer->getUint32();

        if ($count === 0) {
            return self::fromParts($publisherId, []);
        }

        $unpacked = unpack('J*', $buffer->readBytes($count * 8));
        if ($unpacked === false) {
            throw new DeserializationException('Failed to unpack publishing ids');
        }

        return self::fromParts($publisherId, array_values($unpacked));
    }

    /** @param array<int, int> $publishingIds */
    private static function fromParts(int $publisherId, array $publishingIds): static
    {
        $instance = new static($publisherId);
        $instance->publishingIds = $publishingIds;
        return $instance;
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): static
    {
        $publishingIds = TypeCast::toIntArray($data['publishingIds'] ?? []);
        return new static(TypeCast::toInt($data['publisherId']), ...$publishingIds);
    }

    public static function getKey(): int
    {
        return KeyEnum::PUBLISH_CONFIRM->value;
    }
}
