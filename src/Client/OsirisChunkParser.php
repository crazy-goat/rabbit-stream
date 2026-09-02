<?php

declare(strict_types=1);

namespace CrazyGoat\RabbitStream\Client;

use CrazyGoat\RabbitStream\Buffer\ReadBuffer;
use CrazyGoat\RabbitStream\Exception\DeserializationException;
use CrazyGoat\RabbitStream\Exception\InvalidArgumentException;

/**
 * Parses RabbitMQ Stream chunk binary format (Osiris chunk format).
 *
 * Chunk header layout (48 bytes):
 *   1 byte  - magicVersion: high nibble = magic (must be 5), low nibble = version (must be 0)
 *   1 byte  - chunkType: 0 = user data
 *   2 bytes - numEntries: number of entries in this chunk
 *   4 bytes - numRecords: total records across all entries
 *   8 bytes - timestamp: milliseconds since Unix epoch
 *   8 bytes - epoch: leader epoch
 *   8 bytes - chunkFirstOffset: stream offset of the first entry in this chunk
 *   4 bytes - chunkCrc: CRC-32 of the chunk data
 *   4 bytes - dataLength: length of entries data section
 *   4 bytes - trailerLength: on-disk length of trailer section (bytes are omitted from Deliver frames)
 *   1 byte  - bloomSize: on-disk size of the bloom filter section (bytes are omitted from Deliver frames)
 *   3 bytes - reserved (alignment to 4 bytes)
 *
 * The data section (dataLength bytes) follows the header immediately; the trailer
 * (trailerLength bytes) follows it on disk. On the stream-protocol wire, Deliver
 * frames transmit header + data only for user-data chunks (osiris
 * `select_amount_to_send(user_data, ?CHNK_USER, ...)` skips the bloom bytes and
 * omits the trailer), while the header still declares their on-disk sizes — so
 * `bloomSize` and `trailerLength` are informational here, and a nonzero value
 * with no bytes behind it is legitimate. Entry parsing is strictly bounded to
 * the data section; bytes outside it (bloom, trailer, padding) are never read.
 *
 * Each entry:
 *   Simple entry: 4-byte header (bit 31 = 0) + size in lower 31 bits + data
 *   Sub-batch entry: 1-byte header (bit 7 = 1, codec in bits 6-4) + numRecords (uint16)
 *                    + uncompressedSize (uint32) + compressedSize (uint32) + sub-batch data
 *
 * @see https://github.com/rabbitmq/rabbitmq-server/blob/main/deps/rabbitmq_stream/docs/PROTOCOL.adoc
 * @see https://github.com/rabbitmq/rabbitmq-server/blob/main/deps/osiris/src/osiris_log.erl
 */
class OsirisChunkParser
{
    /**
     * Default ceiling for entries produced from a single chunk.
     *
     * A chunk is received in a single frame; the server does not enforce
     * `frame_max` on Deliver frames, so the broker's chunk-flush batching is the
     * practical cap on chunk size. 262 144 is the theoretical record maximum of
     * a 1 MiB data section at the 4-byte minimum per-entry wire cost (a sub-batch
     * inner entry is just a uint32 size prefix); real AMQP records cost at least
     * ~6 bytes including the prefix, so reaching the cap needs a >1.5 MiB chunk
     * of near-empty records — a workload no current broker produces. Exceeding
     * the cap therefore means the chunk is hostile or pathological, and failing
     * loud with a DeserializationException (rather than exhausting memory) is
     * the intended behavior. Callers with a genuinely larger workload can raise
     * it per call via $maxEntriesPerChunk.
     */
    private const DEFAULT_MAX_ENTRIES_PER_CHUNK = 262144;

    /**
     * @param int $maxEntriesPerChunk Hard ceiling for entries produced from one chunk.
     *                                Defaults to DEFAULT_MAX_ENTRIES_PER_CHUNK, which
     *                                is ~7.5x below the ~1.9M records an 8 MB
     *                                amplification chunk can hold
     *                                (see issue #399); pass a larger value only if a
     *                                known workload requires it.
     * @param int $offset Absolute offset of the chunk within $chunkBytes. Defaults to 0
     *                     (the whole string is the chunk), so existing callers passing a
     *                     plain chunk string are unaffected. Pass a nonzero offset (with
     *                     $length) to parse a chunk that lives inside a larger buffer
     *                     (e.g. a whole Deliver frame) without copying it out first.
     * @param ?int $length Chunk length; defaults to everything from $offset to the end
     *                     of $chunkBytes.
     * @return ChunkEntry[]
     * @throws DeserializationException If the chunk violates the declared sizes, declares
     *                                  implausible record counts, or exceeds the entry ceiling
     * @throws InvalidArgumentException If $maxEntriesPerChunk is less than 1
     */
    public static function parse(
        string $chunkBytes,
        int $maxEntriesPerChunk = self::DEFAULT_MAX_ENTRIES_PER_CHUNK,
        int $offset = 0,
        ?int $length = null
    ): array {
        return iterator_to_array(self::parseEntries($chunkBytes, $maxEntriesPerChunk, $offset, $length), false);
    }

    /**
     * Same wire-format parsing as parse(), but yields ChunkEntry instances one at a
     * time instead of materialising the whole entry list, so a caller that only
     * needs the first few entries (or wants to wrap each one lazily, e.g. into a
     * lazily-decoded Message) never allocates the rest.
     *
     * @param int $maxEntriesPerChunk See parse().
     * @param int $offset See parse().
     * @param ?int $length See parse().
     * @return \Generator<int, ChunkEntry>
     * @throws DeserializationException See parse().
     * @throws InvalidArgumentException See parse().
     */
    public static function parseEntries(
        string $chunkBytes,
        int $maxEntriesPerChunk = self::DEFAULT_MAX_ENTRIES_PER_CHUNK,
        int $offset = 0,
        ?int $length = null
    ): \Generator {
        $entries = self::parseRaw($chunkBytes, $maxEntriesPerChunk, $offset, $length);
        foreach ($entries as [$entryOffset, $data, $timestamp]) {
            yield new ChunkEntry($entryOffset, $data, $timestamp);
        }
    }

    /**
     * Same wire-format parsing as parse(), but yields fully-formed Message
     * instances directly — one per chunk entry — without ever allocating an
     * intermediate ChunkEntry, and without copying any entry's payload out of
     * $chunkBytes: each Message is built via {@see Message::fromChunkView()} as a
     * zero-copy view sharing $chunkBytes (PHP strings are refcounted, so every
     * Message from one chunk just bumps that one buffer's refcount instead of
     * substr()-copying its own entry out of it) — including sub-batch entries,
     * whose inner records are views into $chunkBytes too, not into a copied
     * sub-batch buffer. This is the hot path for Consumer's deliver callback: for
     * every entry it saves one ChunkEntry object, one payload copy, plus the extra
     * indirection of AmqpMessageDecoder::decode() per message.
     *
     * @param int $maxEntriesPerChunk See parse().
     * @param int $offset See parse().
     * @param ?int $length See parse().
     * @param ?string $stream Name of the stream the chunk was delivered from, set once per
     *                        yielded Message (see {@see Message::getStream()}); null when unknown.
     * @return \Generator<int, Message>
     * @throws DeserializationException See parse().
     * @throws InvalidArgumentException See parse().
     */
    public static function parseMessages(
        string $chunkBytes,
        int $maxEntriesPerChunk = self::DEFAULT_MAX_ENTRIES_PER_CHUNK,
        int $offset = 0,
        ?int $length = null,
        ?string $stream = null,
    ): \Generator {
        $views = self::parseRawViews($chunkBytes, $maxEntriesPerChunk, $offset, $length);
        foreach ($views as [$entryOffset, $timestamp, $start, $len]) {
            yield Message::fromChunkView($entryOffset, $timestamp, $chunkBytes, $start, $len, $stream);
        }
    }

    /**
     * Shared parsing core behind parseEntries() and parseMessages(): does all
     * chunk-header and entry validation and yields raw [offset, data, timestamp]
     * tuples, so neither caller duplicates the wire-format logic. The entry loop
     * operates directly on the data-section string with a local integer cursor
     * (unpack()/ord() with explicit bounds checks) rather than going through
     * ReadBuffer's per-call overhead, since this loop runs once per message.
     *
     * @param int $maxEntriesPerChunk See parse().
     * @param int $offset See parse().
     * @param ?int $length See parse().
     * @return \Generator<int, array{0: int, 1: string, 2: int}>
     * @throws DeserializationException See parse().
     * @throws InvalidArgumentException See parse().
     */
    private static function parseRaw(
        string $chunkBytes,
        int $maxEntriesPerChunk,
        int $offset = 0,
        ?int $length = null
    ): \Generator {
        [$numEntries, $timestamp, $chunkFirstOffset, $pos, $dataEnd] =
            self::parseChunkHeader($chunkBytes, $maxEntriesPerChunk, $offset, $length);

        $entryCount = 0;
        $currentOffset = $chunkFirstOffset;

        for ($i = 0; $i < $numEntries; $i++) {
            if ($entryCount >= $maxEntriesPerChunk) {
                throw self::entryLimitExceeded($maxEntriesPerChunk);
            }

            $entryType = self::readUint8At($chunkBytes, $dataEnd, $pos);
            $isSubBatch = ($entryType & 0x80) !== 0;

            if (!$isSubBatch) {
                $entrySize = (($entryType & 0x7F) << 24)
                    | (self::readUint16At($chunkBytes, $dataEnd, $pos) << 8)
                    | self::readUint8At($chunkBytes, $dataEnd, $pos);
                $entryData = self::readBytesAt($chunkBytes, $dataEnd, $pos, $entrySize);
                yield [$currentOffset, $entryData, $timestamp];
                $entryCount++;
                $currentOffset++;
            } else {
                $codec = ($entryType >> 4) & 0x07;

                if ($codec !== 0) {
                    throw new DeserializationException(sprintf(
                        'Compressed sub-batches not supported yet (codec: %d)',
                        $codec
                    ));
                }

                $subBatchRecords = self::readUint16At($chunkBytes, $dataEnd, $pos);
                $uncompressedSize = self::readUint32At($chunkBytes, $dataEnd, $pos);

                // Each record costs at least 4 bytes both in the bytes actually
                // present and in the declared uncompressed size (a uint32 size
                // prefix). The broker enforces the latter on publish
                // (rabbit_stream_utils.erl check_message_count_fits_uncompressed_size)
                // but NOT that uncompressedSize equals the on-wire body size
                // (compressedSize), so equality is deliberately not required.
                $compressedSize = self::readUint32At($chunkBytes, $dataEnd, $pos);

                if ($subBatchRecords * 4 > $compressedSize) {
                    throw new DeserializationException(sprintf(
                        'Sub-batch declares %d records but only %d bytes of data (minimum 4 bytes per record)',
                        $subBatchRecords,
                        $compressedSize
                    ));
                }

                if ($subBatchRecords * 4 > $uncompressedSize) {
                    throw new DeserializationException(sprintf(
                        'Sub-batch declares %d records but uncompressedSize %d cannot hold them ' .
                        '(minimum 4 bytes per record)',
                        $subBatchRecords,
                        $uncompressedSize
                    ));
                }

                // The sub-batch payload IS message data (it holds the inner records
                // read below), so this copy stays — unlike the outer data-section
                // substr() removed above, it is proportional to real message bytes.
                $subBatchData = self::readBytesAt($chunkBytes, $dataEnd, $pos, $compressedSize);

                $subLen = $compressedSize;
                $subPos = 0;
                for ($j = 0; $j < $subBatchRecords; $j++) {
                    if ($entryCount >= $maxEntriesPerChunk) {
                        throw self::entryLimitExceeded($maxEntriesPerChunk);
                    }
                    $innerSize = self::readUint32At($subBatchData, $subLen, $subPos);
                    $innerData = self::readBytesAt($subBatchData, $subLen, $subPos, $innerSize);
                    yield [$currentOffset, $innerData, $timestamp];
                    $entryCount++;
                    $currentOffset++;
                }
            }
        }
    }

    /**
     * Parses and validates the chunk header (magic/version/type, declared entry
     * and record counts, data-section bounds), shared by parseRaw() and
     * parseRawViews() so neither duplicates the header wire-format logic.
     *
     * @param int $maxEntriesPerChunk See parse().
     * @param int $offset See parse().
     * @param ?int $length See parse().
     * @return array{0: int, 1: int, 2: int, 3: int, 4: int} [numEntries, timestamp,
     *         chunkFirstOffset, pos (absolute start of the data section), dataEnd
     *         (absolute end of the data section)]
     * @throws DeserializationException See parse().
     * @throws InvalidArgumentException See parse().
     */
    private static function parseChunkHeader(
        string $chunkBytes,
        int $maxEntriesPerChunk,
        int $offset = 0,
        ?int $length = null
    ): array {
        if ($maxEntriesPerChunk < 1) {
            throw new InvalidArgumentException('maxEntriesPerChunk must be at least 1');
        }

        $buffer = new ReadBuffer($chunkBytes, $offset, $length);
        $windowLength = $length ?? (strlen($chunkBytes) - $offset);

        $magicVersion = $buffer->getUint8();
        $magic = ($magicVersion >> 4) & 0x0F;
        $version = $magicVersion & 0x0F;
        if ($magic !== 5) {
            throw new DeserializationException(sprintf(
                'Invalid chunk magic: expected 5, got %d (raw byte: 0x%02x)',
                $magic,
                $magicVersion
            ));
        }
        if ($version !== 0) {
            throw new DeserializationException(sprintf('Unsupported chunk version: expected 0, got %d', $version));
        }

        $chunkType = $buffer->getUint8();
        if ($chunkType !== 0) {
            throw new DeserializationException(
                sprintf('Unsupported chunk type: expected 0 (user data), got %d', $chunkType)
            );
        }

        $numEntries = $buffer->getUint16();      // Number of entries in chunk
        $numRecords = $buffer->getUint32();      // Total record count across all entries
        $timestamp = $buffer->getInt64();        // Chunk timestamp
        $buffer->getUint64();                     // epoch
        $chunkFirstOffset = $buffer->getUint64();  // First offset in chunk
        $buffer->getInt32();                      // chunkCrc
        $dataLength = $buffer->getUint32();      // Size of the data (entries) section
        $buffer->getUint32();                     // trailerLength — informational only (see class docblock)
        $buffer->getUint8();                      // bloomSize — informational only (see class docblock)
        $buffer->readBytes(3);                    // reserved

        // getPosition() is window-relative; the data section is addressed with
        // absolute offsets into $chunkBytes below, so convert once here.
        $headerSize = $offset + $buffer->getPosition();

        // A chunk that declares more records than the client will ever accept is
        // rejected up front, before any entry is allocated.
        if ($numRecords > $maxEntriesPerChunk) {
            throw new DeserializationException(sprintf(
                'Chunk declares %d records, exceeding the maximum of %d entries per chunk',
                $numRecords,
                $maxEntriesPerChunk
            ));
        }

        // The declared data section must fit inside the received chunk window. The
        // trailerLength and bloomSize fields are deliberately NOT part of this
        // check: Deliver frames omit the bloom and trailer bytes for user-data
        // chunks while the header still declares their on-disk sizes, so those
        // fields can be nonzero with no bytes behind them (see class docblock).
        $chunkEnd = $offset + $windowLength;
        if ($headerSize + $dataLength > $chunkEnd) {
            throw new DeserializationException(sprintf(
                'Chunk size mismatch: header (%d) + dataLength (%d) = %d exceeds the %d received bytes',
                $headerSize - $offset,
                $dataLength,
                $headerSize + $dataLength - $offset,
                $windowLength
            ));
        }

        // Bound entry parsing to exactly the declared data section, so entries
        // can never spill into the trailer or past the received bytes. Read
        // directly out of $chunkBytes with an absolute cursor (unpack()/ord(),
        // no substr() copy of the data section) instead of a ReadBuffer, since
        // this loop runs once per message rather than once per chunk (#484).
        $dataEnd = $headerSize + $dataLength;

        return [$numEntries, $timestamp, $chunkFirstOffset, $headerSize, $dataEnd];
    }

    /**
     * Same wire-format parsing as parseRaw(), but yields [offset, timestamp, start,
     * length] VIEWS into $chunkBytes instead of extracted [offset, data, timestamp]
     * tuples: no entry's bytes are ever substr()-copied out of $chunkBytes here,
     * plain entries and sub-batch inner entries alike (unlike parseRaw(), which
     * still substr()s the sub-batch payload since ChunkEntry needs real strings).
     * This is what lets parseMessages() build one Message per entry that shares
     * the whole chunk buffer instead of copying its own payload out of it.
     *
     * @param int $maxEntriesPerChunk See parse().
     * @param int $offset See parse().
     * @param ?int $length See parse().
     * @return \Generator<int, array{0: int, 1: int, 2: int, 3: int}>
     * @throws DeserializationException See parse().
     * @throws InvalidArgumentException See parse().
     */
    private static function parseRawViews(
        string $chunkBytes,
        int $maxEntriesPerChunk,
        int $offset = 0,
        ?int $length = null
    ): \Generator {
        [$numEntries, $timestamp, $chunkFirstOffset, $pos, $dataEnd] =
            self::parseChunkHeader($chunkBytes, $maxEntriesPerChunk, $offset, $length);

        $entryCount = 0;
        $currentOffset = $chunkFirstOffset;

        for ($i = 0; $i < $numEntries; $i++) {
            if ($entryCount >= $maxEntriesPerChunk) {
                throw self::entryLimitExceeded($maxEntriesPerChunk);
            }

            $entryType = self::readUint8At($chunkBytes, $dataEnd, $pos);
            $isSubBatch = ($entryType & 0x80) !== 0;

            if (!$isSubBatch) {
                $entrySize = (($entryType & 0x7F) << 24)
                    | (self::readUint16At($chunkBytes, $dataEnd, $pos) << 8)
                    | self::readUint8At($chunkBytes, $dataEnd, $pos);
                $entryStart = self::checkBytesAt($dataEnd, $pos, $entrySize);
                yield [$currentOffset, $timestamp, $entryStart, $entrySize];
                $entryCount++;
                $currentOffset++;
            } else {
                $codec = ($entryType >> 4) & 0x07;

                if ($codec !== 0) {
                    throw new DeserializationException(sprintf(
                        'Compressed sub-batches not supported yet (codec: %d)',
                        $codec
                    ));
                }

                $subBatchRecords = self::readUint16At($chunkBytes, $dataEnd, $pos);
                $uncompressedSize = self::readUint32At($chunkBytes, $dataEnd, $pos);
                $compressedSize = self::readUint32At($chunkBytes, $dataEnd, $pos);

                if ($subBatchRecords * 4 > $compressedSize) {
                    throw new DeserializationException(sprintf(
                        'Sub-batch declares %d records but only %d bytes of data (minimum 4 bytes per record)',
                        $subBatchRecords,
                        $compressedSize
                    ));
                }

                if ($subBatchRecords * 4 > $uncompressedSize) {
                    throw new DeserializationException(sprintf(
                        'Sub-batch declares %d records but uncompressedSize %d cannot hold them ' .
                        '(minimum 4 bytes per record)',
                        $subBatchRecords,
                        $uncompressedSize
                    ));
                }

                // No substr() here: the sub-batch payload is addressed as a range
                // [$subBatchStart, $subBatchEnd) inside $chunkBytes itself, so inner
                // entries stay zero-copy views into the very same chunk buffer.
                $subBatchStart = self::checkBytesAt($dataEnd, $pos, $compressedSize);
                $subBatchEnd = $subBatchStart + $compressedSize;
                // $pos now sits at $subBatchEnd (checkBytesAt already advanced it past
                // the whole sub-batch), correctly positioned for the outer loop once
                // this inner loop is done; the inner loop cursors separately with
                // $subPos, starting back at $subBatchStart.
                $subPos = $subBatchStart;

                for ($j = 0; $j < $subBatchRecords; $j++) {
                    if ($entryCount >= $maxEntriesPerChunk) {
                        throw self::entryLimitExceeded($maxEntriesPerChunk);
                    }
                    $innerSize = self::readUint32At($chunkBytes, $subBatchEnd, $subPos);
                    $innerStart = self::checkBytesAt($subBatchEnd, $subPos, $innerSize);
                    yield [$currentOffset, $timestamp, $innerStart, $innerSize];
                    $entryCount++;
                    $currentOffset++;
                }
            }
        }
    }

    /**
     * Read a uint8 at $pos in $data (of known length $len), matching
     * ReadBuffer::getUint8()'s bounds-check exception exactly, and advance $pos.
     */
    private static function readUint8At(string $data, int $len, int &$pos): int
    {
        $available = $len - $pos;
        if ($available < 1) {
            throw new DeserializationException(sprintf(
                'Buffer underflow: need %d bytes at position %d, but only %d available',
                1,
                $pos,
                $available
            ));
        }
        $value = ord($data[$pos]);
        $pos++;
        return $value;
    }

    /**
     * Read a big-endian uint16 at $pos in $data (of known length $len), matching
     * ReadBuffer::getUint16()'s bounds-check exception exactly, and advance $pos.
     */
    private static function readUint16At(string $data, int $len, int &$pos): int
    {
        $available = $len - $pos;
        if ($available < 2) {
            throw new DeserializationException(sprintf(
                'Buffer underflow: need %d bytes at position %d, but only %d available',
                2,
                $pos,
                $available
            ));
        }
        $unpacked = unpack('n', $data, $pos);
        if ($unpacked === false) {
            throw new DeserializationException('Failed to unpack uint16 at position ' . $pos);
        }
        $pos += 2;
        return $unpacked[1];
    }

    /**
     * Read a big-endian uint32 at $pos in $data (of known length $len), matching
     * ReadBuffer::getUint32()'s bounds-check exception exactly, and advance $pos.
     */
    private static function readUint32At(string $data, int $len, int &$pos): int
    {
        $available = $len - $pos;
        if ($available < 4) {
            throw new DeserializationException(sprintf(
                'Buffer underflow: need %d bytes at position %d, but only %d available',
                4,
                $pos,
                $available
            ));
        }
        $unpacked = unpack('N', $data, $pos);
        if ($unpacked === false) {
            throw new DeserializationException('Failed to unpack uint32 at position ' . $pos);
        }
        $pos += 4;
        return $unpacked[1];
    }

    /**
     * Read $length bytes at $pos in $data (of known length $len), matching
     * ReadBuffer::readBytes()'s exceptions exactly, and advance $pos.
     */
    private static function readBytesAt(string $data, int $len, int &$pos, int $length): string
    {
        if ($length < 0) {
            throw new DeserializationException(
                sprintf('Invalid read length %d at position %d', $length, $pos)
            );
        }
        $available = $len - $pos;
        if ($length > $available) {
            throw new DeserializationException(
                sprintf(
                    'Buffer underflow: need %d bytes at position %d, but only %d available',
                    $length,
                    $pos,
                    $available
                )
            );
        }
        $result = substr($data, $pos, $length);
        $pos += $length;
        return $result;
    }

    /**
     * Same bounds-check and $pos advance as readBytesAt(), but returns the
     * (pre-advance) start position instead of substr()-ing the bytes out — used by
     * parseRawViews() to validate an entry's range without copying it.
     */
    private static function checkBytesAt(int $len, int &$pos, int $length): int
    {
        if ($length < 0) {
            throw new DeserializationException(
                sprintf('Invalid read length %d at position %d', $length, $pos)
            );
        }
        $available = $len - $pos;
        if ($length > $available) {
            throw new DeserializationException(
                sprintf(
                    'Buffer underflow: need %d bytes at position %d, but only %d available',
                    $length,
                    $pos,
                    $available
                )
            );
        }
        $start = $pos;
        $pos += $length;
        return $start;
    }

    private static function entryLimitExceeded(int $maxEntriesPerChunk): DeserializationException
    {
        return new DeserializationException(sprintf(
            'Chunk contains more than %d entries (the maximum allowed per chunk)',
            $maxEntriesPerChunk
        ));
    }
}
