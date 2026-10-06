<?php

declare(strict_types=1);

namespace CrazyGoat\RabbitStream\Contract;

/**
 * A frame that carries a correlation id, so a response can be matched to the
 * request that produced it.
 *
 * Request classes implement this to let the connection stamp an outgoing
 * frame with the id it will wait for; response classes implement it to expose
 * the id the broker echoed back. The concrete behaviour comes from
 * {@see \CrazyGoat\RabbitStream\Trait\CorrelationTrait}.
 */
interface CorrelationInterface
{
    /**
     * The correlation id currently attached to this frame.
     *
     * @return int Correlation id, or 0 when none has been assigned yet.
     */
    public function getCorrelationId(): int;

    /**
     * Attach the correlation id used to match the reply to this frame.
     *
     * @param int $correlationId Correlation id to store; the connection assigns
     *                           one when it sends a correlated request.
     */
    public function withCorrelationId(int $correlationId): void;
}
