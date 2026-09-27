<?php

declare(strict_types=1);

namespace Thesis\Grpc\Logging;

use Amp\Cancellation;
use Thesis\Google\Rpc\Code;
use Thesis\Grpc\Logging\Internal\LoggingServerStream;
use Thesis\Grpc\Logging\Internal\Recorder;
use Thesis\Grpc\Metadata;
use Thesis\Grpc\Server\StreamInfo;
use Thesis\Grpc\Server\StreamInterceptor;
use Thesis\Grpc\Server\UnaryInterceptor;
use Thesis\Grpc\ServerStream;

/**
 * @api
 */
final readonly class ServerInterceptor implements
    UnaryInterceptor,
    StreamInterceptor
{
    private Recorder $recorder;

    public function __construct(
        private Config $config,
    ) {
        $this->recorder = new Recorder(
            $config,
            Internal\Component::Server,
        );
    }

    #[\Override]
    public function interceptUnary(
        object $request,
        StreamInfo $info,
        Metadata $md,
        Cancellation $cancellation,
        callable $handler,
    ): object {
        $span = $this->recorder->start($info->method, Internal\Type::fromRpcType($info->type));
        $span->payloadReceived($request);

        try {
            $response = $handler($request, $info, $md, $cancellation);
        } catch (\Throwable $e) {
            $span->error($e);

            throw $e;
        }

        $span->payloadSent($response);
        $span->complete(Code::OK);

        return $response;
    }

    #[\Override]
    public function interceptStream(
        ServerStream $stream,
        StreamInfo $info,
        Metadata $md,
        Cancellation $cancellation,
        callable $next,
    ): void {
        $span = $this->recorder->start($info->method, Internal\Type::fromRpcType($info->type));

        if ($this->config->enabled(Event::PayloadSent, Event::PayloadReceived)) {
            $stream = new LoggingServerStream($stream, $span);
        }

        try {
            $next($stream, $info, $md, $cancellation);
        } catch (\Throwable $e) {
            $span->error($e);

            throw $e;
        }

        $span->complete(Code::OK);
    }
}
