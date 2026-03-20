<?php

namespace CrazyGoat\RabbitStream\Response;

use CrazyGoat\RabbitStream\Buffer\FromArrayInterface;
use CrazyGoat\RabbitStream\Buffer\FromStreamBufferInterface;
use CrazyGoat\RabbitStream\Buffer\ReadBuffer;
use CrazyGoat\RabbitStream\Enum\KeyEnum;
use CrazyGoat\RabbitStream\Trait\CommandTrait;
use CrazyGoat\RabbitStream\Trait\KeyVersionInterface;
use CrazyGoat\RabbitStream\Trait\V1Trait;

class MetadataUpdateResponseV1 implements KeyVersionInterface, FromStreamBufferInterface, FromArrayInterface
{
    use CommandTrait;
    use V1Trait;

    public function __construct(private int $code, private string $stream) {}

    public function getCode(): int
    {
        return $this->code;
    }

    public function getStream(): string
    {
        return $this->stream;
    }

    public static function fromStreamBuffer(ReadBuffer $buffer): ?object
    {
        self::validateKeyVersion($buffer->getUint16(), $buffer->getUint16());
        $code = $buffer->getUint16();
        $stream = $buffer->getString();
        return new self($code, $stream);
    }

    public static function fromArray(array $data): static
    {
        return new self($data['code'], $data['stream']);
    }

    static public function getKey(): int
    {
        return KeyEnum::METADATA_UPDATE->value;
    }
}
