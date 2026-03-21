<?php

declare(strict_types=1);

namespace CrazyGoat\RabbitStream\Tests\Enum;

use CrazyGoat\RabbitStream\Enum\KeyEnum;
use PHPUnit\Framework\TestCase;

class KeyEnumTest extends TestCase
{
    public function testFromStreamCodeReturnsExactMatch(): void
    {
        $result = KeyEnum::fromStreamCode(0x0001);
        $this->assertSame(KeyEnum::DECLARE_PUBLISHER, $result);
    }

    public function testFromStreamCodeReturnsResponseCode(): void
    {
        $result = KeyEnum::fromStreamCode(0x8001);
        $this->assertSame(KeyEnum::DECLARE_PUBLISHER_RESPONSE, $result);
    }

    public function testFromStreamCodeNormalizesResponseToRequest(): void
    {
        // 0x000d is CREATE, 0x800d is CREATE_RESPONSE
        // When we pass 0x000d, it should return CREATE (not CREATE_RESPONSE)
        $result = KeyEnum::fromStreamCode(0x000d);
        $this->assertSame(KeyEnum::CREATE, $result);
    }

    public function testFromStreamCodeThrowsForUnknownRequestCode(): void
    {
        $this->expectException(\ValueError::class);
        $this->expectExceptionMessage('Unknown stream protocol command code: 0x0099');

        KeyEnum::fromStreamCode(0x0099);
    }

    public function testFromStreamCodeThrowsForUnknownResponseCode(): void
    {
        $this->expectException(\ValueError::class);
        $this->expectExceptionMessage('Unknown stream protocol command code: 0x8099');

        KeyEnum::fromStreamCode(0x8099);
    }

    public function testFromStreamCodeThrowsForNegativeResult(): void
    {
        // Code less than 0x8000 but not a valid enum value
        // Should throw with the original code, not a negative number
        $this->expectException(\ValueError::class);
        $this->expectExceptionMessage('Unknown stream protocol command code: 0x00ff');

        KeyEnum::fromStreamCode(0x00ff);
    }

    public function testFromStreamCodeHandlesAllValidRequestCodes(): void
    {
        $requestCodes = [
            0x0001 => KeyEnum::DECLARE_PUBLISHER,
            0x0002 => KeyEnum::PUBLISH,
            0x0003 => KeyEnum::PUBLISH_CONFIRM,
            0x0004 => KeyEnum::PUBLISH_ERROR,
            0x0005 => KeyEnum::QUERY_PUBLISHER_SEQUENCE,
            0x0006 => KeyEnum::DELETE_PUBLISHER,
            0x0007 => KeyEnum::SUBSCRIBE,
            0x0008 => KeyEnum::DELIVER,
            0x0009 => KeyEnum::CREDIT,
            0x000a => KeyEnum::STORE_OFFSET,
            0x000b => KeyEnum::QUERY_OFFSET,
            0x000c => KeyEnum::UNSUBSCRIBE,
            0x000d => KeyEnum::CREATE,
            0x000e => KeyEnum::DELETE,
            0x000f => KeyEnum::METADATA,
            0x0010 => KeyEnum::METADATA_UPDATE,
            0x0011 => KeyEnum::PEER_PROPERTIES,
            0x0012 => KeyEnum::SASL_HANDSHAKE,
            0x0013 => KeyEnum::SASL_AUTHENTICATE,
            0x0014 => KeyEnum::TUNE,
            0x0015 => KeyEnum::OPEN,
            0x0016 => KeyEnum::CLOSE,
            0x0017 => KeyEnum::HEARTBEAT,
            0x0018 => KeyEnum::ROUTE,
            0x0019 => KeyEnum::PARTITIONS,
            0x001a => KeyEnum::CONSUMER_UPDATE,
            0x001b => KeyEnum::EXCHANGE_COMMAND_VERSIONS,
            0x001c => KeyEnum::STREAM_STATS,
            0x001d => KeyEnum::CREATE_SUPER_STREAM,
            0x001e => KeyEnum::DELETE_SUPER_STREAM,
            0x001f => KeyEnum::RESOLVE_OFFSET_SPEC,
        ];

        foreach ($requestCodes as $code => $expected) {
            $result = KeyEnum::fromStreamCode($code);
            $this->assertSame($expected, $result, "Failed for code 0x" . dechex($code));
        }
    }

    public function testFromStreamCodeHandlesAllValidResponseCodes(): void
    {
        $responseCodes = [
            0x8001 => KeyEnum::DECLARE_PUBLISHER_RESPONSE,
            0x8006 => KeyEnum::DELETE_PUBLISHER_RESPONSE,
            0x8007 => KeyEnum::SUBSCRIBE_RESPONSE,
            0x8009 => KeyEnum::CREDIT_RESPONSE,
            0x800b => KeyEnum::QUERY_OFFSET_RESPONSE,
            0x800c => KeyEnum::UNSUBSCRIBE_RESPONSE,
            0x800d => KeyEnum::CREATE_RESPONSE,
            0x800e => KeyEnum::DELETE_RESPONSE,
            0x800f => KeyEnum::METADATA_RESPONSE,
            0x8011 => KeyEnum::PEER_PROPERTIES_RESPONSE,
            0x8012 => KeyEnum::SASL_HANDSHAKE_RESPONSE,
            0x8013 => KeyEnum::SASL_AUTHENTICATE_RESPONSE,
            0x8014 => KeyEnum::TUNE_RESPONSE,
            0x8015 => KeyEnum::OPEN_RESPONSE,
            0x8016 => KeyEnum::CLOSE_RESPONSE,
            0x8018 => KeyEnum::ROUTE_RESPONSE,
            0x8019 => KeyEnum::PARTITIONS_RESPONSE,
            0x801a => KeyEnum::CONSUMER_UPDATE_RESPONSE,
            0x801b => KeyEnum::EXCHANGE_COMMAND_VERSIONS_RESPONSE,
            0x801c => KeyEnum::STREAM_STATS_RESPONSE,
            0x801d => KeyEnum::CREATE_SUPER_STREAM_RESPONSE,
            0x801e => KeyEnum::DELETE_SUPER_STREAM_RESPONSE,
            0x801f => KeyEnum::RESOLVE_OFFSET_SPEC_RESPONSE,
            0x8005 => KeyEnum::QUERY_PUBLISHER_SEQUENCE_RESPONSE,
        ];

        foreach ($responseCodes as $code => $expected) {
            $result = KeyEnum::fromStreamCode($code);
            $this->assertSame($expected, $result, "Failed for code 0x" . dechex($code));
        }
    }
}
