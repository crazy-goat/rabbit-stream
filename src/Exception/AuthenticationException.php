<?php

declare(strict_types=1);

namespace CrazyGoat\RabbitStream\Exception;

/**
 * The broker does not offer the only SASL mechanism this client implements.
 *
 * Thrown by {@see \CrazyGoat\RabbitStream\Client\Connection::create()} right
 * after the SASL handshake when the server's advertised mechanism list does not
 * contain `PLAIN` — there is no mechanism to authenticate with, so the client
 * aborts before sending any credentials.
 *
 * Do not confuse this with *wrong credentials*: the broker answers a bad
 * SASL authenticate with `AUTHENTICATION_FAILURE`, which surfaces as a
 * {@see ProtocolException} whose `getResponseCode()` is
 * {@see \CrazyGoat\RabbitStream\Enum\ResponseCodeEnum::AUTHENTICATION_FAILURE},
 * not as this class.
 *
 * ```php
 * use CrazyGoat\RabbitStream\Client\Connection;
 * use CrazyGoat\RabbitStream\Exception\AuthenticationException;
 *
 * try {
 *     $connection = Connection::create(host: $host, port: 5552, user: $user, password: $pass);
 * } catch (AuthenticationException $e) {
 *     // "PLAIN SASL mechanism not supported by server" — broker misconfiguration.
 * }
 * ```
 */
class AuthenticationException extends ProtocolException
{
}
