<?php

declare(strict_types=1);

namespace CrazyGoat\RabbitStream\Tests\Response;

use CrazyGoat\RabbitStream\Buffer\ReadBuffer;
use CrazyGoat\RabbitStream\Response\MetadataUpdateResponseV1;
use PHPUnit\Framework\TestCase;

class MetadataUpdateResponseV1Test extends TestCase
{
    public function testDeserializes(): void
    {
        $raw = pack('n', 0x0010)
            . pack('n', 1)
            . pack('n', 0x0002)
            . pack('n', 11) . 'test-stream';

        $response = MetadataUpdateResponseV1::fromStreamBuffer(new ReadBuffer($raw));

        $this->assertInstanceOf(MetadataUpdateResponseV1::class, $response);
        $this->assertSame(0x0002, $response->getCode());
        $this->assertSame('test-stream', $response->getStream());
    }
}
