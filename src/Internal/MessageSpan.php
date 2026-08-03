<?php

declare(strict_types=1);

namespace Thesis\Grpc\Logging\Internal;

/**
 * @internal
 */
final readonly class MessageSpan
{
    private float $startedAt;

    /**
     * @param \Closure(object, float): void $op
     * @param \Closure(float): float $elapsed
     */
    public function __construct(
        private \Closure $op,
        private \Closure $elapsed,
    ) {
        $this->startedAt = (float) hrtime(true);
    }

    public function record(object $message): void
    {
        $elapsed = ($this->elapsed)($this->startedAt);

        ($this->op)($message, $elapsed);
    }
}
