<?php

declare(strict_types=1);

namespace CrazyGoat\RabbitStream\Tests\Enum;

use CrazyGoat\RabbitStream\Enum\ResponseCodeEnum;
use PHPUnit\Framework\TestCase;

class ResponseCodeEnumTest extends TestCase
{
    public function testOkIsSuccess(): void
    {
        $this->assertTrue(ResponseCodeEnum::OK->isSuccess());
        $this->assertFalse(ResponseCodeEnum::OK->isError());
    }

    public function testCasesMatchCompleteProtocolResponseCodeSet(): void
    {
        $this->assertSame(
            [
                0x01, 0x02, 0x03, 0x04, 0x05, 0x06, 0x07,
                0x08, 0x09, 0x0a, 0x0b, 0x0c, 0x0d, 0x0e,
                0x0f, 0x10, 0x11, 0x12, 0x13, 0x14, 0x15,
            ],
            array_map(
                static fn (ResponseCodeEnum $code): int => $code->value,
                ResponseCodeEnum::cases()
            )
        );
    }

    /**
     * @dataProvider errorCodesProvider
     */
    public function testErrorCodesAreNotSuccess(ResponseCodeEnum $code): void
    {
        $this->assertFalse($code->isSuccess());
        $this->assertTrue($code->isError());
    }

    /**
     * @dataProvider allCodesProvider
     */
    public function testGetMessageReturnsNonEmptyString(ResponseCodeEnum $code): void
    {
        $message = $code->getMessage();
        $this->assertNotEmpty($message);
        $this->assertGreaterThan(0, strlen($message));
    }

    public function testFromIntReturnsNullForUnknownCode(): void
    {
        $invalidCodes = [0x00, 0xFF, -1, 0x16, 999, 0x20, -100];

        foreach ($invalidCodes as $code) {
            $result = ResponseCodeEnum::fromInt($code);
            $this->assertNull($result, "Expected null for code 0x" . dechex($code));
        }
    }

    /**
     * @dataProvider validCodesProvider
     */
    public function testFromIntReturnsEnumForKnownCode(int $code, ResponseCodeEnum $expected): void
    {
        $result = ResponseCodeEnum::fromInt($code);
        $this->assertSame($expected, $result, "Failed for code 0x" . dechex($code));
    }

    /**
     * @dataProvider codeMessageProvider
     */
    public function testGetMessageReturnsExpectedString(ResponseCodeEnum $code, string $expectedMessage): void
    {
        $this->assertSame($expectedMessage, $code->getMessage());
    }

    /**
     * @return array<string, array{ResponseCodeEnum}>
     */
    public static function errorCodesProvider(): array
    {
        $codes = [];
        foreach (self::codeMessageProvider() as $name => [$code]) {
            if (!$code->isError()) {
                continue;
            }

            $codes[$name] = [$code];
        }

        return $codes;
    }

    /**
     * @return array<string, array{ResponseCodeEnum}>
     */
    public static function allCodesProvider(): array
    {
        return array_merge(
            ['OK' => [ResponseCodeEnum::OK]],
            self::errorCodesProvider()
        );
    }

    /**
     * @return array<string, array{int, ResponseCodeEnum}>
     */
    public static function validCodesProvider(): array
    {
        $codes = [];
        foreach (self::codeMessageProvider() as $name => [$code]) {
            $codes[sprintf('%s (0x%02x)', $name, $code->value)] = [$code->value, $code];
        }

        return $codes;
    }

    /**
     * @return array<string, array{ResponseCodeEnum, string}>
     */
    public static function codeMessageProvider(): array
    {
        return [
            'OK' => [ResponseCodeEnum::OK, 'OK'],
            'STREAM_NOT_EXIST' => [ResponseCodeEnum::STREAM_NOT_EXIST, 'Stream does not exist'],
            'SUBSCRIPTION_ID_ALREADY_EXISTS' => [
                ResponseCodeEnum::SUBSCRIPTION_ID_ALREADY_EXISTS,
                'Subscription ID already exists',
            ],
            'SUBSCRIPTION_ID_NOT_EXIST' => [
                ResponseCodeEnum::SUBSCRIPTION_ID_NOT_EXIST,
                'Subscription ID does not exist',
            ],
            'STREAM_ALREADY_EXISTS' => [ResponseCodeEnum::STREAM_ALREADY_EXISTS, 'Stream already exists'],
            'STREAM_NOT_AVAILABLE' => [ResponseCodeEnum::STREAM_NOT_AVAILABLE, 'Stream not available'],
            'SASL_MECHANISM_NOT_SUPPORTED' => [
                ResponseCodeEnum::SASL_MECHANISM_NOT_SUPPORTED,
                'SASL mechanism not supported',
            ],
            'AUTHENTICATION_FAILURE' => [ResponseCodeEnum::AUTHENTICATION_FAILURE, 'Authentication failure'],
            'SASL_ERROR' => [ResponseCodeEnum::SASL_ERROR, 'SASL error'],
            'SASL_CHALLENGE' => [ResponseCodeEnum::SASL_CHALLENGE, 'SASL challenge'],
            'SASL_AUTHENTICATION_FAILURE_LOOPBACK' => [
                ResponseCodeEnum::SASL_AUTHENTICATION_FAILURE_LOOPBACK,
                'SASL authentication failure loopback',
            ],
            'VIRTUAL_HOST_ACCESS_FAILURE' => [
                ResponseCodeEnum::VIRTUAL_HOST_ACCESS_FAILURE,
                'Virtual host access failure',
            ],
            'UNKNOWN_FRAME' => [ResponseCodeEnum::UNKNOWN_FRAME, 'Unknown frame'],
            'FRAME_TOO_LARGE' => [ResponseCodeEnum::FRAME_TOO_LARGE, 'Frame too large'],
            'INTERNAL_ERROR' => [ResponseCodeEnum::INTERNAL_ERROR, 'Internal error'],
            'ACCESS_REFUSED' => [ResponseCodeEnum::ACCESS_REFUSED, 'Access refused'],
            'PRECONDITION_FAILED' => [ResponseCodeEnum::PRECONDITION_FAILED, 'Precondition failed'],
            'PUBLISHER_NOT_EXIST' => [ResponseCodeEnum::PUBLISHER_NOT_EXIST, 'Publisher does not exist'],
            'NO_OFFSET' => [ResponseCodeEnum::NO_OFFSET, 'No offset'],
            'SASL_CANNOT_CHANGE_MECHANISM' => [
                ResponseCodeEnum::SASL_CANNOT_CHANGE_MECHANISM,
                'SASL cannot change mechanism',
            ],
            'SASL_CANNOT_CHANGE_USERNAME' => [
                ResponseCodeEnum::SASL_CANNOT_CHANGE_USERNAME,
                'SASL cannot change username',
            ],
        ];
    }
}
