<?php

declare(strict_types=1);

namespace CrazyGoat\RabbitStream\Exception;

/**
 * The socket backing a connection cannot be used, or was lost.
 *
 * Thrown by {@see \CrazyGoat\RabbitStream\StreamConnection} for socket-level
 * failures: `connect()` cannot create/connect the socket or complete the TLS
 * handshake; a write is attempted on a closed socket; the peer closes the
 * connection; `stream_select()` fails; or a read/write times out with part of a
 * frame already transferred, in which case the consumed bytes cannot be pushed
 * back, so the connection is closed and this (not the retryable
 * {@see TimeoutException}) is thrown. The high-level clients also throw it when
 * all 256 publisher or subscription ids of a connection are in use — an id is a
 * uint8 on the wire, and closing a producer/consumer hands its id back.
 *
 * Recovery means re-establishing the connection; catch {@see TimeoutException}
 * first, since it extends this class.
 *
 * ```php
 * use CrazyGoat\RabbitStream\Client\Connection;
 * use CrazyGoat\RabbitStream\Exception\ConnectionException;
 *
 * try {
 *     $connection = Connection::create(host: $host, port: 5552);
 * } catch (ConnectionException $e) {
 *     error_log('Cannot reach broker: ' . $e->getMessage());
 * }
 * ```
 */
class ConnectionException extends RabbitStreamException
{
}
