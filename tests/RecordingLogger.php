<?php

declare(strict_types=1);

namespace Thesis\Grpc\Logging;

use Psr\Log\AbstractLogger;

/**
 * @phpstan-type Record array{level: non-empty-string, message: string, context: array<array-key, mixed>}
 */
final class RecordingLogger extends AbstractLogger
{
    /** @var list<Record> */
    public array $records = [];

    #[\Override]
    public function log($level, string|\Stringable $message, array $context = []): void
    {
        \assert(\is_string($level) && $level !== '');

        $this->records[] = [
            'level' => $level,
            'message' => (string) $message,
            'context' => $context,
        ];
    }

    /**
     * @return Record
     */
    public function record(int $index): array
    {
        return $this->records[$index] ?? throw new \OutOfRangeException("no log record #{$index}");
    }
}
