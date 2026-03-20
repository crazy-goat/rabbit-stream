<?php

declare(strict_types=1);

namespace CrazyGoat\RabbitStream\Tests\Request;

use CrazyGoat\RabbitStream\Request\PublishRequestV1;
use CrazyGoat\RabbitStream\VO\PublishedMessage;
use PHPUnit\Framework\TestCase;

class PublishRequestV1Test extends TestCase
{
    public function testSerializesWithSingleMessage(): void
    {
        $message = new PublishedMessage(1, 'hello');
        $request = new PublishRequestV1(42, $message);

        $bytes = $request->toStreamBuffer()->getContents();

        $expected = pack('n', 0x0002)
            . pack('n', 1)
            . pack('C', 42)
            . pack('N', 1)
            . pack('J', 1) . pack('N', 5) . 'hello';

        $this->assertSame($expected, $bytes);
    }

    public function testSerializesWithMultipleMessages(): void
    {
        $msg1 = new PublishedMessage(1, 'foo');
        $msg2 = new PublishedMessage(2, 'bar');
        $request = new PublishRequestV1(1, $msg1, $msg2);

        $bytes = $request->toStreamBuffer()->getContents();

        $expected = pack('n', 0x0002)
            . pack('n', 1)
            . pack('C', 1)
            . pack('N', 2)
            . pack('J', 1) . pack('N', 3) . 'foo'
            . pack('J', 2) . pack('N', 3) . 'bar';

        $this->assertSame($expected, $bytes);
    }
}
