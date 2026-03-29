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
        return [
            'STREAM_NOT_EXIST' => [0x02, 'STREAM_NOT_EXIST', 'Stream does not exist'],
            'SUBSCRIPTION_ID_ALREADY_EXISTS' => [
                0x03,
                'SUBSCRIPTION_ID_ALREADY_EXISTS',
                'Subscription ID already exists',
            ],
            'SUBSCRIPTION_ID_NOT_EXIST' => [0x04, 'SUBSCRIPTION_ID_NOT_EXIST', 'Subscription ID does not exist'],
            'STREAM_ALREADY_EXISTS' => [0x05, 'STREAM_ALREADY_EXISTS', 'Stream already exists'],
            'STREAM_NOT_AVAILABLE' => [0x06, 'STREAM_NOT_AVAILABLE', 'Stream not available'],
            'SASL_MECHANISM_NOT_SUPPORTED' => [0x07, 'SASL_MECHANISM_NOT_SUPPORTED', 'SASL mechanism not supported'],
            'AUTHENTICATION_FAILURE' => [0x08, 'AUTHENTICATION_FAILURE', 'Authentication failure'],
            'SASL_ERROR' => [0x09, 'SASL_ERROR', 'SASL error'],
            'SASL_CHALLENGE' => [0x0a, 'SASL_CHALLENGE', 'SASL challenge'],
            'SASL_AUTHENTICATION_FAILURE_LOOPBACK' => [
                0x0b,
                'SASL_AUTHENTICATION_FAILURE_LOOPBACK',
                'SASL authentication failure loopback',
            ],
            'VIRTUAL_HOST_ACCESS_FAILURE' => [0x0c, 'VIRTUAL_HOST_ACCESS_FAILURE', 'Virtual host access failure'],
            'UNKNOWN_FRAME' => [0x0d, 'UNKNOWN_FRAME', 'Unknown frame'],
            'FRAME_TOO_LARGE' => [0x0e, 'FRAME_TOO_LARGE', 'Frame too large'],
            'INTERNAL_ERROR' => [0x0f, 'INTERNAL_ERROR', 'Internal error'],
            'ACCESS_REFUSED' => [0x10, 'ACCESS_REFUSED', 'Access refused'],
            'PRECONDITION_FAILED' => [0x11, 'PRECONDITION_FAILED', 'Precondition failed'],
            'PUBLISHER_NOT_EXIST' => [0x12, 'PUBLISHER_NOT_EXIST', 'Publisher does not exist'],
            'NO_OFFSET' => [0x13, 'NO_OFFSET', 'No offset'],
        ];
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
