<?php

namespace CrazyGoat\RabbitStream\Client;

class ProducerConfig
{
    /** @var ?callable */
    public readonly mixed $onConfirmation;

    public function __construct(
        public readonly ?string $name = null,
        ?callable $onConfirmation = null,
    ) {
        $this->onConfirmation = $onConfirmation;
    }
}
