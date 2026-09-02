<?php

declare(strict_types=1);

namespace CrazyGoat\RabbitStream\Client;

class Message
{
    private bool $decoded;

    /**
     * Construct a fully-decoded Message (backwards-compatible eager path), or —
     * via {@see self::fromRawEntry()} or {@see self::fromChunkView()} — a
     * lazily-decoded one that defers AMQP section decoding until the first
     * accessor call.
     *
     * @param array<int, mixed>|string|int|float|bool|null $body
     * @param array<string, mixed> $properties
     * @param array<string, mixed> $applicationProperties
     * @param array<string, mixed> $messageAnnotations
     * @param string|null $rawData When non-null, the message is constructed lazily: $body,
     *                             $properties, $applicationProperties and $messageAnnotations
     *                             are ignored and instead decoded from $rawData on first access.
     * @param string|null $chunk When non-null (with $rawData null), the message is constructed
     *                           lazily as a zero-copy VIEW into $chunk: the entry's bytes are
     *                           $chunk[$chunkStart..$chunkStart+$chunkLength), decoded on first
     *                           access without ever substr()-copying them out until then.
     * @param string|null $stream The name of the stream this message was delivered from — set
     *                            once at construction (see {@see self::getStream()}); null when
     *                            unknown (e.g. constructed outside a Consumer's deliver path).
     */
    public function __construct(
        private readonly int $offset,
        private readonly int $timestamp,
        private string|int|float|bool|array|null $body = null,
        private array $properties = [],
        private array $applicationProperties = [],
        private array $messageAnnotations = [],
        private ?string $rawData = null,
        private ?string $chunk = null,
        private readonly int $chunkStart = 0,
        private readonly int $chunkLength = 0,
        private readonly ?string $stream = null,
    ) {
        $this->decoded = $this->rawData === null && $this->chunk === null;
    }

    /**
     * Construct a Message from a raw chunk entry's bytes without decoding it.
     * AMQP sections (body, properties, applicationProperties, messageAnnotations)
     * are decoded and cached lazily, on the first call to a getter that needs them.
     *
     * This copies $rawData out of the caller's buffer; prefer {@see self::fromChunkView()}
     * when the entry's bytes are a contiguous range inside a chunk string the caller
     * still holds, to avoid that copy entirely.
     */
    public static function fromRawEntry(int $offset, int $timestamp, string $rawData, ?string $stream = null): self
    {
        return new self(offset: $offset, timestamp: $timestamp, rawData: $rawData, stream: $stream);
    }

    /**
     * Construct a Message as a zero-copy VIEW into $chunk: no bytes are copied out of
     * $chunk at construction time. PHP strings are refcounted, so passing $chunk here
     * just bumps a refcount — every Message built from the same chunk (e.g. all of a
     * chunk's entries in {@see OsirisChunkParser::parseMessages()}) shares that one
     * buffer instead of each holding its own substr() copy of its entry.
     *
     * AMQP sections are decoded and cached lazily, on the first call to a getter that
     * needs them, exactly like {@see self::fromRawEntry()}; decoding extracts only the
     * entry's own range ($chunkStart..$chunkStart+$chunkLength) and releases the chunk
     * reference afterwards.
     */
    public static function fromChunkView(
        int $offset,
        int $timestamp,
        string $chunk,
        int $start,
        int $length,
        ?string $stream = null,
    ): self {
        return new self(
            offset: $offset,
            timestamp: $timestamp,
            chunk: $chunk,
            chunkStart: $start,
            chunkLength: $length,
            stream: $stream,
        );
    }

    /** AMQP 1.0 described-type prefix for a Data section with a vbin32 (4-byte length) payload. */
    private const DATA_VBIN32_PREFIX = "\x00\x53\x75\xb0";

    /** AMQP 1.0 described-type prefix for a Data section with a vbin8 (1-byte length) payload. */
    private const DATA_VBIN8_PREFIX = "\x00\x53\x75\xa0";

    /**
     * Decode $rawData into sections and cache the result. No-op once already decoded.
     */
    private function ensureDecoded(): void
    {
        if ($this->decoded) {
            return;
        }

        if ($this->chunk !== null) {
            $this->ensureDecodedFromChunkView();
            return;
        }

        $rawData = (string) $this->rawData;
        // The raw bytes are no longer needed once decoded: releasing them keeps a
        // single copy of the payload per message (body only) instead of two.
        $this->rawData = null;

        // Fast path: this library's own Producer always encodes a message body
        // as exactly one AMQP 1.0 Data section (no header/properties/annotations),
        // which is either "00 53 75 b0" + uint32 big-endian length + body (vbin32)
        // or "00 53 75 a0" + uint8 length + body (vbin8). Recognising that shape
        // directly avoids the generic AMQP decoder entirely for the common case.
        $rawLength = strlen($rawData);
        if ($rawLength >= 8 && str_starts_with($rawData, self::DATA_VBIN32_PREFIX)) {
            $lengthField = unpack('N', $rawData, 4);
            $bodyLength = $lengthField === false ? -1 : (int) $lengthField[1];
            if ($bodyLength === $rawLength - 8) {
                $this->applyFastPathBody(substr($rawData, 8));
                return;
            }
        } elseif ($rawLength >= 5 && str_starts_with($rawData, self::DATA_VBIN8_PREFIX)) {
            $bodyLength = ord($rawData[4]);
            if ($bodyLength === $rawLength - 5) {
                $this->applyFastPathBody(substr($rawData, 5));
                return;
            }
        }

        $this->applyDecodedSections(AmqpDecoder::decodeMessage($rawData));
    }

    /**
     * Same decoding as ensureDecoded()'s rawData branch, but operating on a VIEW into
     * a shared chunk string ($this->chunk[$this->chunkStart..+$this->chunkLength))
     * instead of a private copy: the fast path reads the prefix bytes and the
     * uint32/uint8 length field directly out of $chunk at their absolute offsets, so
     * no bytes are copied until the single substr() that extracts the body itself;
     * the generic fallback still needs a contiguous string for AmqpDecoder, so it
     * substr()s exactly the entry's own range (not the whole chunk).
     */
    private function ensureDecodedFromChunkView(): void
    {
        $chunk = (string) $this->chunk;
        $start = $this->chunkStart;
        $length = $this->chunkLength;
        // The chunk reference is no longer needed once decoded: releasing it lets
        // PHP's refcounting free the chunk buffer once every message built on top
        // of it has been decoded.
        $this->chunk = null;

        if (
            $length >= 8
            && substr_compare($chunk, self::DATA_VBIN32_PREFIX, $start, 4) === 0
        ) {
            $lengthField = unpack('N', $chunk, $start + 4);
            $bodyLength = $lengthField === false ? -1 : (int) $lengthField[1];
            if ($bodyLength === $length - 8) {
                $this->applyFastPathBody(substr($chunk, $start + 8, $bodyLength));
                return;
            }
        } elseif (
            $length >= 5
            && substr_compare($chunk, self::DATA_VBIN8_PREFIX, $start, 4) === 0
        ) {
            $bodyLength = ord($chunk[$start + 4]);
            if ($bodyLength === $length - 5) {
                $this->applyFastPathBody(substr($chunk, $start + 5, $bodyLength));
                return;
            }
        }

        $this->applyDecodedSections(AmqpDecoder::decodeMessage(substr($chunk, $start, $length)));
    }

    /**
     * Apply the fast-path result: a single Data section with no properties,
     * applicationProperties or messageAnnotations.
     */
    private function applyFastPathBody(string $body): void
    {
        $this->body = $body;
        $this->properties = [];
        $this->applicationProperties = [];
        $this->messageAnnotations = [];
        $this->decoded = true;
    }

    /**
     * Apply a fully-decoded generic AmqpDecoder::decodeMessage() result.
     *
     * @param array<string, mixed> $sections
     */
    private function applyDecodedSections(array $sections): void
    {
        $rawBody = $sections['body'] ?? null;
        if (is_array($rawBody)) {
            $body = array_values($rawBody);
        } elseif ($rawBody === null || is_scalar($rawBody)) {
            $body = $rawBody;
        } else {
            $body = null;
        }

        $properties = $sections['properties'] ?? [];
        $applicationProperties = $sections['applicationProperties'] ?? [];
        $messageAnnotations = $sections['messageAnnotations'] ?? [];

        $this->body = $body;
        $this->properties = is_array($properties) ? $properties : [];
        $this->applicationProperties = is_array($applicationProperties) ? $applicationProperties : [];
        $this->messageAnnotations = is_array($messageAnnotations) ? $messageAnnotations : [];
        $this->decoded = true;
    }

    public function getOffset(): int
    {
        return $this->offset;
    }

    /**
     * The name of the stream this message was delivered from (the stream a
     * Consumer subscribed to), or null when unknown/not set — e.g. a Message
     * constructed outside a Consumer's deliver path.
     */
    public function getStream(): ?string
    {
        return $this->stream;
    }

    public function getTimestamp(): int
    {
        return $this->timestamp;
    }

    /** @return array<int, mixed>|string|int|float|bool|null */
    public function getBody(): string|int|float|bool|array|null
    {
        $this->ensureDecoded();
        return $this->body;
    }

    /** @return array<string, mixed> */
    public function getProperties(): array
    {
        $this->ensureDecoded();
        return $this->properties;
    }

    /** @return array<string, mixed> */
    public function getApplicationProperties(): array
    {
        $this->ensureDecoded();
        return $this->applicationProperties;
    }

    /** @return array<string, mixed> */
    public function getMessageAnnotations(): array
    {
        $this->ensureDecoded();
        return $this->messageAnnotations;
    }

    public function getMessageId(): mixed
    {
        $this->ensureDecoded();
        return $this->properties['message-id'] ?? null;
    }

    public function getCorrelationId(): mixed
    {
        $this->ensureDecoded();
        return $this->properties['correlation-id'] ?? null;
    }

    public function getContentType(): ?string
    {
        $this->ensureDecoded();
        $value = $this->properties['content-type'] ?? null;
        return is_scalar($value) ? (string) $value : null;
    }

    public function getSubject(): ?string
    {
        $this->ensureDecoded();
        $value = $this->properties['subject'] ?? null;
        return is_scalar($value) ? (string) $value : null;
    }

    public function getCreationTime(): ?int
    {
        $this->ensureDecoded();
        $value = $this->properties['creation-time'] ?? null;
        return is_scalar($value) ? (int) $value : null;
    }

    public function getGroupId(): ?string
    {
        $this->ensureDecoded();
        $value = $this->properties['group-id'] ?? null;
        return is_scalar($value) ? (string) $value : null;
    }
}
