<?php

declare(strict_types=1);

namespace CrazyGoat\RabbitStream\Tests\Request;

use CrazyGoat\RabbitStream\Request\ResolveOffsetSpecRequestV1;
use CrazyGoat\RabbitStream\VO\OffsetSpec;
use PHPUnit\Framework\TestCase;

class ResolveOffsetSpecRequestV1Test extends TestCase
{
    public function testSerializesCorrectly(): void
    {
        $request = new ResolveOffsetSpecRequestV1(
            'my-stream',
            OffsetSpec::first()
        );
        $request->withCorrelationId(42);

        $bytes = $request->toStreamBuffer()->getContents();

        $expected = pack('n', 0x001f)                  // key
            . pack('n', 1)                             // version
            . pack('N', 42)                            // correlationId
            . pack('n', strlen('my-stream'))          // stream length
            . 'my-stream'                              // stream
            . pack('n', 0x0001)                        // offsetSpec type (FIRST = 0x0001)
            . pack('N', 0);                            // properties array length (0 = empty)

        $this->assertSame($expected, $bytes);
    }

    public function testSerializesWithOffsetValue(): void
    {
        $request = new ResolveOffsetSpecRequestV1(
            'my-stream',
            OffsetSpec::offset(12345)
        );
        $request->withCorrelationId(99);

        $bytes = $request->toStreamBuffer()->getContents();

        $expected = pack('n', 0x001f)
            . pack('n', 1)
            . pack('N', 99)
            . pack('n', strlen('my-stream'))
            . 'my-stream'
            . pack('n', 0x0004)                        // type OFFSET = 0x0004
            . pack('J', 12345)                         // value uint64
            . pack('N', 0);                            // properties array length (0 = empty)

        $this->assertSame($expected, $bytes);
    }

    public function testSerializesWithProperties(): void
    {
        $request = new ResolveOffsetSpecRequestV1(
            'my-stream',
            OffsetSpec::first(),
            ['match-unfiltered' => 'true', 'filter' => 'abc']
        );
        $request->withCorrelationId(7);

        $bytes = $request->toStreamBuffer()->getContents();

        $expected = pack('n', 0x001f)                  // key
            . pack('n', 1)                             // version
            . pack('N', 7)                             // correlationId
            . pack('n', strlen('my-stream'))           // stream length
            . 'my-stream'                              // stream
            . pack('n', 0x0001)                        // offsetSpec type (FIRST = 0x0001)
            . pack('N', 2)                             // properties array length
            . pack('n', strlen('match-unfiltered'))    // property 1 key length
            . 'match-unfiltered'                       // property 1 key
            . pack('n', strlen('true'))                // property 1 value length
            . 'true'                                   // property 1 value
            . pack('n', strlen('filter'))              // property 2 key length
            . 'filter'                                 // property 2 key
            . pack('n', strlen('abc'))                 // property 2 value length
            . 'abc';                                   // property 2 value

        $this->assertSame($expected, $bytes);
    }
}
