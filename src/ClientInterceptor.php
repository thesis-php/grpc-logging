<?php

declare(strict_types=1);

namespace Thesis\Grpc\Logging;

use Amp\Cancellation;
use Thesis\Google\Rpc\Code;
use Thesis\Grpc\Client\Invoke;
use Thesis\Grpc\Client\StreamInterceptor;
use Thesis\Grpc\Client\UnaryInterceptor;
use Thesis\Grpc\ClientStream;
use Thesis\Grpc\Logging\Internal\LoggingClientStream;
use Thesis\Grpc\Logging\Internal\Recorder;
use Thesis\Grpc\Metadata;
use Thesis\Grpc\RpcType;

/**
 * @api
 */
final readonly class ClientInterceptor implements
    UnaryInterceptor,
    StreamInterceptor
{
    private Recorder $recorder;

    public function __construct(Config $config)
    {
        $this->recorder = new Recorder(
            $config,
            Internal\Component::Client,
        );
    }

    #[\Override]
    public function interceptUnary(
        object $request,
        Invoke $invoke,
        Metadata $md,
        Cancellation $cancellation,
        callable $invoker,
    ): object {
        $span = $this->recorder->start($invoke->method, Internal\Type::fromRpcType($invoke->type));
        $span->payloadSent($request);

        try {
            $response = $invoker($request, $invoke, $md, $cancellation);
        } catch (\Throwable $e) {
            $span->error($e);

            throw $e;
        }

        $span->payloadReceived($response);
        $span->complete(Code::OK);

        return $response;
    }

    #[\Override]
    public function interceptStream(
        Invoke $invoke,
        Metadata $md,
        Cancellation $cancellation,
        callable $newStream,
    ): ClientStream {
        return new LoggingClientStream(
            $newStream($invoke, $md, $cancellation),
            $this->recorder->start($invoke->method, Internal\Type::fromRpcType($invoke->type)),
            $invoke->type === RpcType::ServerStream || $invoke->type === RpcType::BidirectionalStream,
        );
    }
}
