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

        $admin->createSuperStream($this->superStream, $partitions, ['k0', 'k1']);
        foreach ($partitions as $i => $partition) {
            $producer = $admin->createProducer($partition);
            for ($m = 0; $m < 10; $m++) {
                $producer->send("p{$i}-m{$m}");
            }
            $producer->waitForConfirms(timeout: 5.0);
            $producer->close();
        }

        $a = $connectionA->createSuperStreamConsumer(
            $this->superStream,
            OffsetSpec::first(),
            $group,
            autoCommit: 3,
            singleActiveConsumer: true,
        );

        // A reads until partition 1 has a stored offset.
        $deadline = microtime(true) + 20.0;
        while ($admin->queryOffset($group, $partitions[1]) === null && microtime(true) < $deadline) {
            $a->readOne(timeout: 0.5);
        }
        $this->assertNotNull($admin->queryOffset($group, $partitions[1]), 'A must store an offset of partition 1');

        $b = $connectionB->createSuperStreamConsumer(
            $this->superStream,
            OffsetSpec::first(),
            $group,
            autoCommit: 3,
            singleActiveConsumer: true,
        );

        // Wait until the broker has moved partition 1 from A to B. The read of
        // A's connection dispatches the ConsumerUpdate that demotes A.
        $deadline = microtime(true) + 20.0;
        while (microtime(true) < $deadline && ($a->isActive($partitions[1]) || !$b->isActive($partitions[1]))) {
            $connectionA->queryOffset($group, $partitions[0]);
            $b->readOne(timeout: 0.2);
        }
        $this->assertFalse($a->isActive($partitions[1]), 'A must be demoted on partition 1');
        $this->assertTrue($b->isActive($partitions[1]), 'B must be active on partition 1');

        $storedBefore = $admin->queryOffset($group, $partitions[1]);

        // A must not return a message of partition 1 after its demotion.
        for ($i = 0; $i < 12; $i++) {
            $message = $a->readOne(timeout: 0.5);
            if (!$message instanceof Message) {
                continue;
            }
            $this->assertNotSame(
                $partitions[1],
                $message->getStream(),
                'Demoted member returned a message of the lost partition'
            );
        }

        // B reads on and stores its progress. A's stale position must not replace it.
        for ($i = 0; $i < 12; $i++) {
            $b->readOne(timeout: 0.5);
        }
        $storedAfter = $admin->queryOffset($group, $partitions[1]);
        $this->assertGreaterThanOrEqual($storedBefore, $storedAfter);

        $b->close();
        $a->close();
    }
}
