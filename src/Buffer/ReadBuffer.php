<?php

declare(strict_types=1);

namespace CrazyGoat\RabbitStream\Buffer;

class ReadBuffer
{
    private int $position = 0;

    public function __construct(private readonly string $buffer)
    {
    }

    private function ensureAvailable(int $bytes): void
    {
        $available = strlen($this->buffer) - $this->position;
        if ($bytes > $available) {
            throw new \RuntimeException(
                sprintf(
                    'Buffer underflow: need %d bytes at position %d, but only %d available',
                    $bytes,
                    $this->position,
                    $available
                )
            );
        }
    }

    public function getUint8(): int
    {
        $data = unpack('C', substr($this->buffer, $this->position, 1));
        if ($data === false) {
            throw new \RuntimeException('Failed to unpack uint8 at position ' . $this->position);
        }
        $this->position += 1;
        return $data[1];
    }

    public function getUint16(): int
    {
        $data = unpack('n', substr($this->buffer, $this->position, 2));
        if ($data === false) {
            throw new \RuntimeException('Failed to unpack uint16 at position ' . $this->position);
        }
        $this->position += 2;
        return $data[1];
    }

    public function rewind(): void
    {
        $this->position = 0;
    }

    public function getUint32(): int
    {
        $data = unpack('N', substr($this->buffer, $this->position, 4));
        if ($data === false) {
            throw new \RuntimeException('Failed to unpack uint32 at position ' . $this->position);
        }
        $this->position += 4;
        return $data[1];
    }

    public function getUint64(): int
    {
        $data = unpack('J', substr($this->buffer, $this->position, 8));
        if ($data === false) {
            throw new \RuntimeException('Failed to unpack uint64 at position ' . $this->position);
        }
        $this->position += 8;
        return $data[1];
    }

    public function getInt64(): int
    {
        $data = unpack('J', substr($this->buffer, $this->position, 8));
        if ($data === false) {
            throw new \RuntimeException('Failed to unpack int64 at position ' . $this->position);
        }
        $this->position += 8;
        if ($data[1] >= 0x8000000000000000) {
            $data[1] -= 0x10000000000000000;
        }
        return $data[1];
    }

    public function getString(): ?string
    {
        $len = $this->getInt16();
        if ($len === -1) {
            return null;
        }

        $this->ensureAvailable($len);
        $data = substr($this->buffer, $this->position, $len);
        $this->position += $len;
        return $data;
    }

    public function getInt16(): int
    {
        $data = unpack('n', substr($this->buffer, $this->position, 2));
        if ($data === false) {
            throw new \RuntimeException('Failed to unpack int16 at position ' . $this->position);
        }
        $this->position += 2;
        if ($data[1] >= 0x8000) {
            $data[1] -= 0x10000;
        }
        return $data[1];
    }

    public function getInt32(): int
    {
        $data = unpack('N', substr($this->buffer, $this->position, 4));
        if ($data === false) {
            throw new \RuntimeException('Failed to unpack int32 at position ' . $this->position);
        }
        $this->position += 4;
        if ($data[1] >= 0x80000000) {
            $data[1] -= 0x100000000;
        }
        return $data[1];
    }

    /**
     * @template T of FromStreamBufferInterface
     * @param class-string<T> $class
     * @return array<int, T>
     */
    public function getObjectArray(string $class): array
    {
        $arrayLength = $this->getUint32();

        $data = [];
        for ($i = 0; $i < $arrayLength; $i++) {
            $item = $class::fromStreamBuffer($this);
            if ($item === null) {
                throw new \RuntimeException('Failed to deserialize object of class ' . $class);
            }
            $data[] = $item;
        }

        return $data;
    }

    /** @return array<int, string|null> */
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

        $this->ensureAvailable($size);
        $data = substr($this->buffer, $this->position, $size);
        $this->position += $size;
        return $data;
    }

    public function getRemainingBytes(): string
    {
        $this->ensureAvailable(0);
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
        $this->ensureAvailable($bytes);
        $this->position += $bytes;
    }

    public function readBytes(int $length): string
    {
        $this->ensureAvailable($length);
        $data = substr($this->buffer, $this->position, $length);
        $this->position += $length;
        return $data;
    }

    public function peekUint16(): int
    {
        $data = unpack('n', substr($this->buffer, $this->position, 2));
        if ($data === false) {
            throw new \RuntimeException('Failed to unpack uint16 at position ' . $this->position);
        }
        return $data[1];
    }
}
