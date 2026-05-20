<?php

declare(strict_types=1);

namespace CrazyGoat\RabbitStream\Tests\VO;

use CrazyGoat\RabbitStream\Buffer\ReadBuffer;
use CrazyGoat\RabbitStream\VO\Statistic;
use PHPUnit\Framework\TestCase;

class StatisticTest extends TestCase
{
    public function testConstructorAndGetters(): void
    {
        $stat = new Statistic('messages', 42);
        $this->assertSame('messages', $stat->getKey());
        $this->assertSame(42, $stat->getValue());
    }

    public function testZeroValue(): void
    {
        $stat = new Statistic('offset', 0);
        $this->assertSame('offset', $stat->getKey());
        $this->assertSame(0, $stat->getValue());
    }

    public function testLargeValue(): void
    {
        $stat = new Statistic('big-number', 9223372036854775807);
        $this->assertSame(9223372036854775807, $stat->getValue());
    }

    public function testNegativeValue(): void
    {
        $stat = new Statistic('deficit', -100);
        $this->assertSame(-100, $stat->getValue());
    }

    public function testEmptyKey(): void
    {
        $stat = new Statistic('', 1);
        $this->assertSame('', $stat->getKey());
        $this->assertSame(1, $stat->getValue());
    }

    public function testRoundTripSerialization(): void
    {
        $stat = new Statistic('my-stat', 12345);
        $binary = $stat->toStreamBuffer()->getContents();
        $deserialized = Statistic::fromStreamBuffer(new ReadBuffer($binary));
        $this->assertNotNull($deserialized);
        $this->assertSame('my-stat', $deserialized->getKey());
        $this->assertSame(12345, $deserialized->getValue());
    }

    public function testRoundTripSerializationNegative(): void
    {
        $stat = new Statistic('negative', -42);
        $binary = $stat->toStreamBuffer()->getContents();
        $deserialized = Statistic::fromStreamBuffer(new ReadBuffer($binary));
        $this->assertNotNull($deserialized);
        $this->assertSame(-42, $deserialized->getValue());
    }

    public function testBinarySerializationFormat(): void
    {
        $stat = new Statistic('ab', 99);
        $binary = $stat->toStreamBuffer()->getContents();
        // Format: uint16(2) + "ab" + int64(99)
        $this->assertSame(2 + 2 + 8, strlen($binary));
        $expected = pack('n', 2) . 'ab' . pack('J', 99);
        $this->assertSame($expected, $binary);
    }

    public function testToArray(): void
    {
        $stat = new Statistic('consumers', 5);
        $this->assertSame(['key' => 'consumers', 'value' => 5], $stat->toArray());
    }

    public function testFromArray(): void
    {
        $stat = Statistic::fromArray(['key' => 'connections', 'value' => 10]);
        $this->assertInstanceOf(Statistic::class, $stat);
        $this->assertSame('connections', $stat->getKey());
        $this->assertSame(10, $stat->getValue());
    }

    public function testToArrayFromArrayRoundTrip(): void
    {
        $stat = new Statistic('chunks', 999);
        $array = $stat->toArray();
        $restored = Statistic::fromArray($array);
        $this->assertSame('chunks', $restored->getKey());
        $this->assertSame(999, $restored->getValue());
    }
}
