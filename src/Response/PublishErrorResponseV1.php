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
use CrazyGoat\RabbitStream\Util\TypeCast;
use CrazyGoat\RabbitStream\VO\PublishingError;

/** @phpstan-consistent-constructor */
class PublishErrorResponseV1 implements KeyVersionInterface, FromStreamBufferInterface, FromArrayInterface
{
    use CommandTrait;
    use V1Trait;

    /** @var array<int, PublishingError> */
    private array $errors;

    public function __construct(private int $publisherId, PublishingError ...$errors)
    {
        $this->errors = array_values($errors);
    }

    public function getPublisherId(): int
    {
        return $this->publisherId;
    }

    /** @return array<int, PublishingError> */
    public function getErrors(): array
    {
        return $this->errors;
    }

    public static function fromStreamBuffer(ReadBuffer $buffer): ?static
    {
        self::validateKeyVersion($buffer->getUint16(), $buffer->getUint16());
        $publisherId = $buffer->getUint8();
        $count = $buffer->getUint32();
        $errors = [];
        for ($i = 0; $i < $count; $i++) {
            $errors[] = PublishingError::fromStreamBuffer($buffer);
        }
        return new static($publisherId, ...$errors);
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): static
    {
        $errorsData = TypeCast::toArray($data['errors'] ?? []);
        $errors = array_map(
            function (mixed $e): PublishingError {
                $ea = is_array($e) ? $e : [];
                return new PublishingError(
                    TypeCast::toInt($ea['publishingId'] ?? 0),
                    TypeCast::toInt($ea['code'] ?? 0)
                );
            },
            $errorsData
        );
        return new static(TypeCast::toInt($data['publisherId']), ...$errors);
    }

    public static function getKey(): int
    {
        return KeyEnum::PUBLISH_ERROR->value;
    }
}
