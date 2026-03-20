<?php

declare(strict_types=1);

namespace CrazyGoat\RabbitStream\Tests\Client;

use CrazyGoat\RabbitStream\Client\AmqpMessageDecoder;
use CrazyGoat\RabbitStream\Client\ChunkEntry;
use CrazyGoat\RabbitStream\Client\Message;
use PHPUnit\Framework\TestCase;

class AmqpMessageDecoderTest extends TestCase
{
    /**
     * Helper to build a simple AMQP Data section.
     */
    private function buildDataSection(string $content): string
    {
        $length = strlen($content);
        // 0x00 + smallulong 0x75 (Data) + vbin32 + content
        return "\x00\x53\x75\xb0" . pack('N', $length) . $content;
    }

    /**
     * Helper to build a Properties section.
     */
    private function buildPropertiesSection(array $properties): string
    {
        $listItems = '';
        $count = 0;

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
                    $listItems .= "\xa1" . chr(strlen($fieldValue)) . $fieldValue;
                } elseif (is_int($fieldValue)) {
                    if ($fieldValue >= 0 && $fieldValue <= 255) {
                        $listItems .= "\x52" . chr($fieldValue);
                    } else {
                        $listItems .= "\x70" . pack('N', $fieldValue);
                    }
                }
            } else {
                // Fill with null for missing fields
                $listItems .= "\x40"; // null
            }
            $count++;
        }

        $listSize = strlen($listItems) + 1;
        $listData = "\xc0" . chr($listSize) . chr($count) . $listItems;

        // 0x00 + smallulong 0x73 (Properties) + list
        return "\x00\x53\x73" . $listData;
    }

    /**
     * Helper to build an ApplicationProperties section.
     */
    private function buildApplicationPropertiesSection(array $properties): string
    {
        $mapItems = '';
        $count = 0;

        foreach ($properties as $key => $value) {
            $mapItems .= "\xa1" . chr(strlen($key)) . $key;

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
            }
            $count++;
        }

        $mapSize = strlen($mapItems) + 1;
        $mapData = "\xc1" . chr($mapSize) . chr($count * 2) . $mapItems;

        // 0x00 + smallulong 0x74 (ApplicationProperties) + map
        return "\x00\x53\x74" . $mapData;
    }

    // ========== Test cases ==========

    public function testDecodeSimpleChunkEntry(): void
    {
        $amqpData = $this->buildDataSection('Hello World');
        $entry = new ChunkEntry(
            offset: 100,
            data: $amqpData,
            timestamp: 1234567890
        );

        $message = AmqpMessageDecoder::decode($entry);

        $this->assertInstanceOf(Message::class, $message);
        $this->assertSame(100, $message->getOffset());
        $this->assertSame(1234567890, $message->getTimestamp());
        $this->assertSame('Hello World', $message->getBody());
        $this->assertSame([], $message->getProperties());
        $this->assertSame([], $message->getApplicationProperties());
    }

    public function testDecodeChunkEntryWithProperties(): void
    {
        $properties = [
            'message-id' => 'msg-123',
            'content-type' => 'application/json',
        ];

        $amqpData = $this->buildPropertiesSection($properties) .
                     $this->buildDataSection('{"key":"value"}');

        $entry = new ChunkEntry(
            offset: 200,
            data: $amqpData,
            timestamp: 999999999
        );

        $message = AmqpMessageDecoder::decode($entry);

        $this->assertSame(200, $message->getOffset());
        $this->assertSame(999999999, $message->getTimestamp());
        $this->assertSame('{"key":"value"}', $message->getBody());
        $this->assertSame('msg-123', $message->getMessageId());
        $this->assertSame('application/json', $message->getContentType());
        $this->assertSame('msg-123', $message->getProperties()['message-id']);
    }

    public function testDecodeChunkEntryWithApplicationProperties(): void
    {
        $appProps = [
            'x-custom-header' => 'custom-value',
            'priority' => 5,
        ];

        $amqpData = $this->buildApplicationPropertiesSection($appProps) .
                     $this->buildDataSection('Body content');

        $entry = new ChunkEntry(
            offset: 300,
            data: $amqpData,
            timestamp: 888888888
        );

        $message = AmqpMessageDecoder::decode($entry);

        $this->assertSame(300, $message->getOffset());
        $this->assertSame('Body content', $message->getBody());
        $this->assertSame('custom-value', $message->getApplicationProperties()['x-custom-header']);
        $this->assertSame(5, $message->getApplicationProperties()['priority']);
    }

    public function testDecodeFullChunkEntry(): void
    {
        $properties = [
            'message-id' => 'msg-456',
            'subject' => 'Test Subject',
            'content-type' => 'text/plain',
            'creation-time' => 1234567000,
        ];

        $appProps = [
            'source' => 'test-suite',
            'version' => 1,
        ];

        $amqpData = $this->buildPropertiesSection($properties) .
                     $this->buildApplicationPropertiesSection($appProps) .
                     $this->buildDataSection('Full message body');

        $entry = new ChunkEntry(
            offset: 400,
            data: $amqpData,
            timestamp: 777777777
        );

        $message = AmqpMessageDecoder::decode($entry);

        $this->assertSame(400, $message->getOffset());
        $this->assertSame(777777777, $message->getTimestamp());
        $this->assertSame('Full message body', $message->getBody());

        // Test convenience getters
        $this->assertSame('msg-456', $message->getMessageId());
        $this->assertSame('Test Subject', $message->getSubject());
        $this->assertSame('text/plain', $message->getContentType());
        $this->assertSame(1234567000, $message->getCreationTime());

        // Test application properties
        $this->assertSame('test-suite', $message->getApplicationProperties()['source']);
        $this->assertSame(1, $message->getApplicationProperties()['version']);
    }

    public function testDecodeAllWithMultipleEntries(): void
    {
        $entries = [
            new ChunkEntry(
                offset: 100,
                data: $this->buildDataSection('Message 1'),
                timestamp: 1111111111
            ),
            new ChunkEntry(
                offset: 101,
                data: $this->buildDataSection('Message 2'),
                timestamp: 2222222222
            ),
            new ChunkEntry(
                offset: 102,
                data: $this->buildDataSection('Message 3'),
                timestamp: 3333333333
            ),
        ];

        $messages = AmqpMessageDecoder::decodeAll($entries);

        $this->assertCount(3, $messages);
        $this->assertContainsOnlyInstancesOf(Message::class, $messages);

        $this->assertSame(100, $messages[0]->getOffset());
        $this->assertSame('Message 1', $messages[0]->getBody());
        $this->assertSame(1111111111, $messages[0]->getTimestamp());

        $this->assertSame(101, $messages[1]->getOffset());
        $this->assertSame('Message 2', $messages[1]->getBody());
        $this->assertSame(2222222222, $messages[1]->getTimestamp());

        $this->assertSame(102, $messages[2]->getOffset());
        $this->assertSame('Message 3', $messages[2]->getBody());
        $this->assertSame(3333333333, $messages[2]->getTimestamp());
    }

    public function testDecodeAllWithEmptyArray(): void
    {
        $messages = AmqpMessageDecoder::decodeAll([]);

        $this->assertSame([], $messages);
        $this->assertCount(0, $messages);
    }

    public function testDecodeAllWithSingleEntry(): void
    {
        $entries = [
            new ChunkEntry(
                offset: 500,
                data: $this->buildDataSection('Single message'),
                timestamp: 5555555555
            ),
        ];

        $messages = AmqpMessageDecoder::decodeAll($entries);

        $this->assertCount(1, $messages);
        $this->assertSame(500, $messages[0]->getOffset());
        $this->assertSame('Single message', $messages[0]->getBody());
        $this->assertSame(5555555555, $messages[0]->getTimestamp());
    }

    public function testDecodeWithMessageAnnotations(): void
    {
        // Build a message with message annotations
        $annotations = [
            'x-opt-sequence-number' => 12345,
        ];

        $mapItems = '';
        foreach ($annotations as $key => $value) {
            $mapItems .= "\xa3" . chr(strlen($key)) . $key;
            // Use uint32 for values > 255
            $mapItems .= "\x70" . pack('N', $value);
        }
        $mapSize = strlen($mapItems) + 1;
        $mapData = "\xc1" . chr($mapSize) . chr(2) . $mapItems;

        // 0x00 + smallulong 0x72 (MessageAnnotations) + map
        $amqpData = "\x00\x53\x72" . $mapData . $this->buildDataSection('Annotated');

        $entry = new ChunkEntry(
            offset: 600,
            data: $amqpData,
            timestamp: 6666666666
        );

        $message = AmqpMessageDecoder::decode($entry);

        $this->assertSame('Annotated', $message->getBody());
        $this->assertSame(12345, $message->getMessageAnnotations()['x-opt-sequence-number']);
    }

    public function testDecodeWithCorrelationId(): void
    {
        $properties = [
            'message-id' => 'msg-789',
            'correlation-id' => 'corr-abc',
        ];

        $amqpData = $this->buildPropertiesSection($properties) .
                     $this->buildDataSection('With correlation');

        $entry = new ChunkEntry(
            offset: 700,
            data: $amqpData,
            timestamp: 7777777777
        );

        $message = AmqpMessageDecoder::decode($entry);

        $this->assertSame('msg-789', $message->getMessageId());
        $this->assertSame('corr-abc', $message->getCorrelationId());
    }

    public function testDecodeWithGroupId(): void
    {
        $properties = [
            'message-id' => 'msg-999',
            'group-id' => 'my-group',
        ];

        $amqpData = $this->buildPropertiesSection($properties) .
                     $this->buildDataSection('Grouped message');

        $entry = new ChunkEntry(
            offset: 800,
            data: $amqpData,
            timestamp: 8888888888
        );

        $message = AmqpMessageDecoder::decode($entry);

        $this->assertSame('my-group', $message->getGroupId());
    }

    public function testDecodeWithNullProperties(): void
    {
        // Message with no properties set
        $amqpData = $this->buildDataSection('No properties');

        $entry = new ChunkEntry(
            offset: 900,
            data: $amqpData,
            timestamp: 9999999999
        );

        $message = AmqpMessageDecoder::decode($entry);

        $this->assertNull($message->getMessageId());
        $this->assertNull($message->getCorrelationId());
        $this->assertNull($message->getContentType());
        $this->assertNull($message->getSubject());
        $this->assertNull($message->getCreationTime());
        $this->assertNull($message->getGroupId());
    }

    public function testDecodeAmqpValueWithIntBody(): void
    {
        // AmqpValue section with integer body (descriptor 0x77)
        // 0x00 + smallulong 0x77 + smalluint 42
        $amqpData = "\x00\x53\x77\x52\x2a";

        $entry = new ChunkEntry(
            offset: 1000,
            data: $amqpData,
            timestamp: 1111111111
        );

        $message = AmqpMessageDecoder::decode($entry);

        $this->assertSame(42, $message->getBody());
        $this->assertSame(1000, $message->getOffset());
    }

    public function testDecodeAmqpValueWithNullBody(): void
    {
        // AmqpValue section with null body (descriptor 0x77)
        // 0x00 + smallulong 0x77 + null
        $amqpData = "\x00\x53\x77\x40";

        $entry = new ChunkEntry(
            offset: 1001,
            data: $amqpData,
            timestamp: 2222222222
        );

        $message = AmqpMessageDecoder::decode($entry);

        $this->assertNull($message->getBody());
    }
}
