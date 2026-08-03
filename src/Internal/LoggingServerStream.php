<?php

declare(strict_types=1);

namespace Thesis\Grpc\Logging\Internal;

use Thesis\Grpc\Server\DecoratedStream;
use Thesis\Grpc\ServerStream;

/**
 * @internal
 * @template In of object
 * @template Out of object
 * @template-extends DecoratedStream<In, Out>
 */
final class LoggingServerStream extends DecoratedStream
{
    /**
     * @param ServerStream<In, Out> $stream
     */
    public function __construct(
        ServerStream $stream,
        private readonly Span $span,
    ) {
        parent::__construct($stream);
    }

    #[\Override]
    public function send(object $message): void
    {
        $op = $this->span->sending();

        parent::send($message);

        $op->record($message);
    }

    #[\Override]
    public function receive(): object
    {
        $op = $this->span->receiving();

        $message = parent::receive();

        $op->record($message);

        return $message;
    }

    #[\Override]
    public function getIterator(): \Traversable
    {
        $op = $this->span->receiving();

        foreach (parent::getIterator() as $key => $message) {
            $op->record($message);

            yield $key => $message;

            $op = $this->span->receiving();
        }
    }
}
