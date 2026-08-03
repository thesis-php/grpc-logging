<?php

declare(strict_types=1);

namespace Thesis\Grpc\Logging;

use Amp\Cancellation;
use Amp\NullCancellation;
use Google\Rpc\Code;
use Psr\Log\LogLevel;
use Testo\Assert;
use Testo\Test;
use Thesis\Grpc\InvokeError;
use Thesis\Grpc\Metadata;
use Thesis\Grpc\RpcType;
use Thesis\Grpc\Server\StreamInfo;
use Thesis\Grpc\ServerStream;

#[Test]
final class ServerInterceptorTest
{
    public function logsUnary(): void
    {
        $logger = new RecordingLogger();
        $response = new \stdClass();

        $result = new ServerInterceptor(new Config($logger))->interceptUnary(
            new \stdClass(),
            new StreamInfo('/svc/Method', RpcType::Unary),
            new Metadata(),
            new NullCancellation(),
            static fn(object $request, StreamInfo $info, Metadata $md, Cancellation $cancellation): \stdClass => $response,
        );

        Assert::same($result, $response);
        Assert::count($logger->records, 2);

        $start = $logger->record(0);
        Assert::same($start['message'], 'started call');
        Assert::same($start['context']['grpc.component'] ?? null, 'server');
        Assert::same($start['context']['grpc.method'] ?? null, '/svc/Method');
        Assert::same($start['context']['grpc.type'] ?? null, 'unary');

        $finish = $logger->record(1);
        Assert::same($finish['message'], 'finished call');
        Assert::same($finish['level'], LogLevel::INFO);
        Assert::same($finish['context']['grpc.code_name'] ?? null, 'OK');
        Assert::false(\array_key_exists('grpc.error', $finish['context']));
    }

    public function logsTheStatusOfAFailedUnaryCall(): void
    {
        $logger = new RecordingLogger();
        $error = new InvokeError(Code::NOT_FOUND, 'missing');
        $thrown = null;

        try {
            new ServerInterceptor(new Config($logger))->interceptUnary(
                new \stdClass(),
                new StreamInfo('/svc/Method', RpcType::Unary),
                new Metadata(),
                new NullCancellation(),
                static function (object $request, StreamInfo $info, Metadata $md, Cancellation $cancellation) use ($error): \stdClass {
                    throw $error;
                },
            );
        } catch (InvokeError $e) {
            $thrown = $e;
        }

        Assert::same($thrown, $error);

        $finish = $logger->record(1);
        Assert::same($finish['context']['grpc.code_name'] ?? null, 'NOT_FOUND');
        Assert::same($finish['context']['grpc.error'] ?? null, $error);
        Assert::same($finish['level'], LogLevel::INFO);
    }

    public function logsStreamingCallsWithTheirRpcType(): void
    {
        $logger = new RecordingLogger();
        $called = false;

        new ServerInterceptor(new Config($logger))->interceptStream(
            new FakeServerStream(),
            new StreamInfo('/svc/Chat', RpcType::BidirectionalStream),
            new Metadata(),
            new NullCancellation(),
            static function (ServerStream $stream, StreamInfo $info, Metadata $md, Cancellation $cancellation) use (&$called): void {
                $called = true;
            },
        );

        Assert::true($called);
        Assert::count($logger->records, 2);
        Assert::same($logger->record(1)['context']['grpc.type'] ?? null, 'bidi_stream');
        Assert::same($logger->record(1)['context']['grpc.code_name'] ?? null, 'OK');
    }

    public function omitsTheStartRecordWhenTheStartEventIsDisabled(): void
    {
        $logger = new RecordingLogger();

        new ServerInterceptor(new Config($logger, events: [Event::Finish]))->interceptUnary(
            new \stdClass(),
            new StreamInfo('/svc/Method', RpcType::Unary),
            new Metadata(),
            new NullCancellation(),
            static fn(object $request, StreamInfo $info, Metadata $md, Cancellation $cancellation): \stdClass => new \stdClass(),
        );

        Assert::count($logger->records, 1);
        Assert::same($logger->record(0)['message'], 'finished call');
    }

    public function logsEachStreamMessageWhenPayloadEventsAreEnabled(): void
    {
        $logger = new RecordingLogger();
        $incoming = [new \stdClass(), new \stdClass()];
        $stream = new FakeServerStream($incoming);

        new ServerInterceptor(new Config($logger, events: [Event::PayloadReceived, Event::PayloadSent]))->interceptStream(
            $stream,
            new StreamInfo('/svc/Chat', RpcType::BidirectionalStream),
            new Metadata(),
            new NullCancellation(),
            static function (ServerStream $stream, StreamInfo $info, Metadata $md, Cancellation $cancellation): void {
                foreach ($stream as $_message);

                $stream->send(new \stdClass());
            },
        );

        $messages = array_map(static fn(array $record): string => $record['message'], $logger->records);
        Assert::same($messages, ['request received', 'request received', 'response sent']);
        Assert::same($logger->record(0)['context']['grpc.request.content'] ?? null, $incoming[0]);
        Assert::same($logger->record(0)['context']['grpc.type'] ?? null, 'bidi_stream');
        Assert::float($logger->record(0)['context']['grpc.recv.time_ms'] ?? null); // per-message duration
        Assert::float($logger->record(2)['context']['grpc.send.time_ms'] ?? null);
        Assert::count($stream->sent, 1);
    }
}

/**
 * @template-implements ServerStream<\stdClass, \stdClass>
 */
final class FakeServerStream implements ServerStream
{
    public Metadata $headers;

    public Metadata $trailers;

    /** @var list<\stdClass> */
    public array $sent = [];

    /**
     * @param list<\stdClass> $incoming
     */
    public function __construct(
        private array $incoming = [],
    ) {
        $this->headers = new Metadata();
        $this->trailers = new Metadata();
    }

    #[\Override]
    public function send(object $message): void
    {
        $this->sent[] = $message;
    }

    #[\Override]
    public function receive(): object
    {
        return array_shift($this->incoming) ?? throw new \LogicException('FakeServerStream is drained.');
    }

    #[\Override]
    public function getIterator(): \Traversable
    {
        while ($this->incoming !== []) {
            yield array_shift($this->incoming);
        }
    }

    #[\Override]
    public function close(): void {}
}
