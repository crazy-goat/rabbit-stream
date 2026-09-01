<?php

declare(strict_types=1);

namespace CrazyGoat\RabbitStream\Tests\Client;

use CrazyGoat\RabbitStream\Client\AmqpDecoder;
use CrazyGoat\RabbitStream\Client\Message;
use CrazyGoat\RabbitStream\Exception\DeserializationException;
use PHPUnit\Framework\TestCase;

class MessageTest extends TestCase
{
    public function testFromRawEntryDoesNotDecodeUntilAccessorIsCalled(): void
    {
        // Deliberately invalid AMQP bytes (does not start with the 0x00 described-type
        // marker): if fromRawEntry() decoded eagerly, construction itself would throw.
        $msg = Message::fromRawEntry(offset: 1, timestamp: 1000, rawData: "\xFF\xFF\xFF");

        // Construction succeeded — decoding was deferred.
        $this->assertSame(1, $msg->getOffset());
        $this->assertSame(1000, $msg->getTimestamp());

        // The first accessor that needs a decoded section triggers (and fails on) the decode.
        $this->expectException(DeserializationException::class);
        $msg->getBody();
    }

    public function testFromRawEntryDecodesLazilyAndCachesResult(): void
    {
        // 0x00 0x53 0x75 (Data descriptor) + vbin32 length-prefixed "hi"
        $rawData = "\x00\x53\x75\xb0" . pack('N', 2) . 'hi';
        $msg = Message::fromRawEntry(offset: 5, timestamp: 2000, rawData: $rawData);

        $this->assertSame('hi', $msg->getBody());
        // Calling again must return the cached, already-decoded value.
        $this->assertSame('hi', $msg->getBody());
    }

    public function testFromRawEntryGetterAccessorsAllTriggerDecode(): void
    {
        $msg = Message::fromRawEntry(offset: 1, timestamp: 1000, rawData: "\xFF\xFF\xFF");
        $this->expectException(DeserializationException::class);
        $msg->getProperties();
    }

    /**
     * Message::ensureDecoded() has a hand-written fast path for the common case —
     * exactly one AMQP 1.0 Data section, no header/properties/annotations — that
     * bypasses AmqpDecoder::decodeMessage() entirely. These tests prove it produces
     * results equivalent to the generic decoder for both Data-section length forms
     * (vbin32, vbin8), and that a message carrying properties still falls back to
     * (and fully exercises) the generic decoder.
     */
    public function testFastPathVbin32MatchesGenericDecoderForBody(): void
    {
        $body = str_repeat('x', 1024);
        $rawData = "\x00\x53\x75\xb0" . pack('N', strlen($body)) . $body;

        $fast = Message::fromRawEntry(offset: 1, timestamp: 1000, rawData: $rawData);
        $genericBody = AmqpDecoder::decodeMessage($rawData)['body'];
        $generic = new Message(
            offset: 1,
            timestamp: 1000,
            body: is_string($genericBody) ? $genericBody : null,
        );

        $this->assertSame($body, $fast->getBody());
        $this->assertSame($generic->getBody(), $fast->getBody());
        $this->assertSame([], $fast->getProperties());
        $this->assertSame([], $fast->getApplicationProperties());
        $this->assertSame([], $fast->getMessageAnnotations());
    }

    public function testFastPathVbin32HandlesEmptyBody(): void
    {
        $rawData = "\x00\x53\x75\xb0" . pack('N', 0);
        $msg = Message::fromRawEntry(offset: 1, timestamp: 1000, rawData: $rawData);

        $this->assertSame('', $msg->getBody());
    }

    public function testFastPathVbin8MatchesGenericDecoderForBody(): void
    {
        $body = 'short body';
        $rawData = "\x00\x53\x75\xa0" . chr(strlen($body)) . $body;

        $fast = Message::fromRawEntry(offset: 1, timestamp: 1000, rawData: $rawData);
        $generic = AmqpDecoder::decodeMessage($rawData);

        $this->assertSame($body, $fast->getBody());
        $this->assertSame($generic['body'], $fast->getBody());
        $this->assertSame([], $fast->getProperties());
    }

    public function testFastPathVbin8HandlesEmptyBody(): void
    {
        $rawData = "\x00\x53\x75\xa0" . chr(0);
        $msg = Message::fromRawEntry(offset: 1, timestamp: 1000, rawData: $rawData);

        $this->assertSame('', $msg->getBody());
    }

    public function testMessageWithPropertiesStillFullyDecodesViaGenericDecoder(): void
    {
        // Properties section (descriptor 0x73): a list8 containing just message-id
        // ("msg-1", str8) at index 0, followed by a Data section for the body.
        $messageId = 'msg-1';
        $propsListItems = "\xa1" . chr(strlen($messageId)) . $messageId;
        $propsListSize = strlen($propsListItems) + 1; // +1 for the count byte
        $propertiesSection = "\x00\x53\x73" . "\xc0" . chr($propsListSize & 0xFF) . chr(1) . $propsListItems;

        $body = 'hello';
        $dataSection = "\x00\x53\x75\xb0" . pack('N', strlen($body)) . $body;

        $rawData = $propertiesSection . $dataSection;

        // The Properties section means rawData does NOT start with the Data-only
        // fast-path prefix, so this must fall back to the generic decoder.
        $this->assertFalse(str_starts_with($rawData, "\x00\x53\x75\xb0"));

        $msg = Message::fromRawEntry(offset: 1, timestamp: 1000, rawData: $rawData);

        $this->assertSame($body, $msg->getBody());
        $this->assertSame('msg-1', $msg->getMessageId());
        $this->assertSame(['message-id' => 'msg-1'], $msg->getProperties());
    }

    public function testGettersReturnCorrectValues(): void
    {
        $msg = new Message(
            offset: 42,
            timestamp: 1700000000,
            body: 'hello',
            properties: [
                'message-id' => 'msg-1',
                'correlation-id' => 'corr-1',
                'content-type' => 'text/plain',
                'subject' => 'test-subject',
                'creation-time' => 1700000000,
                'group-id' => 'group-1',
            ],
            applicationProperties: ['app-key' => 'app-value'],
            messageAnnotations: ['ann-key' => 'ann-value'],
        );

        $this->assertSame(42, $msg->getOffset());
        $this->assertSame(1700000000, $msg->getTimestamp());
        $this->assertSame('hello', $msg->getBody());
        $this->assertSame(['app-key' => 'app-value'], $msg->getApplicationProperties());
        $this->assertSame(['ann-key' => 'ann-value'], $msg->getMessageAnnotations());
        $this->assertSame('msg-1', $msg->getMessageId());
        $this->assertSame('corr-1', $msg->getCorrelationId());
        $this->assertSame('text/plain', $msg->getContentType());
        $this->assertSame('test-subject', $msg->getSubject());
        $this->assertSame(1700000000, $msg->getCreationTime());
        $this->assertSame('group-1', $msg->getGroupId());
    }

    public function testGettersReturnNullForMissingProperties(): void
    {
        $msg = new Message(offset: 0, timestamp: 0);

        $this->assertNull($msg->getMessageId());
        $this->assertNull($msg->getCorrelationId());
        $this->assertNull($msg->getContentType());
        $this->assertNull($msg->getSubject());
        $this->assertNull($msg->getCreationTime());
        $this->assertNull($msg->getGroupId());
    }

    public function testBodyCanBeArray(): void
    {
        $msg = new Message(offset: 0, timestamp: 0, body: [1, 2, 3]);
        $this->assertSame([1, 2, 3], $msg->getBody());
    }

    public function testBodyCanBeInteger(): void
    {
        $msg = new Message(offset: 0, timestamp: 0, body: 12345);
        $this->assertSame(12345, $msg->getBody());
    }

    public function testBodyCanBeFloat(): void
    {
        $msg = new Message(offset: 0, timestamp: 0, body: 3.14);
        $this->assertSame(3.14, $msg->getBody());
    }

    public function testBodyCanBeBoolean(): void
    {
        $msg = new Message(offset: 0, timestamp: 0, body: true);
        $this->assertTrue($msg->getBody());
    }

    public function testBodyCanBeNull(): void
    {
        $msg = new Message(offset: 0, timestamp: 0);
        $this->assertNull($msg->getBody());
    }

    public function testBodyCanBeString(): void
    {
        $msg = new Message(offset: 0, timestamp: 0, body: 'test string');
        $this->assertSame('test string', $msg->getBody());
    }

    public function testContentTypeReturnsNullForNonScalarValue(): void
    {
        $msg = new Message(
            offset: 0,
            timestamp: 0,
            body: '',
            properties: ['content-type' => ['not', 'scalar']]
        );
        $this->assertNull($msg->getContentType());
    }

    public function testContentTypeCastsIntToString(): void
    {
        $msg = new Message(
            offset: 0,
            timestamp: 0,
            body: '',
            properties: ['content-type' => 123]
        );
        $this->assertSame('123', $msg->getContentType());
    }

    public function testSubjectReturnsNullForNonScalarValue(): void
    {
        $msg = new Message(
            offset: 0,
            timestamp: 0,
            body: '',
            properties: ['subject' => ['not', 'scalar']]
        );
        $this->assertNull($msg->getSubject());
    }

    public function testCreationTimeReturnsNullForNonScalarValue(): void
    {
        $msg = new Message(
            offset: 0,
            timestamp: 0,
            body: '',
            properties: ['creation-time' => ['not', 'scalar']]
        );
        $this->assertNull($msg->getCreationTime());
    }

    public function testCreationTimeCastsStringToInt(): void
    {
        $msg = new Message(
            offset: 0,
            timestamp: 0,
            body: '',
            properties: ['creation-time' => '1700000000']
        );
        $this->assertSame(1700000000, $msg->getCreationTime());
    }

    public function testCreationTimeTruncatesFloat(): void
    {
        $msg = new Message(
            offset: 0,
            timestamp: 0,
            body: '',
            properties: ['creation-time' => 1700000000.99]
        );
        $this->assertSame(1700000000, $msg->getCreationTime());
    }

    public function testGroupIdReturnsNullForNonScalarValue(): void
    {
        $msg = new Message(
            offset: 0,
            timestamp: 0,
            body: '',
            properties: ['group-id' => ['not', 'scalar']]
        );
        $this->assertNull($msg->getGroupId());
    }

    public function testMessageIdReturnsRawValueForNonScalar(): void
    {
        $msg = new Message(
            offset: 0,
            timestamp: 0,
            body: '',
            properties: ['message-id' => ['not', 'scalar']]
        );
        $this->assertSame(['not', 'scalar'], $msg->getMessageId());
    }

    public function testCorrelationIdReturnsRawValueForNonScalar(): void
    {
        $msg = new Message(
            offset: 0,
            timestamp: 0,
            body: '',
            properties: ['correlation-id' => 42]
        );
        $this->assertSame(42, $msg->getCorrelationId());
    }

    public function testPropertiesArrayIsNotSharedWithExternalReference(): void
    {
        $props = ['message-id' => 'original'];
        $msg = new Message(offset: 0, timestamp: 0, body: 'test', properties: $props);

        $props['message-id'] = 'hacked';
        $this->assertSame('original', $msg->getMessageId());
    }

    public function testApplicationPropertiesArrayIsNotSharedWithExternalReference(): void
    {
        $appProps = ['key' => 'original'];
        $msg = new Message(offset: 0, timestamp: 0, body: 'test', applicationProperties: $appProps);

        $appProps['key'] = 'hacked';
        $this->assertSame(['key' => 'original'], $msg->getApplicationProperties());
    }

    public function testDefaultConstructorValues(): void
    {
        $msg = new Message(offset: 0, timestamp: 0);

        $this->assertSame([], $msg->getProperties());
        $this->assertSame([], $msg->getApplicationProperties());
        $this->assertSame([], $msg->getMessageAnnotations());
    }

    public function testGetPropertiesReturnsFullArray(): void
    {
        $props = [
            'message-id' => 'msg-1',
            'content-type' => 'text/html',
        ];
        $msg = new Message(offset: 0, timestamp: 0, body: null, properties: $props);

        $this->assertSame($props, $msg->getProperties());
    }

    public function testExplicitNullPropertyValueReturnsNull(): void
    {
        $msg = new Message(
            offset: 0,
            timestamp: 0,
            body: null,
            properties: ['message-id' => null]
        );
        $this->assertNull($msg->getMessageId());
    }
}
