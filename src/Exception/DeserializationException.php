<?php

declare(strict_types=1);

namespace CrazyGoat\RabbitStream\Exception;

/**
 * Wire data does not match the protocol.
 *
 * Thrown by the low-level readers whenever bytes cannot be decoded:
 * {@see \CrazyGoat\RabbitStream\Buffer\ReadBuffer} reading past the end of the
 * buffer or an out-of-range length, an unknown or inconsistent stream-protocol
 * frame shape, a malformed Osiris chunk, or a malformed AMQP 1.0 payload
 * reached from `Message::getBody()` / `getApplicationProperties()`. Message
 * decoding is lazy, so for a malformed body the throw happens on the accessor,
 * not on `read()`.
 *
 * Treat it as a corrupted conversation: the framing cursor can no longer be
 * trusted, so close the connection rather than continue on it.
 *
 * ```php
 * use CrazyGoat\RabbitStream\Exception\DeserializationException;
 *
 * try {
 *     $message = $consumer->read();
 *     $body = $message->getBody();
 * } catch (DeserializationException $e) {
 *     error_log('Bad frame: ' . $e->getMessage());
 *     $connection->close();
 * }
 * ```
 */
class DeserializationException extends RabbitStreamException
{
}
