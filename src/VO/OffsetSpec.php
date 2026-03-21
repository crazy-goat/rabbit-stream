<?php

declare(strict_types=1);

namespace CrazyGoat\RabbitStream\VO;

use CrazyGoat\RabbitStream\Buffer\ToArrayInterface;
use CrazyGoat\RabbitStream\Buffer\ToStreamBufferInterface;
use CrazyGoat\RabbitStream\Buffer\WriteBuffer;
use CrazyGoat\RabbitStream\Exception\InvalidArgumentException;

class OffsetSpec implements ToStreamBufferInterface, ToArrayInterface
{
    public const TYPE_FIRST = 0x0001;
    public const TYPE_LAST = 0x0002;
    public const TYPE_NEXT = 0x0003;
    public const TYPE_OFFSET = 0x0004;
    public const TYPE_TIMESTAMP = 0x0005;
    public const TYPE_INTERVAL = 0x0006;

    public function __construct(
        private readonly int $type,
        private readonly ?int $value = null
    ) {
        if (
            !in_array(
                $type,
                [
                    self::TYPE_FIRST,
                    self::TYPE_LAST,
                    self::TYPE_NEXT,
                    self::TYPE_OFFSET,
                    self::TYPE_TIMESTAMP,
                    self::TYPE_INTERVAL,
                ],
                true
            )
        ) {
            throw new InvalidArgumentException("Invalid offset spec type: $type");
        }
    }

    public static function first(): self
    {
        return new self(self::TYPE_FIRST);
    }

    public static function last(): self
    {
        return new self(self::TYPE_LAST);
    }

    public static function next(): self
    {
        return new self(self::TYPE_NEXT);
    }

    public static function offset(int $offset): self
    {
        return new self(self::TYPE_OFFSET, $offset);
    }

    public static function timestamp(int $timestamp): self
    {
        return new self(self::TYPE_TIMESTAMP, $timestamp);
    }

    public static function interval(int $interval): self
    {
        return new self(self::TYPE_INTERVAL, $interval);
    }

    public function toStreamBuffer(): WriteBuffer
    {
        $buffer = new WriteBuffer();
        $buffer->addUInt16($this->type);

        if ($this->value !== null) {
            $buffer->addUInt64($this->value);
        }

        return $buffer;
    }

    public function getType(): int
    {
        return $this->type;
    }

    public function getValue(): ?int
    {
        return $this->value;
    }

    /** @return array<string, int|null> */
    public function toArray(): array
    {
        return ['type' => $this->type, 'value' => $this->value];
    }
}
