<?php

declare(strict_types=1);

namespace Thesis\Grpc\Logging;

use Amp\Cancellation;
use Amp\NullCancellation;
use Psr\Log\LogLevel;
use Testo\Assert;
use Testo\Test;
use Thesis\Google\Rpc\Code;
use Thesis\Grpc\Client\Invoke;
use Thesis\Grpc\ClientStream;
use Thesis\Grpc\InvokeError;
use Thesis\Grpc\Metadata;
use Thesis\Grpc\RpcType;

#[Test]
final class ClientInterceptorTest
{
    public function logsUnary(): void
    {
        $logger = new RecordingLogger();
        $response = new \stdClass();

        /** @var Invoke<\stdClass, \stdClass> $invoke */
        $invoke = new Invoke('/svc/Method', \stdClass::class, RpcType::Unary);

        $result = new ClientInterceptor(new Config($logger))->interceptUnary(
            new \stdClass(),
            $invoke,
            new Metadata(),
            new NullCancellation(),
            static fn(object $request, Invoke $invoke, Metadata $md, Cancellation $cancellation): \stdClass => $response,
        );

        Assert::same($result, $response);
        Assert::count($logger->records, 2);
        Assert::same($logger->record(0)['context']['grpc.component'] ?? null, 'client');
        Assert::same($logger->record(1)['context']['grpc.code_name'] ?? null, 'OK');
        Assert::same($logger->record(1)['context']['grpc.type'] ?? null, 'unary');
    }

    public function logsRequestAndResponsePayloads(): void
    {
        $logger = new RecordingLogger();
        $request = new \stdClass();
        $response = new \stdClass();

        /** @var Invoke<\stdClass, \stdClass> $invoke */
        $invoke = new Invoke('/svc/Method', \stdClass::class, RpcType::Unary);

        new ClientInterceptor(new Config($logger, events: [Event::PayloadSent, Event::PayloadReceived]))->interceptUnary(
            $request,
            $invoke,
            new Metadata(),
            new NullCancellation(),
            static fn(object $r, Invoke $i, Metadata $md, Cancellation $c): \stdClass => $response,
        );

        Assert::count($logger->records, 2);
        Assert::same($logger->record(0)['message'], 'request sent');
        Assert::same($logger->record(0)['context']['grpc.request.content'] ?? null, $request);
        Assert::same($logger->record(1)['message'], 'response received');
        Assert::same($logger->record(1)['context']['grpc.response.content'] ?? null, $response);
        Assert::false(\array_key_exists('grpc.send.time_ms', $logger->record(0)['context']));
    }

    public function logsAServerStreamFinishWhenItsIteratorCompletes(): void
    {
        $logger = new RecordingLogger();

        /** @var Invoke<\stdClass, \stdClass> $invoke */
        $invoke = new Invoke('/svc/Stream', \stdClass::class, RpcType::ServerStream);

        $stream = new ClientInterceptor(new Config($logger))->interceptStream(
            $invoke,
            new Metadata(),
            new NullCancellation(),
            static fn(Invoke $invoke, Metadata $md, Cancellation $cancellation): ClientStream => new FakeClientStream([new \stdClass(), new \stdClass()]),
        );

        Assert::count($logger->records, 1);

        iterator_to_array($stream);

        Assert::count($logger->records, 2);
        Assert::same($logger->record(1)['message'], 'finished call');
        Assert::same($logger->record(1)['context']['grpc.code_name'] ?? null, 'OK');
        Assert::same($logger->record(1)['context']['grpc.type'] ?? null, 'server_stream');
    }

    public function logsAClientStreamFinishOnItsSingleReceive(): void
    {
        $logger = new RecordingLogger();
        $response = new \stdClass();

        /** @var Invoke<\stdClass, \stdClass> $invoke */
        $invoke = new Invoke('/svc/Upload', \stdClass::class, RpcType::ClientStream);

        $stream = new ClientInterceptor(new Config($logger))->interceptStream(
            $invoke,
            new Metadata(),
            new NullCancellation(),
            static fn(Invoke $invoke, Metadata $md, Cancellation $cancellation): ClientStream => new FakeClientStream([$response]),
        );

        $stream->send(new \stdClass());
        $stream->close();

        Assert::count($logger->records, 1);
        $received = $stream->receive();

        Assert::same($received, $response);
        Assert::count($logger->records, 2);
        Assert::same($logger->record(1)['message'], 'finished call');
        Assert::same($logger->record(1)['context']['grpc.code_name'] ?? null, 'OK');
        Assert::same($logger->record(1)['context']['grpc.type'] ?? null, 'client_stream');
    }

    public function logsAStreamErrorWithItsStatus(): void
    {
        $logger = new RecordingLogger();

        /** @var Invoke<\stdClass, \stdClass> $invoke */
        $invoke = new Invoke('/svc/Stream', \stdClass::class, RpcType::ServerStream);

        $stream = new ClientInterceptor(new Config($logger))->interceptStream(
            $invoke,
            new Metadata(),
            new NullCancellation(),
            static fn(Invoke $invoke, Metadata $md, Cancellation $cancellation): ClientStream => new FakeClientStream([new InvokeError(Code::UNAVAILABLE)]),
        );

        try {
            iterator_to_array($stream);
        } catch (InvokeError $e) {
            Assert::same($e->statusCode, Code::UNAVAILABLE);
            Assert::same($logger->record(1)['context']['grpc.code_name'] ?? null, 'UNAVAILABLE');
            Assert::same($logger->record(1)['context']['grpc.error'] ?? null, $e);
            Assert::same($logger->record(1)['level'], LogLevel::WARNING);

            return;
        }

        Assert::fail('Expected the failing stream to rethrow.');
    }
}

/**
 * @template-implements ClientStream<\stdClass, \stdClass>
 */
final class FakeClientStream implements ClientStream
{
    /**
     * @param list<\stdClass|InvokeError> $queue
     */
    public function __construct(
        private array $queue = [],
    ) {}

    #[\Override]
    public function send(object $message): void {}

    #[\Override]
    public function receive(): object
    {
        $next = array_shift($this->queue);

        if ($next instanceof InvokeError) {
            throw $next;
        }

        if ($next === null) {
            throw new \LogicException('FakeClientStream queue is exhausted.');
        }

        return $next;
    }

    #[\Override]
    public function getIterator(): \Traversable
    {
        foreach ($this->queue as $item) {
            if ($item instanceof InvokeError) {
                throw $item;
            }

            yield $item;
        }
    }

    #[\Override]
    public function headers(): Metadata
    {
        return new Metadata();
    }

    #[\Override]
    public function trailers(Cancellation $cancellation = new NullCancellation()): Metadata
    {
        return new Metadata();
    }

    #[\Override]
    public function close(): void {}
}
