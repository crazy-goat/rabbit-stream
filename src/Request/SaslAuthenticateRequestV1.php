<?php

declare(strict_types=1);

namespace CrazyGoat\RabbitStream\Request;

use CrazyGoat\RabbitStream\Buffer\ToArrayInterface;
use CrazyGoat\RabbitStream\Buffer\ToStreamBufferInterface;
use CrazyGoat\RabbitStream\Buffer\WriteBuffer;
use CrazyGoat\RabbitStream\Contract\CorrelationInterface;
use CrazyGoat\RabbitStream\Contract\KeyVersionInterface;
use CrazyGoat\RabbitStream\Enum\KeyEnum;
use CrazyGoat\RabbitStream\Trait\CommandTrait;
use CrazyGoat\RabbitStream\Trait\CorrelationTrait;
use CrazyGoat\RabbitStream\Trait\V1Trait;

class SaslAuthenticateRequestV1 implements
    ToStreamBufferInterface,
    ToArrayInterface,
    CorrelationInterface,
    KeyVersionInterface
{
    use CorrelationTrait;
    use V1Trait;
    use CommandTrait;

    public function __construct(private string $mechanism, private string $username, private string $password)
    {
    }
    public function toStreamBuffer(): WriteBuffer
    {
        return  self::getKeYVersion($this->getCorrelationId())
            ->addString($this->mechanism)
            ->addBytes("\0" . $this->username . "\0" . $this->password);
    }

    /** @return array<string, string> */
    public function toArray(): array
    {
        return [
            'mechanism' => $this->mechanism,
            'username' => $this->username,
            'password' => $this->password,
        ];
    }

    public static function getKey(): int
    {
        return KeyEnum::SASL_AUTHENTICATE->value;
    }
}
