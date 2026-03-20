<?php

namespace CrazyGoat\RabbitStream\Client;

/**
 * @deprecated Use Connection::create() parameters instead
 */
class StreamClientConfig
{
    public function __construct(
        public readonly string $host = '127.0.0.1',
        public readonly int $port = 5552,
        public readonly string $user = 'guest',
        public readonly string $password = 'guest',
        public readonly string $vhost = '/',
    ) {
    }
}
