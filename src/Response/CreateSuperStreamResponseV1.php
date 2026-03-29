<?php

declare(strict_types=1);

namespace CrazyGoat\RabbitStream\Response;

use CrazyGoat\RabbitStream\Enum\KeyEnum;

class CreateSuperStreamResponseV1 extends SimpleCorrelatedResponseV1
{
    public static function getKey(): int
    {
        return KeyEnum::CREATE_SUPER_STREAM_RESPONSE->value;
    }
}
