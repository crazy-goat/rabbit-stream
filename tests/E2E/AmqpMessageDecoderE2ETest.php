<?php

declare(strict_types=1);

namespace CrazyGoat\RabbitStream\Tests\E2E;

use CrazyGoat\RabbitStream\Client\AmqpMessageDecoder;
use CrazyGoat\RabbitStream\Client\Message;
use CrazyGoat\RabbitStream\Client\OsirisChunkParser;
use CrazyGoat\RabbitStream\Request\CreateRequestV1;
use CrazyGoat\RabbitStream\Request\CreditRequestV1;
use CrazyGoat\RabbitStream\Request\DeclarePublisherRequestV1;
use CrazyGoat\RabbitStream\Request\DeletePublisherRequestV1;
use CrazyGoat\RabbitStream\Request\OpenRequestV1;
use CrazyGoat\RabbitStream\Request\PeerPropertiesRequestV1;
use CrazyGoat\RabbitStream\Request\PublishRequestV1;
use CrazyGoat\RabbitStream\Request\SaslAuthenticateRequestV1;
use CrazyGoat\RabbitStream\Request\SaslHandshakeRequestV1;
use CrazyGoat\RabbitStream\Request\SubscribeRequestV1;
use CrazyGoat\RabbitStream\Request\TuneRequestV1;
use CrazyGoat\RabbitStream\Request\UnsubscribeRequestV1;
use CrazyGoat\RabbitStream\Response\CreateResponseV1;
use CrazyGoat\RabbitStream\Response\DeclarePublisherResponseV1;
use CrazyGoat\RabbitStream\Response\DeliverResponseV1;
use CrazyGoat\RabbitStream\Response\SubscribeResponseV1;
use CrazyGoat\RabbitStream\Response\TuneResponseV1;
use CrazyGoat\RabbitStream\StreamConnection;
use CrazyGoat\RabbitStream\VO\OffsetSpec;
use CrazyGoat\RabbitStream\VO\PublishedMessage;
use PHPUnit\Framework\TestCase;

/**
 * E2E tests for AMQP 1.0 Message Decoder
 *
 * These tests verify that messages published to RabbitMQ Stream can be
 * correctly decoded using the AmqpMessageDecoder.
 */
class AmqpMessageDecoderE2ETest extends TestCase
{
    private static string $host = '127.0.0.1';
    private static int $port = 5552;

    public static function setUpBeforeClass(): void
    {
        self::$host = getenv('RABBITMQ_HOST') ?: self::$host;
        self::$port = (int)(getenv('RABBITMQ_PORT') ?: self::$port);
    }

    private function connectAndOpen(): StreamConnection
    {
        $connection = new StreamConnection(self::$host, self::$port);
        $connection->connect();

        $connection->sendMessage(new PeerPropertiesRequestV1());
        $connection->readMessage();

        $connection->sendMessage(new SaslHandshakeRequestV1());
        $connection->readMessage();

        $connection->sendMessage(new SaslAuthenticateRequestV1('PLAIN', 'guest', 'guest'));
        $connection->readMessage();

        $tune = $connection->readMessage();
        $this->assertInstanceOf(TuneRequestV1::class, $tune);
        $connection->sendMessage(new TuneResponseV1($tune->getFrameMax(), $tune->getHeartbeat()));

        $connection->sendMessage(new OpenRequestV1('/'));
        $connection->readMessage();

        return $connection;
    }

    /**
     * Build a simple AMQP 1.0 message with Data section only
     */
    private function buildAmqpDataMessage(string $body): string
    {
        // Data section: 0x00 + descriptor 0x75 + vbin32 + content
        $length = strlen($body);
        return "\x00\x53\x75\xb0" . pack('N', $length) . $body;
    }

    /**
     * Build an AMQP 1.0 message with Properties and Data sections
     *
     * @param array<string, string|int> $properties
     */
    private function buildAmqpMessageWithProperties(string $body, array $properties): string
    {
        // Build Properties section (0x73)
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
                $listItems .= "\x40"; // null
            }
            $count++;
        }

        $listSize = strlen($listItems) + 1;
        $propertiesSection = "\x00\x53\x73\xc0" . chr($listSize) . chr($count) . $listItems;

        // Build Data section (0x75)
        $length = strlen($body);
        $dataSection = "\x00\x53\x75\xb0" . pack('N', $length) . $body;

        return $propertiesSection . $dataSection;
    }

    /**
     * Build an AMQP 1.0 message with ApplicationProperties and Data sections
     *
     * @param array<string, string|int|bool> $appProperties
     */
    private function buildAmqpMessageWithAppProperties(string $body, array $appProperties): string
    {
        // Build ApplicationProperties section (0x74)
        $mapItems = '';
        $count = 0;

        foreach ($appProperties as $key => $value) {
            $mapItems .= "\xa1" . chr(strlen((string) $key)) . $key;

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
        $appPropsSection = "\x00\x53\x74\xc1" . chr($mapSize) . chr($count * 2) . $mapItems;

        // Build Data section (0x75)
        $length = strlen($body);
        $dataSection = "\x00\x53\x75\xb0" . pack('N', $length) . $body;

        return $appPropsSection . $dataSection;
    }

    public function testDecodeSimplePublishedMessage(): void
    {
        $connection = $this->connectAndOpen();

        // Create a test stream
        $streamName = 'test-amqp-decode-' . uniqid();
        $connection->sendMessage(new CreateRequestV1($streamName));
        $createResponse = $connection->readMessage();
        $this->assertInstanceOf(CreateResponseV1::class, $createResponse);

        // Declare publisher
        $connection->sendMessage(new DeclarePublisherRequestV1(1, null, $streamName));
        $publisherResponse = $connection->readMessage();
        $this->assertInstanceOf(DeclarePublisherResponseV1::class, $publisherResponse);

        // Publish a simple message
        $messageBody = 'Hello AMQP World';
        $amqpMessage = $this->buildAmqpDataMessage($messageBody);
        $connection->sendMessage(new PublishRequestV1(1, new PublishedMessage(0, $amqpMessage)));

        // Wait for publish confirm using callback
        $confirmed = false;
        $connection->registerPublisher(
            1,
            function (array $ids) use (&$confirmed): void {
                $confirmed = true;
            },
            function (): void {
            }
        );
        $connection->readLoop(maxFrames: 1);
        $this->assertTrue($confirmed, 'Message should be confirmed');

        // Subscribe and receive using callback
        $receivedMessages = [];
        $connection->registerSubscriber(1, function (DeliverResponseV1 $deliver) use (&$receivedMessages): void {
            $entries = OsirisChunkParser::parse($deliver->getChunkBytes());
            foreach ($entries as $entry) {
                $receivedMessages[] = AmqpMessageDecoder::decode($entry);
            }
        });

        $connection->sendMessage(new SubscribeRequestV1(1, $streamName, OffsetSpec::first(), 10));
        $subscribeResponse = $connection->readMessage();
        $this->assertInstanceOf(SubscribeResponseV1::class, $subscribeResponse);

        $connection->sendMessage(new CreditRequestV1(1, 10));
        $connection->readLoop(maxFrames: 1);

        // Verify received message
        $this->assertCount(1, $receivedMessages);
        $this->assertInstanceOf(Message::class, $receivedMessages[0]);
        $this->assertSame($messageBody, $receivedMessages[0]->getBody());
        $this->assertSame(0, $receivedMessages[0]->getOffset());

        // Cleanup
        try {
            if ($connection->isConnected()) {
                $connection->sendMessage(new UnsubscribeRequestV1(1));
                $connection->readMessage();
                $connection->sendMessage(new DeletePublisherRequestV1(1));
                $connection->readMessage();
            }
        } catch (\Throwable) {
            // Server may have already closed the connection
        }

        $connection->close();
    }

    public function testDecodeMessageWithProperties(): void
    {
        $connection = $this->connectAndOpen();

        // Create a test stream
        $streamName = 'test-amqp-props-' . uniqid();
        $connection->sendMessage(new CreateRequestV1($streamName));
        $createResponse = $connection->readMessage();
        $this->assertInstanceOf(CreateResponseV1::class, $createResponse);

        // Declare publisher
        $connection->sendMessage(new DeclarePublisherRequestV1(1, null, $streamName));
        $publisherResponse = $connection->readMessage();
        $this->assertInstanceOf(DeclarePublisherResponseV1::class, $publisherResponse);

        // Publish a message with properties
        $messageBody = '{"key": "value"}';
        $properties = [
            'message-id' => 'msg-123',
            'content-type' => 'application/json',
            'subject' => 'test-message',
        ];
        $amqpMessage = $this->buildAmqpMessageWithProperties($messageBody, $properties);
        $connection->sendMessage(new PublishRequestV1(1, new PublishedMessage(0, $amqpMessage)));

        // Wait for publish confirm
        $confirmed = false;
        $connection->registerPublisher(
            1,
            function (array $ids) use (&$confirmed): void {
                $confirmed = true;
            },
            function (): void {
            }
        );
        $connection->readLoop(maxFrames: 1);
        $this->assertTrue($confirmed);

        // Subscribe and receive
        $receivedMessages = [];
        $connection->registerSubscriber(1, function (DeliverResponseV1 $deliver) use (&$receivedMessages): void {
            $entries = OsirisChunkParser::parse($deliver->getChunkBytes());
            foreach ($entries as $entry) {
                $receivedMessages[] = AmqpMessageDecoder::decode($entry);
            }
        });

        $connection->sendMessage(new SubscribeRequestV1(1, $streamName, OffsetSpec::first(), 10));
        $connection->readMessage();

        $connection->sendMessage(new CreditRequestV1(1, 10));
        $connection->readLoop(maxFrames: 1);

        // Verify
        $this->assertCount(1, $receivedMessages);
        $message = $receivedMessages[0];
        $this->assertSame($messageBody, $message->getBody());
        $this->assertSame('msg-123', $message->getMessageId());
        $this->assertSame('application/json', $message->getContentType());
        $this->assertSame('test-message', $message->getSubject());

        // Cleanup
        try {
            if ($connection->isConnected()) {
                $connection->sendMessage(new UnsubscribeRequestV1(1));
                $connection->readMessage();
                $connection->sendMessage(new DeletePublisherRequestV1(1));
                $connection->readMessage();
            }
        } catch (\Throwable) {
            // Server may have already closed the connection
        }

        $connection->close();
    }

    public function testDecodeMessageWithApplicationProperties(): void
    {
        $connection = $this->connectAndOpen();

        // Create a test stream
        $streamName = 'test-amqp-app-props-' . uniqid();
        $connection->sendMessage(new CreateRequestV1($streamName));
        $connection->readMessage();

        // Declare publisher
        $connection->sendMessage(new DeclarePublisherRequestV1(1, null, $streamName));
        $connection->readMessage();

        // Publish a message with application properties
        $messageBody = 'Message with custom headers';
        $appProperties = [
            'x-custom-header' => 'custom-value',
            'priority' => 5,
            'processed' => false,
        ];
        $amqpMessage = $this->buildAmqpMessageWithAppProperties($messageBody, $appProperties);
        $connection->sendMessage(new PublishRequestV1(1, new PublishedMessage(0, $amqpMessage)));

        // Wait for publish confirm
        $confirmed = false;
        $connection->registerPublisher(
            1,
            function (array $ids) use (&$confirmed): void {
                $confirmed = true;
            },
            function (): void {
            }
        );
        $connection->readLoop(maxFrames: 1);
        $this->assertTrue($confirmed);

        // Subscribe and receive
        $receivedMessages = [];
        $connection->registerSubscriber(1, function (DeliverResponseV1 $deliver) use (&$receivedMessages): void {
            $entries = OsirisChunkParser::parse($deliver->getChunkBytes());
            foreach ($entries as $entry) {
                $receivedMessages[] = AmqpMessageDecoder::decode($entry);
            }
        });

        $connection->sendMessage(new SubscribeRequestV1(1, $streamName, OffsetSpec::first(), 10));
        $connection->readMessage();

        $connection->sendMessage(new CreditRequestV1(1, 10));
        $connection->readLoop(maxFrames: 1);

        // Verify
        $this->assertCount(1, $receivedMessages);
        $message = $receivedMessages[0];
        $this->assertSame($messageBody, $message->getBody());
        $this->assertSame('custom-value', $message->getApplicationProperties()['x-custom-header']);
        $this->assertSame(5, $message->getApplicationProperties()['priority']);
        $this->assertFalse($message->getApplicationProperties()['processed']);

        // Cleanup
        try {
            if ($connection->isConnected()) {
                $connection->sendMessage(new UnsubscribeRequestV1(1));
                $connection->readMessage();
                $connection->sendMessage(new DeletePublisherRequestV1(1));
                $connection->readMessage();
            }
        } catch (\Throwable) {
            // Server may have already closed the connection
        }

        $connection->close();
    }

    public function testDecodeMultipleMessages(): void
    {
        $connection = $this->connectAndOpen();

        // Create a test stream
        $streamName = 'test-amqp-multi-' . uniqid();
        $connection->sendMessage(new CreateRequestV1($streamName));
        $connection->readMessage();

        // Declare publisher
        $connection->sendMessage(new DeclarePublisherRequestV1(1, null, $streamName));
        $connection->readMessage();

        // Publish multiple messages in a single request
        $messages = [
            ['body' => 'Message 1', 'props' => ['message-id' => 'msg-1', 'content-type' => 'text/plain']],
            ['body' => 'Message 2', 'props' => ['message-id' => 'msg-2', 'content-type' => 'text/plain']],
            ['body' => 'Message 3', 'props' => ['message-id' => 'msg-3', 'content-type' => 'text/plain']],
        ];

        $publishedMessages = [];
        foreach ($messages as $index => $msg) {
            $amqpMessage = $this->buildAmqpMessageWithProperties($msg['body'], $msg['props']);
            $publishedMessages[] = new PublishedMessage($index, $amqpMessage);
        }
        $connection->sendMessage(new PublishRequestV1(1, ...$publishedMessages));

        // Wait for all publish confirms
        $confirmedCount = 0;
        $connection->registerPublisher(
            1,
            function (array $ids) use (&$confirmedCount): void {
                $confirmedCount += count($ids);
            },
            function (): void {
            }
        );
        $connection->readLoop(maxFrames: 1);
        $this->assertSame(3, $confirmedCount);

        // Subscribe and receive all messages
        $receivedMessages = [];
        $connection->registerSubscriber(1, function (DeliverResponseV1 $deliver) use (&$receivedMessages): void {
            $entries = OsirisChunkParser::parse($deliver->getChunkBytes());
            foreach ($entries as $entry) {
                $receivedMessages[] = AmqpMessageDecoder::decode($entry);
            }
        });

        $connection->sendMessage(new SubscribeRequestV1(1, $streamName, OffsetSpec::first(), 10));
        $connection->readMessage();

        $connection->sendMessage(new CreditRequestV1(1, 10));
        $connection->readLoop(maxFrames: 1);

        // Verify all messages (they may arrive in one or multiple chunks)
        $this->assertGreaterThanOrEqual(3, count($receivedMessages), 'Should receive at least 3 messages');

        // Verify message IDs are present (order may vary)
        $receivedIds = array_map(fn(Message $m): mixed => $m->getMessageId(), $receivedMessages);
        $this->assertContains('msg-1', $receivedIds);
        $this->assertContains('msg-2', $receivedIds);
        $this->assertContains('msg-3', $receivedIds);

        // Cleanup
        try {
            if ($connection->isConnected()) {
                $connection->sendMessage(new UnsubscribeRequestV1(1));
                $connection->readMessage();
                $connection->sendMessage(new DeletePublisherRequestV1(1));
                $connection->readMessage();
            }
        } catch (\Throwable) {
            // Server may have already closed the connection
        }

        $connection->close();
    }
}
