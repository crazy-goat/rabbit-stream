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
 * Tolerated malformed input (issue #944): bytes inside the data section that follow
 * the last declared entry, or the last declared record of a sub-batch, are ignored
 * without an error. The chunk CRC covers those bytes, so the CRC does not flag them.
 * The parser reads only the declared counts, so every entry it yields is one of the
 * declared entries.
 *
 * Each entry:
 *   Simple entry: 4-byte header (bit 31 = 0) + size in lower 31 bits + data
 *   Sub-batch entry: 1-byte header (bit 7 = 1, codec in bits 6-4) + numRecords (uint16)
 *                    + uncompressedSize (uint32) + compressedSize (uint32) + sub-batch data
 *
 * Sub-batch codecs: 0 (NONE) and 1 (GZIP, RFC 1952 gzip stream) are decoded. Codecs 2 to 7
 * (SNAPPY, LZ4, ZSTD, and reserved values) throw DeserializationException. A gzip sub-batch
 * must inflate to exactly its declared uncompressedSize, and the inflate stops at that size
 * (issue #399). The declared uncompressedSize values of all gzip sub-batches in one chunk
 * total at most 32 MiB (33 554 432 bytes). The parser checks this total before it inflates
 * anything, so one chunk cannot make it allocate more than that budget. Decoding gzip needs
 * the ext-zlib extension.
 *
 * @see https://github.com/rabbitmq/rabbitmq-server/blob/main/deps/rabbitmq_stream/docs/PROTOCOL.adoc
 * @see https://github.com/rabbitmq/rabbitmq-server/blob/main/deps/osiris/src/osiris_log.erl
 */
class OsirisChunkParser
{
    /**
     * Sub-batch codec values, from com.rabbitmq.stream.compression.Compression in the
     * RabbitMQ Stream Java client (NONE=0, GZIP=1, SNAPPY=2, LZ4=3, ZSTD=4).
     *
     * @see https://github.com/rabbitmq/rabbitmq-stream-java-client/blob/main/src/main/java/com/rabbitmq/stream/compression/Compression.java
     */
    private const CODEC_NONE = 0;
    private const CODEC_GZIP = 1;
    private const CODEC_NAMES = ['NONE', 'GZIP', 'SNAPPY', 'LZ4', 'ZSTD'];

    /**
     * Budget for the declared uncompressedSize of all gzip sub-batches in one chunk: 32 MiB.
     *
     * The declared size comes from the chunk and can be up to 4 GiB, so it cannot bound the
     * memory that an inflate uses. This budget does. It is private and is not configurable.
     */
    private const MAX_GZIP_UNCOMPRESSED_PER_CHUNK = 33554432;

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
     * @param bool $verifyCrc Whether the chunk's CRC-32 is verified against its data
     *                        section (default true). Disable only in throughput-
     *                        critical deployments that accept the risk of silently
     *                        consuming corrupted chunks.
     * @return ChunkEntry[]
     * @throws DeserializationException If the chunk violates the declared sizes, declares
     *                                  implausible record counts, exceeds the entry ceiling,
     *                                  or fails CRC verification
     * @throws InvalidArgumentException If $maxEntriesPerChunk is less than 1
     */
    public static function parse(
        string $chunkBytes,
        int $maxEntriesPerChunk = self::DEFAULT_MAX_ENTRIES_PER_CHUNK,
        int $offset = 0,
        ?int $length = null,
        bool $verifyCrc = true
    ): array {
        return iterator_to_array(
            self::parseEntries($chunkBytes, $maxEntriesPerChunk, $offset, $length, $verifyCrc),
            false
        );
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
     * @param bool $verifyCrc See parse().
     * @return \Generator<int, ChunkEntry>
     * @throws DeserializationException See parse().
     * @throws InvalidArgumentException See parse().
     */
    public static function parseEntries(
        string $chunkBytes,
        int $maxEntriesPerChunk = self::DEFAULT_MAX_ENTRIES_PER_CHUNK,
        int $offset = 0,
        ?int $length = null,
        bool $verifyCrc = true
    ): \Generator {
        $entries = self::parseRaw($chunkBytes, $maxEntriesPerChunk, $offset, $length, $verifyCrc);
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
     * substr()-copying its own entry out of it) — including the inner records of
     * an uncompressed sub-batch. This is the hot path for Consumer's deliver callback: for
     * every entry it saves one ChunkEntry object, one payload copy, plus the extra
     * indirection of AmqpMessageDecoder::decode() per message.
     *
     * The views share $chunkBytes, except for the records of a gzip sub-batch. Those
     * records are views into the inflated buffer that the sub-batch owns, so their
     * Messages share that buffer instead of $chunkBytes.
     *
     * @param int $maxEntriesPerChunk See parse().
     * @param int $offset See parse().
     * @param ?int $length See parse().
     * @param ?string $stream Name of the stream the chunk was delivered from, set once per
     *                        yielded Message (see {@see Message::getStream()}); null when unknown.
     * @param int $maxDepth Maximum AMQP nesting depth accepted when a yielded Message is
     *                        decoded; carried on each Message because that decode is lazy (#450).
     * @param bool $verifyCrc See parse().
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
        int $maxDepth = AmqpDecoder::MAX_RECURSION_DEPTH,
        bool $verifyCrc = true,
    ): \Generator {
        $views = self::parseRawViews($chunkBytes, $maxEntriesPerChunk, $offset, $length, $verifyCrc);
        foreach ($views as [$entryOffset, $timestamp, $buffer, $start, $len]) {
            yield Message::fromChunkView($entryOffset, $timestamp, $buffer, $start, $len, $stream, $maxDepth);
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
     * @param bool $verifyCrc See parse().
     * @return \Generator<int, array{0: int, 1: string, 2: int}>
     * @throws DeserializationException See parse().
     * @throws InvalidArgumentException See parse().
     */
    private static function parseRaw(
        string $chunkBytes,
        int $maxEntriesPerChunk,
        int $offset = 0,
        ?int $length = null,
        bool $verifyCrc = true
    ): \Generator {
        [$numEntries, $numRecords, $timestamp, $chunkFirstOffset, $pos, $dataEnd] =
            self::parseChunkHeader($chunkBytes, $maxEntriesPerChunk, $offset, $length, $verifyCrc);

        $entryCount = 0;
        $recordCount = 0;
        $currentOffset = $chunkFirstOffset;
        $gzipDeclaredTotal = 0;

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
                self::assertOffsetFits($recordCount, $chunkFirstOffset);
                yield [$currentOffset, $entryData, $timestamp];
                $entryCount++;
                $recordCount++;
                $currentOffset++;
            } else {
                $codec = ($entryType >> 4) & 0x07;
                self::assertSupportedCodec($codec);

                $subBatchRecords = self::readUint16At($chunkBytes, $dataEnd, $pos);
                $uncompressedSize = self::readUint32At($chunkBytes, $dataEnd, $pos);

                // Each record costs at least 4 bytes in the declared uncompressed size
                // (a uint32 size prefix). The broker enforces that on publish
                // (rabbit_stream_utils.erl check_message_count_fits_uncompressed_size).
                // For codec 0 the bytes on the wire are the records, so they must
                // hold 4 bytes per record as well. A gzip body can be far smaller than
                // its records, so that check does not apply to it. Equality of
                // uncompressedSize and compressedSize is never required here.
                $compressedSize = self::readUint32At($chunkBytes, $dataEnd, $pos);

                if ($codec === self::CODEC_NONE && $subBatchRecords * 4 > $compressedSize) {
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

                if ($codec === self::CODEC_GZIP) {
                    $gzipDeclaredTotal = self::reserveGzipBudget($gzipDeclaredTotal, $uncompressedSize);
                    $subBatchData = self::decompressGzip($subBatchData, $uncompressedSize);
                }

                $subLen = strlen($subBatchData);
                $subPos = 0;
                for ($j = 0; $j < $subBatchRecords; $j++) {
                    if ($entryCount >= $maxEntriesPerChunk) {
                        throw self::entryLimitExceeded($maxEntriesPerChunk);
                    }
                    $innerSize = self::readUint32At($subBatchData, $subLen, $subPos);
                    $innerData = self::readBytesAt($subBatchData, $subLen, $subPos, $innerSize);
                    self::assertOffsetFits($recordCount, $chunkFirstOffset);
                    yield [$currentOffset, $innerData, $timestamp];
                    $entryCount++;
                    $recordCount++;
                    $currentOffset++;
                }
            }
        }

        self::assertRecordCount($recordCount, $numRecords);
    }

    /**
     * Parses and validates the chunk header (magic/version/type, declared entry
     * and record counts, data-section bounds), shared by parseRaw() and
     * parseRawViews() so neither duplicates the header wire-format logic.
     *
     * @param int $maxEntriesPerChunk See parse().
     * @param int $offset See parse().
     * @param ?int $length See parse().
     * @param bool $verifyCrc See parse().
     * @return array{0: int, 1: int, 2: int, 3: int, 4: int, 5: int} [numEntries, numRecords,
     *         timestamp, chunkFirstOffset, pos (absolute start of the data section), dataEnd
     *         (absolute end of the data section)]
     * @throws DeserializationException See parse().
     * @throws InvalidArgumentException See parse().
     */
    private static function parseChunkHeader(
        string $chunkBytes,
        int $maxEntriesPerChunk,
        int $offset = 0,
        ?int $length = null,
        bool $verifyCrc = true
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
        $chunkCrc = $buffer->getUint32();          // CRC-32 of the data section
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

        // Every record gets the next offset, so the last one is
        // chunkFirstOffset + numRecords - 1. Reject a chunk whose last offset does
        // not fit in an int, before any entry is yielded: the offset counter would
        // otherwise turn into a float and raise a TypeError in ChunkEntry/Message.
        // getUint64() never returns a negative value, so the subtraction cannot overflow.
        if ($numRecords > 0 && $chunkFirstOffset > PHP_INT_MAX - ($numRecords - 1)) {
            throw new DeserializationException(sprintf(
                'Chunk declares %d records starting at offset %d, so an offset exceeds PHP_INT_MAX',
                $numRecords,
                $chunkFirstOffset
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

        // CRC-32 (the same standard CRC-32 as erlang:crc32) over exactly the
        // dataLength bytes of the data section, compared with the value the
        // header declares. Runs only after the bounds check above, so the
        // substr() below can never read past the received bytes.
        if ($verifyCrc) {
            $computedCrc = crc32(substr($chunkBytes, $headerSize, $dataLength));
            if ($computedCrc !== $chunkCrc) {
                throw new DeserializationException(sprintf(
                    'Chunk CRC mismatch at offset %d: computed 0x%08x but the chunk header declares 0x%08x',
                    $chunkFirstOffset,
                    $computedCrc,
                    $chunkCrc
                ));
            }
        }

        return [$numEntries, $numRecords, $timestamp, $chunkFirstOffset, $headerSize, $dataEnd];
    }

    /**
     * Same wire-format parsing as parseRaw(), but yields [offset, timestamp, buffer,
     * start, length] VIEWS instead of extracted [offset, data, timestamp] tuples: no
     * entry's bytes are ever substr()-copied out of its buffer here, plain entries and
     * sub-batch inner entries alike (unlike parseRaw(), which still substr()s the
     * sub-batch payload since ChunkEntry needs real strings). The buffer is $chunkBytes,
     * except for a gzip sub-batch: its inflated records are yielded as views into the
     * inflated buffer. This is what lets parseMessages() build one Message per entry
     * that shares a buffer instead of copying its own payload out of it.
     *
     * @param int $maxEntriesPerChunk See parse().
     * @param int $offset See parse().
     * @param ?int $length See parse().
     * @param bool $verifyCrc See parse().
     * @return \Generator<int, array{0: int, 1: int, 2: string, 3: int, 4: int}>
     * @throws DeserializationException See parse().
     * @throws InvalidArgumentException See parse().
     */
    private static function parseRawViews(
        string $chunkBytes,
        int $maxEntriesPerChunk,
        int $offset = 0,
        ?int $length = null,
        bool $verifyCrc = true
    ): \Generator {
        [$numEntries, $numRecords, $timestamp, $chunkFirstOffset, $pos, $dataEnd] =
            self::parseChunkHeader($chunkBytes, $maxEntriesPerChunk, $offset, $length, $verifyCrc);

        $entryCount = 0;
        $recordCount = 0;
        $currentOffset = $chunkFirstOffset;
        $gzipDeclaredTotal = 0;

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
                self::assertOffsetFits($recordCount, $chunkFirstOffset);
                yield [$currentOffset, $timestamp, $chunkBytes, $entryStart, $entrySize];
                $entryCount++;
                $recordCount++;
                $currentOffset++;
            } else {
                $codec = ($entryType >> 4) & 0x07;
                self::assertSupportedCodec($codec);

                $subBatchRecords = self::readUint16At($chunkBytes, $dataEnd, $pos);
                $uncompressedSize = self::readUint32At($chunkBytes, $dataEnd, $pos);
                $compressedSize = self::readUint32At($chunkBytes, $dataEnd, $pos);

                if ($codec === self::CODEC_NONE && $subBatchRecords * 4 > $compressedSize) {
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

                // $pos now sits past the whole sub-batch (checkBytesAt advances it), which
                // is where the outer loop resumes once this inner loop is done. The inner
                // loop uses its own cursor, $subPos, over $subBuffer up to $subEnd.
                $subBatchStart = self::checkBytesAt($dataEnd, $pos, $compressedSize);

                if ($codec === self::CODEC_GZIP) {
                    // The inflated records are not in $chunkBytes, so they form their own
                    // buffer. The inner entries are views into that buffer.
                    $gzipDeclaredTotal = self::reserveGzipBudget($gzipDeclaredTotal, $uncompressedSize);
                    $subBuffer = self::decompressGzip(
                        substr($chunkBytes, $subBatchStart, $compressedSize),
                        $uncompressedSize
                    );
                    $subEnd = strlen($subBuffer);
                    $subPos = 0;
                } else {
                    // No substr() here: the sub-batch payload is addressed as a range
                    // [$subBatchStart, $subEnd) inside $chunkBytes itself, so inner
                    // entries stay zero-copy views into the very same chunk buffer.
                    $subBuffer = $chunkBytes;
                    $subEnd = $subBatchStart + $compressedSize;
                    $subPos = $subBatchStart;
                }

                for ($j = 0; $j < $subBatchRecords; $j++) {
                    if ($entryCount >= $maxEntriesPerChunk) {
                        throw self::entryLimitExceeded($maxEntriesPerChunk);
                    }
                    $innerSize = self::readUint32At($subBuffer, $subEnd, $subPos);
                    $innerStart = self::checkBytesAt($subEnd, $subPos, $innerSize);
                    self::assertOffsetFits($recordCount, $chunkFirstOffset);
                    yield [$currentOffset, $timestamp, $subBuffer, $innerStart, $innerSize];
                    $entryCount++;
                    $recordCount++;
                    $currentOffset++;
                }
            }
        }

        self::assertRecordCount($recordCount, $numRecords);
    }

    /**
     * Adds the declared uncompressedSize of one gzip sub-batch to the total of its chunk
     * and returns the new total. Call it before decompressGzip(), so a chunk that goes over
     * MAX_GZIP_UNCOMPRESSED_PER_CHUNK is rejected without any inflate.
     *
     * @param int $declaredTotal Sum of the uncompressedSize values of the earlier gzip
     *                           sub-batches of the same chunk.
     * @return int The new total, including $uncompressedSize.
     * @throws DeserializationException If the new total is above the per-chunk budget.
     */
    private static function reserveGzipBudget(int $declaredTotal, int $uncompressedSize): int
    {
        $newTotal = $declaredTotal + $uncompressedSize;
        if ($newTotal > self::MAX_GZIP_UNCOMPRESSED_PER_CHUNK) {
            throw new DeserializationException(sprintf(
                'GZIP sub-batch declares uncompressedSize %d; the chunk total of declared GZIP sizes ' .
                'would be %d, above the per-chunk budget of %d bytes (32 MiB)',
                $uncompressedSize,
                $newTotal,
                self::MAX_GZIP_UNCOMPRESSED_PER_CHUNK
            ));
        }

        return $newTotal;
    }

    /**
     * Accepts only the codecs this parser decodes: NONE and GZIP.
     *
     * @throws DeserializationException For every other codec value (2 to 7).
     */
    private static function assertSupportedCodec(int $codec): void
    {
        if ($codec === self::CODEC_NONE || $codec === self::CODEC_GZIP) {
            return;
        }

        throw new DeserializationException(sprintf(
            'Unsupported sub-batch codec %d (%s): only NONE (0) and GZIP (1) are supported',
            $codec,
            self::CODEC_NAMES[$codec] ?? 'UNKNOWN'
        ));
    }

    /**
     * Inflates a gzip sub-batch body (RFC 1952 gzip stream, as the Java client's
     * GZIPOutputStream writes it) to exactly $uncompressedSize bytes.
     *
     * The inflate stops at $uncompressedSize, so a small body cannot expand past the
     * size the sub-batch declares (issue #399). gzdecode() treats a max length of 0
     * as no limit, so the cap is at least 1. A declared size of 0 then fails only
     * when the body inflates to a non-empty result.
     *
     * @throws DeserializationException If ext-zlib is missing, the body is corrupt,
     *                                  or the inflated size is not $uncompressedSize
     */
    private static function decompressGzip(string $compressed, int $uncompressedSize): string
    {
        if (!function_exists('gzdecode')) {
            throw new DeserializationException(
                'Reading a GZIP sub-batch needs the ext-zlib PHP extension, which is not loaded'
            );
        }

        // gzdecode() reports corrupt data and an over-long result with a PHP warning.
        // Suppress it here, so that a user error handler cannot turn it into an unrelated exception.
        set_error_handler(static fn (): bool => true);
        try {
            $decoded = gzdecode($compressed, max(1, $uncompressedSize));
        } finally {
            restore_error_handler();
        }

        if ($decoded === false) {
            throw new DeserializationException(sprintf(
                'GZIP sub-batch is corrupt or inflates to more than its uncompressedSize %d',
                $uncompressedSize
            ));
        }

        if (strlen($decoded) !== $uncompressedSize) {
            throw new DeserializationException(sprintf(
                'GZIP sub-batch inflates to %d bytes but declares uncompressedSize %d',
                strlen($decoded),
                $uncompressedSize
            ));
        }

        return $decoded;
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

    /**
     * Rejects the record at index $recordCount (0-based) when its offset,
     * $chunkFirstOffset + $recordCount, does not fit in an int. Called before each
     * yield, so a chunk that holds more records than it declares fails with a
     * DeserializationException instead of a float offset.
     */
    private static function assertOffsetFits(int $recordCount, int $chunkFirstOffset): void
    {
        if ($recordCount > PHP_INT_MAX - $chunkFirstOffset) {
            throw new DeserializationException(sprintf(
                'Chunk record %d starting at offset %d exceeds PHP_INT_MAX',
                $recordCount,
                $chunkFirstOffset
            ));
        }
    }

    private static function assertRecordCount(int $recordCount, int $numRecords): void
    {
        if ($recordCount !== $numRecords) {
            throw new DeserializationException(sprintf(
                'Chunk declares %d records, but parsed %d records',
                $numRecords,
                $recordCount
            ));
        }
    }

    private static function entryLimitExceeded(int $maxEntriesPerChunk): DeserializationException
    {
        return new DeserializationException(sprintf(
            'Chunk contains more than %d entries (the maximum allowed per chunk)',
            $maxEntriesPerChunk
        ));
    }
}
