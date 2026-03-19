<?php

namespace CrazyGoat\RabbitStream\Buffer;

interface FromArrayInterface
{
    public static function fromArray(array $data): static;
}
