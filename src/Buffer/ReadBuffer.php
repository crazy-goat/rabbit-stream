<?php

namespace CrazyGoat\StreamyCarrot\Buffer;

class ReadBuffer
{
    private int $position = 0;

    public function __construct(private string $buffer)
    {
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

    public function gatString(): ?string
    {
        $len = $this->getInt16();
        if ($len === -1) {
            return null;
        }

        $data = substr($this->buffer, $this->position, $len);
        $this->position += $len;
        return $data;
    }

    public function getInt16()
    {
        $data = unpack('n', substr($this->buffer, $this->position, 2))[1];
        $this->position += 2;
        return $data;
    }

    public function getInt32()
    {
        $data = unpack('N', substr($this->buffer, $this->position, 4))[1];
        $this->position += 4;
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
            $data[] = $this->gatString();
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
}