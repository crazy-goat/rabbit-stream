<?php

namespace CrazyGoat\StreamyCarrot\Enum;

enum KeyEnum: int
{
    case DECLARE_PUBLISHER = 0x0001;
    case PUBLISH = 0x0002;
    case PUBLISH_CONFIRM = 0x0003;
    case PUBLISH_ERROR = 0x0004;
    case QUERY_PUBLISHER_SEQUENCE = 0x0005;
    case DELETE_PUBLISHER = 0x0006;
    case SUBSCRIBE = 0x0007;
    case DELIVER = 0x0008;
    case CREDIT = 0x0009;
    case STORE_OFFSET = 0x000a;
    case QUERY_OFFSET = 0x000b;
    case UNSUBSCRIBE = 0x000c;
    case CREATE = 0x000d;
    case DELETE = 0x000e;
    case METADATA = 0x000f;
    case METADATA_UPDATE = 0x0010;
    case PEER_PROPERTIES = 0x0011;
    case SASL_HANDSHAKE = 0x0012;
    case SASL_AUTHENTICATE = 0x0013;
    case TUNE = 0x0014;
    case OPEN = 0x0015;
    case CLOSE = 0x0016;
    case HEARTBEAT = 0x0017;
    case ROUTE = 0x0018;
    case PARTITIONS = 0x0019;
    case CONSUMER_UPDATE = 0x001a;
    case EXCHANGE_COMMAND_VERSIONS = 0x001b;
    case STREAM_STATS = 0x001c;
    case CREATE_SUPER_STREAM = 0x001d;
    case DELETE_SUPER_STREAM = 0x001e;

    case PEER_PROPERTIES_RESPONSE = 0x8011;
    case SASL_HANDSHAKE_RESPONSE = 0x8012;
    case SASL_AUTHENTICATE_RESPONSE = 0x8013;
    case TUNE_RESPONSE = 0x8014;
    case OPEN_RESPONSE = 0x8015;

    public static function fromStreamCode(int $code): KeyEnum
    {
        return self::tryFrom($code) ?? self::from($code - 0x8000);
    }
}