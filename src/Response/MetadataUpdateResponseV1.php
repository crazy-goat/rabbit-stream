<?php

namespace CrazyGoat\StreamyCarrot\Response;

use CrazyGoat\StreamyCarrot\Buffer\FromStreamBufferInterface;
use CrazyGoat\StreamyCarrot\Buffer\ReadBuffer;
use CrazyGoat\StreamyCarrot\Enum\KeyEnum;
use CrazyGoat\StreamyCarrot\Trait\CommandTrait;
use CrazyGoat\StreamyCarrot\Trait\KeyVersionInterface;
use CrazyGoat\StreamyCarrot\Trait\V1Trait;

class MetadataUpdateResponseV1 implements KeyVersionInterface, FromStreamBufferInterface
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
        $stream = $buffer->gatString();
        return new self($code, $stream);
    }

    static public function getKey(): int
    {
        return KeyEnum::METADATA_UPDATE->value;
    }
}
