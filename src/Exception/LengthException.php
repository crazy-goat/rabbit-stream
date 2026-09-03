<?php

declare(strict_types=1);

namespace CrazyGoat\RabbitStream\Exception;

/**
 * A value is too long to be represented on the wire.
 *
 * Extends the native \LengthException — like InvalidArgumentException does for
 * its native counterpart — so callers that already catch \LengthException (or
 * \LogicException) keep working, while a catch (RabbitStreamExceptionInterface)
 * now sees it too (#394).
 */
class LengthException extends \LengthException implements RabbitStreamExceptionInterface
{
}
