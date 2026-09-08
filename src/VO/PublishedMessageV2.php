<?php

declare(strict_types=1);

namespace CrazyGoat\RabbitStream\VO;

use CrazyGoat\RabbitStream\Buffer\ToArrayInterface;
use CrazyGoat\RabbitStream\Buffer\ToStreamBufferInterface;
use CrazyGoat\RabbitStream\Buffer\WriteBuffer;
use CrazyGoat\RabbitStream\Exception\InvalidArgumentException;

class PublishedMessageV2 implements ToStreamBufferInterface, ToArrayInterface
{
    private const UINT64_MAX = PHP_INT_MAX;
    private const INT16_MAX = 32767;
    private const INT32_MAX = 2147483647;

    public function __construct(
        private readonly int $publishingId,
        private readonly string $filterValue,
        private readonly string $message,
    ) {
    }

    public function toStreamBuffer(): WriteBuffer
    {
        return (new WriteBuffer())
            ->addUInt64($this->publishingId)
            ->addString($this->filterValue)
            ->addBytes($this->message);
    }

    /**
     * Cheap single-pass wire encoding: publishingId(uint64) + filterValue(int16-prefixed)
     * + length(int32) + body, built directly with pack() and one concatenation instead
     * of a WriteBuffer object per message. Used by PublishRequestV2::toStreamBuffer()
     * on the hot publish path; produces byte-identical output to
     * toStreamBuffer()->getContents() (same validation as addUInt64/addString/addBytes).
     */
    public function toWire(): string
    {
        if ($this->publishingId < 0 || $this->publishingId > self::UINT64_MAX) {
            throw new InvalidArgumentException(
                "Value {$this->publishingId} is out of range for uint64 (0 to " . self::UINT64_MAX . ")"
            );
        }

        $filterLength = strlen($this->filterValue);
        if ($filterLength > self::INT16_MAX) {
            throw new InvalidArgumentException(
                "Value $filterLength is out of range for string length (0 to " . self::INT16_MAX . ")"
            );
        }
        if (!mb_check_encoding($this->filterValue, 'UTF-8')) {
            throw new InvalidArgumentException('String must be valid UTF-8');
        }

        $length = strlen($this->message);
        if ($length > self::INT32_MAX) {
            throw new InvalidArgumentException(
                "Value $length is out of range for bytes length (0 to " . self::INT32_MAX . ")"
            );
        }

        return pack('Jn', $this->publishingId, $filterLength) . $this->filterValue
            . pack('N', $length) . $this->message;
    }

    public function getPublishingId(): int
    {
        return $this->publishingId;
    }

    /** @return array<string, int|string> */
    public function toArray(): array
    {
        return [
            'publishingId' => $this->publishingId,
            'filterValue' => $this->filterValue,
            'data' => $this->message,
        ];
    }
}
