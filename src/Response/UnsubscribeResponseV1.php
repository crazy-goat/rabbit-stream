<?php

declare(strict_types=1);

namespace CrazyGoat\RabbitStream\Response;

use CrazyGoat\RabbitStream\Enum\KeyEnum;

class UnsubscribeResponseV1 extends SimpleCorrelatedResponseV1
{
    public static function getKey(): int
    {
        return KeyEnum::UNSUBSCRIBE_RESPONSE->value;
    }
}
