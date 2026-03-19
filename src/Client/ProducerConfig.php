<?php

namespace CrazyGoat\RabbitStream\Client;

/**
 * @deprecated Use Connection::createProducer() parameters instead
 */
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
