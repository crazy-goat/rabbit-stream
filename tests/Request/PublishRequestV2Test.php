<?php

declare(strict_types=1);

namespace CrazyGoat\RabbitStream\Tests\Request;

use CrazyGoat\RabbitStream\Request\PublishRequestV2;
use CrazyGoat\RabbitStream\VO\PublishedMessageV2;
use PHPUnit\Framework\TestCase;

class PublishRequestV2Test extends TestCase
{
    public function testSerializesWithSingleMessage(): void
    {
        $message = new PublishedMessageV2(1, 'region-a', 'hello');
        $request = new PublishRequestV2(7, $message);

        $bytes = $request->toStreamBuffer()->getContents();

        $expected = pack('n', 0x0002)
            . pack('n', 2)
            . pack('C', 7)
            . pack('N', 1)
            . pack('J', 1)
            . pack('n', 8) . 'region-a'
            . pack('N', 5) . 'hello';

        $this->assertSame($expected, $bytes);
    }

    public function testSerializesWithMultipleMessages(): void
    {
        $msg1 = new PublishedMessageV2(1, 'eu', 'foo');
        $msg2 = new PublishedMessageV2(2, 'us', 'bar');
        $request = new PublishRequestV2(3, $msg1, $msg2);

        $bytes = $request->toStreamBuffer()->getContents();

        $expected = pack('n', 0x0002)
            . pack('n', 2)
            . pack('C', 3)
            . pack('N', 2)
            . pack('J', 1) . pack('n', 2) . 'eu' . pack('N', 3) . 'foo'
            . pack('J', 2) . pack('n', 2) . 'us' . pack('N', 3) . 'bar';

        $this->assertSame($expected, $bytes);
    }
}
