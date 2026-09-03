<?php

declare(strict_types=1);

namespace CrazyGoat\RabbitStream\Response;

use CrazyGoat\RabbitStream\Buffer\FromArrayInterface;
use CrazyGoat\RabbitStream\Buffer\FromStreamBufferInterface;
use CrazyGoat\RabbitStream\Buffer\ReadBuffer;
use CrazyGoat\RabbitStream\Contract\KeyVersionInterface;
use CrazyGoat\RabbitStream\Enum\KeyEnum;
use CrazyGoat\RabbitStream\Exception\ProtocolException;
use CrazyGoat\RabbitStream\Trait\CommandTrait;
use CrazyGoat\RabbitStream\Trait\V1Trait;
use CrazyGoat\RabbitStream\Util\TypeCast;

/** @phpstan-consistent-constructor */
class DeliverResponseV1 implements KeyVersionInterface, FromStreamBufferInterface, FromArrayInterface
{
    use CommandTrait;
    use V1Trait;

    /** Length of the chunk window into $frameBuffer, starting at $chunkOffset. */
    private readonly int $chunkLength;

    /**
     * @param string $frameBuffer The frame string the chunk lives in. When constructed
     *                            directly (e.g. via fromArray()) this IS the chunk itself
     *                            (offset 0). When constructed via fromStreamBuffer(), this
     *                            is the whole Deliver frame and the chunk is a window into
     *                            it — never copied out.
     * @param int $chunkOffset Absolute offset of the chunk within $frameBuffer.
     * @param ?int $chunkLength Chunk length; defaults to everything from $chunkOffset to
     *                          the end of $frameBuffer.
     */
    public function __construct(
        private int $subscriptionId,
        private string $frameBuffer,
        private int $chunkOffset = 0,
        ?int $chunkLength = null,
    ) {
        $this->chunkLength = $chunkLength ?? (strlen($this->frameBuffer) - $this->chunkOffset);
    }

    public function getSubscriptionId(): int
    {
        return $this->subscriptionId;
    }

    /**
     * Materialises the chunk as a plain string. Kept for backward compatibility;
     * prefer getChunkView() (or getChunkBuffer()) on the consume hot path, since
     * this always copies the chunk out of the frame buffer.
     */
    public function getChunkBytes(): string
    {
        if ($this->chunkOffset === 0 && $this->chunkLength === strlen($this->frameBuffer)) {
            return $this->frameBuffer;
        }
        return substr($this->frameBuffer, $this->chunkOffset, $this->chunkLength);
    }

    /**
     * Zero-copy view of the chunk: the frame buffer plus the offset/length of the
     * chunk within it. Callers (e.g. OsirisChunkParser) parse the chunk directly
     * out of this window instead of forcing a copy via getChunkBytes().
     *
     * @return array{0: string, 1: int, 2: int}
     */
    public function getChunkView(): array
    {
        return [$this->frameBuffer, $this->chunkOffset, $this->chunkLength];
    }

    /** Zero-copy ReadBuffer window over the chunk, sharing the frame buffer. */
    public function getChunkBuffer(): ReadBuffer
    {
        return new ReadBuffer($this->frameBuffer, $this->chunkOffset, $this->chunkLength);
    }

    public static function fromStreamBuffer(ReadBuffer $buffer): ?static
    {
        $key = $buffer->getUint16();
        $version = $buffer->getUint16();

        if (self::getKey() !== $key) {
            throw new ProtocolException('Unexpected command code');
        }

        // Validate version: Deliver supports v1 and v2 only
        // Cannot use validateKeyVersion() because this class handles both v1 and v2 frames
        if ($version !== 1 && $version !== 2) {
            throw new ProtocolException("Unexpected version: {$version}");
        }

        $subscriptionId = $buffer->getUint8();

        // Deliver v2 has CommittedChunkId (uint64) before OsirisChunk
        if ($version === 2) {
            $buffer->skip(8);
        }

        // Zero-copy: the chunk is described as a window into the frame buffer
        // instead of being copied out via getRemainingBytes() (#412).
        [$frameBuffer, $chunkOffset, $chunkLength] = $buffer->getRemainingWindow();
        return new static($subscriptionId, $frameBuffer, $chunkOffset, $chunkLength);
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): static
    {
        return new static(TypeCast::toInt($data['subscriptionId']), TypeCast::toString($data['chunkBytes']));
    }

    public static function getKey(): int
    {
        return KeyEnum::DELIVER->value;
    }
}
