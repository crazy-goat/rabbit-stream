<?php

declare(strict_types=1);

namespace CrazyGoat\RabbitStream\Client;

use CrazyGoat\RabbitStream\Exception\DeserializationException;
use CrazyGoat\RabbitStream\Platform;

/**
 * Decoder for AMQP 1.0 values and message sections.
 *
 * ## Map keys
 *
 * An AMQP 1.0 map (`map8` 0xc1, `map32` 0xd1) may use any AMQP value as a key
 * (Part 1, §1.6.23 forbids duplicate key *values*, not non-scalar keys). PHP
 * arrays, however, accept only int and string keys, and the conversion is
 * lossy: `null`, a list, a map and `''` all become `''`; a symbol and a string
 * with the same characters become one key; `true`, `1.0` and `'1'` all become
 * the int `1`. Coercing keys therefore silently collapses distinct entries
 * (last one wins), which is data loss on untrusted broker data with no signal
 * to the caller.
 *
 * This decoder prefers failing loudly over silent corruption. A key that is not
 * an int or a string is rejected with a {@see DeserializationException} naming
 * the key type: a non-scalar key (null, list, map), or a float/bool key that PHP
 * would retype to int. A key that is an int or a string is also rejected when it
 * lands on the same PHP array key as an earlier key (a symbol and a string of
 * equal characters, an int and its numeric-string spelling, or an exact
 * duplicate), rather than overwriting that earlier entry (see
 * {@see addMapEntry()}). An int or string key that does not collide is kept, so
 * every ordinary map still decodes unchanged, including an empty string key. The
 * alternative, a lossless representation (a map value object or a list of
 * key/value pairs), is a larger design change and is not implemented here.
 */
class AmqpDecoder
{
    /**
     * Symbolic section descriptors (AMQP 1.0 Part 3) and their numeric codes.
     * They map to the same handling as the numeric codes 0x70-0x78.
     */
    private const SECTION_SYMBOLS = [
        'amqp:header:list' => 0x70,
        'amqp:delivery-annotations:map' => 0x71,
        'amqp:message-annotations:map' => 0x72,
        'amqp:properties:list' => 0x73,
        'amqp:application-properties:map' => 0x74,
        'amqp:data:binary' => 0x75,
        'amqp:amqp-sequence:list' => 0x76,
        'amqp:amqp-value:*' => 0x77,
        'amqp:footer:map' => 0x78,
    ];

    /**
     * Format codes of the list types: list0 (0x45), list8 (0xc0) and list32 (0xd0).
     */
    private const LIST_FORMAT_CODES = [0x45, 0xc0, 0xd0];

    /**
     * Default maximum nesting depth accepted while decoding (#397). Public so the
     * callers that thread an operator-supplied limit down to here — Consumer,
     * OsirisChunkParser, Message — can spell their own default as this one.
     */
    public const MAX_RECURSION_DEPTH = 32;

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
     * Upper limit on the elements of an array whose constructor takes no bytes per element
     * (null, boolean, zero numbers, empty list, #924). The content of such an array is the
     * constructor byte alone, so the limit is 8 elements. It keeps the decoded values of a
     * message proportional to its wire size, so a few bytes cannot expand into the whole
     * #947 budget. 8 is generous for real encoders, which send booleans as 0x56 and small arrays.
     */
    private const MAX_ZERO_WIDTH_ARRAY_ELEMENTS = 8;

    /**
     * Decoded-value budget for one message (or one decodeValue() call): the total of
     * `count + COMPOUND_OVERHEAD` over every list, map and array, nested ones
     * included, plus `2 + COMPOUND_OVERHEAD` for every described value. MAX_COMPOUND_ELEMENTS
     * caps one compound only, so a body of many compounds, each under that cap, still
     * grew without bound and ended in an uncatchable `Allowed memory size exhausted`
     * fatal (#947). When the budget is spent the decoder throws a catchable
     * DeserializationException instead.
     *
     * The budget bounds the number of decoded PHP values and their array overhead. It
     * does not bound the size of string payloads, which a message still pays for in
     * bytes. One unit is about 16 bytes of PHP array memory (a null element in a packed
     * array). 2 097 152 units = 16 x MAX_COMPOUND_ELEMENTS: a message may hold about 15
     * full-size compounds, well above any real AMQP message. Smaller compounds are
     * cheaper, so the budget is only reached by a body built to reach it.
     */
    private const MAX_DECODED_VALUES = 2097152;

    /**
     * Fixed cost, in budget units, of every compound on top of its element count.
     * An empty-ish PHP array costs ~250 bytes (about 16 units), so a body of many
     * tiny lists (a list8 of one null is 4 bytes on the wire) cannot slip under
     * the budget by having few elements per compound. Described values pay it too.
     */
    private const COMPOUND_OVERHEAD = 16;

    /**
     * Decode a single AMQP 1.0 value from the binary data at the given position.
     * Returns [value, newPosition].
     *
     * Kept as the public entry point for callers (tests, external users); the
     * internal decoding path goes through decodeValueInPlace(), which advances
     * `$position` in place and returns the plain value, so no throwaway
     * two-element array is allocated per decoded value (#405).
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
        $budget = self::MAX_DECODED_VALUES;
        $value = self::decodeValueInPlace($data, $position, $depth, $maxDepth, $budget);
        return [$value, $position];
    }

    /**
     * Decode a single AMQP 1.0 value, advancing `$position` past it in place and
     * returning the decoded value itself — no per-value tuple allocation (#405).
     *
     * @param int $depth current recursion depth (start at 0)
     * @param int $maxDepth maximum allowed recursion depth
     * @return mixed the decoded value, with $position advanced past it
     */
    private static function decodeValueInPlace(
        string $data,
        int &$position,
        int $depth,
        int $maxDepth,
        int &$budget
    ): mixed {
        if ($depth === 0) {
            // unpack('N'/'J') in the fixed-width readers would return floats on a
            // 32-bit build; checked once per top-level value, not per element (#458).
            Platform::assertSixtyFourBitIntegers();
        }
        if ($position >= strlen($data)) {
            throw new DeserializationException('Unexpected end of data');
        }

        $formatCode = ord($data[$position]);
        $position++;

        return self::decodeWithFormatCode($data, $formatCode, $position, $depth, $maxDepth, $budget);
    }

    /**
     * Decode the body of one value whose format code (constructor) is already read.
     * An array element has no format code of its own: the array carries one
     * constructor for all its elements (#924), so array elements call this directly.
     *
     * @param int $depth current recursion depth of the value
     * @param int $maxDepth maximum allowed recursion depth
     */
    private static function decodeWithFormatCode(
        string $data,
        int $formatCode,
        int &$position,
        int $depth,
        int $maxDepth,
        int &$budget
    ): mixed {
        if ($depth > $maxDepth) {
            throw new DeserializationException(sprintf('AMQP recursion depth limit exceeded (max %d)', $maxDepth));
        }

        return match ($formatCode) {
            // Fixed-width types
            0x40 => null, // null
            0x41 => true, // boolean true
            0x42 => false, // boolean false
            0x43 => 0, // uint zero
            0x44 => 0, // ulong zero
            0x45 => [], // list0 (empty list)
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
            0xc0 => self::readList8($data, $position, $depth, $maxDepth, $budget), // list8
            0xc1 => self::readMap8($data, $position, $depth, $maxDepth, $budget), // map8

            // Compound types (32-bit length)
            0xd0 => self::readList32($data, $position, $depth, $maxDepth, $budget), // list32
            0xd1 => self::readMap32($data, $position, $depth, $maxDepth, $budget), // map32

            // Array types (#464)
            0xe0 => self::readArray8($data, $position, $depth, $maxDepth, $budget), // array8
            0xf0 => self::readArray32($data, $position, $depth, $maxDepth, $budget), // array32

            // Described type
            0x00 => self::readDescribedType($data, $position, $depth, $maxDepth, $budget),

            default => throw new DeserializationException(sprintf('Unsupported AMQP type: 0x%02x', $formatCode)),
        };
    }

    /**
     * Decode a full AMQP 1.0 message into sections.
     * Returns ['header' => [...], 'properties' => [...], 'applicationProperties' => [...],
     *          'messageAnnotations' => [...], 'body' => string|mixed]
     *
     * A Properties section (0x73) whose value is a map, a scalar or a described value
     * (not list0, list8 or list32) throws a DeserializationException. A null value
     * decodes as no properties. The forms below are tolerated malformed input (issue
     * #944). The decoder accepts them and does not throw:
     * - A Properties section (0x73) whose value is null (0x40) decodes as [].
     * - A Data section (0x75) whose value is str8 or sym8 (AMQP 1.0 requires binary).
     *   The string bytes become the body.
     * - An AmqpValue (0x77) or AmqpSequence (0x76) section mixed with Data sections in
     *   one message (AMQP 1.0 allows one body kind). Data bytes after a string AmqpValue
     *   are joined to it. A Data section after an AmqpSequence or a non-string AmqpValue
     *   gives the body, and the earlier body is discarded. A Data section before an
     *   AmqpValue is replaced by it.
     *
     * @param int $maxDepth maximum allowed recursion depth
     * @return array<string, mixed>
     */
    public static function decodeMessage(string $data, int $maxDepth = self::MAX_RECURSION_DEPTH): array
    {
        Platform::assertSixtyFourBitIntegers();

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
        $budget = self::MAX_DECODED_VALUES;
        $dataLength = strlen($data);
        $bodyParts = [];
        $isAccumulatingDataBody = false;

        while ($position < $dataLength) {
            // Check for described type marker
            if (ord($data[$position]) !== 0x00) {
                throw new DeserializationException(sprintf(
                    'Expected described type marker (0x00) at position %d, got 0x%02x. '
                    . 'The payload is not an AMQP 1.0 message — this is what a raw, '
                    . 'un-framed body looks like, e.g. an entry published by rabbit-stream '
                    . 'before #413 that is still sitting in the stream. Such entries cannot '
                    . 'be decoded; read them as raw bytes instead, via '
                    . 'OsirisChunkParser::parseEntries() and ChunkEntry::getData().',
                    $position,
                    ord($data[$position])
                ));
            }

            // Read the described type (skip the 0x00 marker; depth 0 -> 1)
            $position++;
            $valueFormatCode = null;
            ['descriptor' => $descriptor, 'value' => $value] = self::readDescribedType(
                $data,
                $position,
                0,
                $maxDepth,
                $budget,
                $valueFormatCode
            );

            // Match descriptor to section
            switch (self::sectionCode($descriptor)) {
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
                    // Tolerated (#944): a null value decodes as no properties, as before.
                    if ($valueFormatCode === 0x40) {
                        $sections['properties'] = [];
                        break;
                    }
                    // AMQP 1.0 defines Properties as a composite type with a list source.
                    // A map or a scalar would otherwise give empty or shifted fields.
                    // Every decoded list passes array_is_list(); it also narrows the type.
                    if (
                        !is_array($value)
                        || !array_is_list($value)
                        || !in_array($valueFormatCode, self::LIST_FORMAT_CODES, true)
                    ) {
                        throw new DeserializationException(sprintf(
                            'Properties section (0x73) must be a list, got format code 0x%02x',
                            $valueFormatCode ?? 0x00
                        ));
                    }
                    $sections['properties'] = self::parsePropertiesList($value);
                    break;

                case 0x74: // ApplicationProperties
                    $sections['applicationProperties'] = $value;
                    break;

                case 0x75: // Data (body)
                    // AMQP 1.0 defines Data as binary, so anything else is malformed.
                    // Silently dropping it (the pre-#452 behaviour) handed the caller
                    // an empty body with no exception and no way to notice the loss.
                    if (!is_string($value)) {
                        throw new DeserializationException(sprintf(
                            'Data section (0x75) must carry binary data, got %s',
                            get_debug_type($value)
                        ));
                    }
                    if (!$isAccumulatingDataBody) {
                        $currentBody = $sections['body'];
                        $bodyParts = is_string($currentBody) && $currentBody !== '' ? [$currentBody] : [];
                        $isAccumulatingDataBody = true;
                    }
                    $bodyParts[] = $value;
                    break;

                case 0x76: // AmqpSequence (body)
                case 0x77: // AmqpValue (body)
                    if ($isAccumulatingDataBody) {
                        $bodyParts = [];
                        $isAccumulatingDataBody = false;
                    }
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

        if ($isAccumulatingDataBody) {
            $sections['body'] = implode('', $bodyParts);
        }

        return $sections;
    }

    /**
     * Map a section descriptor to its numeric code (0x70-0x78).
     *
     * Only an int (ulong) or a symbol name matches. Any other type, such as a
     * boolean or a string that looks like a number, matches no section.
     *
     * @return int|null the section code, or null when the descriptor names no known section
     */
    private static function sectionCode(mixed $descriptor): ?int
    {
        if (is_int($descriptor)) {
            return $descriptor;
        }

        if (is_string($descriptor)) {
            return self::SECTION_SYMBOLS[$descriptor] ?? null;
        }

        return null;
    }

    /**
     * Parse Properties list (descriptor 0x73) into named fields.
     * A list with more than the 13 fields defined by AMQP 1.0 is rejected
     * instead of silently discarding the trailing values.
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

        if (count($list) > count($propertyNames)) {
            throw new DeserializationException(sprintf(
                'Properties section (0x73) contains %d fields; maximum is %d',
                count($list),
                count($propertyNames)
            ));
        }

        $properties = [];
        foreach ($list as $index => $value) {
            if (isset($propertyNames[$index]) && $value !== null) {
                $properties[$propertyNames[$index]] = $value;
            }
        }

        return $properties;
    }

    // Fixed-width type readers

    private static function readUint8(string $data, int &$position): int
    {
        if ($position >= strlen($data)) {
            throw new DeserializationException('Unexpected end of data reading uint8');
        }
        $value = ord($data[$position]);
        $position++;
        return $value;
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

    private static function readInt8(string $data, int &$position): int
    {
        if ($position >= strlen($data)) {
            throw new DeserializationException('Unexpected end of data reading int8');
        }
        $value = self::unpackIntAt('c', $data, $position, 'int8');
        $position++;
        return $value;
    }

    private static function readUint16(string $data, int &$position): int
    {
        if ($position + 1 >= strlen($data)) {
            throw new DeserializationException('Unexpected end of data reading uint16');
        }
        $value = self::unpackIntAt('n', $data, $position, 'uint16');
        $position += 2;
        return $value;
    }

    private static function readInt16(string $data, int &$position): int
    {
        if ($position + 1 >= strlen($data)) {
            throw new DeserializationException('Unexpected end of data reading int16');
        }
        $value = self::unpackIntAt('n', $data, $position, 'int16');
        $position += 2;
        if ($value >= 0x8000) {
            $value -= 0x10000;
        }
        return $value;
    }

    private static function readUint32(string $data, int &$position): int
    {
        if ($position + 3 >= strlen($data)) {
            throw new DeserializationException('Unexpected end of data reading uint32');
        }
        $value = self::unpackIntAt('N', $data, $position, 'uint32');
        $position += 4;
        return $value;
    }

    private static function readInt32(string $data, int &$position): int
    {
        if ($position + 3 >= strlen($data)) {
            throw new DeserializationException('Unexpected end of data reading int32');
        }
        $value = self::unpackIntAt('N', $data, $position, 'int32');
        $position += 4;
        if ($value >= 0x80000000) {
            $value -= 0x100000000;
        }
        return $value;
    }

    private static function readFloat(string $data, int &$position): float
    {
        if ($position + 3 >= strlen($data)) {
            throw new DeserializationException('Unexpected end of data reading float');
        }
        $value = self::unpackFloatAt('G', $data, $position, 'float');
        $position += 4;
        return $value;
    }

    private static function readUint64(string $data, int &$position): int
    {
        if ($position + 7 >= strlen($data)) {
            throw new DeserializationException('Unexpected end of data reading uint64');
        }
        $value = self::unpackIntAt('J', $data, $position, 'uint64');
        if ($value < 0) {
            throw new DeserializationException(sprintf(
                'uint64 value 0x%s at position %d exceeds PHP_INT_MAX',
                bin2hex(substr($data, $position, 8)),
                $position
            ));
        }
        $position += 8;
        return $value;
    }

    private static function readInt64(string $data, int &$position): int
    {
        if ($position + 7 >= strlen($data)) {
            throw new DeserializationException('Unexpected end of data reading int64');
        }
        // 'J' unpacks as a native 64-bit PHP int, which is already signed (PHP has
        // no unsigned 64-bit type), so no manual sign correction is needed here —
        // unlike the 16/32-bit readers, where 'n'/'N' return an unsigned value.
        $value = self::unpackIntAt('J', $data, $position, 'int64');
        $position += 8;
        return $value;
    }

    private static function readDouble(string $data, int &$position): float
    {
        if ($position + 7 >= strlen($data)) {
            throw new DeserializationException('Unexpected end of data reading double');
        }
        $value = self::unpackFloatAt('E', $data, $position, 'double');
        $position += 8;
        return $value;
    }

    private static function readTimestamp(string $data, int &$position): int
    {
        if ($position + 7 >= strlen($data)) {
            throw new DeserializationException('Unexpected end of data reading timestamp');
        }
        // Timestamp is milliseconds since Unix epoch (int64); see readInt64() for
        // why no manual sign correction is needed for a 'J' unpack.
        $value = self::unpackIntAt('J', $data, $position, 'timestamp');
        $position += 8;
        return $value;
    }

    private static function readUuid(string $data, int &$position): string
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
        $position += 16;
        return sprintf(
            '%08x-%04x-%04x-%04x-%012x',
            $p1,
            $p2,
            $p3,
            $p4,
            $p5a * 65536 + $p5b
        );
    }

    private static function readBoolean(string $data, int &$position): bool
    {
        if ($position >= strlen($data)) {
            throw new DeserializationException('Unexpected end of data reading boolean');
        }
        $value = ord($data[$position]) !== 0;
        $position++;
        return $value;
    }

    // Variable-width type readers

    private static function readBinary8(string $data, int &$position): string
    {
        if ($position >= strlen($data)) {
            throw new DeserializationException('Unexpected end of data reading binary8 length');
        }
        $length = ord($data[$position]);
        $position++;
        if ($position + $length > strlen($data)) {
            throw new DeserializationException('Unexpected end of data reading binary8 content');
        }
        $value = substr($data, $position, $length);
        $position += $length;
        return $value;
    }

    private static function readBinary32(string $data, int &$position): string
    {
        if ($position + 3 >= strlen($data)) {
            throw new DeserializationException('Unexpected end of data reading binary32 length');
        }
        $length = self::unpackIntAt('N', $data, $position, 'binary32 length');
        $position += 4;
        // Written as a subtraction, never as $position + $length: on 32-bit PHP an
        // attacker-supplied 32-bit $length near PHP_INT_MAX makes that addition
        // overflow to a negative int and skip the check entirely (#451).
        // strlen($data) - $position is always >= 0 here, so it cannot overflow.
        if ($length < 0 || $length > strlen($data) - $position) {
            throw new DeserializationException('Unexpected end of data reading binary32 content');
        }
        $value = substr($data, $position, $length);
        $position += $length;
        return $value;
    }

    private static function readString8(string $data, int &$position): string
    {
        if ($position >= strlen($data)) {
            throw new DeserializationException('Unexpected end of data reading string8 length');
        }
        $length = ord($data[$position]);
        $position++;
        if ($position + $length > strlen($data)) {
            throw new DeserializationException('Unexpected end of data reading string8 content');
        }
        $value = substr($data, $position, $length);
        $position += $length;
        return $value;
    }

    private static function readString32(string $data, int &$position): string
    {
        if ($position + 3 >= strlen($data)) {
            throw new DeserializationException('Unexpected end of data reading string32 length');
        }
        $length = self::unpackIntAt('N', $data, $position, 'string32 length');
        $position += 4;
        // Written as a subtraction, never as $position + $length: on 32-bit PHP an
        // attacker-supplied 32-bit $length near PHP_INT_MAX makes that addition
        // overflow to a negative int and skip the check entirely (#451).
        // strlen($data) - $position is always >= 0 here, so it cannot overflow.
        if ($length < 0 || $length > strlen($data) - $position) {
            throw new DeserializationException('Unexpected end of data reading string32 content');
        }
        $value = substr($data, $position, $length);
        $position += $length;
        return $value;
    }

    private static function readSymbol8(string $data, int &$position): string
    {
        if ($position >= strlen($data)) {
            throw new DeserializationException('Unexpected end of data reading symbol8 length');
        }
        $length = ord($data[$position]);
        $position++;
        if ($position + $length > strlen($data)) {
            throw new DeserializationException('Unexpected end of data reading symbol8 content');
        }
        $value = substr($data, $position, $length);
        $position += $length;
        return $value;
    }

    private static function readSymbol32(string $data, int &$position): string
    {
        if ($position + 3 >= strlen($data)) {
            throw new DeserializationException('Unexpected end of data reading symbol32 length');
        }
        $length = self::unpackIntAt('N', $data, $position, 'symbol32 length');
        $position += 4;
        // Written as a subtraction, never as $position + $length: on 32-bit PHP an
        // attacker-supplied 32-bit $length near PHP_INT_MAX makes that addition
        // overflow to a negative int and skip the check entirely (#451).
        // strlen($data) - $position is always >= 0 here, so it cannot overflow.
        if ($length < 0 || $length > strlen($data) - $position) {
            throw new DeserializationException('Unexpected end of data reading symbol32 content');
        }
        $value = substr($data, $position, $length);
        $position += $length;
        return $value;
    }

    // Compound type readers

    /**
     * Content window of a compound type: the position of the first byte after its
     * content. EXCLUSIVE, so `$position >= $contentEnd` means "no content left"
     * and `$contentEnd - $position` is exactly the number of bytes still available.
     *
     * The declared `size` covers the count field plus the content, so the content
     * is `$size - $countWidth` bytes long. The pre-#488 code stored
     * `$position + $size - $countWidth` as an *inclusive* bound, i.e. one byte past
     * the real last content byte, which made every window — and the #449
     * available-bytes cap computed from it — one byte too generous.
     *
     * @param int $countWidth 1 for the 8-bit compounds, 4 for the 32-bit ones
     * @param string $what compound name, for exception messages
     */
    private static function compoundContentEnd(
        string $data,
        int $position,
        int $size,
        int $countWidth,
        string $what
    ): int {
        if ($size < $countWidth) {
            throw new DeserializationException(sprintf(
                '%s size %d is smaller than its own %d-byte count field',
                $what,
                $size,
                $countWidth
            ));
        }
        $declaredContent = $size - $countWidth;
        $remaining = strlen($data) - $position;
        if ($declaredContent > $remaining) {
            throw new DeserializationException(sprintf(
                '%s declares %d content bytes but only %d remain in the frame',
                $what,
                $declaredContent,
                $remaining
            ));
        }

        return $position + $declaredContent;
    }

    /**
     * Verify that the element loop consumed the compound's declared content
     * exactly (#453). Before this check a lying `size` was silently accepted: the
     * per-element guards only stop the loop from *starting* another element past
     * the window, so the last element could still read beyond it (individual reads
     * are bounded by strlen($data), not by the compound's own size), and a `size`
     * larger than the elements actually present left the cursor short of the end,
     * which then desynchronised whatever the caller decoded next.
     *
     * @param string $what compound name, for exception messages
     */
    private static function assertCompoundConsumed(int $position, int $contentEnd, int $size, string $what): void
    {
        if ($position === $contentEnd) {
            return;
        }

        throw new DeserializationException(sprintf(
            '%s size mismatch: declared %d bytes, elements consumed %d',
            $what,
            $size,
            $size + ($position - $contentEnd)
        ));
    }

    /**
     * #449 element-count guard shared by the 32-bit compound readers
     * (readList32 / readMap32 / readArray32) and by readArrayElements() for
     * array8 (#924). The 32-bit $count is attacker-supplied. A list or map element
     * has its own format code, so it takes at least 1 byte: a count larger than
     * the available content bytes is malformed and cannot be satisfied without
     * allocating a multi-hundred-MB array from a small frame. An array element
     * has no format code of its own, so readArrayElements() passes $available
     * as the bytes of its elements; for a zero-width constructor that is the
     * count itself, and the zero-width bound applies instead. The element cap
     * applies in both cases. A flat compound is depth 1, so the #397 recursion
     * guard does not apply — cap both before any allocation.
     *
     * @param string $what compound name, for exception messages
     */
    private static function assertCompoundCount(int $count, int $available, string $what): void
    {
        if ($count > $available) {
            throw new DeserializationException(sprintf(
                '%s count %d exceeds available bytes %d',
                $what,
                $count,
                $available
            ));
        }
        if ($count > self::MAX_COMPOUND_ELEMENTS) {
            throw new DeserializationException(sprintf(
                '%s count %d exceeds maximum compound elements %d',
                $what,
                $count,
                self::MAX_COMPOUND_ELEMENTS
            ));
        }
    }

    /**
     * Charge one compound against the per-message budget before its array is built (#947).
     *
     * @param int $budget remaining units, reduced in place
     * @param int $count number of elements the compound declares
     * @param string $what compound name, for exception messages
     */
    private static function chargeBudget(int &$budget, int $count, string $what): void
    {
        $cost = $count + self::COMPOUND_OVERHEAD;
        if ($cost > $budget) {
            throw new DeserializationException(sprintf(
                'Decoded value limit exceeded at %s (limit %d): the message holds too many list, map and array values',
                $what,
                self::MAX_DECODED_VALUES
            ));
        }
        $budget -= $cost;
    }

    /**
     * @return array<int, mixed>
     */
    private static function readList8(string $data, int &$position, int $depth, int $maxDepth, int &$budget): array
    {
        if ($position + 1 >= strlen($data)) {
            throw new DeserializationException('Unexpected end of data reading list8 header');
        }
        $size = ord($data[$position]);
        $count = ord($data[$position + 1]);
        $position += 2;
        $contentEnd = self::compoundContentEnd($data, $position, $size, 1, 'List8');
        self::chargeBudget($budget, $count, 'List8');

        $list = [];
        for ($i = 0; $i < $count; $i++) {
            if ($position >= $contentEnd) {
                throw new DeserializationException('List8 count exceeds available data');
            }
            $list[] = self::decodeValueInPlace($data, $position, $depth + 1, $maxDepth, $budget);
        }
        self::assertCompoundConsumed($position, $contentEnd, $size, 'List8');

        return $list;
    }

    /**
     * @return array<int, mixed>
     */
    private static function readList32(string $data, int &$position, int $depth, int $maxDepth, int &$budget): array
    {
        if ($position + 7 >= strlen($data)) {
            throw new DeserializationException('Unexpected end of data reading list32 header');
        }
        $size = self::unpackIntAt('N', $data, $position, 'list32 size');
        $count = self::unpackIntAt('N', $data, $position + 4, 'list32 count');
        $position += 8;
        $contentEnd = self::compoundContentEnd($data, $position, $size, 4, 'List32');

        // Security (#449): the 32-bit $count is attacker-supplied. A flat list is
        // depth 1, so the #397 recursion guard does not apply — see
        // assertCompoundCount().
        self::assertCompoundCount($count, $contentEnd - $position, 'List32');
        self::chargeBudget($budget, $count, 'List32');

        $list = [];
        for ($i = 0; $i < $count; $i++) {
            if ($position >= $contentEnd) {
                throw new DeserializationException('List32 count exceeds available data');
            }
            $list[] = self::decodeValueInPlace($data, $position, $depth + 1, $maxDepth, $budget);
        }
        self::assertCompoundConsumed($position, $contentEnd, $size, 'List32');

        return $list;
    }

    /**
     * @return array<string|int, mixed>
     */
    private static function readMap8(string $data, int &$position, int $depth, int $maxDepth, int &$budget): array
    {
        if ($position + 1 >= strlen($data)) {
            throw new DeserializationException('Unexpected end of data reading map8 header');
        }
        $size = ord($data[$position]);
        $count = ord($data[$position + 1]); // count is number of key-value pairs * 2
        $position += 2;
        $contentEnd = self::compoundContentEnd($data, $position, $size, 1, 'Map8');
        self::assertEvenMapCount($count, 'Map8');
        self::chargeBudget($budget, $count, 'Map8');

        $map = [];
        $numPairs = intdiv($count, 2);
        for ($i = 0; $i < $numPairs; $i++) {
            if ($position >= $contentEnd) {
                throw new DeserializationException('Map8 count exceeds available data');
            }
            $key = self::decodeValueInPlace($data, $position, $depth + 1, $maxDepth, $budget);
            if ($position >= $contentEnd) {
                throw new DeserializationException('Map8 missing value for key');
            }
            $value = self::decodeValueInPlace($data, $position, $depth + 1, $maxDepth, $budget);
            self::addMapEntry($map, $key, $value, 'Map8');
        }
        self::assertCompoundConsumed($position, $contentEnd, $size, 'Map8');

        return $map;
    }

    /**
     * AMQP 1.0 array8 (0xe0) / array32 (0xf0) (#464): a size in bytes (which
     * includes the count field), then an element count, then one element
     * constructor shared by all elements, then the element bodies without
     * format codes (#924). The declared size bounds decoding exactly like the
     * compound readers do. The element count is guarded by readArrayElements():
     * a non-zero-width element takes at least 1 byte, so the count must fit in
     * the remaining bytes, and a zero-width element is limited to
     * MAX_ZERO_WIDTH_ARRAY_ELEMENTS elements. Both are capped by MAX_COMPOUND_ELEMENTS.
     *
     * @return list<mixed>
     */
    private static function readArray8(string $data, int &$position, int $depth, int $maxDepth, int &$budget): array
    {
        if ($position + 1 >= strlen($data)) {
            throw new DeserializationException('Unexpected end of data reading array8 header');
        }
        $size = ord($data[$position]);
        $count = ord($data[$position + 1]);
        $position += 2;
        $contentEnd = self::compoundContentEnd($data, $position, $size, 1, 'Array8');

        return self::readArrayElements(
            $data,
            $position,
            $size,
            $count,
            $contentEnd,
            $depth,
            $maxDepth,
            $budget,
            'Array8'
        );
    }

    /**
     * Read the elements of an array8 or array32 (#924). The array has one element
     * constructor after the count; every element is a body built with it, and no
     * element has a format code.
     *
     * The count guard depends on the constructor:
     * - A non-zero-width element takes at least 1 byte, so the count must not exceed
     *   the content bytes after the constructor.
     * - A zero-width element (null, boolean, zero number, empty list) takes no bytes,
     *   so the count is limited to MAX_ZERO_WIDTH_ARRAY_ELEMENTS (8) instead. Without
     *   this limit, a 13-byte array32 of 131072 nulls would decode into 131072 values,
     *   and the #947 budget would then allow that cost for every message.
     *
     * @return list<mixed>
     */
    private static function readArrayElements(
        string $data,
        int &$position,
        int $size,
        int $count,
        int $contentEnd,
        int $depth,
        int $maxDepth,
        int &$budget,
        string $what
    ): array {
        self::chargeBudget($budget, $count, $what);
        if ($count === 0) {
            self::assertCompoundConsumed($position, $contentEnd, $size, $what);

            return [];
        }
        if ($position >= $contentEnd) {
            throw new DeserializationException($what . ' constructor is missing');
        }
        $formatCode = ord($data[$position]);
        $position++;
        if (self::isZeroWidthConstructor($formatCode)) {
            if ($count > self::MAX_ZERO_WIDTH_ARRAY_ELEMENTS) {
                throw new DeserializationException(sprintf(
                    '%s count %d exceeds the limit of %d zero-width elements',
                    $what,
                    $count,
                    self::MAX_ZERO_WIDTH_ARRAY_ELEMENTS
                ));
            }
            // The byte guard of assertCompoundCount() does not apply: a zero-width element has no bytes.
            self::assertCompoundCount($count, $count, $what);
        } else {
            self::assertCompoundCount($count, $contentEnd - $position, $what);
        }

        $array = [];
        for ($i = 0; $i < $count; $i++) {
            $array[] = self::decodeWithFormatCode($data, $formatCode, $position, $depth + 1, $maxDepth, $budget);
            if ($position > $contentEnd) {
                throw new DeserializationException($what . ' count exceeds available data');
            }
        }
        self::assertCompoundConsumed($position, $contentEnd, $size, $what);

        return $array;
    }

    /**
     * Format codes whose value has no body: a constructor of this kind takes no bytes per element.
     */
    private static function isZeroWidthConstructor(int $formatCode): bool
    {
        return in_array($formatCode, [0x40, 0x41, 0x42, 0x43, 0x44, 0x45], true);
    }

    /** @return list<mixed> */
    private static function readArray32(string $data, int &$position, int $depth, int $maxDepth, int &$budget): array
    {
        if ($position + 7 >= strlen($data)) {
            throw new DeserializationException('Unexpected end of data reading array32 header');
        }
        $size = self::unpackIntAt('N', $data, $position, 'array32 size');
        $count = self::unpackIntAt('N', $data, $position + 4, 'array32 count');
        $position += 8;
        $contentEnd = self::compoundContentEnd($data, $position, $size, 4, 'Array32');

        // Security (#449): the 32-bit $count is attacker-supplied; readArrayElements()
        // caps it before the loop allocates anything. A flat array is depth 1, so
        // the #397 recursion guard does not apply.
        return self::readArrayElements(
            $data,
            $position,
            $size,
            $count,
            $contentEnd,
            $depth,
            $maxDepth,
            $budget,
            'Array32'
        );
    }

    /**
     * @return array<string|int, mixed>
     */
    private static function readMap32(string $data, int &$position, int $depth, int $maxDepth, int &$budget): array
    {
        if ($position + 7 >= strlen($data)) {
            throw new DeserializationException('Unexpected end of data reading map32 header');
        }
        $size = self::unpackIntAt('N', $data, $position, 'map32 size');
        $count = self::unpackIntAt('N', $data, $position + 4, 'map32 count');
        $position += 8;
        $contentEnd = self::compoundContentEnd($data, $position, $size, 4, 'Map32');

        // Security (#449): the 32-bit $count (total key+value elements, i.e. pairs*2)
        // is attacker-supplied. As with readList32, see assertCompoundCount().
        self::assertCompoundCount($count, $contentEnd - $position, 'Map32');

        self::assertEvenMapCount($count, 'Map32');
        self::chargeBudget($budget, $count, 'Map32');

        $map = [];
        $numPairs = intdiv($count, 2);
        for ($i = 0; $i < $numPairs; $i++) {
            if ($position >= $contentEnd) {
                throw new DeserializationException('Map32 count exceeds available data');
            }
            $key = self::decodeValueInPlace($data, $position, $depth + 1, $maxDepth, $budget);
            if ($position >= $contentEnd) {
                throw new DeserializationException('Map32 missing value for key');
            }
            $value = self::decodeValueInPlace($data, $position, $depth + 1, $maxDepth, $budget);
            self::addMapEntry($map, $key, $value, 'Map32');
        }
        self::assertCompoundConsumed($position, $contentEnd, $size, 'Map32');

        return $map;
    }

    /**
     * An AMQP map's `count` is the total number of key AND value elements, so it
     * must be even. Before #489 an odd count was truncated with intdiv-like
     * `(int)($count / 2)`, which accepted the malformed frame and silently dropped
     * the orphan key instead of rejecting it.
     *
     * @param string $what compound name, for exception messages
     */
    private static function assertEvenMapCount(int $count, string $what): void
    {
        if ($count % 2 !== 0) {
            throw new DeserializationException(sprintf(
                '%s count must be even (keys and values are counted separately), got %d',
                $what,
                $count
            ));
        }
    }

    /**
     * Convert a decoded AMQP map key to a PHP array key, or reject it (#525).
     *
     * AMQP 1.0 (§1.6.23) allows a key of any type, but PHP arrays take only int
     * and string keys and retype the rest, so a lossy conversion makes distinct
     * keys collide and silently drop entries: `null`, a list, a map and `''` all
     * became `''`; a symbol and a string of the same characters became one key;
     * and `true`, `1.0` and `'1'` all became the int `1`. Only int and string keys
     * survive the conversion untouched, so those are kept and anything else —
     * a non-scalar key (null, list, map, …) or a float/bool key PHP would retype
     * to int — is rejected with a message naming the key type.
     *
     * @param string $what compound name, for exception messages
     * @throws DeserializationException when the key is neither an int nor a string
     */
    private static function toPhpMapKey(mixed $key, string $what): int|string
    {
        if (is_int($key) || is_string($key)) {
            return $key;
        }

        if (is_float($key) || is_bool($key)) {
            // PHP would retype these to an int key (true -> 1, 1.0 -> 1, ...),
            // which is exactly how a `true`/`1.0`/`'1'` triple collapses to one.
            throw new DeserializationException(sprintf(
                '%s key of type %s cannot be represented as a PHP array key without colliding '
                . '(PHP would retype it to an integer key); keys of type int and string are supported',
                $what,
                get_debug_type($key)
            ));
        }

        throw new DeserializationException(sprintf(
            '%s key of type %s cannot be represented as a PHP array key '
            . '(only int and string keys are supported)',
            $what,
            get_debug_type($key)
        ));
    }

    /**
     * Add one key/value pair to a map being decoded, refusing to overwrite an
     * earlier entry (#525).
     *
     * Two AMQP keys can reach the same PHP array key without being the same
     * value: a symbol and a string with equal characters, or an int and the
     * string spelling of that int. Assigning would silently drop the earlier
     * entry, so a collision is rejected instead. Identical key values are also
     * rejected, because AMQP 1.0 (§1.6.23) requires a map's keys to be distinct
     * and silently keeping only the last one loses data too.
     *
     * @param array<int|string, mixed> $map
     * @param string $what compound name, for exception messages
     * @throws DeserializationException when the key collides with an earlier one
     */
    private static function addMapEntry(array &$map, mixed $key, mixed $value, string $what): void
    {
        $mapKey = self::toPhpMapKey($key, $what);

        if (array_key_exists($mapKey, $map)) {
            throw new DeserializationException(sprintf(
                '%s key of type %s collides with an earlier key after conversion to a PHP array key '
                . '(%s); refusing to overwrite the earlier entry',
                $what,
                get_debug_type($key),
                is_string($mapKey) ? "'" . $mapKey . "'" : (string) $mapKey
            ));
        }

        $map[$mapKey] = $value;
    }

    // Described type reader

    /**
     * @return array{descriptor: mixed, value: mixed}
     */
    private static function readDescribedType(
        string $data,
        int &$position,
        int $depth,
        int $maxDepth,
        int &$budget,
        ?int &$valueFormatCode = null
    ): array {
        // A described value becomes a two-member PHP array, so it is charged like a compound.
        self::chargeBudget($budget, 2, 'Described type');
        $descriptor = self::decodeValueInPlace($data, $position, $depth + 1, $maxDepth, $budget);
        // The format code of the value tells a list from a map, which PHP arrays do not.
        $valueFormatCode = $position < strlen($data) ? ord($data[$position]) : null;
        $value = self::decodeValueInPlace($data, $position, $depth + 1, $maxDepth, $budget);
        return ['descriptor' => $descriptor, 'value' => $value];
    }
}
