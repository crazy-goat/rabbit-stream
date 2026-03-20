<?php

declare(strict_types=1);

namespace CrazyGoat\RabbitStream\Tests\Request;

use CrazyGoat\RabbitStream\Request\CreateSuperStreamRequestV1;
use PHPUnit\Framework\TestCase;

class CreateSuperStreamRequestV1Test extends TestCase
{
    public function testSerializesCorrectly(): void
    {
        $request = new CreateSuperStreamRequestV1(
            'my-super-stream',
            ['partition1', 'partition2', 'partition3'],
            ['key1', 'key2', 'key3'],
            ['max-length-bytes' => '1000000']
        );
        $request->withCorrelationId(42);

        $bytes = $request->toStreamBuffer()->getContents();

        $expected = pack('n', 0x001d)   // key (CREATE_SUPER_STREAM)
            . pack('n', 1)              // version
            . pack('N', 42)             // correlationId
            . pack('n', 15)             // super stream name length
            . 'my-super-stream'         // super stream name
            . pack('N', 3)              // 3 partitions
            . pack('n', 10)             // partition 1 name length
            . 'partition1'               // partition 1 name
            . pack('n', 10)             // partition 2 name length
            . 'partition2'               // partition 2 name
            . pack('n', 10)             // partition 3 name length
            . 'partition3'              // partition 3 name
            . pack('N', 3)              // 3 binding keys
            . pack('n', 4)              // binding key 1 length
            . 'key1'                    // binding key 1
            . pack('n', 4)              // binding key 2 length
            . 'key2'                    // binding key 2
            . pack('n', 4)              // binding key 3 length
            . 'key3'                    // binding key 3
            . pack('N', 1)              // 1 argument
            . pack('n', 16)             // argument key length
            . 'max-length-bytes'        // argument key
            . pack('n', 7)              // argument value length
            . '1000000';               // argument value

        $this->assertSame($expected, $bytes);
    }

    public function testSerializesWithEmptyArrays(): void
    {
        $request = new CreateSuperStreamRequestV1('empty-super-stream');
        $request->withCorrelationId(1);

        $bytes = $request->toStreamBuffer()->getContents();

        $expected = pack('n', 0x001d)   // key
            . pack('n', 1)              // version
            . pack('N', 1)              // correlationId
            . pack('n', 18)             // super stream name length
            . 'empty-super-stream'      // super stream name
            . pack('N', 0)              // 0 partitions
            . pack('N', 0)              // 0 binding keys
            . pack('N', 0);            // 0 arguments

        $this->assertSame($expected, $bytes);
    }
}
