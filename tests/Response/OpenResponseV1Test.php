<?php

declare(strict_types=1);

namespace CrazyGoat\RabbitStream\Tests\Response;

use CrazyGoat\RabbitStream\Buffer\ReadBuffer;
use CrazyGoat\RabbitStream\Response\OpenResponseV1;
use CrazyGoat\RabbitStream\VO\KeyValue;
use PHPUnit\Framework\TestCase;

class OpenResponseV1Test extends TestCase
{
    public function testDeserializesWithNoProperties(): void
    {
        $raw = pack('n', 0x8015)    // key
            . pack('n', 1)          // version
            . pack('N', 3)          // correlationId
            . pack('n', 0x0001)     // responseCode OK
            . pack('N', 0);        // empty properties array

        $response = OpenResponseV1::fromStreamBuffer(new ReadBuffer($raw));

        $this->assertInstanceOf(OpenResponseV1::class, $response);
        $this->assertSame(3, $response->getCorrelationId());
    }

    public function testDeserializesWithProperties(): void
    {
        $raw = pack('n', 0x8015)
            . pack('n', 1)
            . pack('N', 1)
            . pack('n', 0x0001)
            . pack('N', 1)                      // 1 property
            . pack('n', 7) . 'cluster'          // key
            . pack('n', 6) . 'rabbit';         // value

        $response = OpenResponseV1::fromStreamBuffer(new ReadBuffer($raw));

        $this->assertInstanceOf(OpenResponseV1::class, $response);
    }

    public function testThrowsOnErrorResponseCode(): void
    {
        $raw = pack('n', 0x8015)
            . pack('n', 1)
            . pack('N', 1)
            . pack('n', 0x000c); // Virtual host access failure

        $this->expectException(\Exception::class);
        OpenResponseV1::fromStreamBuffer(new ReadBuffer($raw));
    }

    public function testAdvertisedHostAndPortFromWireBytes(): void
    {
        $raw = pack('n', 0x8015)
            . pack('n', 1)
            . pack('N', 1)
            . pack('n', 0x0001)
            . pack('N', 2)                                  // 2 properties
            . pack('n', 15) . 'advertised_host'
            . pack('n', 11) . 'lb.internal'
            . pack('n', 15) . 'advertised_port'
            . pack('n', 4) . '5552';

        $response = OpenResponseV1::fromStreamBuffer(new ReadBuffer($raw));

        $this->assertInstanceOf(OpenResponseV1::class, $response);
        $this->assertSame('lb.internal', $response->getAdvertisedHost());
        $this->assertSame(5552, $response->getAdvertisedPort());
    }

    public function testAdvertisedHostAndPortArePresent(): void
    {
        $response = new OpenResponseV1(
            new KeyValue('product', 'RabbitMQ'),
            new KeyValue('advertised_host', 'broker.example.com'),
            new KeyValue('advertised_port', '5552'),
        );

        $this->assertSame('broker.example.com', $response->getAdvertisedHost());
        $this->assertSame(5552, $response->getAdvertisedPort());
    }

    public function testAdvertisedHostAndPortAreNullWhenAbsent(): void
    {
        $response = new OpenResponseV1(new KeyValue('product', 'RabbitMQ'));

        $this->assertNull($response->getAdvertisedHost());
        $this->assertNull($response->getAdvertisedPort());
    }

    public function testAdvertisedHostAndPortAreNullWithNoProperties(): void
    {
        $response = new OpenResponseV1();

        $this->assertNull($response->getAdvertisedHost());
        $this->assertNull($response->getAdvertisedPort());
    }

    public function testAdvertisedHostIsNullWhenOnlyPortIsPresent(): void
    {
        $response = new OpenResponseV1(new KeyValue('advertised_port', '5552'));

        $this->assertNull($response->getAdvertisedHost());
        $this->assertSame(5552, $response->getAdvertisedPort());
    }

    public function testAdvertisedPortIsNullWhenOnlyHostIsPresent(): void
    {
        $response = new OpenResponseV1(new KeyValue('advertised_host', 'broker.example.com'));

        $this->assertSame('broker.example.com', $response->getAdvertisedHost());
        $this->assertNull($response->getAdvertisedPort());
    }

    /** @return iterable<string, array{string|null}> */
    public static function emptyHostProvider(): iterable
    {
        yield 'empty string' => [''];
        yield 'null value' => [null];
    }

    /** @dataProvider emptyHostProvider */
    public function testAdvertisedHostIsNullWhenEmpty(?string $value): void
    {
        $response = new OpenResponseV1(new KeyValue('advertised_host', $value));

        $this->assertNull($response->getAdvertisedHost());
    }

    /** @return iterable<string, array{string|null}> */
    public static function malformedPortProvider(): iterable
    {
        yield 'empty string' => [''];
        yield 'null value' => [null];
        yield 'letters' => ['abc'];
        yield 'mixed letters and digits' => ['5552abc'];
        yield 'leading sign' => ['+5552'];
        yield 'negative' => ['-1'];
        yield 'decimal' => ['5552.0'];
        yield 'exponent' => ['1e3'];
        yield 'surrounding spaces' => [' 5552 '];
        yield 'zero' => ['0'];
        yield 'just above range' => ['65536'];
        yield 'far above range' => ['99999999999999999999'];
        yield 'six digits' => ['100000'];
        yield 'trailing newline' => ["5552\n"];
    }

    /** @dataProvider malformedPortProvider */
    public function testAdvertisedPortIsNullWhenMalformedOrOutOfRange(?string $value): void
    {
        $response = new OpenResponseV1(new KeyValue('advertised_port', $value));

        $this->assertNull($response->getAdvertisedPort());
    }

    /** @return iterable<string, array{string, int}> */
    public static function boundaryPortProvider(): iterable
    {
        yield 'lowest valid port' => ['1', 1];
        yield 'highest valid port' => ['65535', 65535];
        yield 'default stream port' => ['5552', 5552];
    }

    /** @dataProvider boundaryPortProvider */
    public function testAdvertisedPortAcceptsRange(string $value, int $expected): void
    {
        $response = new OpenResponseV1(new KeyValue('advertised_port', $value));

        $this->assertSame($expected, $response->getAdvertisedPort());
    }

    public function testFirstAdvertisedPropertyWins(): void
    {
        $response = new OpenResponseV1(
            new KeyValue('advertised_port', '5552'),
            new KeyValue('advertised_port', '6000'),
        );

        $this->assertSame(5552, $response->getAdvertisedPort());
    }
}
