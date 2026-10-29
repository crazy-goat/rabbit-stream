<?php

declare(strict_types=1);

namespace CrazyGoat\RabbitStream\Tests\Client;

use CrazyGoat\RabbitStream\Client\AmqpDecoder;
use CrazyGoat\RabbitStream\Exception\DeserializationException;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

/**
 * The per-message budget of decoded values (#947).
 *
 * Each test that builds a large body runs in its own process: the bodies need tens of MiB, and the peak memory
 * they leave behind would break the peak-memory assertions of AmqpDecoderTest.
 */
class AmqpDecoderBudgetTest extends TestCase
{
    private const ELEMENTS = 131072;

    /** An amqp-value (0x77) section around a value. */
    private function amqpValue(string $value): string
    {
        return "\x00\x53\x77" . $value;
    }

    private function list32(int $count, string $content): string
    {
        return "\xd0" . pack('N', 4 + strlen($content)) . pack('N', $count) . $content;
    }

    /** Parents of list32 with 131072 children, each child a list8 of one null (4 bytes). */
    private function listOfList8Children(int $parents): string
    {
        $child = "\xc0\x02\x01\x40";
        $parent = $this->list32(self::ELEMENTS, str_repeat($child, self::ELEMENTS));

        return $this->amqpValue($this->list32($parents, str_repeat($parent, $parents)));
    }

    /** A list32 of lists, each a list32 of 131072 nulls (1 byte each). */
    private function listOfNullLists(int $lists): string
    {
        $inner = $this->list32(self::ELEMENTS, str_repeat("\x40", self::ELEMENTS));

        return $this->amqpValue($this->list32($lists, str_repeat($inner, $lists)));
    }

    #[RunInSeparateProcess]
    public function testManyList8ChildrenThrowCatchableException(): void
    {
        try {
            AmqpDecoder::decodeMessage($this->listOfList8Children(8));
            $this->fail('Expected DeserializationException');
        } catch (DeserializationException $e) {
            $this->assertStringContainsString('Decoded value limit exceeded', $e->getMessage());
        }

        // The process is still alive and decodes the next message.
        $this->assertSame([null], AmqpDecoder::decodeMessage($this->amqpValue("\xc0\x02\x01\x40"))['body']);
    }

    #[RunInSeparateProcess]
    public function testManyNullListsThrowCatchableException(): void
    {
        $this->expectException(DeserializationException::class);
        $this->expectExceptionMessage('Decoded value limit exceeded');

        AmqpDecoder::decodeMessage($this->listOfNullLists(64));
    }

    #[RunInSeparateProcess]
    public function testBudgetIsSharedAcrossSections(): void
    {
        // Two sections, each below the budget alone (about 1.5 M units), over it together.
        $section = $this->listOfNullLists(11);
        $this->expectException(DeserializationException::class);
        $this->expectExceptionMessage('Decoded value limit exceeded');

        AmqpDecoder::decodeMessage($section . $section);
    }

    #[RunInSeparateProcess]
    public function testBudgetAppliesToMaps(): void
    {
        // map32 of 131072 elements (65536 pairs) with distinct uint keys, 17 times.
        $pairs = '';
        for ($i = 0; $i < 65536; $i++) {
            $pairs .= "\x70" . pack('N', $i) . "\x40";
        }
        $map = "\xd1" . pack('N', 4 + strlen($pairs)) . pack('N', 131072) . $pairs;
        $maps = str_repeat($map, 17);
        $this->expectException(DeserializationException::class);
        $this->expectExceptionMessage('Decoded value limit exceeded');

        AmqpDecoder::decodeMessage($this->amqpValue($this->list32(17, $maps)));
    }

    #[RunInSeparateProcess]
    public function testLargeValidMessageUnderBudgetStillDecodes(): void
    {
        // 2 full-size lists (131072 elements each, the per-compound cap): decoded in full.
        $decoded = AmqpDecoder::decodeMessage($this->listOfNullLists(2));

        $body = $decoded['body'];
        unset($decoded);
        $this->assertIsArray($body);
        $this->assertSame(2, count($body));
        $this->assertSame(self::ELEMENTS, count($body[1]));
    }

    #[RunInSeparateProcess]
    public function testDescribedValuesAreChargedToTheBudget(): void
    {
        // 4 lists of 131072 described values (00 40 40: descriptor null, value null).
        $inner = $this->list32(self::ELEMENTS, str_repeat("\x00\x40\x40", self::ELEMENTS));
        $this->expectException(DeserializationException::class);
        $this->expectExceptionMessage('Decoded value limit exceeded');

        AmqpDecoder::decodeMessage($this->amqpValue($this->list32(4, str_repeat($inner, 4))));
    }

    #[RunInSeparateProcess]
    public function testBudgetBoundaryIsFifteenFullLists(): void
    {
        $decoded = AmqpDecoder::decodeMessage($this->listOfNullLists(15));
        $count = is_array($decoded['body']) ? count($decoded['body']) : -1;
        unset($decoded);
        $this->assertSame(15, $count);

        $this->expectException(DeserializationException::class);
        $this->expectExceptionMessage('Decoded value limit exceeded');
        AmqpDecoder::decodeMessage($this->listOfNullLists(16));
    }
}
