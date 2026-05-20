<?php

declare(strict_types=1);

namespace CrazyGoat\RabbitStream\Tests\Client;

use CrazyGoat\RabbitStream\Client\Message;
use PHPUnit\Framework\TestCase;

class MessageTest extends TestCase
{
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
        $msg = new Message(offset: 0, timestamp: 0, body: null);

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
        $msg = new Message(offset: 0, timestamp: 0, body: null);
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
        $msg = new Message(offset: 0, timestamp: 0, body: null);

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
