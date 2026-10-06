<?php

declare(strict_types=1);

namespace CrazyGoat\RabbitStream\Enum;

/**
 * The `ResponseCode` (uint16) carried by every correlated response, plus the
 * code inside a server-push `PublishError` and `MetadataUpdate`.
 *
 * How a code reaches the caller:
 *
 * - On a **correlated response** a non-`OK` code is asserted during
 *   deserialization ({@see \CrazyGoat\RabbitStream\Trait\CommandTrait::assertResponseCodeOk()})
 *   and surfaces as a {@see \CrazyGoat\RabbitStream\Exception\ProtocolException}
 *   with `getResponseCode()` set to the case — it is never returned as a value
 *   (#424). Catch that exception to branch on the exact code.
 * - `PublishError` is a server-push frame: its code arrives through the
 *   producer's error callback, not as an exception.
 * - `NO_OFFSET` is the one non-`OK` code that is a **normal** reply
 *   (`QueryOffset` for a reference with nothing stored yet); the client maps it
 *   to a `null` offset instead of an exception (#467).
 */
enum ResponseCodeEnum: int
{
    /** Success; the result of a correlated command that worked. `isSuccess()` is true only for this case. */
    case OK = 0x01;

    /**
     * The referenced stream (or super stream) does not exist. Emitted by
     * DeclarePublisher, QueryPublisherSequence, Subscribe, Delete, StreamStats,
     * Route and Partitions. Handling: create it first, or treat it as
     * retryable while it is being recreated — the Producer re-declare and
     * Consumer re-subscribe paths retry exactly this code.
     */
    case STREAM_NOT_EXIST = 0x02;

    /**
     * Subscribe used a subscription id that is already in use on this
     * connection. Handling: allocate a free id; the Connection id pool already
     * guarantees uniqueness.
     */
    case SUBSCRIPTION_ID_ALREADY_EXISTS = 0x03;

    /**
     * Unsubscribe or Credit named a subscription id the broker does not know
     * (for example after a MetadataUpdate dropped it). Handling: treat the
     * subscription as gone; Consumer::close() skips Unsubscribe for an
     * already-lost subscription to avoid this.
     */
    case SUBSCRIPTION_ID_NOT_EXIST = 0x04;

    /**
     * Create named a stream that already exists. Handling: usually benign —
     * check existence first, or catch the ProtocolException and branch on this
     * code.
     */
    case STREAM_ALREADY_EXISTS = 0x05;

    /**
     * The stream exists but currently has no leader (leader election, node
     * down). Handling: retry with back-off; the Producer re-declare and
     * Consumer re-subscribe paths retry this code.
     */
    case STREAM_NOT_AVAILABLE = 0x06;

    /**
     * SaslHandshake/SaslAuthenticate requested a SASL mechanism the broker does
     * not offer. Handling: choose one from the handshake's mechanism list; the
     * client only supports PLAIN.
     */
    case SASL_MECHANISM_NOT_SUPPORTED = 0x07;

    /**
     * SaslAuthenticate rejected the credentials. Handling: fail fast; the
     * handshake raises AuthenticationException.
     */
    case AUTHENTICATION_FAILURE = 0x08;

    /**
     * A generic SASL failure. Handling: fail the connection; the handshake
     * raises AuthenticationException.
     */
    case SASL_ERROR = 0x09;

    /**
     * SaslAuthenticate asks for another round of challenge data. Handling:
     * answer with the next SaslAuthenticate frame; this is not a failure.
     */
    case SASL_CHALLENGE = 0x0a;

    /**
     * SaslAuthenticate failed over the loopback interface. Handling: fail
     * fast; the handshake raises AuthenticationException.
     */
    case SASL_AUTHENTICATION_FAILURE_LOOPBACK = 0x0b;

    /**
     * Open could not access the requested virtual host. Handling: fail the
     * connection; the handshake raises AuthenticationException.
     */
    case VIRTUAL_HOST_ACCESS_FAILURE = 0x0c;

    /**
     * The broker received a frame key it does not know. Handling: client-side
     * this should not occur; treat it as a protocol error.
     */
    case UNKNOWN_FRAME = 0x0d;

    /**
     * An incoming frame exceeded the negotiated frame max. Handling: reduce the
     * request size or negotiate a larger frame max.
     */
    case FRAME_TOO_LARGE = 0x0e;

    /**
     * An unexpected broker-side error. Handling: surface it; a retry may
     * succeed.
     */
    case INTERNAL_ERROR = 0x0f;

    /**
     * The user lacks the permission (configure/write/read) for the requested
     * operation. Handling: fix the broker permissions; not retryable.
     */
    case ACCESS_REFUSED = 0x10;

    /**
     * A request violated a broker precondition (for example an invalid stream
     * argument). Handling: fix the request; not retryable.
     */
    case PRECONDITION_FAILED = 0x11;

    /**
     * Publish/DeletePublisher/QueryPublisherSequence named an unknown publisher
     * id, or a PublishError reports that the broker dropped the publisher.
     * Handling: re-declare the publisher; Producer marks itself stale and
     * re-declares on the next send.
     */
    case PUBLISHER_NOT_EXIST = 0x12;

    /**
     * QueryOffset found nothing stored for the reference/stream pair yet. This
     * is a NORMAL reply, not an error: the client maps it to a `null` offset
     * (queryOffset() returns null) instead of a ProtocolException (#467).
     */
    case NO_OFFSET = 0x13;

    public function getMessage(): string
    {
        return match ($this) {
            self::OK => 'OK',
            self::STREAM_NOT_EXIST => 'Stream does not exist',
            self::SUBSCRIPTION_ID_ALREADY_EXISTS => 'Subscription ID already exists',
            self::SUBSCRIPTION_ID_NOT_EXIST => 'Subscription ID does not exist',
            self::STREAM_ALREADY_EXISTS => 'Stream already exists',
            self::STREAM_NOT_AVAILABLE => 'Stream not available',
            self::SASL_MECHANISM_NOT_SUPPORTED => 'SASL mechanism not supported',
            self::AUTHENTICATION_FAILURE => 'Authentication failure',
            self::SASL_ERROR => 'SASL error',
            self::SASL_CHALLENGE => 'SASL challenge',
            self::SASL_AUTHENTICATION_FAILURE_LOOPBACK => 'SASL authentication failure loopback',
            self::VIRTUAL_HOST_ACCESS_FAILURE => 'Virtual host access failure',
            self::UNKNOWN_FRAME => 'Unknown frame',
            self::FRAME_TOO_LARGE => 'Frame too large',
            self::INTERNAL_ERROR => 'Internal error',
            self::ACCESS_REFUSED => 'Access refused',
            self::PRECONDITION_FAILED => 'Precondition failed',
            self::PUBLISHER_NOT_EXIST => 'Publisher does not exist',
            self::NO_OFFSET => 'No offset',
        };
    }

    public static function fromInt(int $code): ?self
    {
        return self::tryFrom($code);
    }

    public function isSuccess(): bool
    {
        return $this === self::OK;
    }

    public function isError(): bool
    {
        return !$this->isSuccess();
    }
}
