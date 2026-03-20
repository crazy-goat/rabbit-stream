<?php

declare(strict_types=1);

namespace CrazyGoat\RabbitStream\Tests\Client;

use CrazyGoat\RabbitStream\Client\AmqpDecoder;
use PHPUnit\Framework\TestCase;

class AmqpDecoderMessageTest extends TestCase
{
    /**
     * Helper to build a described type section.
     */
    private function buildSection(int $descriptor, string $valueData): string
    {
        // 0x00 + smallulong descriptor + value
        return "\x00\x53" . chr($descriptor) . $valueData;
    }

    /**
     * Helper to build a Data section (0x75) with binary content.
     */
    private function buildDataSection(string $content): string
    {
        // Data section uses vbin32 for the content
        $length = strlen($content);
        $valueData = "\xb0" . pack('N', $length) . $content;
        return $this->buildSection(0x75, $valueData);
    }

    /**
     * Helper to build a Properties section (0x73) from a list.
     *
     * @param array<string, string|int> $properties
     */
    private function buildPropertiesSection(array $properties): string
    {
        // Build list8 with properties
        $listItems = '';
        $count = 0;

        // Properties list has up to 13 fields in order
        $fieldOrder = [
            'message-id',
            'user-id',
            'to',
            'subject',
            'reply-to',
            'correlation-id',
            'content-type',
            'content-encoding',
            'absolute-expiry-time',
            'creation-time',
            'group-id',
            'group-sequence',
            'reply-to-group-id',
        ];

        // Find the last field index that has a value
        $lastIndex = -1;
        foreach ($fieldOrder as $index => $fieldName) {
            if (isset($properties[$fieldName])) {
                $lastIndex = $index;
            }
        }

        // Build list up to the last field with a value
        for ($i = 0; $i <= $lastIndex; $i++) {
            $fieldName = $fieldOrder[$i];
            if (isset($properties[$fieldName])) {
                $fieldValue = $properties[$fieldName];
                if (is_string($fieldValue)) {
                    // Use str8-utf8 for strings
                    $listItems .= "\xa1" . chr(strlen($fieldValue)) . $fieldValue;
                } elseif (is_int($fieldValue)) {
                    if ($fieldValue >= 0 && $fieldValue <= 255) {
                        // Use smalluint for small positive integers
                        $listItems .= "\x52" . chr($fieldValue);
                    } else {
                        // Use uint32 for larger integers
                        $listItems .= "\x70" . pack('N', $fieldValue);
                    }
                }
            } else {
                // Fill with null for missing fields
                $listItems .= "\x40"; // null
            }
            $count++;
        }

        // Build list8: size (1 byte) + count (1 byte) + items
        $listSize = strlen($listItems) + 1; // +1 for count byte
        $listData = "\xc0" . chr($listSize) . chr($count) . $listItems;

        return $this->buildSection(0x73, $listData);
    }

    /**
     * Helper to build an ApplicationProperties section (0x74) from a map.
     *
     * @param array<string, string|int|bool|null> $properties
     */
    private function buildApplicationPropertiesSection(array $properties): string
    {
        // Build map8 with properties
        $mapItems = '';
        $count = 0;

        foreach ($properties as $key => $value) {
            // Key: str8-utf8
            $mapItems .= "\xa1" . chr(strlen((string) $key)) . $key;

            // Value
            if (is_string($value)) {
                $mapItems .= "\xa1" . chr(strlen($value)) . $value;
            } elseif (is_int($value)) {
                if ($value >= 0 && $value <= 255) {
                    $mapItems .= "\x52" . chr($value);
                } else {
                    $mapItems .= "\x70" . pack('N', $value);
                }
            } elseif (is_bool($value)) {
                $mapItems .= $value ? "\x41" : "\x42";
            } elseif (is_null($value)) {
                $mapItems .= "\x40";
            }
            $count++;
        }

        // Build map8: size (1 byte) + count (1 byte) + items
        // count is number of pairs * 2 (key + value)
        $mapSize = strlen($mapItems) + 1; // +1 for count byte
        $mapData = "\xc1" . chr($mapSize) . chr($count * 2) . $mapItems;

        return $this->buildSection(0x74, $mapData);
    }

    /**
     * Helper to build a MessageAnnotations section (0x72) from a map.
     *
     * @param array<string, string|int> $annotations
     */
    private function buildMessageAnnotationsSection(array $annotations): string
    {
        // Similar to ApplicationProperties
        $mapItems = '';
        $count = 0;

        foreach ($annotations as $key => $value) {
            // Key: str8-utf8 or sym8
            $mapItems .= "\xa3" . chr(strlen((string) $key)) . $key;

            // Value
            if (is_string($value)) {
                $mapItems .= "\xa1" . chr(strlen($value)) . $value;
            } elseif (is_int($value)) {
                if ($value >= 0 && $value <= 255) {
                    $mapItems .= "\x52" . chr($value);
                } else {
                    $mapItems .= "\x70" . pack('N', $value);
                }
            }
            $count++;
        }

        $mapSize = strlen($mapItems) + 1;
        $mapData = "\xc1" . chr($mapSize) . chr($count * 2) . $mapItems;

        return $this->buildSection(0x72, $mapData);
    }

    /**
     * Helper to build an AmqpValue section (0x77).
     */
    private function buildAmqpValueSection(string|int $value): string
    {
        $valueData = is_string($value) ? "\xa1" . chr(strlen($value)) . $value : "\x52" . chr($value);
        return $this->buildSection(0x77, $valueData);
    }

    // ========== Test cases ==========

    public function testDecodeSimpleMessageWithDataOnly(): void
    {
        // Simple message: just a Data section
        $message = $this->buildDataSection('Hello World');

        $sections = AmqpDecoder::decodeMessage($message);

        $this->assertSame('Hello World', $sections['body']);
        $this->assertSame([], $sections['properties']);
        $this->assertSame([], $sections['applicationProperties']);
        $this->assertNull($sections['header']);
    }

    public function testDecodeMessageWithProperties(): void
    {
        // Message with Properties + Data
        $properties = [
            'message-id' => 'msg-123',
            'content-type' => 'application/json',
        ];

        $message = $this->buildPropertiesSection($properties) .
                   $this->buildDataSection('{"key":"value"}');

        $sections = AmqpDecoder::decodeMessage($message);

        $this->assertSame('{"key":"value"}', $sections['body']);
        $this->assertSame('msg-123', $sections['properties']['message-id']);
        $this->assertSame('application/json', $sections['properties']['content-type']);
    }

    public function testDecodeMessageWithApplicationProperties(): void
    {
        // Message with ApplicationProperties + Data
        $appProps = [
            'x-custom-header' => 'custom-value',
            'priority' => 5,
        ];

        $message = $this->buildApplicationPropertiesSection($appProps) .
                   $this->buildDataSection('Body content');

        $sections = AmqpDecoder::decodeMessage($message);

        $this->assertSame('Body content', $sections['body']);
        $this->assertSame('custom-value', $sections['applicationProperties']['x-custom-header']);
        $this->assertSame(5, $sections['applicationProperties']['priority']);
    }

    public function testDecodeFullMessage(): void
    {
        // Full message: Properties + ApplicationProperties + Data
        $properties = [
            'message-id' => 'msg-456',
            'subject' => 'Test Subject',
            'content-type' => 'text/plain',
        ];

        $appProps = [
            'source' => 'test-suite',
            'version' => 1,
        ];

        $message = $this->buildPropertiesSection($properties) .
                   $this->buildApplicationPropertiesSection($appProps) .
                   $this->buildDataSection('Full message body');

        $sections = AmqpDecoder::decodeMessage($message);

        $this->assertSame('Full message body', $sections['body']);
        $this->assertSame('msg-456', $sections['properties']['message-id']);
        $this->assertSame('Test Subject', $sections['properties']['subject']);
        $this->assertSame('text/plain', $sections['properties']['content-type']);
        $this->assertSame('test-suite', $sections['applicationProperties']['source']);
        $this->assertSame(1, $sections['applicationProperties']['version']);
    }

    public function testDecodeMessageWithAmqpValueBody(): void
    {
        // Message with AmqpValue instead of Data
        $message = $this->buildAmqpValueSection('String value');

        $sections = AmqpDecoder::decodeMessage($message);

        $this->assertSame('String value', $sections['body']);
    }

    public function testDecodeMessageWithAmqpValueInt(): void
    {
        // Message with AmqpValue containing an integer
        $message = $this->buildAmqpValueSection(42);

        $sections = AmqpDecoder::decodeMessage($message);

        $this->assertSame(42, $sections['body']);
    }

    public function testDecodeMessageWithMessageAnnotations(): void
    {
        // Message with MessageAnnotations + Data
        $annotations = [
            'x-opt-sequence-number' => 12345,
        ];

        $message = $this->buildMessageAnnotationsSection($annotations) .
                   $this->buildDataSection('Annotated message');

        $sections = AmqpDecoder::decodeMessage($message);

        $this->assertSame('Annotated message', $sections['body']);
        $this->assertSame(12345, $sections['messageAnnotations']['x-opt-sequence-number']);
    }

    public function testDecodeMessageWithMultipleDataSections(): void
    {
        // Message with multiple Data sections (should be concatenated)
        $message = $this->buildDataSection('Part 1') .
                   $this->buildDataSection('Part 2') .
                   $this->buildDataSection('Part 3');

        $sections = AmqpDecoder::decodeMessage($message);

        $this->assertSame('Part 1Part 2Part 3', $sections['body']);
    }

    public function testDecodeMessageWithAllProperties(): void
    {
        // Test all 13 properties fields
        $properties = [
            'message-id' => 'msg-id',
            'user-id' => 'user123',
            'to' => 'destination',
            'subject' => 'test-subject',
            'reply-to' => 'reply-dest',
            'correlation-id' => 'corr-123',
            'content-type' => 'application/json',
            'content-encoding' => 'gzip',
            'absolute-expiry-time' => 1234567890,
            'creation-time' => 1234567000,
            'group-id' => 'group-1',
            'group-sequence' => 1,
            'reply-to-group-id' => 'reply-group',
        ];

        $message = $this->buildPropertiesSection($properties) .
                   $this->buildDataSection('Test');

        $sections = AmqpDecoder::decodeMessage($message);

        $this->assertSame('msg-id', $sections['properties']['message-id']);
        $this->assertSame('user123', $sections['properties']['user-id']);
        $this->assertSame('destination', $sections['properties']['to']);
        $this->assertSame('test-subject', $sections['properties']['subject']);
        $this->assertSame('reply-dest', $sections['properties']['reply-to']);
        $this->assertSame('corr-123', $sections['properties']['correlation-id']);
        $this->assertSame('application/json', $sections['properties']['content-type']);
        $this->assertSame('gzip', $sections['properties']['content-encoding']);
        $this->assertSame(1234567890, $sections['properties']['absolute-expiry-time']);
        $this->assertSame(1234567000, $sections['properties']['creation-time']);
        $this->assertSame('group-1', $sections['properties']['group-id']);
        $this->assertSame(1, $sections['properties']['group-sequence']);
        $this->assertSame('reply-group', $sections['properties']['reply-to-group-id']);
    }

    public function testDecodeEmptyMessage(): void
    {
        // Empty message (no sections)
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Empty message data');
        AmqpDecoder::decodeMessage('');
    }

    public function testDecodeMessageWithoutDescribedTypeMarker(): void
    {
        // Message that doesn't start with 0x00
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Expected described type marker (0x00)');

        AmqpDecoder::decodeMessage("\x01\x02\x03");
    }

    public function testDecodeMessageWithUnknownSection(): void
    {
        // Message with an unknown section (should be skipped)
        $unknownSection = "\x00\x53\x99\x52\x01"; // Unknown descriptor 0x99
        $message = $unknownSection . $this->buildDataSection('Body');

        $sections = AmqpDecoder::decodeMessage($message);

        $this->assertSame('Body', $sections['body']);
    }

    public function testDecodeMessageWithHeaderSection(): void
    {
        // Message with Header section
        // Header is a list with: durable, priority, ttl, first-acquirer, delivery-count
        $headerList = "\xc0\x01\x00"; // Empty list (size=1, count=0)
        $headerSection = "\x00\x53\x70" . $headerList;

        $message = $headerSection . $this->buildDataSection('With header');

        $sections = AmqpDecoder::decodeMessage($message);

        $this->assertSame('With header', $sections['body']);
        $this->assertSame([], $sections['header']);
    }

    public function testDecodeMessageWithFooterSection(): void
    {
        // Message with Footer section
        $footerMap = "\xc1\x01\x00"; // Empty map (size=1, count=0)
        $footerSection = "\x00\x53\x78" . $footerMap;

        $message = $this->buildDataSection('With footer') . $footerSection;

        $sections = AmqpDecoder::decodeMessage($message);

        $this->assertSame('With footer', $sections['body']);
        $this->assertSame([], $sections['footer']);
    }

    public function testDecodeMessageWithHeaderList0(): void
    {
        // Header section using list0 (0x45) — empty list without size/count bytes
        $headerSection = "\x00\x53\x70\x45"; // described(0x70, list0)

        $message = $headerSection . $this->buildDataSection('With list0 header');

        $sections = AmqpDecoder::decodeMessage($message);

        $this->assertSame('With list0 header', $sections['body']);
        $this->assertSame([], $sections['header']);
    }
}
