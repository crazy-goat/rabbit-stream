<?php

declare(strict_types=1);

namespace CrazyGoat\RabbitStream\Exception;

use CrazyGoat\RabbitStream\Enum\ResponseCodeEnum;

/**
 * The broker answered with an error response code, or a frame violated the
 * protocol.
 *
 * Thrown by {@see \CrazyGoat\RabbitStream\Trait\CommandTrait::assertResponseCodeOk()}
 * whenever a correlated response carries a code other than `OK`, by
 * {@see \CrazyGoat\RabbitStream\Enum\KeyEnum::fromStreamCode()} for a command
 * key the client does not know, and by
 * {@see \CrazyGoat\RabbitStream\ResponseBuilder} for an unexpected protocol
 * version. Routing those through this class is deliberate: a junk key or a
 * command added by a future RabbitMQ must not escape a
 * `catch (RabbitStreamExceptionInterface)` loop as a bare `\ValueError` (#394).
 * The high-level clients also raise it for semantic failures the broker cannot
 * express with a code, such as an unnamed consumer storing an offset.
 *
 * `getResponseCode()` returns the broker's {@see ResponseCodeEnum} when the
 * frame carried one, and `null` when the frame could not be decoded far enough
 * to have a code (unknown command key or protocol version):
 *
 * ```php
 * use CrazyGoat\RabbitStream\Exception\ProtocolException;
 * use CrazyGoat\RabbitStream\Enum\ResponseCodeEnum;
 *
 * try {
 *     $connection->createStream('my-stream');
 * } catch (ProtocolException $e) {
 *     if ($e->getResponseCode() === ResponseCodeEnum::STREAM_ALREADY_EXISTS) {
 *         // Fine — the stream is already there.
 *     }
 * }
 * ```
 *
 * {@see AuthenticationException} and {@see UnexpectedResponseException} are its
 * two specialised subclasses.
 */
class ProtocolException extends RabbitStreamException
{
    public function __construct(
        string $message = '',
        int $code = 0,
        ?\Throwable $previous = null,
        private readonly ?ResponseCodeEnum $responseCode = null
    ) {
        parent::__construct($message, $code, $previous);
    }

    public function getResponseCode(): ?ResponseCodeEnum
    {
        return $this->responseCode;
    }
}
