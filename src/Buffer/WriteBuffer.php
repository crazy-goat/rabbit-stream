<?php

namespace CrazyGoat\StreamyCarrot\Buffer;

use CrazyGoat\StreamyCarrot\Request\StreamBufferInterface;

class WriteBuffer
{
    // Signed integers limits
    private const INT8_MIN = -128;
    private const INT8_MAX = 127;
    private const INT16_MIN = -32768;
    private const INT16_MAX = 32767;
    private const INT32_MIN = -2147483648;
    private const INT32_MAX = 2147483647;
    private const INT64_MIN = -9223372036854775808;
    private const INT64_MAX = 9223372036854775807;

    // Unsigned integers limits
    private const UINT8_MIN = 0;
    private const UINT8_MAX = 255;
    private const UINT16_MIN = 0;
    private const UINT16_MAX = 65535;
    private const UINT32_MIN = 0;
    private const UINT32_MAX = 4294967295;
    private const UINT64_MIN = 0;
    private const UINT64_MAX = 18446744073709551615;

    public function __construct(private string $buffer = '')
    {
    }

    public function addInt8(int $value): self
    {
        $this->validateInt($value, self::INT8_MIN, self::INT8_MAX, 'int8');
        $this->buffer .= pack('c', $value);
        return $this;
    }

    public function addInt16(int $value): self
    {
        $this->validateInt($value, self::INT16_MIN, self::INT16_MAX, 'int16');
        $this->buffer .= pack('n', $value);
        return $this;
    }

    public function addInt32(int $value): self
    {
        $this->validateInt($value, self::INT32_MIN, self::INT32_MAX, 'int32');
        $this->buffer .= pack('N', $value);
        return $this;
    }

    public function addInt64(int $value): self
    {
        $this->validateInt($value, self::INT64_MIN, self::INT64_MAX, 'int64');
        $this->buffer .= pack('J', $value);
        return $this;
    }

    public function addUInt8(int $value): self
    {
        $this->validateInt($value, self::UINT8_MIN, self::UINT8_MAX, 'uint8');
        $this->buffer .= pack('C', $value);
        return $this;
    }

    public function addUInt16(int $value): self
    {
        $this->validateInt($value, self::UINT16_MIN, self::UINT16_MAX, 'uint16');
        $this->buffer .= pack('n', $value);
        return $this;
    }

    public function addUInt32(int $value): self
    {
        $this->validateInt($value, self::UINT32_MIN, self::UINT32_MAX, 'uint32');
        $this->buffer .= pack('N', $value);
        return $this;
    }

    public function addUInt64(int $value): self
    {
        $this->validateInt($value, self::UINT64_MIN, self::UINT64_MAX, 'uint64');
        $this->buffer .= pack('J', $value);
        return $this;
    }

    private function validateInt(int $value, int $min, int $max, string $type): void
    {
        if ($value < $min || $value > $max) {
            throw new \InvalidArgumentException("Wartość $value jest poza zakresem $type ($min do $max)");
        }
    }

    public function addBytes(?string $value): self
    {
        if ($value === null) {
            // Wartość null reprezentowana jako długość -1
            $this->buffer .= pack('N', 0xFFFFFFFF); // -1 jako unsigned int32
        } else {
            // Dodaj długość jako int32 a następnie bajty zawartości
            $length = strlen($value);
            $this->validateInt($length, 0, self::INT32_MAX, 'bytes length');
            $this->buffer .= pack('N', $length);
            $this->buffer .= $value;
        }
        return $this;
    }

    public function addString(?string $value): self
    {
        if ($value === null) {
            $this->buffer .= pack('n', 0xFFFF); // -1 jako unsigned int16
        } else {
            $utf8Value = mb_convert_encoding($value, 'UTF-8', 'auto');
            $length = strlen($utf8Value);
            $this->validateInt($length, 0, self::INT16_MAX, 'string length');
            $this->buffer .= pack('n', $length);
            $this->buffer .= $utf8Value;
        }
        return $this;
    }

    public function addArray(StreamBufferInterface ...$items): self
    {
        $this->addInt32(count($items));
        foreach ($items as $item) {
            $this->addRaw($item->getStreamBuffer()->getContents());
        }

        return $this;
    }

    public function addRaw(string $value): self
    {
        $this->buffer .= $value;

        return $this;
    }

    public function getContents(): string
    {
        return $this->buffer;
    }
}