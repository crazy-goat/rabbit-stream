<?php

declare(strict_types=1);

namespace CrazyGoat\RabbitStream\Client;

class Message
{
    private bool $decoded;

    /**
     * Construct a fully-decoded Message (backwards-compatible eager path), or —
     * via {@see self::fromRawEntry()} — a lazily-decoded one that defers AMQP
     * section decoding until the first accessor call.
     *
     * @param array<int, mixed>|string|int|float|bool|null $body
     * @param array<string, mixed> $properties
     * @param array<string, mixed> $applicationProperties
     * @param array<string, mixed> $messageAnnotations
     * @param string|null $rawData When non-null, the message is constructed lazily: $body,
     *                             $properties, $applicationProperties and $messageAnnotations
     *                             are ignored and instead decoded from $rawData on first access.
     */
    public function __construct(
        private readonly int $offset,
        private readonly int $timestamp,
        private string|int|float|bool|array|null $body = null,
        private array $properties = [],
        private array $applicationProperties = [],
        private array $messageAnnotations = [],
        private readonly ?string $rawData = null,
    ) {
        $this->decoded = $this->rawData === null;
    }

    /**
     * Construct a Message from a raw chunk entry's bytes without decoding it.
     * AMQP sections (body, properties, applicationProperties, messageAnnotations)
     * are decoded and cached lazily, on the first call to a getter that needs them.
     */
    public static function fromRawEntry(int $offset, int $timestamp, string $rawData): self
    {
        return new self(offset: $offset, timestamp: $timestamp, rawData: $rawData);
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

        $rawData = (string) $this->rawData;

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
                $this->body = substr($rawData, 8);
                $this->properties = [];
                $this->applicationProperties = [];
                $this->messageAnnotations = [];
                $this->decoded = true;
                return;
            }
        } elseif ($rawLength >= 5 && str_starts_with($rawData, self::DATA_VBIN8_PREFIX)) {
            $bodyLength = ord($rawData[4]);
            if ($bodyLength === $rawLength - 5) {
                $this->body = substr($rawData, 5);
                $this->properties = [];
                $this->applicationProperties = [];
                $this->messageAnnotations = [];
                $this->decoded = true;
                return;
            }
        }

        $sections = AmqpDecoder::decodeMessage($rawData);

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
