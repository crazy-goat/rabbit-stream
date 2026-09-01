<?php

declare(strict_types=1);

namespace CrazyGoat\RabbitStream\Client;

use CrazyGoat\RabbitStream\Exception\DeserializationException;

class AmqpDecoder
{
    private const MAX_RECURSION_DEPTH = 32;

    /**
     * Maximum number of elements a single compound (list or map) may declare.
     * Caps breadth amplification: without it an honest 8 MiB frame of 1-byte
     * null elements builds an ~8 M-entry PHP array (~128–256 MB) and triggers an
     * uncatchable `Allowed memory size exhausted` fatal at memory_limit=128M.
     * 128 K elements is generous for real AMQP messages (~10 MB worst case) and
     * well below any OOM threshold. See issue #449.
     */
    private const MAX_COMPOUND_ELEMENTS = 131072;

    /**
     * Decode a single AMQP 1.0 value from the binary data at the given position.
     * Returns [value, newPosition].
     *
     * @param int $depth current recursion depth (start at 0)
     * @param int $maxDepth maximum allowed recursion depth
     * @return array{0: mixed, 1: int}
     */
    public static function decodeValue(
        string $data,
        int $position,
        int $depth = 0,
        int $maxDepth = self::MAX_RECURSION_DEPTH
    ): array {
        if ($position >= strlen($data)) {
            throw new DeserializationException('Unexpected end of data');
        }
        if ($depth > $maxDepth) {
            throw new DeserializationException(sprintf('AMQP recursion depth limit exceeded (max %d)', $maxDepth));
        }

        $formatCode = ord($data[$position]);
        $position++;

        return match ($formatCode) {
            // Fixed-width types
            0x40 => [null, $position], // null
            0x41 => [true, $position], // boolean true
            0x42 => [false, $position], // boolean false
            0x43 => [0, $position], // uint zero
            0x44 => [0, $position], // ulong zero
            0x45 => [[], $position], // list0 (empty list)
            0x50 => self::readUint8($data, $position), // ubyte
            0x51 => self::readInt8($data, $position), // byte
            0x52 => self::readUint8($data, $position), // smalluint
            0x53 => self::readUint8($data, $position), // smallulong
            0x54 => self::readInt8($data, $position), // smallint
            0x55 => self::readInt8($data, $position), // smalllong
            0x56 => self::readBoolean($data, $position), // boolean
            0x60 => self::readUint16($data, $position), // ushort
            0x61 => self::readInt16($data, $position), // short
            0x70 => self::readUint32($data, $position), // uint
            0x71 => self::readInt32($data, $position), // int
            0x72 => self::readFloat($data, $position), // float
            0x80 => self::readUint64($data, $position), // ulong
            0x81 => self::readInt64($data, $position), // long
            0x82 => self::readDouble($data, $position), // double
            0x83 => self::readTimestamp($data, $position), // timestamp
            0x98 => self::readUuid($data, $position), // uuid

            // Variable-width types (8-bit length)
            0xa0 => self::readBinary8($data, $position), // vbin8
            0xa1 => self::readString8($data, $position), // str8-utf8
            0xa3 => self::readSymbol8($data, $position), // sym8

            // Variable-width types (32-bit length)
            0xb0 => self::readBinary32($data, $position), // vbin32
            0xb1 => self::readString32($data, $position), // str32-utf8
            0xb3 => self::readSymbol32($data, $position), // sym32

            // Compound types (8-bit length)
            0xc0 => self::readList8($data, $position, $depth, $maxDepth), // list8
            0xc1 => self::readMap8($data, $position, $depth, $maxDepth), // map8

            // Compound types (32-bit length)
            0xd0 => self::readList32($data, $position, $depth, $maxDepth), // list32
            0xd1 => self::readMap32($data, $position, $depth, $maxDepth), // map32

            // Described type
            0x00 => self::readDescribedType($data, $position, $depth, $maxDepth),

            default => throw new DeserializationException(sprintf('Unsupported AMQP type: 0x%02x', $formatCode)),
        };
    }

    /**
     * Decode a full AMQP 1.0 message into sections.
     * Returns ['header' => [...], 'properties' => [...], 'applicationProperties' => [...],
     *          'messageAnnotations' => [...], 'body' => string|mixed]
     *
     * @param int $maxDepth maximum allowed recursion depth
     * @return array<string, mixed>
     */
    public static function decodeMessage(string $data, int $maxDepth = self::MAX_RECURSION_DEPTH): array
    {
        if ($data === '') {
            throw new DeserializationException('Empty message data');
        }

        $sections = [
            'header' => null,
            'deliveryAnnotations' => null,
            'messageAnnotations' => [],
            'properties' => [],
            'applicationProperties' => [],
            'body' => '',
            'footer' => null,
        ];

        $position = 0;
        $dataLength = strlen($data);

        while ($position < $dataLength) {
            // Check for described type marker
            if (ord($data[$position]) !== 0x00) {
                throw new DeserializationException(sprintf(
                    'Expected described type marker (0x00) at position %d, got 0x%02x',
                    $position,
                    ord($data[$position])
                ));
            }

            // Read the described type
            [$descriptor, $value, $position] = self::readDescribedTypeWithPosition($data, $position, 0, $maxDepth);

            // Match descriptor to section
            switch ($descriptor) {
                case 0x70: // Header
                    $sections['header'] = $value;
                    break;

                case 0x71: // DeliveryAnnotations
                    $sections['deliveryAnnotations'] = $value;
                    break;

                case 0x72: // MessageAnnotations
                    $sections['messageAnnotations'] = $value;
                    break;

                case 0x73: // Properties
                    $sections['properties'] = self::parsePropertiesList(is_array($value) ? $value : []);
                    break;

                case 0x74: // ApplicationProperties
                    $sections['applicationProperties'] = $value;
                    break;

                case 0x75: // Data (body)
                    if (is_string($value)) {
                        $currentBody = $sections['body'];
                        $sections['body'] = (is_string($currentBody) ? $currentBody : '') . $value;
                    }
                    break;

                case 0x76:
                case 0x77: // AmqpSequence (body)
                    // For now, treat as array
                    $sections['body'] = $value;
                    break;

                case 0x78: // Footer
                    $sections['footer'] = $value;
                    break;

                default:
                    // Skip unknown sections
                    break;
            }
        }

        return $sections;
    }

    /**
     * Parse Properties list (descriptor 0x73) into named fields.
     *
     * @param array<int, mixed> $list
     * @return array<string, mixed>
     */
    private static function parsePropertiesList(array $list): array
    {
        $propertyNames = [
            0 => 'message-id',
            1 => 'user-id',
            2 => 'to',
            3 => 'subject',
            4 => 'reply-to',
            5 => 'correlation-id',
            6 => 'content-type',
            7 => 'content-encoding',
            8 => 'absolute-expiry-time',
            9 => 'creation-time',
            10 => 'group-id',
            11 => 'group-sequence',
            12 => 'reply-to-group-id',
        ];

        $properties = [];
        foreach ($list as $index => $value) {
            if (isset($propertyNames[$index]) && $value !== null) {
                $properties[$propertyNames[$index]] = $value;
            }
        }

        return $properties;
    }

    // Fixed-width type readers

    /** @return array{0: int, 1: int} */
    private static function readUint8(string $data, int $position): array
    {
        if ($position >= strlen($data)) {
            throw new DeserializationException('Unexpected end of data reading uint8');
        }
        return [ord($data[$position]), $position + 1];
    }

    /**
     * Unpack an integer at $position without slicing $data first (offset-form
     * unpack()). $format must describe exactly one value.
     */
    private static function unpackIntAt(string $format, string $data, int $position, string $context): int
    {
        $result = unpack($format, $data, $position);
        if ($result === false) {
            throw new DeserializationException('Failed to unpack ' . $context);
        }
        return (int) $result[1];
    }

    /**
     * Unpack a float/double at $position without slicing $data first. $format
     * must be a big-endian format ('G' float, 'E' double) — 'f'/'d' are
     * machine-endian and must never be used here.
     */
    private static function unpackFloatAt(string $format, string $data, int $position, string $context): float
    {
        $result = unpack($format, $data, $position);
        if ($result === false) {
            throw new DeserializationException('Failed to unpack ' . $context);
        }
        return (float) $result[1];
    }

    /** @return array{0: int, 1: int} */
    private static function readInt8(string $data, int $position): array
    {
        if ($position >= strlen($data)) {
            throw new DeserializationException('Unexpected end of data reading int8');
        }
        $value = self::unpackIntAt('c', $data, $position, 'int8');
        return [$value, $position + 1];
    }

    /** @return array{0: int, 1: int} */
    private static function readUint16(string $data, int $position): array
    {
        if ($position + 1 >= strlen($data)) {
            throw new DeserializationException('Unexpected end of data reading uint16');
        }
        $value = self::unpackIntAt('n', $data, $position, 'uint16');
        return [$value, $position + 2];
    }

    /** @return array{0: int, 1: int} */
    private static function readInt16(string $data, int $position): array
    {
        if ($position + 1 >= strlen($data)) {
            throw new DeserializationException('Unexpected end of data reading int16');
        }
        $value = self::unpackIntAt('n', $data, $position, 'int16');
        if ($value >= 0x8000) {
            $value -= 0x10000;
        }
        return [$value, $position + 2];
    }

    /** @return array{0: int, 1: int} */
    private static function readUint32(string $data, int $position): array
    {
        if ($position + 3 >= strlen($data)) {
            throw new DeserializationException('Unexpected end of data reading uint32');
        }
        $value = self::unpackIntAt('N', $data, $position, 'uint32');
        return [$value, $position + 4];
    }

    /** @return array{0: int, 1: int} */
    private static function readInt32(string $data, int $position): array
    {
        if ($position + 3 >= strlen($data)) {
            throw new DeserializationException('Unexpected end of data reading int32');
        }
        $value = self::unpackIntAt('N', $data, $position, 'int32');
        if ($value >= 0x80000000) {
            $value -= 0x100000000;
        }
        return [$value, $position + 4];
    }

    /** @return array{0: float, 1: int} */
    private static function readFloat(string $data, int $position): array
    {
        if ($position + 3 >= strlen($data)) {
            throw new DeserializationException('Unexpected end of data reading float');
        }
        $value = self::unpackFloatAt('G', $data, $position, 'float');
        return [$value, $position + 4];
    }

    /** @return array{0: int, 1: int} */
    private static function readUint64(string $data, int $position): array
    {
        if ($position + 7 >= strlen($data)) {
            throw new DeserializationException('Unexpected end of data reading uint64');
        }
        $value = self::unpackIntAt('J', $data, $position, 'uint64');
        return [$value, $position + 8];
    }

    /** @return array{0: int, 1: int} */
    private static function readInt64(string $data, int $position): array
    {
        if ($position + 7 >= strlen($data)) {
            throw new DeserializationException('Unexpected end of data reading int64');
        }
        // 'J' unpacks as a native 64-bit PHP int, which is already signed (PHP has
        // no unsigned 64-bit type), so no manual sign correction is needed here —
        // unlike the 16/32-bit readers, where 'n'/'N' return an unsigned value.
        $value = self::unpackIntAt('J', $data, $position, 'int64');
        return [$value, $position + 8];
    }

    /** @return array{0: float, 1: int} */
    private static function readDouble(string $data, int $position): array
    {
        if ($position + 7 >= strlen($data)) {
            throw new DeserializationException('Unexpected end of data reading double');
        }
        $value = self::unpackFloatAt('E', $data, $position, 'double');
        return [$value, $position + 8];
    }

    /** @return array{0: int, 1: int} */
    private static function readTimestamp(string $data, int $position): array
    {
        if ($position + 7 >= strlen($data)) {
            throw new DeserializationException('Unexpected end of data reading timestamp');
        }
        // Timestamp is milliseconds since Unix epoch (int64); see readInt64() for
        // why no manual sign correction is needed for a 'J' unpack.
        $value = self::unpackIntAt('J', $data, $position, 'timestamp');
        return [$value, $position + 8];
    }

    /** @return array{0: string, 1: int} */
    private static function readUuid(string $data, int $position): array
    {
        if ($position + 15 >= strlen($data)) {
            throw new DeserializationException('Unexpected end of data reading uuid');
        }
        // Format as UUID string: 8-4-4-4-12 hex digits
        $p1 = self::unpackIntAt('N', $data, $position, 'uuid part1');
        $p2 = self::unpackIntAt('n', $data, $position + 4, 'uuid part2');
        $p3 = self::unpackIntAt('n', $data, $position + 6, 'uuid part3');
        $p4 = self::unpackIntAt('n', $data, $position + 8, 'uuid part4');
        $p5a = self::unpackIntAt('N', $data, $position + 10, 'uuid part5a');
        $p5b = self::unpackIntAt('n', $data, $position + 14, 'uuid part5b');
        $value = sprintf(
            '%08x-%04x-%04x-%04x-%012x',
            $p1,
            $p2,
            $p3,
            $p4,
            $p5a * 65536 + $p5b
        );
        return [$value, $position + 16];
    }

    /** @return array{0: bool, 1: int} */
    private static function readBoolean(string $data, int $position): array
    {
        if ($position >= strlen($data)) {
            throw new DeserializationException('Unexpected end of data reading boolean');
        }
        return [ord($data[$position]) !== 0, $position + 1];
    }

    // Variable-width type readers

    /** @return array{0: string, 1: int} */
    private static function readBinary8(string $data, int $position): array
    {
        if ($position >= strlen($data)) {
            throw new DeserializationException('Unexpected end of data reading binary8 length');
        }
        $length = ord($data[$position]);
        $position++;
        if ($position + $length > strlen($data)) {
            throw new DeserializationException('Unexpected end of data reading binary8 content');
        }
        return [substr($data, $position, $length), $position + $length];
    }

    /** @return array{0: string, 1: int} */
    private static function readBinary32(string $data, int $position): array
    {
        if ($position + 3 >= strlen($data)) {
            throw new DeserializationException('Unexpected end of data reading binary32 length');
        }
        $length = self::unpackIntAt('N', $data, $position, 'binary32 length');
        $position += 4;
        if ($position + $length > strlen($data)) {
            throw new DeserializationException('Unexpected end of data reading binary32 content');
        }
        return [substr($data, $position, $length), $position + $length];
    }

    /** @return array{0: string, 1: int} */
    private static function readString8(string $data, int $position): array
    {
        if ($position >= strlen($data)) {
            throw new DeserializationException('Unexpected end of data reading string8 length');
        }
        $length = ord($data[$position]);
        $position++;
        if ($position + $length > strlen($data)) {
            throw new DeserializationException('Unexpected end of data reading string8 content');
        }
        return [substr($data, $position, $length), $position + $length];
    }

    /** @return array{0: string, 1: int} */
    private static function readString32(string $data, int $position): array
    {
        if ($position + 3 >= strlen($data)) {
            throw new DeserializationException('Unexpected end of data reading string32 length');
        }
        $length = self::unpackIntAt('N', $data, $position, 'string32 length');
        $position += 4;
        if ($position + $length > strlen($data)) {
            throw new DeserializationException('Unexpected end of data reading string32 content');
        }
        return [substr($data, $position, $length), $position + $length];
    }

    /** @return array{0: string, 1: int} */
    private static function readSymbol8(string $data, int $position): array
    {
        if ($position >= strlen($data)) {
            throw new DeserializationException('Unexpected end of data reading symbol8 length');
        }
        $length = ord($data[$position]);
        $position++;
        if ($position + $length > strlen($data)) {
            throw new DeserializationException('Unexpected end of data reading symbol8 content');
        }
        return [substr($data, $position, $length), $position + $length];
    }

    /** @return array{0: string, 1: int} */
    private static function readSymbol32(string $data, int $position): array
    {
        if ($position + 3 >= strlen($data)) {
            throw new DeserializationException('Unexpected end of data reading symbol32 length');
        }
        $length = self::unpackIntAt('N', $data, $position, 'symbol32 length');
        $position += 4;
        if ($position + $length > strlen($data)) {
            throw new DeserializationException('Unexpected end of data reading symbol32 content');
        }
        return [substr($data, $position, $length), $position + $length];
    }

    // Compound type readers

    /** @return array{0: array<int, mixed>, 1: int} */
    private static function readList8(string $data, int $position, int $depth, int $maxDepth): array
    {
        if ($position + 1 >= strlen($data)) {
            throw new DeserializationException('Unexpected end of data reading list8 header');
        }
        $size = ord($data[$position]);
        $count = ord($data[$position + 1]);
        $position += 2;
        $endPosition = $position + $size - 1; // size includes the count byte

        $list = [];
        for ($i = 0; $i < $count; $i++) {
            if ($position > $endPosition) {
                throw new DeserializationException('List8 count exceeds available data');
            }
            [$value, $position] = self::decodeValue($data, $position, $depth + 1, $maxDepth);
            $list[] = $value;
        }

        return [$list, $position];
    }

    /** @return array{0: array<int, mixed>, 1: int} */
    private static function readList32(string $data, int $position, int $depth, int $maxDepth): array
    {
        if ($position + 7 >= strlen($data)) {
            throw new DeserializationException('Unexpected end of data reading list32 header');
        }
        $size = self::unpackIntAt('N', $data, $position, 'list32 size');
        $count = self::unpackIntAt('N', $data, $position + 4, 'list32 count');
        $position += 8;
        $endPosition = $position + $size - 4; // size includes the 4 count bytes

        // Security (#449): the 32-bit $count is attacker-supplied. A flat list is
        // depth 1, so the #397 recursion guard does not apply. Cap $count to the
        // bytes actually available in the content span before allocating: every
        // element is at least 1 byte (its format code), so a count larger than the
        // available bytes is malformed and cannot be satisfied without allocating
        // a multi-hundred-MB array from a small frame (OOM fatal).
        $available = $endPosition - $position + 1;
        if ($count > $available) {
            throw new DeserializationException(sprintf(
                'List32 count %d exceeds available bytes %d',
                $count,
                $available
            ));
        }
        // Security (#449): also cap honest large frames. When count truthfully
        // equals the available bytes (e.g. 8 M null elements in an 8 MiB frame),
        // the available-bytes guard above does not fire, but the loop still
        // builds a multi-hundred-MB array → uncatchable OOM fatal. A flat list
        // is depth 1, so the #397 recursion guard does not apply either.
        if ($count > self::MAX_COMPOUND_ELEMENTS) {
            throw new DeserializationException(sprintf(
                'List32 count %d exceeds maximum compound elements %d',
                $count,
                self::MAX_COMPOUND_ELEMENTS
            ));
        }

        $list = [];
        for ($i = 0; $i < $count; $i++) {
            if ($position > $endPosition) {
                throw new DeserializationException('List32 count exceeds available data');
            }
            [$value, $position] = self::decodeValue($data, $position, $depth + 1, $maxDepth);
            $list[] = $value;
        }

        return [$list, $position];
    }

    /** @return array{0: array<string|int, mixed>, 1: int} */
    private static function readMap8(string $data, int $position, int $depth, int $maxDepth): array
    {
        if ($position + 1 >= strlen($data)) {
            throw new DeserializationException('Unexpected end of data reading map8 header');
        }
        $size = ord($data[$position]);
        $count = ord($data[$position + 1]); // count is number of key-value pairs * 2
        $position += 2;
        $endPosition = $position + $size - 1; // size includes the count byte

        $map = [];
        $numPairs = (int)($count / 2);
        for ($i = 0; $i < $numPairs; $i++) {
            if ($position > $endPosition) {
                throw new DeserializationException('Map8 count exceeds available data');
            }
            [$key, $position] = self::decodeValue($data, $position, $depth + 1, $maxDepth);
            if ($position > $endPosition) {
                throw new DeserializationException('Map8 missing value for key');
            }
            [$value, $position] = self::decodeValue($data, $position, $depth + 1, $maxDepth);
            $mapKey = is_int($key) ? $key : (is_scalar($key) ? (string) $key : '');
            $map[$mapKey] = $value;
        }

        return [$map, $position];
    }

    /** @return array{0: array<string|int, mixed>, 1: int} */
    private static function readMap32(string $data, int $position, int $depth, int $maxDepth): array
    {
        if ($position + 7 >= strlen($data)) {
            throw new DeserializationException('Unexpected end of data reading map32 header');
        }
        $size = self::unpackIntAt('N', $data, $position, 'map32 size');
        $count = self::unpackIntAt('N', $data, $position + 4, 'map32 count');
        $position += 8;
        $endPosition = $position + $size - 4; // size includes the 4 count bytes

        // Security (#449): the 32-bit $count (total key+value elements, i.e. pairs*2)
        // is attacker-supplied. As with readList32, cap it to the bytes actually
        // available before allocating: every element is at least 1 byte (its format
        // code), so a count larger than the available bytes is malformed and would
        // otherwise allocate a multi-hundred-MB map from a small frame (OOM fatal).
        $available = $endPosition - $position + 1;
        if ($count > $available) {
            throw new DeserializationException(sprintf(
                'Map32 count %d exceeds available bytes %d',
                $count,
                $available
            ));
        }
        // Security (#449): also cap honest large frames — see readList32.
        if ($count > self::MAX_COMPOUND_ELEMENTS) {
            throw new DeserializationException(sprintf(
                'Map32 count %d exceeds maximum compound elements %d',
                $count,
                self::MAX_COMPOUND_ELEMENTS
            ));
        }

        $map = [];
        $numPairs = (int)($count / 2);
        for ($i = 0; $i < $numPairs; $i++) {
            if ($position > $endPosition) {
                throw new DeserializationException('Map32 count exceeds available data');
            }
            [$key, $position] = self::decodeValue($data, $position, $depth + 1, $maxDepth);
            if ($position > $endPosition) {
                throw new DeserializationException('Map32 missing value for key');
            }
            [$value, $position] = self::decodeValue($data, $position, $depth + 1, $maxDepth);
            $mapKey = is_int($key) ? $key : (is_scalar($key) ? (string) $key : '');
            $map[$mapKey] = $value;
        }

        return [$map, $position];
    }

    // Described type reader

    /** @return array{0: array{descriptor: mixed, value: mixed}, 1: int} */
    private static function readDescribedType(string $data, int $position, int $depth, int $maxDepth): array
    {
        [$descriptor, $position] = self::decodeValue($data, $position, $depth + 1, $maxDepth);
        [$value, $position] = self::decodeValue($data, $position, $depth + 1, $maxDepth);
        return [['descriptor' => $descriptor, 'value' => $value], $position];
    }

    /**
     * Read a described type and return [descriptor, value, newPosition].
     *
     * @return array{0: mixed, 1: mixed, 2: int}
     */
    private static function readDescribedTypeWithPosition(string $data, int $position, int $depth, int $maxDepth): array
    {
        // Skip the 0x00 marker (already checked by caller)
        $position++;

        // Read the descriptor
        [$descriptor, $position] = self::decodeValue($data, $position, $depth + 1, $maxDepth);

        // Read the value
        [$value, $position] = self::decodeValue($data, $position, $depth + 1, $maxDepth);

        return [$descriptor, $value, $position];
    }
}
