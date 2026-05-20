<?php

declare(strict_types=1);

namespace CrazyGoat\RabbitStream\Tests\VO;

use CrazyGoat\RabbitStream\Buffer\ReadBuffer;
use CrazyGoat\RabbitStream\VO\KeyValue;
use PHPUnit\Framework\TestCase;

class KeyValueTest extends TestCase
{
    public function testConstructorAndGetters(): void
    {
        $kv = new KeyValue('key1', 'value1');
        $this->assertSame('key1', $kv->getKey());
        $this->assertSame('value1', $kv->getValue());
    }

    public function testNullValue(): void
    {
        $kv = new KeyValue('key', null);
        $this->assertSame('key', $kv->getKey());
        $this->assertNull($kv->getValue());
    }

    public function testEmptyStringKeyAndValue(): void
    {
        $kv = new KeyValue('', '');
        $this->assertSame('', $kv->getKey());
        $this->assertSame('', $kv->getValue());
    }

    public function testRoundTripSerialization(): void
    {
        $kv = new KeyValue('my-key', 'my-value');
        $binary = $kv->toStreamBuffer()->getContents();
        $deserialized = KeyValue::fromStreamBuffer(new ReadBuffer($binary));
        $this->assertNotNull($deserialized);
        $this->assertSame('my-key', $deserialized->getKey());
        $this->assertSame('my-value', $deserialized->getValue());
    }

    public function testRoundTripSerializationWithNullValue(): void
    {
        $kv = new KeyValue('key-only', null);
        $binary = $kv->toStreamBuffer()->getContents();
        $deserialized = KeyValue::fromStreamBuffer(new ReadBuffer($binary));
        $this->assertNotNull($deserialized);
        $this->assertSame('key-only', $deserialized->getKey());
        $this->assertNull($deserialized->getValue());
    }

    public function testRoundTripSerializationWithEmptyStrings(): void
    {
        $kv = new KeyValue('', '');
        $binary = $kv->toStreamBuffer()->getContents();
        $deserialized = KeyValue::fromStreamBuffer(new ReadBuffer($binary));
        $this->assertNotNull($deserialized);
        $this->assertSame('', $deserialized->getKey());
        $this->assertSame('', $deserialized->getValue());
    }

    public function testToArray(): void
    {
        $kv = new KeyValue('key', 'value');
        $this->assertSame(['key' => 'key', 'value' => 'value'], $kv->toArray());
    }

    public function testToArrayWithNullValue(): void
    {
        $kv = new KeyValue('key', null);
        $this->assertSame(['key' => 'key', 'value' => null], $kv->toArray());
    }

    public function testFromArray(): void
    {
        $kv = KeyValue::fromArray(['key' => 'my-key', 'value' => 'my-value']);
        $this->assertInstanceOf(KeyValue::class, $kv);
        $this->assertSame('my-key', $kv->getKey());
        $this->assertSame('my-value', $kv->getValue());
    }

    public function testFromArrayWithNullValue(): void
    {
        $kv = KeyValue::fromArray(['key' => 'my-key', 'value' => null]);
        $this->assertInstanceOf(KeyValue::class, $kv);
        $this->assertSame('my-key', $kv->getKey());
        $this->assertNull($kv->getValue());
    }

    public function testFromArrayWithMissingValue(): void
    {
        $kv = KeyValue::fromArray(['key' => 'my-key']);
        $this->assertInstanceOf(KeyValue::class, $kv);
        $this->assertSame('my-key', $kv->getKey());
        $this->assertNull($kv->getValue());
    }

    public function testToArrayFromArrayRoundTrip(): void
    {
        $kv = new KeyValue('round-trip', 'works');
        $array = $kv->toArray();
        $restored = KeyValue::fromArray($array);
        $this->assertSame('round-trip', $restored->getKey());
        $this->assertSame('works', $restored->getValue());
    }

    public function testBinarySerializationFormat(): void
    {
        $kv = new KeyValue('ab', 'cd');
        $binary = $kv->toStreamBuffer()->getContents();
        // Format: uint16(2) + "ab" + uint16(2) + "cd"
        $this->assertSame(8, strlen($binary));
        $expected = pack('n', 2) . 'ab' . pack('n', 2) . 'cd';
        $this->assertSame($expected, $binary);
    }

    public function testBinarySerializationFormatNullValue(): void
    {
        $kv = new KeyValue('ab', null);
        $binary = $kv->toStreamBuffer()->getContents();
        // Format: uint16(2) + "ab" + uint16(0xFFFF) (-1 as null)
        $this->assertSame(6, strlen($binary));
        $expected = pack('n', 2) . 'ab' . pack('n', 0xFFFF);
        $this->assertSame($expected, $binary);
    }
}
