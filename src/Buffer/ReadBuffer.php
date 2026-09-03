<?php

declare(strict_types=1);

namespace CrazyGoat\RabbitStream\Buffer;

use CrazyGoat\RabbitStream\Exception\DeserializationException;
use CrazyGoat\RabbitStream\Platform;

/**
 * Reads scalars, strings and byte arrays out of a binary buffer with an
 * internal cursor.
 *
 * The buffer can be constructed over the whole of `$buffer`, or over a
 * zero-copy window into it via `$offset`/`$length`: the backing string is
 * never copied (`$buffer` is stored by reference-counted value, as PHP
 * strings always are), only the window bounds differ. `getPosition()` and
 * every bounds check are relative to the window, not to the underlying
 * string — from the outside a windowed ReadBuffer behaves exactly like one
 * constructed over `substr($buffer, $offset, $length)`, just without the
 * copy. Windows nest freely: `slice()` derives a new window relative to the
 * caller's current position, still against the same underlying string.
 */
class ReadBuffer
{
    private int $position = 0;
    private readonly int $windowLength;

    /**
     * @param string $buffer The backing string (shared, never copied here).
     * @param int $offset Absolute start of the window into $buffer.
     * @param ?int $length Window length; defaults to everything from $offset to the end of $buffer.
     */
    public function __construct(
        private readonly string $buffer,
        private readonly int $offset = 0,
        ?int $length = null
    ) {
        // getUint32()/getUint64()/getInt64() would return floats instead of ints
        // on a 32-bit build, silently corrupting offsets (#458).
        Platform::assertSixtyFourBitIntegers();
        $this->windowLength = $length ?? (strlen($buffer) - $this->offset);
    }

    private function ensureAvailable(int $bytes): void
    {
        $available = $this->windowLength - $this->position;
        if ($bytes > $available) {
            throw new DeserializationException(
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
        $this->ensureAvailable(1);
        $value = ord($this->buffer[$this->offset + $this->position]);
        $this->position += 1;
        return $value;
    }

    public function getUint16(): int
    {
        $this->ensureAvailable(2);
        $data = unpack('n', $this->buffer, $this->offset + $this->position);
        if ($data === false) {
            throw new DeserializationException('Failed to unpack uint16 at position ' . $this->position);
        }
        $this->position += 2;
        return $data[1];
    }

    public function rewind(): void
    {
        $this->position = 0;
    }

    /**
     * A full uint32 fits a 64-bit PHP int, so the value is always exact here;
     * on a 32-bit build unpack('N') would return a float above PHP_INT_MAX,
     * which is why the constructor rejects that platform outright (#458).
     */
    public function getUint32(): int
    {
        $this->ensureAvailable(4);
        $data = unpack('N', $this->buffer, $this->offset + $this->position);
        if ($data === false) {
            throw new DeserializationException('Failed to unpack uint32 at position ' . $this->position);
        }
        $this->position += 4;
        return $data[1];
    }

    /**
     * uint64 is read as a native 64-bit PHP int, so values above PHP_INT_MAX wrap
     * to negative (tracked separately as #393); on a 32-bit build unpack('J')
     * would instead return a float for most values, which is why the constructor
     * rejects that platform outright (#458).
     */
    public function getUint64(): int
    {
        $this->ensureAvailable(8);
        $data = unpack('J', $this->buffer, $this->offset + $this->position);
        if ($data === false) {
            throw new DeserializationException('Failed to unpack uint64 at position ' . $this->position);
        }
        $this->position += 8;
        return $data[1];
    }

    public function getInt64(): int
    {
        $this->ensureAvailable(8);
        $data = unpack('J', $this->buffer, $this->offset + $this->position);
        if ($data === false) {
            throw new DeserializationException('Failed to unpack int64 at position ' . $this->position);
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

        if ($len < 0) {
            throw new DeserializationException(
                sprintf('Invalid string length %d at position %d', $len, $this->position - 2)
            );
        }

        $this->ensureAvailable($len);
        $data = substr($this->buffer, $this->offset + $this->position, $len);
        $this->position += $len;
        return $data;
    }

    public function getInt16(): int
    {
        $this->ensureAvailable(2);
        $data = unpack('n', $this->buffer, $this->offset + $this->position);
        if ($data === false) {
            throw new DeserializationException('Failed to unpack int16 at position ' . $this->position);
        }
        $this->position += 2;
        if ($data[1] >= 0x8000) {
            $data[1] -= 0x10000;
        }
        return $data[1];
    }

    public function getInt32(): int
    {
        $this->ensureAvailable(4);
        $data = unpack('N', $this->buffer, $this->offset + $this->position);
        if ($data === false) {
            throw new DeserializationException('Failed to unpack int32 at position ' . $this->position);
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

        $remaining = $this->windowLength - $this->position;
        if ($arrayLength > $remaining) {
            throw new DeserializationException(
                sprintf(
                    'Invalid object array count %d at position %d: need at least %d bytes, but only %d available',
                    $arrayLength,
                    $this->position,
                    $arrayLength,
                    $remaining
                )
            );
        }

        $data = [];
        for ($i = 0; $i < $arrayLength; $i++) {
            $item = $class::fromStreamBuffer($this);
            if ($item === null) {
                throw new DeserializationException('Failed to deserialize object of class ' . $class);
            }
            $data[] = $item;
        }

        return $data;
    }

    /** @return array<int, string|null> */
    public function getStringArray(): array
    {
        $arrayLength = $this->getUint32();

        $remaining = $this->windowLength - $this->position;
        if ($arrayLength * 2 > $remaining) {
            throw new DeserializationException(
                sprintf(
                    'Invalid string array count %d at position %d: need at least %d bytes, but only %d available',
                    $arrayLength,
                    $this->position,
                    $arrayLength * 2,
                    $remaining
                )
            );
        }

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

        if ($size < 0) {
            throw new DeserializationException(
                sprintf('Invalid bytes length %d at position %d', $size, $this->position - 4)
            );
        }

        $this->ensureAvailable($size);
        $data = substr($this->buffer, $this->offset + $this->position, $size);
        $this->position += $size;
        return $data;
    }

    public function getRemainingBytes(): string
    {
        if ($this->position > $this->windowLength) {
            throw new DeserializationException(
                sprintf(
                    'Buffer underflow: position %d is past buffer end %d',
                    $this->position,
                    $this->windowLength
                )
            );
        }
        $data = substr($this->buffer, $this->offset + $this->position, $this->windowLength - $this->position);
        $this->position = $this->windowLength;
        return $data;
    }

    /**
     * Zero-copy equivalent of getRemainingBytes(): instead of materialising the
     * remaining bytes as a new string, returns a [string, offset, length]
     * window describing them against the shared backing string. Consumes the
     * buffer exactly like getRemainingBytes() (position is advanced to the end
     * of the window).
     *
     * @return array{0: string, 1: int, 2: int}
     */
    public function getRemainingWindow(): array
    {
        if ($this->position > $this->windowLength) {
            throw new DeserializationException(
                sprintf(
                    'Buffer underflow: position %d is past buffer end %d',
                    $this->position,
                    $this->windowLength
                )
            );
        }
        $offset = $this->offset + $this->position;
        $length = $this->windowLength - $this->position;
        $this->position = $this->windowLength;
        return [$this->buffer, $offset, $length];
    }

    public function getPosition(): int
    {
        return $this->position;
    }

    public function skip(int $bytes): void
    {
        if ($bytes < 0) {
            throw new DeserializationException(
                sprintf('Invalid skip length %d at position %d', $bytes, $this->position)
            );
        }
        $this->ensureAvailable($bytes);
        $this->position += $bytes;
    }

    public function readBytes(int $length): string
    {
        if ($length < 0) {
            throw new DeserializationException(
                sprintf('Invalid read length %d at position %d', $length, $this->position)
            );
        }
        $this->ensureAvailable($length);
        $data = substr($this->buffer, $this->offset + $this->position, $length);
        $this->position += $length;
        return $data;
    }

    public function peekUint16(): int
    {
        $this->ensureAvailable(2);
        $data = unpack('n', $this->buffer, $this->offset + $this->position);
        if ($data === false) {
            throw new DeserializationException('Failed to unpack uint16 at position ' . $this->position);
        }
        return $data[1];
    }

    /**
     * Derives a new zero-copy window relative to this buffer's current
     * position, sharing the same backing string. Does not consume any bytes
     * from this buffer — the two ReadBuffer instances read independently.
     *
     * @param int $offset Offset relative to this buffer's current position.
     * @param ?int $length Window length; defaults to everything from $offset to the end of this buffer's window.
     */
    public function slice(int $offset, ?int $length = null): self
    {
        if ($offset < 0) {
            throw new DeserializationException(sprintf('Invalid slice offset %d', $offset));
        }
        $available = $this->windowLength - $this->position - $offset;
        if ($available < 0) {
            throw new DeserializationException(
                sprintf(
                    'Buffer underflow: slice offset %d at position %d is past buffer end %d',
                    $offset,
                    $this->position,
                    $this->windowLength
                )
            );
        }
        if ($length !== null && $length > $available) {
            throw new DeserializationException(
                sprintf(
                    'Buffer underflow: need %d bytes at slice offset %d, but only %d available',
                    $length,
                    $offset,
                    $available
                )
            );
        }
        return new self($this->buffer, $this->offset + $this->position + $offset, $length ?? $available);
    }
}
