<?php

declare(strict_types=1);

namespace CrazyGoat\RabbitStream\Exception;

class InvalidArgumentException extends \InvalidArgumentException implements RabbitStreamExceptionInterface
{
}
