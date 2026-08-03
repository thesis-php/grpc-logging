<?php

declare(strict_types=1);

namespace Thesis\Grpc\Logging\Internal;

use Thesis\Grpc\Logging\Config;

/**
 * @internal
 */
final readonly class Recorder
{
    public function __construct(
        private Config $config,
        private Component $component,
    ) {}

    /**
     * @param non-empty-string $method
     */
    public function start(string $method, Type $type): Span
    {
        return new Span($this->config, $this->component, $method, $type);
    }
}
