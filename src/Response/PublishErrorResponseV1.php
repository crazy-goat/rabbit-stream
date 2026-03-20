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
        $this->errors = $errors;
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

    public static function fromStreamBuffer(ReadBuffer $buffer): ?object
    {
        self::validateKeyVersion($buffer->getUint16(), $buffer->getUint16());
        $publisherId = $buffer->getUint8();
        $count = $buffer->getUint32();
        $errors = [];
        for ($i = 0; $i < $count; $i++) {
            $errors[] = PublishingError::fromStreamBuffer($buffer);
        }
        return new self($publisherId, ...$errors);
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): static
    {
        $errors = array_map(
            fn(array $e): \CrazyGoat\RabbitStream\VO\PublishingError => new PublishingError(
                $e['publishingId'],
                $e['code']
            ),
            $data['errors']
        );
        return new static($data['publisherId'], ...$errors);
    }

    public static function getKey(): int
    {
        return KeyEnum::PUBLISH_ERROR->value;
    }
}
