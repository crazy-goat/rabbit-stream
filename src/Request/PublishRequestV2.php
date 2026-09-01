<?php

declare(strict_types=1);

namespace CrazyGoat\RabbitStream\Request;

use CrazyGoat\RabbitStream\Buffer\ToArrayInterface;
use CrazyGoat\RabbitStream\Buffer\ToStreamBufferInterface;
use CrazyGoat\RabbitStream\Buffer\WriteBuffer;
use CrazyGoat\RabbitStream\Contract\KeyVersionInterface;
use CrazyGoat\RabbitStream\Enum\KeyEnum;
use CrazyGoat\RabbitStream\Exception\InvalidArgumentException;
use CrazyGoat\RabbitStream\Trait\CommandTrait;
use CrazyGoat\RabbitStream\VO\PublishedMessageV2;

class PublishRequestV2 implements ToStreamBufferInterface, ToArrayInterface, KeyVersionInterface
{
    use CommandTrait;

    /** @var array<int, PublishedMessageV2> */
    private array $messages;

    public function __construct(private int $publisherId, PublishedMessageV2 ...$messages)
    {
        $this->messages = array_values($messages);
    }

    /**
     * Build the header with a single pack() call and concatenate each
     * message's already-serialized wire bytes directly, instead of routing
     * the header and every message through addUInt8()/addArray()'s
     * per-message WriteBuffer allocation.
     *
     * PublishedMessageV2 carries a variable-length filterValue field that is
     * only reachable through its own toStreamBuffer(), so (unlike
     * PublishRequestV1) the per-message encoding itself is not further
     * flattened here.
     */
    public function toStreamBuffer(): WriteBuffer
    {
        if ($this->publisherId < 0 || $this->publisherId > 255) {
            throw new InvalidArgumentException(
                "Value {$this->publisherId} is out of range for uint8 (0 to 255)"
            );
        }

        $payload = pack('nnCN', self::getKey(), self::getVersion(), $this->publisherId, count($this->messages));

        foreach ($this->messages as $message) {
            $payload .= $message->toStreamBuffer()->getContents();
        }

        // Pass the finished payload straight to the constructor rather than
        // addRaw()-ing it into an empty buffer, saving one more full copy.
        return new WriteBuffer($payload);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'publisherId' => $this->publisherId,
            'messages' => array_map(fn(PublishedMessageV2 $m): array => $m->toArray(), $this->messages),
        ];
    }

    public static function getKey(): int
    {
        return KeyEnum::PUBLISH->value;
    }

    public static function getVersion(): int
    {
        return 2;
    }
}
