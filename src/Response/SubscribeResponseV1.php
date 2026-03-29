<?php

declare(strict_types=1);

namespace CrazyGoat\RabbitStream\Response;

use CrazyGoat\RabbitStream\Enum\KeyEnum;

class SubscribeResponseV1 extends SimpleCorrelatedResponseV1
{
    public static function getKey(): int
    {
        return KeyEnum::SUBSCRIBE_RESPONSE->value;
    }
}
