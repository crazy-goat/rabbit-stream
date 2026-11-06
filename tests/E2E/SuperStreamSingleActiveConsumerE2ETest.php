<?php

declare(strict_types=1);

namespace CrazyGoat\RabbitStream\Tests\E2E;

use CrazyGoat\RabbitStream\Client\Connection;
use CrazyGoat\RabbitStream\Client\Message;
use CrazyGoat\RabbitStream\VO\OffsetSpec;

/**
 * Issue #934: a single-active-consumer member that the broker demotes on a
 * super stream must not return messages of the partition it lost, and the
 * stored offset of that partition must not move back.
 *
 * Member A reads partition 1 first. Member B joins the same name, and the
 * broker moves partition 1 to B. A learns this only from its ConsumerUpdate.
 * The test drives A's connection with a synchronous request so that A reads
 * the ConsumerUpdate, as the issue's repro does.
 */
class SuperStreamSingleActiveConsumerE2ETest extends E2ETestCase
{
    private ?Connection $admin = null;
    private ?Connection $connectionA = null;
    private ?Connection $connectionB = null;
    private string $superStream = '';

    protected function setUp(): void
    {
        $this->admin = $this->createConnection();
        $this->connectionA = $this->createConnection();
        $this->connectionB = $this->createConnection();
        $this->superStream = 'test-ssac-' . uniqid();
    }

    protected function tearDown(): void
    {
        foreach ([$this->connectionB, $this->connectionA] as $connection) {
            if ($connection instanceof Connection) {
                try {
                    $connection->close();
                } catch (\Exception) {
                    // Cleanup is best effort.
                }
            }
        }
        if ($this->admin instanceof Connection) {
            try {
                $this->admin->deleteSuperStream($this->superStream);
            } catch (\Exception) {
                // The super stream may not exist when setUp() failed.
            }
            try {
                $this->admin->close();
            } catch (\Exception) {
                // Cleanup is best effort.
            }
        }
    }

    public function testDemotedMemberReturnsNoMessageOfLostPartitionAndStoredOffsetDoesNotMoveBack(): void
    {
        $this->assertNotNull($this->admin);
        $this->assertNotNull($this->connectionA);
        $this->assertNotNull($this->connectionB);

        $admin = $this->admin;
        $connectionA = $this->connectionA;
        $connectionB = $this->connectionB;
        $partitions = [$this->superStream . '-p0', $this->superStream . '-p1'];
        $group = 'ssac-group-' . uniqid();
        $autoCommit = 3;
        // Partition 1 offsets that B returns, in order. Used to derive B's last store.
        $bPartitionOffsets = [];

        $admin->createSuperStream($this->superStream, $partitions, ['k0', 'k1']);
        foreach ($partitions as $i => $partition) {
            $producer = $admin->createProducer($partition);
            // One batch per partition. The broker normally stores one Publish frame as
            // one chunk, so the consumer holds unread messages when autoCommit stores an offset.
            $producer->sendBatch(array_map(
                static fn (int $m): string => "p{$i}-m{$m}",
                range(0, 9),
            ));
            $producer->waitForConfirms(timeout: 5.0);
            $producer->close();
        }

        $a = $connectionA->createSuperStreamConsumer(
            $this->superStream,
            OffsetSpec::first(),
            $group,
            autoCommit: $autoCommit,
            singleActiveConsumer: true,
        );

        // A reads until partition 1 has a stored offset.
        $deadline = microtime(true) + 20.0;
        while ($admin->queryOffset($group, $partitions[1]) === null && microtime(true) < $deadline) {
            $a->readOne(timeout: 0.5);
        }
        $this->assertNotNull($admin->queryOffset($group, $partitions[1]), 'A must store an offset of partition 1');

        // A keeps partition 1 messages unread. Without them the old code has nothing to return.
        $this->assertTrue(
            $a->getConsumers()[$partitions[1]]->hasUnread(),
            'A must hold unread partition 1 messages before the handover'
        );

        $b = $connectionB->createSuperStreamConsumer(
            $this->superStream,
            OffsetSpec::first(),
            $group,
            autoCommit: $autoCommit,
            singleActiveConsumer: true,
        );

        // Wait until the broker has moved partition 1 from A to B. The read on A's
        // connection dispatches the ConsumerUpdate that demotes A.
        $deadline = microtime(true) + 20.0;
        while (microtime(true) < $deadline && ($a->isActive($partitions[1]) || !$b->isActive($partitions[1]))) {
            $connectionA->queryOffset($group, $partitions[0]);
            $message = $b->readOne(timeout: 0.2);
            if ($message instanceof Message && $message->getStream() === $partitions[1]) {
                $bPartitionOffsets[] = $message->getOffset();
            }
        }
        $this->assertFalse($a->isActive($partitions[1]), 'A must be demoted on partition 1');
        $this->assertTrue($b->isActive($partitions[1]), 'B must be active on partition 1');

        // B reads partition 1 up to offset 9 and stores its progress.
        $deadline = microtime(true) + 20.0;
        $lastRead = null;
        while ($lastRead !== 9 && microtime(true) < $deadline) {
            $message = $b->readOne(timeout: 0.5);
            $lastRead = $message?->getOffset();
            if ($message instanceof Message && $message->getStream() === $partitions[1]) {
                $bPartitionOffsets[] = $message->getOffset();
            }
        }
        $this->assertSame(9, $lastRead, 'B must read partition 1 up to offset 9');
        // Derive the value B stores last. B resumes at the stored offset, which A may
        // have raised on demotion, so the first offset B returns is B's resume point.
        // Auto-commit then stores lastOffset + 1 after every $autoCommit messages B
        // processes. If B stores nothing, the broker keeps that resume point.
        $this->assertNotEmpty($bPartitionOffsets, 'B must return partition 1 messages');
        $expectedByB = $bPartitionOffsets[0];
        $processedSinceStore = 0;
        foreach ($bPartitionOffsets as $offset) {
            $processedSinceStore++;
            if ($processedSinceStore === $autoCommit) {
                $expectedByB = $offset + 1;
                $processedSinceStore = 0;
            }
        }
        // StoreOffset has no reply. Poll until the broker shows B's last store.
        $deadline = microtime(true) + 5.0;
        while (microtime(true) < $deadline && $admin->queryOffset($group, $partitions[1]) !== $expectedByB) {
            usleep(50_000);
        }
        $storedByB = $admin->queryOffset($group, $partitions[1]);
        $this->assertSame($expectedByB, $storedByB, 'B must store its last offset of partition 1');
        $this->assertGreaterThan(3, $storedByB, 'B must store progress beyond the offset A stored');

        // A reads after its demotion. Each read must leave the stored offset unchanged.
        $streamsReturnedByA = [];
        for ($i = 0; $i < 12; $i++) {
            $message = $a->readOne(timeout: 0.5);
            $this->assertSame(
                $storedByB,
                $admin->queryOffset($group, $partitions[1]),
                'A read must not move the stored offset of partition 1'
            );
            if ($message instanceof Message) {
                $streamsReturnedByA[] = $message->getStream();
            }
        }
        $this->assertNotContains(
            $partitions[1],
            $streamsReturnedByA,
            'Demoted member returned a message of the lost partition'
        );

        // A closes. Its stale position must not replace the offset that B stored.
        $a->close();
        $this->assertSame(
            $storedByB,
            $admin->queryOffset($group, $partitions[1]),
            'The demoted member must not move the stored offset of partition 1 back'
        );

        $b->close();
    }
}
