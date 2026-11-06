<?php

declare(strict_types=1);

namespace CrazyGoat\RabbitStream\Tests\Request;

use CrazyGoat\RabbitStream\Exception\InvalidArgumentException;
use CrazyGoat\RabbitStream\Request\CreditRequestV1;
use PHPUnit\Framework\TestCase;

class CreditRequestV1Test extends TestCase
{
    public function testSerializesCorrectly(): void
    {
        $request = new CreditRequestV1(5, 100);

        $bytes = $request->toStreamBuffer()->getContents();

        $expected = pack('n', 0x0009)   // key
            . pack('n', 1)              // version
            . pack('C', 5)              // subscriptionId
            . pack('n', 100);           // credit

        $this->assertSame($expected, $bytes);
    }

    public function testSerializesMaxCreditAsSignedInt16(): void
    {
        $request = new CreditRequestV1(5, 32767);

        $bytes = $request->toStreamBuffer()->getContents();

        $this->assertSame(pack('n', 32767), substr($bytes, -2));
    }

    public function testSerializesZeroCredit(): void
    {
        $request = new CreditRequestV1(5, 0);

        $bytes = $request->toStreamBuffer()->getContents();

        $this->assertSame(pack('n', 0), substr($bytes, -2));
    }

    /**
     * @dataProvider invalidCreditProvider
     */
    public function testRejectsCreditOutsideSignedInt16Range(int $credit): void
    {
        $this->expectException(InvalidArgumentException::class);

        new CreditRequestV1(5, $credit);
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function invalidCreditProvider(): iterable
    {
        yield 'just above the signed limit' => [32768];
        yield 'top of the uint16 range' => [65535];
        yield 'negative' => [-1];
    }
}
