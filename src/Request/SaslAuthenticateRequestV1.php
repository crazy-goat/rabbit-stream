<?php

namespace CrazyGoat\StreamyCarrot\Request;

use CrazyGoat\StreamyCarrot\CommandCode;
use CrazyGoat\StreamyCarrot\ToStreamBufferInterface;

class SaslAuthenticateRequestV1 extends RequestAbstract implements ToStreamBufferInterface, RequestInterface
{
    private const VERSION = 1;

    public function __construct(private string $mechanism, private string $username, private string $password)
    {
    }

    public function getCommandCode(): CommandCode
    {
        return CommandCode::SASL_AUTHENTICATE;
    }

    public function toStreamBuffer(): WriteBuffer
    {
        return (new WriteBuffer())
            ->addUInt16($this->getCommandCode()->value)
            ->addUInt16(self::VERSION)
            ->addUInt32($this->getCorrelationId())
            ->addString($this->mechanism)
            ->addBytes("\0" . $this->username . "\0" . $this->password);
    }
}