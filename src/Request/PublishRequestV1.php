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
use CrazyGoat\RabbitStream\Trait\V1Trait;
use CrazyGoat\RabbitStream\VO\PublishedMessage;

class PublishRequestV1 implements ToStreamBufferInterface, ToArrayInterface, KeyVersionInterface
{
    use V1Trait;
    use CommandTrait;

    /** @var array<int, PublishedMessage> */
    private array $messages;

    public function __construct(private int $publisherId, PublishedMessage ...$messages)
    {
        $this->messages = array_values($messages);
    }

    /**
     * Build the wire payload in a single pass: header + per-message
     * publishingId/length/body, concatenated directly with pack() instead of
     * routing every message through its own WriteBuffer object. This is the
     * hot path for Producer::send()/sendBatch(); see PublishedMessage::toWire().
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
            $payload .= $message->toWire();
        }

        // Pass the finished payload straight to the constructor rather than
        // addRaw()-ing it into an empty buffer: addRaw() would otherwise
        // trigger one more full copy of $payload (costly for large bodies).
        return new WriteBuffer($payload);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'publisherId' => $this->publisherId,
            'messages' => array_map(fn(PublishedMessage $m): array => $m->toArray(), $this->messages),
        ];
    }

    public static function getKey(): int
    {
        return KeyEnum::PUBLISH->value;
    }
}
