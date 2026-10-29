<?php

declare(strict_types=1);

namespace CrazyGoat\RabbitStream\Tests\Request;

use CrazyGoat\RabbitStream\Exception\InvalidArgumentException;
use CrazyGoat\RabbitStream\Request\SaslAuthenticateRequestV1;
use CrazyGoat\RabbitStream\StreamConnection;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class SaslAuthenticateRequestV1Test extends TestCase
{
    public function testSerializesCorrectly(): void
    {
        $request = new SaslAuthenticateRequestV1('PLAIN', 'guest', 'guest');
        $request->withCorrelationId(2);

        $bytes = $request->toStreamBuffer()->getContents();

        $saslData = "\0guest\0guest";
        $expected = pack('n', 0x0013)           // key
            . pack('n', 1)                      // version
            . pack('N', 2)                      // correlationId
            . pack('n', 5) . 'PLAIN'            // mechanism string
            . pack('N', strlen($saslData))      // bytes length
            . $saslData;                        // bytes content

        $this->assertSame($expected, $bytes);
    }

    public function testPasswordIsMaskedInToArray(): void
    {
        $request = new SaslAuthenticateRequestV1('PLAIN', 'guest', 'secretpassword123');
        $array = $request->toArray();

        $this->assertSame('***', $array['password']);
        $this->assertSame('guest', $array['username']);
        $this->assertSame('PLAIN', $array['mechanism']);
    }

    public function testPasswordIsMaskedInDebugInfo(): void
    {
        $request = new SaslAuthenticateRequestV1('PLAIN', 'guest', 'secretpassword123');
        $debugInfo = $request->__debugInfo();

        $this->assertSame('***', $debugInfo['password']);
        $this->assertSame('guest', $debugInfo['username']);
        $this->assertSame('PLAIN', $debugInfo['mechanism']);
    }

    public function testPasswordIsNotMaskedInStreamBuffer(): void
    {
        $request = new SaslAuthenticateRequestV1('PLAIN', 'guest', 'secretpassword123');
        $bytes = $request->toStreamBuffer()->getContents();

        $this->assertStringContainsString('secretpassword123', $bytes);
        $this->assertStringContainsString('guest', $bytes);
    }

    public function testPasswordIsHiddenInVarExportSerializeJsonAndArrayCast(): void
    {
        $request = new SaslAuthenticateRequestV1('PLAIN', 'guest', 'example-password');

        $this->assertStringNotContainsString('example-password', var_export($request, true));
        $this->assertStringNotContainsString('example-password', serialize($request));
        $this->assertStringNotContainsString('example-password', (string) json_encode($request));
        $this->assertStringNotContainsString('example-password', print_r((array) $request, true));
        $this->assertStringNotContainsString('example-password', print_r($request, true));
        $this->assertStringContainsString('example-password', $request->toStreamBuffer()->getContents());
    }

    public function testSerializedRequestStillRoundTrips(): void
    {
        $request = new SaslAuthenticateRequestV1('PLAIN', 'guest', 'example-password');
        $restored = unserialize(serialize($request));

        $this->assertInstanceOf(SaslAuthenticateRequestV1::class, $restored);
        $this->assertSame('guest', $restored->toArray()['username']);
    }

    public function testStackTraceOfSendMessageHoldsNoPassword(): void
    {
        if (PHP_VERSION_ID < 80200) {
            $this->markTestSkipped('#[\\SensitiveParameter] needs PHP 8.2');
        }
        ini_set('zend.exception_ignore_args', '0');

        $stream = new StreamConnection('127.0.0.1', 1, new NullLogger());
        $stream->setOutgoingMaxFrameSize(8);

        try {
            $stream->sendMessage(new SaslAuthenticateRequestV1('PLAIN', 'app', 'example-password'));
            $this->fail('Expected an exception');
        } catch (InvalidArgumentException $e) {
            $trace = var_export($e->getTrace(), true) . json_encode($e->getTrace());
            $this->assertStringNotContainsString('example-password', $trace);
        }
    }
}
