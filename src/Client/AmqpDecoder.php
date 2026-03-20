<?php

declare(strict_types=1);

namespace CrazyGoat\RabbitStream\Client;

class AmqpDecoder
{
    /**
     * Decode a single AMQP 1.0 value from the binary data at the given position.
     * Returns [value, newPosition].
     *
     * @return array{0: mixed, 1: int}
     */
    public static function decodeValue(string $data, int $position): array
    {
        if ($position >= strlen($data)) {
            throw new \RuntimeException('Unexpected end of data');
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
            0xc0 => self::readList8($data, $position), // list8
            0xc1 => self::readMap8($data, $position), // map8

            // Compound types (32-bit length)
            0xd0 => self::readList32($data, $position), // list32
            0xd1 => self::readMap32($data, $position), // map32

            // Described type
            0x00 => self::readDescribedType($data, $position),

            default => throw new \RuntimeException(sprintf('Unsupported AMQP type: 0x%02x', $formatCode)),
        };
    }

    /**
     * Decode a full AMQP 1.0 message into sections.
     * Returns ['header' => [...], 'properties' => [...], 'applicationProperties' => [...],
     *          'messageAnnotations' => [...], 'body' => string|mixed]
     *
     * @return array<string, mixed>
     */
    public static function decodeMessage(string $data): array
    {
        if ($data === '') {
            throw new \RuntimeException('Empty message data');
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
                throw new \RuntimeException(sprintf(
                    'Expected described type marker (0x00) at position %d, got 0x%02x',
                    $position,
                    ord($data[$position])
                ));
            }

            // Read the described type
            [$descriptor, $value, $position] = self::readDescribedTypeWithPosition($data, $position);

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
            throw new \RuntimeException('Unexpected end of data reading uint8');
        }
        return [ord($data[$position]), $position + 1];
    }

    /**
     * @return array<int|string, mixed>
     */
    private static function safeUnpack(string $format, string $data, string $context): array
    {
        $result = unpack($format, $data);
        if ($result === false) {
            throw new \RuntimeException('Failed to unpack ' . $context);
        }
        return $result;
    }

    private static function unpackInt(string $format, string $data, string $context): int
    {
        $val = self::safeUnpack($format, $data, $context)[1];
        return is_scalar($val) ? (int) $val : 0;
    }

    private static function unpackFloat(string $format, string $data, string $context): float
    {
        $val = self::safeUnpack($format, $data, $context)[1];
        return is_scalar($val) ? (float) $val : 0.0;
    }

    /** @return array{0: int, 1: int} */
    private static function readInt8(string $data, int $position): array
    {
        if ($position >= strlen($data)) {
            throw new \RuntimeException('Unexpected end of data reading int8');
        }
        $value = self::unpackInt('c', $data[$position], 'int8');
        return [$value, $position + 1];
    }

    /** @return array{0: int, 1: int} */
    private static function readUint16(string $data, int $position): array
    {
        if ($position + 1 >= strlen($data)) {
            throw new \RuntimeException('Unexpected end of data reading uint16');
        }
        $value = self::unpackInt('n', substr($data, $position, 2), 'uint16');
        return [$value, $position + 2];
    }

    /** @return array{0: int, 1: int} */
    private static function readInt16(string $data, int $position): array
    {
        if ($position + 1 >= strlen($data)) {
            throw new \RuntimeException('Unexpected end of data reading int16');
        }
        $value = self::unpackInt('s', strrev(substr($data, $position, 2)), 'int16');
        return [$value, $position + 2];
    }

    /** @return array{0: int, 1: int} */
    private static function readUint32(string $data, int $position): array
    {
        if ($position + 3 >= strlen($data)) {
            throw new \RuntimeException('Unexpected end of data reading uint32');
        }
        $value = self::unpackInt('N', substr($data, $position, 4), 'uint32');
        // Handle unsigned 32-bit values > PHP_INT_MAX
        if ($value < 0) {
            $value += 4294967296;
        }
        return [$value, $position + 4];
    }

    /** @return array{0: int, 1: int} */
    private static function readInt32(string $data, int $position): array
    {
        if ($position + 3 >= strlen($data)) {
            throw new \RuntimeException('Unexpected end of data reading int32');
        }
        $value = self::unpackInt('l', strrev(substr($data, $position, 4)), 'int32');
        return [$value, $position + 4];
    }

    /** @return array{0: float, 1: int} */
    private static function readFloat(string $data, int $position): array
    {
        if ($position + 3 >= strlen($data)) {
            throw new \RuntimeException('Unexpected end of data reading float');
        }
        $value = self::unpackFloat('f', strrev(substr($data, $position, 4)), 'float');
        return [$value, $position + 4];
    }

    /** @return array{0: int, 1: int} */
    private static function readUint64(string $data, int $position): array
    {
        if ($position + 7 >= strlen($data)) {
            throw new \RuntimeException('Unexpected end of data reading uint64');
        }
        $high = self::unpackInt('N', substr($data, $position, 4), 'uint64 high');
        $low = self::unpackInt('N', substr($data, $position + 4, 4), 'uint64 low');
        // Handle as string for large values
        if ($high < 0) {
            $high += 4294967296;
        }
        if ($low < 0) {
            $low += 4294967296;
        }
        $value = ($high << 32) | $low;
        return [$value, $position + 8];
    }

    /** @return array{0: int, 1: int} */
    private static function readInt64(string $data, int $position): array
    {
        if ($position + 7 >= strlen($data)) {
            throw new \RuntimeException('Unexpected end of data reading int64');
        }
        $value = self::unpackInt('q', strrev(substr($data, $position, 8)), 'int64');
        return [$value, $position + 8];
    }

    /** @return array{0: float, 1: int} */
    private static function readDouble(string $data, int $position): array
    {
        if ($position + 7 >= strlen($data)) {
            throw new \RuntimeException('Unexpected end of data reading double');
        }
        $value = self::unpackFloat('d', strrev(substr($data, $position, 8)), 'double');
        return [$value, $position + 8];
    }

    /** @return array{0: int, 1: int} */
    private static function readTimestamp(string $data, int $position): array
    {
        if ($position + 7 >= strlen($data)) {
            throw new \RuntimeException('Unexpected end of data reading timestamp');
        }
        // Timestamp is milliseconds since Unix epoch (int64)
        $value = self::unpackInt('q', strrev(substr($data, $position, 8)), 'timestamp');
        return [$value, $position + 8];
    }

    /** @return array{0: string, 1: int} */
    private static function readUuid(string $data, int $position): array
    {
        if ($position + 15 >= strlen($data)) {
            throw new \RuntimeException('Unexpected end of data reading uuid');
        }
        $bytes = substr($data, $position, 16);
        // Format as UUID string: 8-4-4-4-12 hex digits
        $p1 = self::unpackInt('N', substr($bytes, 0, 4), 'uuid part1');
        $p2 = self::unpackInt('n', substr($bytes, 4, 2), 'uuid part2');
        $p3 = self::unpackInt('n', substr($bytes, 6, 2), 'uuid part3');
        $p4 = self::unpackInt('n', substr($bytes, 8, 2), 'uuid part4');
        $p5a = self::unpackInt('N', substr($bytes, 10, 4), 'uuid part5a');
        $p5b = self::unpackInt('n', substr($bytes, 14, 2), 'uuid part5b');
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
            throw new \RuntimeException('Unexpected end of data reading boolean');
        }
        return [ord($data[$position]) !== 0, $position + 1];
    }

    // Variable-width type readers

    /** @return array{0: string, 1: int} */
    private static function readBinary8(string $data, int $position): array
    {
        if ($position >= strlen($data)) {
            throw new \RuntimeException('Unexpected end of data reading binary8 length');
        }
        $length = ord($data[$position]);
        $position++;
        if ($position + $length > strlen($data)) {
            throw new \RuntimeException('Unexpected end of data reading binary8 content');
        }
        return [substr($data, $position, $length), $position + $length];
    }

    /** @return array{0: string, 1: int} */
    private static function readBinary32(string $data, int $position): array
    {
        if ($position + 3 >= strlen($data)) {
            throw new \RuntimeException('Unexpected end of data reading binary32 length');
        }
        $length = self::unpackInt('N', substr($data, $position, 4), 'binary32 length');
        if ($length < 0) {
            $length += 4294967296;
        }
        $position += 4;
        if ($position + $length > strlen($data)) {
            throw new \RuntimeException('Unexpected end of data reading binary32 content');
        }
        return [substr($data, $position, $length), $position + $length];
    }

    /** @return array{0: string, 1: int} */
    private static function readString8(string $data, int $position): array
    {
        if ($position >= strlen($data)) {
            throw new \RuntimeException('Unexpected end of data reading string8 length');
        }
        $length = ord($data[$position]);
        $position++;
        if ($position + $length > strlen($data)) {
            throw new \RuntimeException('Unexpected end of data reading string8 content');
        }
        return [substr($data, $position, $length), $position + $length];
    }

    /** @return array{0: string, 1: int} */
    private static function readString32(string $data, int $position): array
    {
        if ($position + 3 >= strlen($data)) {
            throw new \RuntimeException('Unexpected end of data reading string32 length');
        }
        $length = self::unpackInt('N', substr($data, $position, 4), 'string32 length');
        if ($length < 0) {
            $length += 4294967296;
        }
        $position += 4;
        if ($position + $length > strlen($data)) {
            throw new \RuntimeException('Unexpected end of data reading string32 content');
        }
        return [substr($data, $position, $length), $position + $length];
    }

    /** @return array{0: string, 1: int} */
    private static function readSymbol8(string $data, int $position): array
    {
        if ($position >= strlen($data)) {
            throw new \RuntimeException('Unexpected end of data reading symbol8 length');
        }
        $length = ord($data[$position]);
        $position++;
        if ($position + $length > strlen($data)) {
            throw new \RuntimeException('Unexpected end of data reading symbol8 content');
        }
        return [substr($data, $position, $length), $position + $length];
    }

    /** @return array{0: string, 1: int} */
    private static function readSymbol32(string $data, int $position): array
    {
        if ($position + 3 >= strlen($data)) {
            throw new \RuntimeException('Unexpected end of data reading symbol32 length');
        }
        $length = self::unpackInt('N', substr($data, $position, 4), 'symbol32 length');
        if ($length < 0) {
            $length += 4294967296;
        }
        $position += 4;
        if ($position + $length > strlen($data)) {
            throw new \RuntimeException('Unexpected end of data reading symbol32 content');
        }
        return [substr($data, $position, $length), $position + $length];
    }

    // Compound type readers

    /** @return array{0: array<int, mixed>, 1: int} */
    private static function readList8(string $data, int $position): array
    {
        if ($position + 1 >= strlen($data)) {
            throw new \RuntimeException('Unexpected end of data reading list8 header');
        }
        $size = ord($data[$position]);
        $count = ord($data[$position + 1]);
        $position += 2;
        $endPosition = $position + $size - 1; // size includes the count byte

        $list = [];
        for ($i = 0; $i < $count; $i++) {
            if ($position > $endPosition) {
                throw new \RuntimeException('List8 count exceeds available data');
            }
            [$value, $position] = self::decodeValue($data, $position);
            $list[] = $value;
        }

        return [$list, $position];
    }

    /** @return array{0: array<int, mixed>, 1: int} */
    private static function readList32(string $data, int $position): array
    {
        if ($position + 7 >= strlen($data)) {
            throw new \RuntimeException('Unexpected end of data reading list32 header');
        }
        $size = self::unpackInt('N', substr($data, $position, 4), 'list32 size');
        if ($size < 0) {
            $size += 4294967296;
        }
        $count = self::unpackInt('N', substr($data, $position + 4, 4), 'list32 count');
        if ($count < 0) {
            $count += 4294967296;
        }
        $position += 8;
        $endPosition = $position + $size - 4; // size includes the 4 count bytes

        $list = [];
        for ($i = 0; $i < $count; $i++) {
            if ($position > $endPosition) {
                throw new \RuntimeException('List32 count exceeds available data');
            }
            [$value, $position] = self::decodeValue($data, $position);
            $list[] = $value;
        }

        return [$list, $position];
    }

    /** @return array{0: array<string|int, mixed>, 1: int} */
    private static function readMap8(string $data, int $position): array
    {
        if ($position + 1 >= strlen($data)) {
            throw new \RuntimeException('Unexpected end of data reading map8 header');
        }
        $size = ord($data[$position]);
        $count = ord($data[$position + 1]); // count is number of key-value pairs * 2
        $position += 2;
        $endPosition = $position + $size - 1; // size includes the count byte

        $map = [];
        $numPairs = (int)($count / 2);
        for ($i = 0; $i < $numPairs; $i++) {
            if ($position > $endPosition) {
                throw new \RuntimeException('Map8 count exceeds available data');
            }
            [$key, $position] = self::decodeValue($data, $position);
            if ($position > $endPosition) {
                throw new \RuntimeException('Map8 missing value for key');
            }
            [$value, $position] = self::decodeValue($data, $position);
            $mapKey = is_int($key) ? $key : (is_scalar($key) ? (string) $key : '');
            $map[$mapKey] = $value;
        }

        return [$map, $position];
    }

    /** @return array{0: array<string|int, mixed>, 1: int} */
    private static function readMap32(string $data, int $position): array
    {
        if ($position + 7 >= strlen($data)) {
            throw new \RuntimeException('Unexpected end of data reading map32 header');
        }
        $size = self::unpackInt('N', substr($data, $position, 4), 'map32 size');
        if ($size < 0) {
            $size += 4294967296;
        }
        $count = self::unpackInt('N', substr($data, $position + 4, 4), 'map32 count');
        if ($count < 0) {
            $count += 4294967296;
        }
        $position += 8;
        $endPosition = $position + $size - 4; // size includes the 4 count bytes

        $map = [];
        $numPairs = (int)($count / 2);
        for ($i = 0; $i < $numPairs; $i++) {
            if ($position > $endPosition) {
                throw new \RuntimeException('Map32 count exceeds available data');
            }
            [$key, $position] = self::decodeValue($data, $position);
            if ($position > $endPosition) {
                throw new \RuntimeException('Map32 missing value for key');
            }
            [$value, $position] = self::decodeValue($data, $position);
            $mapKey = is_int($key) ? $key : (is_scalar($key) ? (string) $key : '');
            $map[$mapKey] = $value;
        }

        return [$map, $position];
    }

    // Described type reader

    /** @return array{0: array{descriptor: mixed, value: mixed}, 1: int} */
    private static function readDescribedType(string $data, int $position): array
    {
        [$descriptor, $position] = self::decodeValue($data, $position);
        [$value, $position] = self::decodeValue($data, $position);
        return [['descriptor' => $descriptor, 'value' => $value], $position];
    }

    /**
     * Read a described type and return [descriptor, value, newPosition].
     *
     * @return array{0: mixed, 1: mixed, 2: int}
     */
    private static function readDescribedTypeWithPosition(string $data, int $position): array
    {
        // Skip the 0x00 marker (already checked by caller)
        $position++;

        // Read the descriptor
        [$descriptor, $position] = self::decodeValue($data, $position);

        // Read the value
        [$value, $position] = self::decodeValue($data, $position);

        return [$descriptor, $value, $position];
    }
}
