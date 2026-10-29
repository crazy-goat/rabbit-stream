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
use CrazyGoat\RabbitStream\VO\Secret;

class SaslAuthenticateRequestV1 implements
    ToStreamBufferInterface,
    ToArrayInterface,
    CorrelationInterface,
    KeyVersionInterface
{
    use CorrelationTrait;
    use V1Trait;
    use CommandTrait;

    /**
     * The password lives in a Secret holder, not in a string property, so that
     * var_export(), serialize(), json_encode() and the (array) cast do not show it.
     */
    private Secret $password;

    public function __construct(
        private string $mechanism,
        private string $username,
        #[\SensitiveParameter] string $password,
    ) {
        $this->password = new Secret($password);
    }

    public function toStreamBuffer(): WriteBuffer
    {
        return self::getKeyVersion($this->getCorrelationId())
            ->addString($this->mechanism)
            ->addBytes("\0" . $this->username . "\0" . $this->password->reveal());
    }

    /** @return array<string, string> */
    public function toArray(): array
    {
        return [
            'mechanism' => $this->mechanism,
            'username' => $this->username,
            'password' => '***',
        ];
    }

    /** @return array<string, string> */
    public function __debugInfo(): array
    {
        return $this->toArray();
    }

    public static function getKey(): int
    {
        return KeyEnum::SASL_AUTHENTICATE->value;
    }
}
