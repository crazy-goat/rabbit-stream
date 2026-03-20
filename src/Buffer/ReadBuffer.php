<?php

namespace CrazyGoat\RabbitStream\Buffer;

class ReadBuffer
{
    private int $position = 0;

    public function __construct(private string $buffer)
    {
    }

    public function getUint8(): int
    {
        $data = unpack('C', substr($this->buffer, $this->position, 1))[1];
        $this->position += 1;
        return $data;
    }

    public function getUint16(): int
    {
        $data = unpack('n', substr($this->buffer, $this->position, 2))[1];
        $this->position += 2;
        return $data;
    }

    public function rewind(): void
    {
        $this->position = 0;
    }

    public function getUint32(): int
    {
        $data = unpack('N', substr($this->buffer, $this->position, 4))[1];
        $this->position += 4;
        return $data;
    }

    public function getUint64(): int
    {
        $data = unpack('J', substr($this->buffer, $this->position, 8))[1];
        $this->position += 8;
        return $data;
    }

    public function getInt64(): int
    {
        $data = unpack('J', substr($this->buffer, $this->position, 8))[1];
        $this->position += 8;
        if ($data >= 0x8000000000000000) {
            $data -= 0x10000000000000000;
        }
        return $data;
    }

    public function getString(): ?string
    {
        $len = $this->getInt16();
        if ($len === -1) {
            return null;
        }

        $data = substr($this->buffer, $this->position, $len);
        $this->position += $len;
        return $data;
    }

    public function getInt16(): int
    {
        $data = unpack('n', substr($this->buffer, $this->position, 2))[1];
        $this->position += 2;
        if ($data >= 0x8000) {
            $data -= 0x10000;
        }
        return $data;
    }

    public function getInt32(): int
    {
        $data = unpack('N', substr($this->buffer, $this->position, 4))[1];
        $this->position += 4;
        if ($data >= 0x80000000) {
            $data -= 0x100000000;
        }
        return $data;
    }

    public function getObjectArray(string $class): array
    {
        $arrayLength = $this->getUint32();

        $data = [];
        for ($i = 0; $i < $arrayLength; $i++) {
            $data[] = $class::fromStreamBuffer($this);
        }

        return $data;
    }

    public function getStringArray(): array
    {
        $arrayLength = $this->getUint32();

        $data = [];
        for ($i = 0; $i < $arrayLength; $i++) {
            $data[] = $this->getString();
        }

        return $data;
    }

    public function getBytes(): ?string
    {
        $size = $this->getInt32();
        if ($size === -1) {
            return null;
        }

        $data = substr($this->buffer, $this->position, $size);
        $this->position += $size;
        return $data;
    }

    public function getRemainingBytes(): string
    {
        $data = substr($this->buffer, $this->position);
        $this->position = strlen($this->buffer);
        return $data;
    }

    public function getPosition(): int
    {
        return $this->position;
    }

    public function skip(int $bytes): void
    {
        $this->position += $bytes;
    }

    public function readBytes(int $length): string
    {
        $data = substr($this->buffer, $this->position, $length);
        $this->position += $length;
        return $data;
    }

    public function peekUint16(): int
    {
        return unpack('n', substr($this->buffer, $this->position, 2))[1];
    }
}