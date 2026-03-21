<?php

declare(strict_types=1);

namespace CrazyGoat\RabbitStream\Buffer;

use CrazyGoat\RabbitStream\Exception\InvalidArgumentException;

class WriteBuffer
{
    // Signed integers limits
    private const INT8_MIN = -128;
    private const INT8_MAX = 127;
    private const INT16_MIN = -32768;
    private const INT16_MAX = 32767;
    private const INT32_MIN = -2147483648;
    private const INT32_MAX = 2147483647;
    private const INT64_MIN = PHP_INT_MIN;
    private const INT64_MAX = PHP_INT_MAX;

    // Unsigned integers limits
    private const UINT8_MIN = 0;
    private const UINT8_MAX = 255;
    private const UINT16_MIN = 0;
    private const UINT16_MAX = 65535;
    private const UINT32_MIN = 0;
    private const UINT32_MAX = 4294967295;
    private const UINT64_MIN = 0;
    private const UINT64_MAX = PHP_INT_MAX;

    public function __construct(private string $buffer = '')
    {
    }

    public function addInt8(int $value): self
    {
        $this->validateInt($value, self::INT8_MIN, self::INT8_MAX, 'int8');
        $this->buffer .= pack('c', $value);
        return $this;
    }

    /**
     * Add a signed 16-bit integer to the buffer.
     *
     * Note: Uses unsigned pack format 'n' intentionally. PHP's pack() with unsigned
     * formats produces the correct two's complement binary representation for negative
     * values. For example, pack('n', -1) produces 0xFFFF. This matches the RabbitMQ
     * Stream protocol which uses big-endian encoding. PHP has no native big-endian
     * signed 16-bit pack format ('s' is machine-dependent).
     *
     * @see ReadBuffer::getInt16() for the reverse conversion
     */
    public function addInt16(int $value): self
    {
        $this->validateInt($value, self::INT16_MIN, self::INT16_MAX, 'int16');
        $this->buffer .= pack('n', $value);
        return $this;
    }

    /**
     * Add a signed 32-bit integer to the buffer.
     *
     * Note: Uses unsigned pack format 'N' intentionally. PHP's pack() with unsigned
     * formats produces the correct two's complement binary representation for negative
     * values. For example, pack('N', -1) produces 0xFFFFFFFF. This matches the RabbitMQ
     * Stream protocol which uses big-endian encoding. PHP has no native big-endian
     * signed 32-bit pack format ('l' is machine-dependent).
     *
     * @see ReadBuffer::getInt32() for the reverse conversion
     */
    public function addInt32(int $value): self
    {
        $this->validateInt($value, self::INT32_MIN, self::INT32_MAX, 'int32');
        $this->buffer .= pack('N', $value);
        return $this;
    }

    /**
     * Add a signed 64-bit integer to the buffer.
     *
     * Note: Uses unsigned pack format 'J' intentionally. PHP's pack() with unsigned
     * formats produces the correct two's complement binary representation for negative
     * values. For example, pack('J', -1) produces 0xFFFFFFFFFFFFFFFF. This matches the
     * RabbitMQ Stream protocol which uses big-endian encoding. PHP has no native
     * big-endian signed 64-bit pack format ('q' is machine-dependent).
     *
     * @see ReadBuffer::getInt64() for the reverse conversion
     */
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
            throw new InvalidArgumentException("Value $value is out of range for $type ($min to $max)");
        }
    }

    public function addBytes(?string $value): self
    {
        if ($value === null) {
            // Null value represented as length -1
            $this->buffer .= pack('N', 0xFFFFFFFF); // -1 as unsigned int32
        } else {
            // Add length as int32 followed by content bytes
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
            $this->buffer .= pack('n', 0xFFFF); // -1 as unsigned int16
        } else {
            if (!mb_check_encoding($value, 'UTF-8')) {
                throw new InvalidArgumentException('String must be valid UTF-8');
            }
            $length = strlen($value);
            $this->validateInt($length, 0, self::INT16_MAX, 'string length');
            $this->buffer .= pack('n', $length);
            $this->buffer .= $value;
        }
        return $this;
    }

    public function addArray(ToStreamBufferInterface ...$items): self
    {
        $this->addInt32(count($items));
        foreach ($items as $item) {
            $this->addRaw($item->toStreamBuffer()->getContents());
        }

        return $this;
    }

    public function addStringArray(string ...$strings): self
    {
        $this->addInt32(count($strings));
        foreach ($strings as $string) {
            $this->addString($string);
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
