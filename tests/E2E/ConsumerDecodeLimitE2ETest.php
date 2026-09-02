<?php

declare(strict_types=1);

namespace CrazyGoat\RabbitStream\Tests\E2E;

use CrazyGoat\RabbitStream\Client\Connection;
use CrazyGoat\RabbitStream\Client\Message;
use CrazyGoat\RabbitStream\Exception\DeserializationException;
use CrazyGoat\RabbitStream\Request\DeclarePublisherRequestV1;
use CrazyGoat\RabbitStream\Request\PublishRequestV1;
use CrazyGoat\RabbitStream\Response\DeclarePublisherResponseV1;
use CrazyGoat\RabbitStream\Tests\Support\AmqpFixtures;
use CrazyGoat\RabbitStream\VO\OffsetSpec;
use CrazyGoat\RabbitStream\VO\PublishedMessage;

/**
 * The consumer's AMQP decode depth limit, end to end (#450).
 *
 * The limit is chosen on the consumer but applied inside Message, on the first
 * accessor call — so nothing short of a real delivery proves it survives the
 * whole path (Consumer -> OsirisChunkParser -> Message -> AmqpDecoder). The
 * nested body has to be published through the low-level API, since the
 * high-level Producer always wraps a payload in a flat Data section.
 */
class ConsumerDecodeLimitE2ETest extends E2ETestCase
{
    private ?Connection $connection = null;
    private string $stream = '';

    protected function setUp(): void
    {
        $this->connection = $this->createConnection();
        $this->stream = 'test-decode-depth-' . uniqid();
        $this->connection->createStream($this->stream);
    }

    protected function tearDown(): void
    {
        if ($this->connection instanceof Connection) {
            try {
                $this->connection->deleteStream($this->stream);
            } catch (\Exception) {
                // Already gone.
            }
            $this->connection->close();
        }
        $this->connection = null;
    }

    public function testDeliveredMessageIsRejectedWhenNestedDeeperThanTheConsumerAllows(): void
    {
        $this->publishNestedBody(depth: 4);

        $connection = $this->connection;
        $this->assertInstanceOf(Connection::class, $connection);

        $strict = $connection->createConsumer($this->stream, OffsetSpec::first(), maxDecodeDepth: 2);
        $message = $strict->readOne(timeout: 5.0);
        $this->assertInstanceOf(Message::class, $message);

        // read() succeeded: decoding is lazy, so the limit only bites here.
        try {
            $message->getBody();
            $this->fail('Expected maxDecodeDepth: 2 to reject a body nested 4 lists deep');
        } catch (DeserializationException $e) {
            $this->assertStringContainsString('recursion depth limit exceeded (max 2)', $e->getMessage());
        }
        $strict->close();

        // The same delivered bytes decode for a consumer with the default limit.
        $lenient = $connection->createConsumer($this->stream, OffsetSpec::first());
        $decoded = $lenient->readOne(timeout: 5.0);
        $this->assertInstanceOf(Message::class, $decoded);
        $this->assertSame([[[[null]]]], $decoded->getBody());
        $lenient->close();
    }

    /**
     * Publish one entry whose body is an AmqpValue section nested $depth lists
     * deep, using the low-level API (the high-level Producer only ever writes a
     * flat Data section).
     */
    private function publishNestedBody(int $depth): void
    {
        $publisher = $this->connectAndOpen();
        $publisher->sendMessage(new DeclarePublisherRequestV1(1, null, $this->stream));
        $this->assertInstanceOf(DeclarePublisherResponseV1::class, $publisher->readMessage());

        $confirmed = false;
        $publisher->registerPublisher(
            1,
            function (array $ids) use (&$confirmed): void {
                $confirmed = true;
            },
            function (): void {
            }
        );
        $publisher->sendMessage(new PublishRequestV1(
            1,
            new PublishedMessage(0, AmqpFixtures::messageWithNestedBody($depth))
        ));
        $publisher->readLoop(maxFrames: 1);
        $this->assertTrue($confirmed, 'The nested-body entry must be confirmed before consuming');
        $publisher->close();
    }
}
