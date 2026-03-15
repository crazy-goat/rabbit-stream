<?php

namespace CrazyGoat\StreamyCarrot\Response;

use CrazyGoat\StreamyCarrot\Buffer\FromStreamBufferInterface;
use CrazyGoat\StreamyCarrot\Buffer\ReadBuffer;
use CrazyGoat\StreamyCarrot\Enum\KeyEnum;
use CrazyGoat\StreamyCarrot\Trait\CommandTrait;
use CrazyGoat\StreamyCarrot\Trait\KeyVersionInterface;
use CrazyGoat\StreamyCarrot\Trait\V1Trait;
use CrazyGoat\StreamyCarrot\VO\PublishingError;

class PublishErrorResponseV1 implements KeyVersionInterface, FromStreamBufferInterface
{
    use CommandTrait;
    use V1Trait;

    private array $errors;

    public function __construct(private int $publisherId, PublishingError ...$errors)
    {
        $this->errors = $errors;
    }

    public function getPublisherId(): int
    {
        return $this->publisherId;
    }

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

    static public function getKey(): int
    {
        return KeyEnum::PUBLISH_ERROR->value;
    }
}
