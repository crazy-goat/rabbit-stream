<?php

declare(strict_types=1);

namespace CrazyGoat\RabbitStream\Enum;

use CrazyGoat\RabbitStream\Exception\ProtocolException;

/**
 * Protocol command keys, from both the client and the server side.
 *
 * The key is a uint16, but the protocol only uses its low 15 bits: bit 15
 * distinguishes a **request** (0) from its **response** (1). In other words
 *
 *     response key = request key | 0x8000
 *
 * so `PUBLISH` (`0x0002`) is answered by a frame with key `0x8002`, `CREATE`
 * (`0x000d`) by `0x800d`, and so on. The response bit is applied by the broker
 * to the frame it sends back; a request frame never carries it.
 *
 * The response keys are listed as explicit cases rather than computed, because
 * not every request has a response (for example `PUBLISH` and `STORE_OFFSET`
 * do not) and some commands use their request key even when sent by the server
 * (`PUBLISH_CONFIRM`, `DELIVER`, `METADATA_UPDATE`, `HEARTBEAT`,
 * `CONSUMER_UPDATE`). {@see self::fromStreamCode()} therefore does an exact
 * lookup and **throws** rather than stripping bit 15: an unknown response code
 * such as `0x8002` is a real error, not a request key in disguise (#394).
 *
 * @see https://github.com/rabbitmq/rabbitmq-server/blob/main/deps/rabbitmq_stream/docs/PROTOCOL.adoc
 */
enum KeyEnum: int
{
    /** Client declares a publisher on a stream; answered by DECLARE_PUBLISHER_RESPONSE. */
    case DECLARE_PUBLISHER = 0x0001;

    /** Client publishes messages; no response, confirms arrive asynchronously. */
    case PUBLISH = 0x0002;

    /** Server-push confirm of published ids; routed by publisher id, no correlation id. */
    case PUBLISH_CONFIRM = 0x0003;

    /** Server-push publish failure; routed by publisher id, no correlation id. */
    case PUBLISH_ERROR = 0x0004;

    /** Client queries the last confirmed publishing id for a named producer. */
    case QUERY_PUBLISHER_SEQUENCE = 0x0005;

    /** Client deletes a publisher and frees its id. */
    case DELETE_PUBLISHER = 0x0006;

    /** Client subscribes to a stream; answered by SUBSCRIBE_RESPONSE. */
    case SUBSCRIBE = 0x0007;

    /** Server-push message delivery; routed by subscription id, no correlation id. */
    case DELIVER = 0x0008;

    /** Client grants chunk credit; the response is error-only. */
    case CREDIT = 0x0009;

    /** Client stores a consumer offset; no response. */
    case STORE_OFFSET = 0x000a;

    /** Client queries a stored consumer offset; answered by QUERY_OFFSET_RESPONSE. */
    case QUERY_OFFSET = 0x000b;

    /** Client unsubscribes from a stream. */
    case UNSUBSCRIBE = 0x000c;

    /** Client creates a stream. */
    case CREATE = 0x000d;

    /** Client deletes a stream. */
    case DELETE = 0x000e;

    /** Client queries stream metadata. */
    case METADATA = 0x000f;

    /** Server-push metadata change; routed by stream name, no correlation id. */
    case METADATA_UPDATE = 0x0010;

    /** Client exchanges peer properties during the connection handshake. */
    case PEER_PROPERTIES = 0x0011;

    /** Client starts the SASL handshake. */
    case SASL_HANDSHAKE = 0x0012;

    /** Client sends SASL authentication data. */
    case SASL_AUTHENTICATE = 0x0013;

    /** Connection tuning parameters; sent by the server, answered by the client. */
    case TUNE = 0x0014;

    /** Client opens a virtual host on the connection. */
    case OPEN = 0x0015;

    /** Client or server closes the connection. */
    case CLOSE = 0x0016;

    /** Server-push heartbeat; must be echoed immediately, no correlation id. */
    case HEARTBEAT = 0x0017;

    /** Client resolves a routing key to super-stream partitions. */
    case ROUTE = 0x0018;

    /** Client resolves a super stream's partition names. */
    case PARTITIONS = 0x0019;

    /** Server asks a single-active consumer for its offset; the client must reply. */
    case CONSUMER_UPDATE = 0x001a;

    /** Client exchanges supported per-command version ranges. */
    case EXCHANGE_COMMAND_VERSIONS = 0x001b;

    /** Client queries stream statistics. */
    case STREAM_STATS = 0x001c;

    /** Client creates a super stream and its partition streams. */
    case CREATE_SUPER_STREAM = 0x001d;

    /** Client deletes a super stream and its partition streams. */
    case DELETE_SUPER_STREAM = 0x001e;

    /** Client resolves an offset specification to a concrete offset. */
    case RESOLVE_OFFSET_SPEC = 0x001f;

    /** Response to EXCHANGE_COMMAND_VERSIONS. */
    case EXCHANGE_COMMAND_VERSIONS_RESPONSE = 0x801b;

    /** Response to STREAM_STATS. */
    case STREAM_STATS_RESPONSE = 0x801c;

    /** Response to CREATE_SUPER_STREAM. */
    case CREATE_SUPER_STREAM_RESPONSE = 0x801d;

    /** Response to DELETE_SUPER_STREAM. */
    case DELETE_SUPER_STREAM_RESPONSE = 0x801e;

    /** Response to RESOLVE_OFFSET_SPEC. */
    case RESOLVE_OFFSET_SPEC_RESPONSE = 0x801f;

    /** Response to PARTITIONS. */
    case PARTITIONS_RESPONSE = 0x8019;

    /** Response to ROUTE. */
    case ROUTE_RESPONSE = 0x8018;

    /** Response to DECLARE_PUBLISHER. */
    case DECLARE_PUBLISHER_RESPONSE = 0x8001;

    /** Response to DELETE_PUBLISHER. */
    case DELETE_PUBLISHER_RESPONSE = 0x8006;

    /** Response to SUBSCRIBE. */
    case SUBSCRIBE_RESPONSE = 0x8007;

    /** Response to UNSUBSCRIBE. */
    case UNSUBSCRIBE_RESPONSE = 0x800c;

    /** Response to CREATE. */
    case CREATE_RESPONSE = 0x800d;

    /** Response to DELETE. */
    case DELETE_RESPONSE = 0x800e;

    /** Response to METADATA. */
    case METADATA_RESPONSE = 0x800f;

    /** Response to QUERY_PUBLISHER_SEQUENCE. */
    case QUERY_PUBLISHER_SEQUENCE_RESPONSE = 0x8005;

    /** Response to CONSUMER_UPDATE. */
    case CONSUMER_UPDATE_RESPONSE = 0x801a;

    /** Error-only response to CREDIT (for example an unknown subscription); uncorrelated. */
    case CREDIT_RESPONSE = 0x8009;

    /** Response to QUERY_OFFSET. */
    case QUERY_OFFSET_RESPONSE = 0x800b;

    /** Response to PEER_PROPERTIES. */
    case PEER_PROPERTIES_RESPONSE = 0x8011;

    /** Response to SASL_HANDSHAKE. */
    case SASL_HANDSHAKE_RESPONSE = 0x8012;

    /** Response to SASL_AUTHENTICATE. */
    case SASL_AUTHENTICATE_RESPONSE = 0x8013;

    /** Response to TUNE. */
    case TUNE_RESPONSE = 0x8014;

    /** Response to OPEN. */
    case OPEN_RESPONSE = 0x8015;

    /** Response to CLOSE. */
    case CLOSE_RESPONSE = 0x8016;

    /**
     * Resolve a raw protocol command code to its enum case.
     *
     * Does an exact lookup over request and response keys; there is no
     * `0x8000`-stripping fallback, so an unknown response code throws instead
     * of silently resolving to the request key.
     *
     * @param int $code Raw command key read from a frame header.
     * @return KeyEnum The matching request or response case.
     * @throws ProtocolException when the code matches no defined command. Reached from
     *                           ResponseBuilder::fromResponseBuffer() for every inbound
     *                           frame, so it must stay inside the library's exception
     *                           hierarchy: a junk key, or a command added by a future
     *                           RabbitMQ, used to escape a
     *                           catch (RabbitStreamExceptionInterface) consume loop as a
     *                           bare \ValueError and take the worker down (#394).
     */
    public static function fromStreamCode(int $code): KeyEnum
    {
        $result = self::tryFrom($code);
        if ($result !== null) {
            return $result;
        }

        throw new ProtocolException(sprintf(
            'Unknown stream protocol command code: 0x%04x',
            $code
        ));
    }
}
