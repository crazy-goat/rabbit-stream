<?php

declare(strict_types=1);

namespace CrazyGoat\RabbitStream\Response;

use CrazyGoat\RabbitStream\Enum\KeyEnum;

class DeclarePublisherResponseV1 extends SimpleCorrelatedResponseV1
{
    public static function getKey(): int
    {
        return KeyEnum::DECLARE_PUBLISHER_RESPONSE->value;
    }
}
