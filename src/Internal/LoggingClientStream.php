<?php

declare(strict_types=1);

namespace Thesis\Grpc\Logging\Internal;

use Amp\Cancellation;
use Amp\NullCancellation;
use Thesis\Google\Rpc\Code;
use Thesis\Grpc\ClientStream;
use Thesis\Grpc\Metadata;

/**
 * @internal
 * @template In of object
 * @template Out of object
 * @template-implements ClientStream<In, Out>
 */
final class LoggingClientStream implements ClientStream
{
    private bool $finished = false;

    /**
     * @param ClientStream<In, Out> $stream
     */
    public function __construct(
        private readonly ClientStream $stream,
        private readonly Span $span,
        private readonly bool $serverStreams,
    ) {}

    #[\Override]
    public function send(object $message): void
    {
        $op = $this->span->sending();

        $this->stream->send($message);

        $op->record($message);
    }

    #[\Override]
    public function receive(): object
    {
        $op = $this->span->receiving();

        try {
            $message = $this->stream->receive();
        } catch (\Throwable $e) {
            if (!$this->serverStreams) {
                $this->finish($e);
            }

            throw $e;
        }

        $op->record($message);

        if (!$this->serverStreams) {
            $this->finish();
        }

        return $message;
    }

    #[\Override]
    public function getIterator(): \Traversable
    {
        try {
            $op = $this->span->receiving();

            foreach ($this->stream as $key => $message) {
                $op->record($message);

                yield $key => $message;

                $op = $this->span->receiving();
            }
        } catch (\Throwable $e) {
            $this->finish($e);

            throw $e;
        }

        $this->finish();
    }

    #[\Override]
    public function headers(): Metadata
    {
        return $this->stream->headers();
    }

    #[\Override]
    public function trailers(Cancellation $cancellation = new NullCancellation()): Metadata
    {
        return $this->stream->trailers($cancellation);
    }

    #[\Override]
    public function close(): void
    {
        $this->stream->close();
    }

    private function finish(?\Throwable $e = null): void
    {
        if ($this->finished) {
            return;
        }

        $this->finished = true;

        if ($e === null) {
            $this->span->complete(Code::OK);
        } else {
            $this->span->error($e);
        }
    }
}
