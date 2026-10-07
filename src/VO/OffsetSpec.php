<?php

declare(strict_types=1);

namespace CrazyGoat\RabbitStream\VO;

use CrazyGoat\RabbitStream\Buffer\ToArrayInterface;
use CrazyGoat\RabbitStream\Buffer\ToStreamBufferInterface;
use CrazyGoat\RabbitStream\Buffer\WriteBuffer;
use CrazyGoat\RabbitStream\Exception\InvalidArgumentException;

class OffsetSpec implements ToStreamBufferInterface, ToArrayInterface
{
    public const TYPE_NONE = 0x0000;
    public const TYPE_FIRST = 0x0001;
    public const TYPE_LAST = 0x0002;
    public const TYPE_NEXT = 0x0003;
    public const TYPE_OFFSET = 0x0004;
    public const TYPE_TIMESTAMP = 0x0005;

    /**
     * Types that encode as the 2-byte type field only — no 8-byte value.
     *
     * @var list<int>
     */
    private const VALUELESS_TYPES = [
        self::TYPE_NONE,
        self::TYPE_FIRST,
        self::TYPE_LAST,
        self::TYPE_NEXT,
    ];

    /**
     * Types whose wire form is the 2-byte type field plus an 8-byte value.
     *
     * @var list<int>
     */
    private const VALUE_TYPES = [
        self::TYPE_OFFSET,
        self::TYPE_TIMESTAMP,
    ];

    /**
     * Build an offset specification directly from a raw type and value.
     *
     * Prefer the named factories ({@see first()}, {@see last()}, {@see next()},
     * {@see offset()}, {@see timestamp()}, {@see none()}); this constructor is
     * public so a caller can round-trip an arbitrary spec, but it validates the
     * pair eagerly.
     *
     * @param int $type One of the `TYPE_*` constants.
     * @param int|null $value Required for the value-carrying types
     *                        (`TYPE_OFFSET`, `TYPE_TIMESTAMP`)
     *                        and rejected for the value-less ones (`TYPE_NONE`,
     *                        `TYPE_FIRST`, `TYPE_LAST`, `TYPE_NEXT`).
     * @throws InvalidArgumentException If `$type` is unknown, if a value-carrying
     *         type is given no value, or if a value-less type is given one.
     */
    public function __construct(
        private readonly int $type,
        private readonly ?int $value = null
    ) {
        if (
            !in_array($type, self::VALUELESS_TYPES, true)
            && !in_array($type, self::VALUE_TYPES, true)
        ) {
            throw new InvalidArgumentException("Invalid offset spec type: $type");
        }

        if (in_array($type, self::VALUE_TYPES, true) && $value === null) {
            throw new InvalidArgumentException(
                "Offset spec type $type requires a value (offset/timestamp)"
            );
        }

        if (in_array($type, self::VALUELESS_TYPES, true) && $value !== null) {
            throw new InvalidArgumentException(
                "Offset spec type $type does not accept a value (value-less type)"
            );
        }
    }

    /**
     * "Keep current position" — used only as a ConsumerUpdate reply value, never
     * as a Subscribe offset specification.
     *
     * The broker does not move the consumer: it keeps delivering from the
     * consumer's current position (see the `ConsumerUpdate` reply contract in
     * `StreamConnection::onConsumerUpdate()`). `TYPE_NONE` is the one type the
     * protocol allows in a `ConsumerUpdate` reply but not in a `Subscribe`, so
     * passing `none()` to a subscription is out of spec.
     *
     * The constructor raises `InvalidArgumentException` for a malformed spec
     * (an unknown type, or a value-carrying type with no value); `none()`
     * supplies the valid value-less `TYPE_NONE`, so that guard cannot fire.
     *
     * @return self New offset spec meaning "keep the current position".
     */
    public static function none(): self
    {
        return new self(self::TYPE_NONE);
    }

    /**
     * Start from the first message still available in the stream.
     *
     * The broker resolves this to the stream's first available offset — offset
     * `0` unless retention has truncated the log, in which case it is the first
     * offset that still exists. Use it for a full replay of what is currently
     * retained.
     *
     * The constructor raises `InvalidArgumentException` for a malformed spec
     * (an unknown type, or a value-carrying type with no value); `first()`
     * supplies the valid value-less `TYPE_FIRST`, so that guard cannot fire.
     *
     * @return self New offset spec starting at the first available message.
     */
    public static function first(): self
    {
        return new self(self::TYPE_FIRST);
    }

    /**
     * Start from the last chunk of messages in the stream.
     *
     * The broker resolves this to the **last written chunk**, which it then
     * delivers **in full**: a consumer attached with `last()` can receive the
     * messages that share that final chunk, not only the very last message, and
     * then continues with messages written afterwards. Use {@see next()} to
     * receive only messages published after the subscription.
     *
     * The constructor raises `InvalidArgumentException` for a malformed spec
     * (an unknown type, or a value-carrying type with no value); `last()`
     * supplies the valid value-less `TYPE_LAST`, so that guard cannot fire.
     *
     * @return self New offset spec starting at the last chunk of messages.
     */
    public static function last(): self
    {
        return new self(self::TYPE_LAST);
    }

    /**
     * Start at the next offset to be written — the end of the stream.
     *
     * The broker resolves this to the end of the log, so nothing already stored
     * is replayed and only messages published after the subscription are
     * delivered. The raw stream protocol carries no consumer reference in
     * `Subscribe`, so the broker does **not** substitute a stored offset here;
     * to resume an offset-tracked consumer, query its stored offset
     * (`Consumer::queryOffset()` / `Connection::queryOffset()`) and subscribe
     * with {@see offset()} instead.
     *
     * The constructor raises `InvalidArgumentException` for a malformed spec
     * (an unknown type, or a value-carrying type with no value); `next()`
     * supplies the valid value-less `TYPE_NEXT`, so that guard cannot fire.
     *
     * @return self New offset spec starting at the end of the stream.
     */
    public static function next(): self
    {
        return new self(self::TYPE_NEXT);
    }

    /**
     * Start from a specific absolute offset in the stream.
     *
     * The broker resolves this to exactly that offset in the log; offsets are
     * assigned by the broker and the first message of a non-truncated stream is
     * offset `0`. The offset is **inclusive** — the message at `$offset` is the
     * first one delivered. If retention has removed that offset, the broker
     * attaches at the first offset still available.
     *
     * The constructor raises `InvalidArgumentException` for a malformed spec
     * (an unknown type, or a value-carrying type with no value); `offset()`
     * supplies `TYPE_OFFSET` and the given value, so that guard cannot fire.
     *
     * @param int $offset Absolute offset to start from (inclusive).
     * @return self New offset spec starting at the given offset.
     */
    public static function offset(int $offset): self
    {
        return new self(self::TYPE_OFFSET, $offset);
    }

    /**
     * Start from a point in time, resolved by the broker.
     *
     * The broker resolves the value to the **first chunk whose chunk timestamp
     * is greater than or equal to $timestamp**, then delivers that chunk **in
     * full**. Chunks are the broker's batching unit: a chunk carries a single
     * write timestamp shared by every entry in it (see
     * {@see \CrazyGoat\RabbitStream\Client\Message::getTimestamp()}), so
     * messages written before $timestamp are legitimately delivered whenever
     * they share a chunk with a message at or after it. A tie — $timestamp
     * exactly equal to a chunk's timestamp — selects the *earlier* chunk. The
     * broker clamps an out-of-range value to the closest end of the log, so a
     * value before the first retained chunk starts at the beginning and one
     * after the last chunk starts at the end.
     *
     * To pick a boundary that reliably lands where you intend, derive it from
     * the chunk timestamps the broker actually wrote — the
     * `Message::getTimestamp()` values you read back from the stream — rather
     * than from the client clock.
     *
     * The constructor raises `InvalidArgumentException` for a malformed spec
     * (an unknown type, or a value-carrying type with no value); `timestamp()`
     * supplies `TYPE_TIMESTAMP` and the given value, so that guard cannot fire.
     *
     * @param int $timestamp Boundary in **milliseconds** since the Unix epoch,
     *                       e.g. `(time() - 3600) * 1000` for an hour ago. A
     *                       value in seconds resolves near 1970 and replays the
     *                       whole stream.
     * @return self New offset spec starting at the first qualifying chunk.
     */
    public static function timestamp(int $timestamp): self
    {
        return new self(self::TYPE_TIMESTAMP, $timestamp);
    }

    public function toStreamBuffer(): WriteBuffer
    {
        $buffer = new WriteBuffer();
        $buffer->addUInt16($this->type);

        // Value-less types (none/first/last/next) encode the type field only;
        // only offset/timestamp carry an 8-byte value. The constructor
        // guarantees a value is present for every value-carrying type.
        if (!in_array($this->type, self::VALUE_TYPES, true)) {
            return $buffer;
        }

        // The constructor guarantees a value for every value-carrying type;
        // this guard only keeps a null out of the int-typed buffer calls.
        if ($this->value === null) {
            return $buffer;
        }

        // The value field is uint64 for offset and int64 for timestamp, so a
        // pre-1970 (negative) timestamp is encoded as two's complement.
        if ($this->type === self::TYPE_TIMESTAMP) {
            $buffer->addInt64($this->value);
        } else {
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
