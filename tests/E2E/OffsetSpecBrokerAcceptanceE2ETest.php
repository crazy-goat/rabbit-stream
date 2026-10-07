<?php

declare(strict_types=1);

namespace CrazyGoat\RabbitStream\Tests\E2E;

use CrazyGoat\RabbitStream\Request\CreateRequestV1;
use CrazyGoat\RabbitStream\Request\DeleteStreamRequestV1;
use CrazyGoat\RabbitStream\Request\SubscribeRequestV1;
use CrazyGoat\RabbitStream\Response\SubscribeResponseV1;
use CrazyGoat\RabbitStream\StreamConnection;
use CrazyGoat\RabbitStream\VO\OffsetSpec;

/**
 * #468: every offset type the library can still build must be one the broker
 * accepts.
 *
 * `OffsetSpec::interval()` used to serialize offset type `0x0006`, which is not
 * in the protocol: RabbitMQ 4.3.6 crashes the connection process with
 * `{case_clause,6}` in `rabbit_stream_core:parse_request/1` and drops the TCP
 * connection, so a Subscribe carrying it fails with "connection closed by peer"
 * rather than a response code. The factory is gone; this test is the live-broker
 * gate that a future constant cannot reintroduce an out-of-spec type.
 *
 * Reflection over the public `TYPE_*` constants (not a hard-coded list) keeps
 * the test valid when the set changes, exactly as the client builds the frame.
 */
class OffsetSpecBrokerAcceptanceE2ETest extends E2ETestCase
{
    private ?StreamConnection $admin = null;
    private string $streamName = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->connectAndOpen();
        $this->streamName = 'test-offset-spec-acceptance-' . uniqid();
        $this->admin->sendMessage(new CreateRequestV1($this->streamName));
        $this->admin->readMessage();
    }

    protected function tearDown(): void
    {
        if ($this->admin instanceof StreamConnection && $this->admin->isConnected() && $this->streamName !== '') {
            try {
                $this->admin->sendMessage(new DeleteStreamRequestV1($this->streamName));
                $this->admin->readMessage();
            } catch (\Exception) {
                // Ignore cleanup errors — the stream may already be gone.
            }
        }

        $this->admin?->close();
        $this->admin = null;
    }

    /**
     * @return array<string, array{OffsetSpec}>
     */
    public static function subscribableSpecProvider(): array
    {
        $specs = [];

        $constants = (new \ReflectionClass(OffsetSpec::class))
            ->getConstants(\ReflectionClassConstant::IS_PUBLIC);

        foreach ($constants as $name => $type) {
            // TYPE_NONE is ConsumerUpdate-only and is rejected by Subscribe by
            // design, so it is not a subscribable spec.
            if (!str_starts_with($name, 'TYPE_') || $type === OffsetSpec::TYPE_NONE || !is_int($type)) {
                continue;
            }

            $value = match ($type) {
                OffsetSpec::TYPE_OFFSET => 0,
                OffsetSpec::TYPE_TIMESTAMP => (int) (microtime(true) * 1000) - 60_000,
                default => null,
            };

            $specs[$name] = [new OffsetSpec($type, $value)];
        }

        return $specs;
    }

    /**
     * @dataProvider subscribableSpecProvider
     */
    public function testTheBrokerAcceptsEverySubscribableOffsetType(OffsetSpec $spec): void
    {
        // A dedicated connection per type, so a broker-side crash is attributed
        // to exactly that type instead of poisoning the next subscription.
        $connection = $this->connectAndOpen();

        try {
            $connection->sendMessage(new SubscribeRequestV1(1, $this->streamName, $spec, 10));
            $response = $connection->readMessage();

            $this->assertInstanceOf(
                SubscribeResponseV1::class,
                $response,
                sprintf('The broker rejected offset type 0x%04X.', $spec->getType())
            );

            // The connection must still be usable: a broker that crashed on the
            // frame would have dropped it by now.
            $this->assertTrue($connection->isConnected());
        } finally {
            try {
                $connection->close();
            } catch (\Throwable) {
                // The broker may already have dropped the connection.
            }
        }
    }
}
