<?php

namespace CrazyGoat\StreamyCarrot;

enum ResponseCode: int
{
    case OK = 0x01;
    case STREAM_NOT_EXIST = 0x02;
    case SUBSCRIPTION_ID_ALREADY_EXISTS = 0x03;
    case SUBSCRIPTION_ID_NOT_EXIST = 0x04;
    case STREAM_ALREADY_EXISTS = 0x05;
    case STREAM_NOT_AVAILABLE = 0x06;
    case SASL_MECHANISM_NOT_SUPPORTED = 0x07;
    case AUTHENTICATION_FAILURE = 0x08;
    case SASL_ERROR = 0x09;
    case SASL_CHALLENGE = 0x0a;
    case SASL_AUTHENTICATION_FAILURE_LOOPBACK = 0x0b;
    case VIRTUAL_HOST_ACCESS_FAILURE = 0x0c;
    case UNKNOWN_FRAME = 0x0d;
    case FRAME_TOO_LARGE = 0x0e;
    case INTERNAL_ERROR = 0x0f;
    case ACCESS_REFUSED = 0x10;
    case PRECONDITION_FAILED = 0x11;
    case PUBLISHER_NOT_EXIST = 0x12;
    case NO_OFFSET = 0x13;

    public function getMessage(): string
    {
        return match($this) {
            self::OK => 'OK',
            self::STREAM_NOT_EXIST => 'Stream does not exist',
            self::SUBSCRIPTION_ID_ALREADY_EXISTS => 'Subscription ID already exists',
            self::SUBSCRIPTION_ID_NOT_EXIST => 'Subscription ID does not exist',
            self::STREAM_ALREADY_EXISTS => 'Stream already exists',
            self::STREAM_NOT_AVAILABLE => 'Stream not available',
            self::SASL_MECHANISM_NOT_SUPPORTED => 'SASL mechanism not supported',
            self::AUTHENTICATION_FAILURE => 'Authentication failure',
            self::SASL_ERROR => 'SASL error',
            self::SASL_CHALLENGE => 'SASL challenge',
            self::SASL_AUTHENTICATION_FAILURE_LOOPBACK => 'SASL authentication failure loopback',
            self::VIRTUAL_HOST_ACCESS_FAILURE => 'Virtual host access failure',
            self::UNKNOWN_FRAME => 'Unknown frame',
            self::FRAME_TOO_LARGE => 'Frame too large',
            self::INTERNAL_ERROR => 'Internal error',
            self::ACCESS_REFUSED => 'Access refused',
            self::PRECONDITION_FAILED => 'Precondition failed',
            self::PUBLISHER_NOT_EXIST => 'Publisher does not exist',
            self::NO_OFFSET => 'No offset',
        };
    }

    public static function fromInt(int $code): ?self
    {
        return self::tryFrom($code);
    }

    public function isSuccess(): bool
    {
        return $this === self::OK;
    }

    public function isError(): bool
    {
        return !$this->isSuccess();
    }
}
