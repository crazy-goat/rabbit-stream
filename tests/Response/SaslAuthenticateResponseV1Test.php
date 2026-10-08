<?php

declare(strict_types=1);

namespace CrazyGoat\RabbitStream\Tests\Response;

use CrazyGoat\RabbitStream\Buffer\ReadBuffer;
use CrazyGoat\RabbitStream\Enum\ResponseCodeEnum;
use CrazyGoat\RabbitStream\Exception\ProtocolException;
use CrazyGoat\RabbitStream\Response\SaslAuthenticateResponseV1;
use PHPUnit\Framework\TestCase;

class SaslAuthenticateResponseV1Test extends TestCase
{
    public function testDeserializesCorrectly(): void
    {
        $raw = pack('n', 0x8013)    // key
            . pack('n', 1)          // version
            . pack('N', 2)          // correlationId
            . pack('n', 0x0001);   // responseCode OK

        $response = SaslAuthenticateResponseV1::fromStreamBuffer(new ReadBuffer($raw));

        $this->assertInstanceOf(SaslAuthenticateResponseV1::class, $response);
        $this->assertSame(2, $response->getCorrelationId());
        $this->assertNull($response->getChallenge());
    }

    public function testDeserializesSuccessfulResponseOpaqueData(): void
    {
        $raw = pack('n', 0x8013)
            . pack('n', 1)
            . pack('N', 2)
            . pack('n', ResponseCodeEnum::OK->value)
            . pack('n', 4) . 'data';

        $response = SaslAuthenticateResponseV1::fromStreamBuffer(new ReadBuffer($raw));

        $this->assertNotNull($response);
        $this->assertSame('data', $response->getChallenge());
    }

    public function testDeserializesSaslChallengeAsNonFatalResponse(): void
    {
        $raw = pack('n', 0x8013)
            . pack('n', 1)
            . pack('N', 2)
            . pack('n', ResponseCodeEnum::SASL_CHALLENGE->value)
            . pack('n', 9) . 'challenge';

        $response = SaslAuthenticateResponseV1::fromStreamBuffer(new ReadBuffer($raw));

        $this->assertNotNull($response);
        $this->assertSame(2, $response->getCorrelationId());
        $this->assertSame('challenge', $response->getChallenge());
    }

    public function testThrowsOnErrorResponseCode(): void
    {
        $raw = pack('n', 0x8013)
            . pack('n', 1)
            . pack('N', 1)
            . pack('n', ResponseCodeEnum::SASL_ERROR->value);

        try {
            SaslAuthenticateResponseV1::fromStreamBuffer(new ReadBuffer($raw));
            $this->fail('Expected a protocol exception');
        } catch (ProtocolException $exception) {
            $this->assertSame(ResponseCodeEnum::SASL_ERROR, $exception->getResponseCode());
        }
    }

    public function testThrowsOnUnknownResponseCode(): void
    {
        $raw = pack('n', 0x8013)
            . pack('n', 1)
            . pack('N', 1)
            . pack('n', 0x9999);

        try {
            SaslAuthenticateResponseV1::fromStreamBuffer(new ReadBuffer($raw));
            $this->fail('Expected a protocol exception');
        } catch (ProtocolException $exception) {
            $this->assertNull($exception->getResponseCode());
        }
    }

    public function testThrowsOnUnexpectedCommandCode(): void
    {
        $raw = pack('n', 0x8012)
            . pack('n', 1)
            . pack('N', 1)
            . pack('n', ResponseCodeEnum::OK->value);

        $this->expectException(ProtocolException::class);
        $this->expectExceptionMessage('Unexpected command code');

        SaslAuthenticateResponseV1::fromStreamBuffer(new ReadBuffer($raw));
    }

    public function testThrowsOnUnexpectedVersion(): void
    {
        $raw = pack('n', 0x8013)
            . pack('n', 2)
            . pack('N', 1)
            . pack('n', ResponseCodeEnum::OK->value);

        $this->expectException(ProtocolException::class);
        $this->expectExceptionMessage('Unexpected version');

        SaslAuthenticateResponseV1::fromStreamBuffer(new ReadBuffer($raw));
    }

    public function testThrowsOnTrailingDataAfterChallenge(): void
    {
        $raw = pack('n', 0x8013)
            . pack('n', 1)
            . pack('N', 1)
            . pack('n', ResponseCodeEnum::SASL_CHALLENGE->value)
            . pack('n', 4)
            . 'data'
            . 'x';

        $this->expectException(ProtocolException::class);
        $this->expectExceptionMessage('Unexpected trailing data in SASL authenticate response');

        SaslAuthenticateResponseV1::fromStreamBuffer(new ReadBuffer($raw));
    }
}
