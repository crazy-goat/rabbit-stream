<?php

namespace CrazyGoat\StreamyCarrot\Request;

use CrazyGoat\StreamyCarrot\CommandCode;
use CrazyGoat\StreamyCarrot\ToStreamBufferInterface;

class SaslHandshakeRequestV1 extends RequestAbstract implements ToStreamBufferInterface, RequestInterface
{

    const VERSION = 1;

    public function getCommandCode(): CommandCode
    {
        return CommandCode::SASL_HANDSHAKE;
    }

    public function toStreamBuffer(): WriteBuffer
    {
        return (new WriteBuffer())
            ->addUInt16($this->getCommandCode()->value)
            ->addUInt16(self::VERSION)
            ->addUInt32($this->getCorrelationId());
    }
}