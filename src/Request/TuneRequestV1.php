<?php

namespace CrazyGoat\StreamyCarrot\Request;

use CrazyGoat\StreamyCarrot\CommandCode;
use CrazyGoat\StreamyCarrot\Response\ReadBuffer;
use CrazyGoat\StreamyCarrot\FromStreamBufferInterface;
use CrazyGoat\StreamyCarrot\ToStreamBufferInterface;

class TuneRequestV1 implements FromStreamBufferInterface, ToStreamBufferInterface
{

    private const VERSION = 1;

    public function __construct(private int $frameMax = 0, private int $heartbeat = 0)
    {
    }

    public function getCommandCode(): CommandCode
    {
        return CommandCode::TUNE;
    }

    public function toStreamBuffer(): WriteBuffer
    {
        return (new WriteBuffer())
            ->addUInt16($this->getCommandCode()->value)
            ->addUInt16(self::VERSION)
            ->addUInt32($this->frameMax)
            ->addUInt32($this->heartbeat);
    }

    public static function fromStreamBuffer(ReadBuffer $buffer): ?object
    {
        if (CommandCode::fromStreamCode($buffer->getUint16()) !== CommandCode::TUNE) {
            throw new \Exception('Unexpected command code');
        }

        if ($buffer->getUint16() !== self::VERSION) {
            throw new \Exception('Unexpected version');
        }

        return new self($buffer->getUint32(), $buffer->getUint32());
    }

    public function getFrameMax(): int
    {
        return $this->frameMax;
    }

    public function getHeartbeat(): int
    {
        return $this->heartbeat;
    }
}