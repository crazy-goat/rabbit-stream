<?php

declare(strict_types=1);

namespace CrazyGoat\RabbitStream\Tests\Request;

use CrazyGoat\RabbitStream\Request\SaslAuthenticateRequestV1;
use PHPUnit\Framework\TestCase;

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
}
