<?php

declare(strict_types=1);

namespace CrazyGoat\RabbitStream\Tests\Trait;

use CrazyGoat\RabbitStream\Enum\ResponseCodeEnum;
use CrazyGoat\RabbitStream\Exception\ProtocolException;
use CrazyGoat\RabbitStream\Tests\Trait\Fixtures\TestCommand;
use PHPUnit\Framework\TestCase;

class CommandTraitTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        TestCommand::setKey(0x0001);
        TestCommand::setVersion(1);
    }

    public function testAssertResponseCodeOkPassesForOk(): void
    {
        $this->expectNotToPerformAssertions();
        TestCommand::callAssertResponseCodeOk(0x01);
    }

    /**
     * @dataProvider knownErrorCodesProvider
     */
    public function testAssertResponseCodeOkThrowsForKnownErrorCodes(
        int $code,
        string $expectedName,
        string $expectedMessage
    ): void {
        $hexCode = sprintf('%04x', $code);
        $this->expectException(ProtocolException::class);
        $this->expectExceptionMessage(
            "Unexpected response code: 0x{$hexCode} ({$expectedName}: {$expectedMessage})"
        );

        TestCommand::callAssertResponseCodeOk($code);
    }

    /**
     * @return array<string, array{int, string, string}>
     */
    public static function knownErrorCodesProvider(): array
    {
        $codes = [];
        foreach (ResponseCodeEnum::cases() as $code) {
            if (!$code->isError()) {
                continue;
            }

            $codes[$code->name] = [$code->value, $code->name, $code->getMessage()];
        }

        return $codes;
    }

    public function testAssertResponseCodeOkThrowsForUnknownCode(): void
    {
        $this->expectException(ProtocolException::class);
        $this->expectExceptionMessage('Unexpected response code: 0x00ff (unknown)');

        TestCommand::callAssertResponseCodeOk(0xFF);
    }

    public function testAssertResponseCodeOkExceptionHasResponseCode(): void
    {
        try {
            TestCommand::callAssertResponseCodeOk(0x02);
            $this->fail('Expected ProtocolException to be thrown');
        } catch (ProtocolException $e) {
            $this->assertSame(ResponseCodeEnum::STREAM_NOT_EXIST, $e->getResponseCode());
        }
    }

    public function testAssertResponseCodeOkExceptionHasNullResponseCodeForUnknown(): void
    {
        try {
            TestCommand::callAssertResponseCodeOk(0xFF);
            $this->fail('Expected ProtocolException to be thrown');
        } catch (ProtocolException $e) {
            $this->assertNull($e->getResponseCode());
        }
    }

    public function testValidateKeyVersionPassesForCorrectKeyAndVersion(): void
    {
        $this->expectNotToPerformAssertions();
        TestCommand::callValidateKeyVersion(0x0001, 1);
    }

    public function testValidateKeyVersionThrowsOnWrongKey(): void
    {
        $this->expectException(ProtocolException::class);
        $this->expectExceptionMessage('Unexpected command code');

        TestCommand::callValidateKeyVersion(0x0002, 1);
    }

    public function testValidateKeyVersionThrowsOnWrongVersion(): void
    {
        $this->expectException(ProtocolException::class);
        $this->expectExceptionMessage('Unexpected version');

        TestCommand::callValidateKeyVersion(0x0001, 2);
    }

    public function testGetKeyVersionWithoutCorrelationId(): void
    {
        $buffer = TestCommand::callGetKeyVersion();
        $contents = $buffer->getContents();

        $this->assertSame(4, strlen($contents));

        $expected = pack('n', 0x0001)   // key
            . pack('n', 1);              // version

        $this->assertSame($expected, $contents);
    }

    public function testGetKeyVersionWithCorrelationId(): void
    {
        $buffer = TestCommand::callGetKeyVersion(42);
        $contents = $buffer->getContents();

        $this->assertSame(8, strlen($contents));

        $expected = pack('n', 0x0001)   // key
            . pack('n', 1)               // version
            . pack('N', 42);             // correlationId

        $this->assertSame($expected, $contents);
    }

    public function testGetKeyVersionBufferContentsWithDifferentKey(): void
    {
        TestCommand::setKey(0x0012);

        $buffer = TestCommand::callGetKeyVersion(100);
        $contents = $buffer->getContents();

        $expected = pack('n', 0x0012)   // key
            . pack('n', 1)               // version
            . pack('N', 100);            // correlationId

        $this->assertSame($expected, $contents);
    }

    public function testGetKeyVersionBufferContentsWithDifferentVersion(): void
    {
        TestCommand::setVersion(2);

        $buffer = TestCommand::callGetKeyVersion();
        $contents = $buffer->getContents();

        $expected = pack('n', 0x0001)   // key
            . pack('n', 2);              // version

        $this->assertSame($expected, $contents);
    }
}
