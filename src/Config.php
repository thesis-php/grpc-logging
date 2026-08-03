<?php

declare(strict_types=1);

namespace Thesis\Grpc\Logging;

use Psr\Log\LoggerInterface;

/**
 * @api
 */
final readonly class Config
{
    /**
     * @param list<Event> $events which points of an RPC's lifetime produce a log record
     */
    public function __construct(
        public LoggerInterface $logger,
        public Levels $levels = new DefaultLevels(),
        public array $events = [
            Event::Start,
            Event::Finish,
        ],
    ) {}

    public function enabled(Event ...$events): bool
    {
        return array_any($events, fn(Event $event) => \in_array($event, $this->events, true));
    }
}
